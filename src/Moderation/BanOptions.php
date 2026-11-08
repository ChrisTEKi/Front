<?php

namespace App\Moderation;

use App\Enum\BlacklistKind;

/**
 * Mesures d'accompagnement d'un bannissement, choisies par l'administrateur (chacune exige son propre droit : voir AdminSanctionController).
 */
final class BanOptions
{
    public const CATALOG = ['none', 'remove', 'delete'];
    public const REFUND = ['none', 'open', 'all'];

    /**
     * @param string              $catalog       « none », « remove » (retrait de la vente, réversible) ou « delete » (suppression définitive) — fournisseur seulement
     * @param string              $refund        « none », « open » (commandes en cours) ou « all » (en cours + livrées)
     * @param list<BlacklistKind> $blacklist     identifiants à interdire d'inscription
     * @param int|null            $blacklistDays durée de la liste noire en jours (null = définitive ; 30 jours par défaut pour une IP)
     */
    public function __construct(
        public readonly string $catalog = 'none',
        public readonly string $refund = 'none',
        public readonly array $blacklist = [],
        public readonly ?int $blacklistDays = null,
    ) {
    }

    /**
     * Contrôle TOUTES les options AVANT de prononcer le bannissement : un choix invalide (durée de liste noire hors limites…) ne
     * doit pas laisser un bannissement à moitié appliqué.
     *
     * @throws ModerationException « invalid_option », « invalid_days »
     */
    public function validate(): void
    {
        if (!\in_array($this->catalog, self::CATALOG, true) || !\in_array($this->refund, self::REFUND, true)) {
            throw new ModerationException('invalid_option');
        }
        if ([] !== $this->blacklist && null !== $this->blacklistDays) {
            // La même durée vaut pour tous les identifiants cochés ; une adresse IP est plafonnée plus bas (voir BlacklistService).
            $max = \in_array(BlacklistKind::Ip, $this->blacklist, true) ? BlacklistService::MAX_IP_DAYS : BlacklistService::MAX_DAYS;
            if ($this->blacklistDays < 1 || $this->blacklistDays > $max) {
                throw new ModerationException('invalid_days', ['%max%' => $max]);
            }
        }
    }
}
