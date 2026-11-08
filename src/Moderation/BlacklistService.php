<?php

namespace App\Moderation;

use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Auth\Exception\RegistrationBlockedException;
use App\Entity\BlacklistEntry;
use App\Entity\SupplierProfile;
use App\Entity\User;
use App\Enum\BlacklistKind;
use App\Phone\PhoneNumberParser;
use App\Repository\AuditLogRepository;
use App\Repository\BlacklistEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Liste noire : refuse les INSCRIPTIONS dont l'e-mail, le téléphone, le nom, la raison sociale ou l'adresse IP ont été interdits
 * par l'équipe (droit « blacklist.manage », ou automatiquement lors d'un bannissement).
 *
 * Toute valeur est NORMALISÉE avant d'être comparée, pour que les variantes évidentes tombent dans la même entrée :
 *   - e-mail : minuscules, « +étiquette » retirée, points ignorés chez Gmail (« j.dupont+x@googlemail.com » ≡ « jdupont@gmail.com ») ;
 *   - téléphone : format international E.164 ;
 *   - nom / raison sociale : sans accents ni ponctuation, minuscules, mots triés pour un nom (« Dupont Jean » ≡ « jean dupont ») ;
 *   - IP : forme canonique (IPv6 abrégée), et une échéance est OBLIGATOIRE (une même IP est souvent partagée par des centaines
 *     d'abonnés d'un opérateur mobile : un blocage définitif frapperait des innocents).
 *
 * Limite assumée : un blocage par NOM peut toucher un homonyme honnête. Le refus affiche donc un message neutre qui renvoie vers
 * le support (voir RegistrationBlockedException).
 */
final class BlacklistService
{
    public const MAX_DAYS = 3650;
    public const MAX_IP_DAYS = 365;
    public const MAX_VALUE_LENGTH = 255;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BlacklistEntryRepository $entries,
        private readonly PhoneNumberParser $phones,
        private readonly AuditLogRepository $auditLogs,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
        private readonly RequestStack $requestStack,
    ) {
    }

    // ------------------------------------------------------------------ Normalisation

    /** Valeur normalisée, ou null si la saisie n'est pas valable pour ce type. */
    public function normalize(BlacklistKind $kind, ?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ('' === $raw || mb_strlen($raw) > self::MAX_VALUE_LENGTH) {
            return null;
        }

        return match ($kind) {
            BlacklistKind::Email => $this->normalizeEmail($raw),
            BlacklistKind::Phone => $this->phones->parse($raw)?->e164,
            BlacklistKind::Name => $this->normalizeName($raw, true),
            BlacklistKind::Company => $this->normalizeName($raw, false),
            BlacklistKind::Ip => $this->normalizeIp($raw),
        };
    }

    private function normalizeEmail(string $raw): ?string
    {
        $email = mb_strtolower($raw);
        if (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        [$local, $domain] = explode('@', $email, 2);
        $local = explode('+', $local, 2)[0];
        if (\in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $domain = 'gmail.com';
            $local = str_replace('.', '', $local);
        }

        return '' === $local ? null : $local.'@'.$domain;
    }

    /** @param bool $person vrai pour un nom de personne (au moins deux mots, ordre sans importance) */
    private function normalizeName(string $raw, bool $person): ?string
    {
        $ascii = \Transliterator::create('Any-Latin; Latin-ASCII; Lower()')?->transliterate($raw) ?: mb_strtolower($raw);
        $words = preg_split('/[^a-z0-9]+/', $ascii, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        if ($person) {
            // Un seul mot (« Rakoto ») ferait trop d'homonymes : un nom complet est exigé.
            if (\count($words) < 2) {
                return null;
            }
            sort($words);
        }
        $value = implode(' ', $words);

        return mb_strlen($value) >= 3 ? $value : null;
    }

    private function normalizeIp(string $raw): ?string
    {
        if (false === filter_var($raw, \FILTER_VALIDATE_IP)) {
            return null;
        }
        $packed = @inet_pton($raw);

        return false === $packed ? null : (inet_ntop($packed) ?: null);
    }

    // ------------------------------------------------------------------ Gestion de la liste

    /**
     * Ajoute (ou renouvelle) une entrée.
     *
     * @param int|null $days durée en jours ; null = définitive (refusé pour une adresse IP)
     *
     * @throws ModerationException « reason_required », « invalid_blacklist_value », « ip_needs_expiry », « invalid_days »
     */
    public function add(?User $admin, BlacklistKind $kind, string $raw, string $reason, ?int $days): BlacklistEntry
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new ModerationException('reason_required');
        }
        $value = $this->normalize($kind, $raw) ?? throw new ModerationException('invalid_blacklist_value', ['%kind%' => $kind->value]);
        $expires = $this->expiry($kind, $days);

        $now = $this->clock->now();
        $reason = mb_substr($reason, 0, 500);
        $entry = $this->entries->findOneByKindAndValue($kind, $value);
        if (null === $entry) {
            $entry = new BlacklistEntry($kind, $value, $reason, $admin, $now, $expires);
            $this->em->persist($entry);
        } else {
            $entry->renew($reason, $admin, $now, $expires);
        }
        $this->em->flush();
        $this->audit->record(AuditAction::BLACKLIST_ADDED, $admin, 'blacklist', $entry->getId()->toRfc4122(), ['kind' => $kind->value, 'value' => $value, 'days' => $days, 'reason' => $reason]);

        return $entry;
    }

    public function remove(User $admin, BlacklistEntry $entry): void
    {
        $id = $entry->getId()->toRfc4122();
        $meta = ['kind' => $entry->getKind()->value, 'value' => $entry->getValue()];
        $this->em->remove($entry);
        $this->em->flush();
        $this->audit->record(AuditAction::BLACKLIST_REMOVED, $admin, 'blacklist', $id, $meta);
    }

    /**
     * Compte BANNI puis supprimé : son e-mail et son téléphone ne doivent pas se libérer (sinon la personne se réinscrirait avec).
     * Appelé par la purge du compte, avant l'effacement. Entrées définitives, rattachées à l'administrateur qui a banni (s'il existe encore).
     *
     * @return list<string> natures ajoutées
     */
    public function keepBannedIdentity(User $banned): array
    {
        $by = $banned->getBannedBy() ?? $banned->getDeletionRequestedBy();

        return $this->addForAccount($by, $banned, [BlacklistKind::Email, BlacklistKind::Phone], 'Compte banni puis supprimé : identifiants conservés sur la liste noire.', null);
    }

    /**
     * Interdit d'un coup les identifiants d'un compte (e-mail, téléphone, nom, raison sociale, dernière adresse IP connue).
     * Les natures qui n'ont pas de valeur pour ce compte (pas de téléphone, client sans raison sociale…) sont ignorées.
     *
     * @param list<BlacklistKind> $kinds
     *
     * @return list<string> natures réellement ajoutées
     *
     * @throws ModerationException
     */
    public function addForAccount(?User $admin, User $target, array $kinds, string $reason, ?int $days): array
    {
        $added = [];
        foreach (array_unique($kinds, \SORT_REGULAR) as $kind) {
            foreach ($this->valuesOf($target, $kind) as $raw) {
                // Une valeur inutilisable (nom d'un seul mot…) est ignorée plutôt que de faire échouer tout le bannissement.
                if (null === $this->normalize($kind, $raw)) {
                    continue;
                }
                $this->add($admin, $kind, $raw, $reason, BlacklistKind::Ip === $kind ? ($days ?? 30) : $days);
                $added[] = $kind->value;
            }
        }

        return array_values(array_unique($added));
    }

    /** @return list<string> */
    private function valuesOf(User $target, BlacklistKind $kind): array
    {
        return match ($kind) {
            BlacklistKind::Email => [$target->getEmail()],
            BlacklistKind::Phone => null !== $target->getPhone() ? [$target->getPhone()] : [],
            BlacklistKind::Name => [$target->getFullName()],
            BlacklistKind::Company => $this->companyOf($target),
            BlacklistKind::Ip => $this->auditLogs->findKnownIps($target->getId()->toRfc4122(), 2),
        };
    }

    /** @return list<string> */
    private function companyOf(User $target): array
    {
        $profile = $this->em->getRepository(SupplierProfile::class)->find($target->getId());

        return $profile instanceof SupplierProfile ? [$profile->getCompanyName()] : [];
    }

    private function expiry(BlacklistKind $kind, ?int $days): ?\DateTimeImmutable
    {
        if (null === $days) {
            if (BlacklistKind::Ip === $kind) {
                throw new ModerationException('ip_needs_expiry', ['%max%' => self::MAX_IP_DAYS]);
            }

            return null;
        }
        if ($days < 1 || $days > self::MAX_DAYS || (BlacklistKind::Ip === $kind && $days > self::MAX_IP_DAYS)) {
            throw new ModerationException('invalid_days', ['%max%' => BlacklistKind::Ip === $kind ? self::MAX_IP_DAYS : self::MAX_DAYS]);
        }

        return $this->clock->now()->modify('+'.$days.' days');
    }

    // ------------------------------------------------------------------ Contrôle à l'inscription

    /**
     * Refuse l'inscription si l'une des données figure sur la liste noire. L'IP est lue sur la requête en cours.
     *
     * @throws RegistrationBlockedException
     */
    public function assertAllowed(?string $email, ?string $phone, ?string $name, ?string $company = null): void
    {
        $candidates = [
            BlacklistKind::Email->value => $this->candidate(BlacklistKind::Email, $email),
            BlacklistKind::Phone->value => $this->candidate(BlacklistKind::Phone, $phone),
            BlacklistKind::Name->value => $this->candidate(BlacklistKind::Name, $name),
            BlacklistKind::Company->value => $this->candidate(BlacklistKind::Company, $company),
            BlacklistKind::Ip->value => $this->candidate(BlacklistKind::Ip, $this->requestStack->getCurrentRequest()?->getClientIp()),
        ];

        $hits = $this->entries->findActiveMatching($candidates, $this->clock->now());
        if ([] === $hits) {
            return;
        }

        $kinds = array_values(array_unique(array_map(static fn (BlacklistEntry $e): string => $e->getKind()->value, $hits)));
        $this->audit->record(AuditAction::BLACKLIST_HIT, null, 'blacklist', null, ['kinds' => $kinds], $email);

        throw new RegistrationBlockedException($kinds);
    }

    /** @return list<string> */
    private function candidate(BlacklistKind $kind, ?string $raw): array
    {
        $value = null === $raw ? null : $this->normalize($kind, $raw);

        return null === $value ? [] : [$value];
    }
}
