<?php

namespace App\Account;

use App\Entity\Address;
use App\Entity\User;
use App\Phone\PhoneNumberParser;
use App\Repository\AddressRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Carnet d'adresses de livraison d'un CLIENT : plusieurs adresses, dont UNE « par défaut ».
 *
 * L'adresse par défaut est celle que la page de commande propose en premier ; le client peut en choisir une autre ou en saisir
 * une nouvelle à ce moment-là, sans toucher à son adresse par défaut (sauf s'il le demande).
 *
 * Règles :
 *   - 10 adresses au maximum par compte ;
 *   - la 1re adresse enregistrée devient automatiquement l'adresse par défaut ;
 *   - quand on supprime l'adresse par défaut, la plus récente des autres prend le relais ;
 *   - une adresse identique (même destinataire, même rue, même ville) n'est jamais créée deux fois ;
 *   - on n'agit que sur les adresses du compte connecté : l'identifiant d'une adresse d'un autre compte donne « introuvable ».
 *
 * Concurrence : chaque modification prend un verrou PostgreSQL par client (pg_advisory_xact_lock, libéré à la fin de la
 * transaction). Deux requêtes simultanées du même client (double-clic, deux onglets) se suivent donc au lieu de se croiser,
 * et l'index unique partiel `uniq_addresses_default` reste le garde-fou ultime : jamais deux adresses par défaut.
 */
final class AddressBook
{
    /** Nombre maximal d'adresses gardées par compte. */
    public const MAX_ADDRESSES = 10;

    public function __construct(
        private readonly AddressRepository $addresses,
        private readonly EntityManagerInterface $em,
        private readonly PhoneNumberParser $phoneParser,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return list<Address> l'adresse par défaut d'abord, puis les plus récentes */
    public function list(User $user): array
    {
        return $this->addresses->findForUser($user);
    }

    public function defaultFor(User $user): ?Address
    {
        return $this->addresses->findForUser($user)[0] ?? null;
    }

    /**
     * Ajoute une adresse.
     *
     * @throws AccountActionException « address_limit »
     */
    public function add(User $user, AddressData $data): Address
    {
        return $this->atomically($user, function () use ($user, $data): Address {
            $saved = $this->addresses->findForUser($user);

            // Même adresse déjà enregistrée : on la met à jour au lieu d'en créer une seconde.
            if (null !== $known = $this->findSame($saved, $data->fullName, $data->street, $data->city)) {
                $this->apply($known, $data);
                if ($data->makeDefault) {
                    $this->makeDefault($user, $saved, $known);
                }

                return $known;
            }
            if (\count($saved) >= self::MAX_ADDRESSES) {
                throw new AccountActionException('address_limit', ['%max%' => self::MAX_ADDRESSES]);
            }

            // La première adresse du compte devient d'office l'adresse par défaut.
            $makeDefault = $data->makeDefault || !$this->hasDefault($saved);
            $address = new Address($user, $data->fullName, $this->phone($data->phone), $data->street, $data->city, '' !== $data->postalCode ? $data->postalCode : null, '' !== $data->instructions ? $data->instructions : null, $this->clock->now(), '' !== $data->label ? $data->label : null);
            if ($makeDefault) {
                $this->clearDefaults($user, $saved);
                $address->markDefault(true);
            }
            $this->em->persist($address);

            return $address;
        });
    }

    /**
     * Modifie une adresse du compte.
     *
     * @throws AccountActionException « not_found »
     */
    public function update(User $user, string $id, AddressData $data): Address
    {
        return $this->atomically($user, function () use ($user, $id, $data): Address {
            $address = $this->addresses->findOwned($user, $id) ?? throw new AccountActionException('not_found');
            $this->apply($address, $data);
            if ($data->makeDefault && !$address->isDefault()) {
                $this->makeDefault($user, $this->addresses->findForUser($user), $address);
            }

            return $address;
        });
    }

    /**
     * Fait de cette adresse l'adresse par défaut.
     *
     * @throws AccountActionException « not_found »
     */
    public function setDefault(User $user, string $id): Address
    {
        return $this->atomically($user, function () use ($user, $id): Address {
            $address = $this->addresses->findOwned($user, $id) ?? throw new AccountActionException('not_found');
            if (!$address->isDefault()) {
                $this->makeDefault($user, $this->addresses->findForUser($user), $address);
            }

            return $address;
        });
    }

    /**
     * Supprime une adresse. Si c'était l'adresse par défaut, la plus récente des autres la remplace.
     * (Les commandes passées gardent leur propre COPIE de l'adresse : rien n'y change.)
     *
     * @throws AccountActionException « not_found »
     */
    public function delete(User $user, string $id): void
    {
        $this->atomically($user, function () use ($user, $id): void {
            $address = $this->addresses->findOwned($user, $id) ?? throw new AccountActionException('not_found');
            $wasDefault = $address->isDefault();
            $this->em->remove($address);
            $this->em->flush();

            if ($wasDefault && null !== $next = $this->addresses->findForUser($user)[0] ?? null) {
                $next->markDefault(true);
            }
        });
    }

    /**
     * Mémorise l'adresse utilisée à la COMMANDE (appelé par OrderService, dans la transaction de la commande).
     *
     * @param array<string, mixed> $address     copie figée de l'adresse de la commande
     * @param bool                 $save        enregistrer cette adresse dans le carnet (case cochée par défaut sur la page de commande)
     * @param bool                 $makeDefault en faire l'adresse par défaut
     *
     * Jamais d'erreur ici : une commande ne doit pas échouer parce que le carnet est plein. Dans ce cas, l'adresse n'est
     * simplement pas enregistrée.
     */
    public function remember(User $user, array $address, bool $save, bool $makeDefault): void
    {
        $this->lock($user);
        $saved = $this->addresses->findForUser($user);

        $fullName = (string) $address['fullName'];
        $street = (string) $address['street'];
        $city = (string) $address['city'];

        // Adresse déjà enregistrée : on ne la recrée pas ; on la met par défaut si demandé.
        if (null !== $known = $this->findSame($saved, $fullName, $street, $city)) {
            if ($makeDefault && !$known->isDefault()) {
                $this->makeDefault($user, $saved, $known);
            }

            return;
        }
        if (!$save || \count($saved) >= self::MAX_ADDRESSES) {
            return;
        }

        $phone = \is_string($address['phone'] ?? null) && '' !== $address['phone'] ? $address['phone'] : null;
        $new = new Address($user, $fullName, $phone, $street, $city, $address['postalCode'] ?? null, $address['instructions'] ?? null, $this->clock->now());
        if ($makeDefault || !$this->hasDefault($saved)) {
            $this->clearDefaults($user, $saved);
            $new->markDefault(true);
        }
        $this->em->persist($new);
    }

    // ------------------------------------------------------------------ Outils internes

    /**
     * Exécute `$work` dans UNE transaction, sous le verrou du carnet de ce client.
     *
     * Un refus prévu (AccountActionException) est relancé APRÈS la fin de la transaction : si on le laissait traverser
     * `wrapInTransaction`, Doctrine fermerait l'EntityManager (il considère toute exception comme une panne), alors qu'il
     * s'agit d'un simple « adresse introuvable » ou « carnet plein ».
     */
    private function atomically(User $user, callable $work): mixed
    {
        $outcome = $this->em->wrapInTransaction(function () use ($user, $work): mixed {
            $this->lock($user);
            try {
                return $work();
            } catch (AccountActionException $refusal) {
                return $refusal;
            }
        });
        if ($outcome instanceof AccountActionException) {
            throw $outcome;
        }

        return $outcome;
    }

    /** Verrou par client, libéré à la fin de la transaction en cours : les modifications d'un même carnet se suivent. */
    private function lock(User $user): void
    {
        $this->em->getConnection()->executeStatement('SELECT pg_advisory_xact_lock(hashtext(:k))', ['k' => 'address_book|'.$user->getId()->toRfc4122()]);
    }

    private function apply(Address $address, AddressData $data): void
    {
        $address->update($data->fullName, $this->phone($data->phone), $data->street, $data->city, '' !== $data->postalCode ? $data->postalCode : null, '' !== $data->instructions ? $data->instructions : null, '' !== $data->label ? $data->label : null);
    }

    private function phone(string $raw): ?string
    {
        return '' === $raw ? null : $this->phoneParser->parse($raw)?->e164;
    }

    /** @param list<Address> $saved */
    private function hasDefault(array $saved): bool
    {
        foreach ($saved as $address) {
            if ($address->isDefault()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<Address> $saved
     */
    private function findSame(array $saved, string $fullName, string $street, string $city): ?Address
    {
        $norm = static fn (string $s): string => mb_strtolower(trim($s));
        foreach ($saved as $known) {
            if ($norm($known->getFullName()) === $norm($fullName) && $norm($known->getStreet()) === $norm($street) && $norm($known->getCity()) === $norm($city)) {
                return $known;
            }
        }

        return null;
    }

    /**
     * Retire l'étiquette « par défaut » de toutes les adresses du client, TOUT DE SUITE (UPDATE en base) : l'index unique ne
     * tolère jamais deux adresses par défaut, même une fraction de seconde au sein d'une même transaction.
     *
     * @param list<Address> $saved
     */
    private function clearDefaults(User $user, array $saved): void
    {
        $this->em->getConnection()->executeStatement('UPDATE addresses SET is_default = false WHERE user_id = :u AND is_default = true', ['u' => $user->getId()->toRfc4122()]);
        foreach ($saved as $address) {
            if ($address->isDefault()) {
                $address->markDefault(false);
            }
        }
    }

    /** @param list<Address> $saved */
    private function makeDefault(User $user, array $saved, Address $address): void
    {
        $this->clearDefaults($user, $saved);
        $address->markDefault(true);
    }
}
