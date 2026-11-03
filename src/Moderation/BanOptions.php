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

    public function validate(): void
    {
        if (!\in_array($this->catalog, self::CATALOG, true) || !\in_array($this->refund, self::REFUND, true)) {
            throw new ModerationException('invalid_option');
        }
    }
}
