<?php

namespace App\Account;

use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Entity\Order;
use App\Entity\User;
use App\Notification\SecurityNotifier;
use App\Repository\OrderRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * SUPPRESSION DÉFINITIVE des comptes clients dont le délai de grâce de 30 jours est écoulé.
 *
 * Lancée chaque jour par la commande `app:accounts:purge` (cron). Pour chaque compte « en cours de suppression » dont la
 * date est dépassée, dans UNE transaction (tout ou rien) :
 *
 *   1. les COMMANDES du client sont anonymisées (nom, e-mail, téléphone, adresse, numéro Mobile Money effacés ; montants,
 *      articles et dates gardés : obligations comptables) ;
 *   2. le JOURNAL D'AUDIT est épuré : e-mail, IP et navigateur effacés des lignes du client (et des tentatives de connexion
 *      anonymes faites avec son e-mail), ainsi que les rares métadonnées personnelles. Les lignes elles-mêmes restent
 *      (« une commande a été passée », « un export a eu lieu ») : la traçabilité de la sécurité n'est pas perdue ;
 *   3. le compte est SUPPRIMÉ. La base fait le reste : adresses, comptes Mobile Money, identités Google/Apple et codes
 *      à usage unique sont supprimés en cascade (clés étrangères « ON DELETE CASCADE ») ;
 *   4. une ligne neutre « account.purged » est ajoutée au journal (identifiant du compte seulement : plus aucune donnée
 *      personnelle) ;
 *   5. APRÈS la validation de la transaction, un dernier e-mail prévient la personne.
 *
 * Garde-fous : le compte est relu SOUS VERROU juste avant l'effacement et revérifié (une personne qui vient de restaurer
 * son compte, ou qui a été réactivée, n'est pas effacée par une purge qui tournait en même temps) ; un compte qui a encore
 * une commande EN COURS est reporté au passage suivant ; seuls les comptes
 * CLIENTS sont concernés (jamais un fournisseur ni un administrateur) ; le mode « simulation » ne touche à rien.
 */
final class AccountPurger
{
    /**
     * Clés des métadonnées d'audit qui peuvent contenir une donnée personnelle, retirées à la purge :
     * e-mail, téléphone, numéro Mobile Money masqué, adresse IP, nom.
     */
    private const PERSONAL_METADATA_KEYS = ['email', 'phone', 'number', 'ip', 'name'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ManagerRegistry $registry,
        private readonly UserRepository $users,
        private readonly OrderRepository $orders,
        private readonly AuditLogger $audit,
        private readonly SecurityNotifier $notifier,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param int  $limit  nombre maximal de comptes traités par passage (le reste sera pris au passage suivant)
     * @param bool $dryRun true = ne rien modifier, seulement compter les comptes concernés
     *
     * @return array{due: int, purged: int, failed: int} comptes à purger / purgés / en échec
     */
    public function purgeDue(\DateTimeImmutable $now, int $limit = 200, bool $dryRun = false): array
    {
        $ids = $this->users->findIdsDueForPurge($now, $limit);
        $report = ['due' => \count($ids), 'purged' => 0, 'failed' => 0];
        if ($dryRun) {
            return $report;
        }

        foreach ($ids as $id) {
            try {
                $contact = $this->purgeOne($id, $now);
            } catch (\Throwable $e) {
                // Un compte en échec ne doit pas bloquer les autres : on note l'incident et on passe au suivant.
                ++$report['failed'];
                $this->logger->error('Purge du compte {id} impossible : {message}', ['id' => $id, 'message' => $e->getMessage(), 'exception' => $e]);
                // Une erreur de base ferme l'EntityManager : on en obtient un neuf, sinon on arrête proprement.
                if (!$this->em->isOpen()) {
                    $this->registry->resetManager();
                }
                if (!$this->em->isOpen()) {
                    break;
                }
                continue;
            }
            if (null === $contact) {
                continue; // plus concerné (restauré entre-temps) : ni purgé ni en échec
            }
            ++$report['purged'];
            // Hors transaction : un serveur d'e-mails en panne ne doit pas annuler une suppression déjà validée.
            $this->notifier->accountPurged($contact['email'], $contact['name'], $contact['locale']);
            $this->em->clear();
        }

        return $report;
    }

    /**
     * Efface UN compte. Renvoie ses coordonnées (pour le dernier e-mail) ou null s'il n'est plus à purger.
     *
     * @return array{email: string, name: string, locale: string}|null
     */
    private function purgeOne(string $id, \DateTimeImmutable $now): ?array
    {
        return $this->em->wrapInTransaction(function () use ($id, $now): ?array {
            // Relecture SOUS VERROU (SELECT … FOR UPDATE) : personne ne peut modifier ce compte pendant qu'on décide.
            $user = $this->em->find(User::class, $id, LockMode::PESSIMISTIC_WRITE);
            if (!$user instanceof User
                || !$user->isClient()
                || !$user->isPendingDeletion()
                || null === $user->getDeletionScheduledAt()
                || $user->getDeletionScheduledAt() > $now
            ) {
                return null;
            }
            // Une commande est encore en cours (créée avant la demande de suppression, ou rouverte depuis) : on n'efface PAS
            // maintenant, sinon on couperait le lien entre un colis en route et son destinataire. Le compte reste « en cours de
            // suppression » et sera repris au prochain passage, une fois la commande livrée ou annulée.
            if ($this->orders->countOpenForClient($user) > 0) {
                return null;
            }

            $contact = ['email' => $user->getEmail(), 'name' => $user->getFullName(), 'locale' => $user->getLocale()];

            // 1) Commandes : anonymisation (le lien vers le client est coupé, les montants et dates restent).
            foreach ($this->em->getRepository(Order::class)->findBy(['client' => $user]) as $order) {
                $order->anonymize($now);
            }
            $this->em->flush();

            // 2) Journal d'audit.
            $this->scrubAuditLog($user);

            // 3) Le compte. Adresses, Mobile Money, identités sociales et codes sont supprimés en cascade par la base.
            $this->em->remove($user);
            $this->em->flush();

            // 4) Trace neutre : identifiant seulement.
            $this->audit->record(AuditAction::ACCOUNT_PURGED, null, 'user', $id, ['type' => 'client']);

            return $contact;
        });
    }

    /**
     * Retire les données personnelles du journal d'audit pour ce compte (les lignes sont conservées).
     * Requêtes SQL directes : le journal est volontairement sans clé étrangère et sans méthode de modification dans l'application.
     */
    private function scrubAuditLog(User $user): void
    {
        $connection = $this->em->getConnection();
        $id = $user->getId()->toRfc4122();
        // « jsonb - text[] » retire plusieurs clés d'un coup (opérateur PostgreSQL).
        $keys = '{'.implode(',', self::PERSONAL_METADATA_KEYS).'}';

        // Lignes dont le client est l'AUTEUR : on efface e-mail, IP, navigateur et métadonnées personnelles.
        $connection->executeStatement(
            'UPDATE audit_logs SET actor_email = NULL, ip_address = NULL, user_agent = NULL, metadata = metadata - CAST(:keys AS text[]) WHERE actor_id = :id',
            ['id' => $id, 'keys' => $keys],
        );
        // Tentatives anonymes faites avec son e-mail (connexion ratée, etc.).
        $connection->executeStatement(
            'UPDATE audit_logs SET actor_email = NULL, ip_address = NULL, user_agent = NULL, metadata = metadata - CAST(:keys AS text[]) WHERE actor_id IS NULL AND lower(actor_email) = lower(:email)',
            ['email' => $user->getEmail(), 'keys' => $keys],
        );
        // Lignes où le client est seulement la CIBLE (ex. un admin l'a suspendu) : l'admin reste identifié, on épure le reste.
        $connection->executeStatement(
            'UPDATE audit_logs SET metadata = metadata - CAST(:keys AS text[]) WHERE target_id = :target AND (actor_id IS NULL OR actor_id <> :id)',
            ['target' => $id, 'id' => $id, 'keys' => $keys],
        );
    }
}
