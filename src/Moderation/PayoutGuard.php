<?php

namespace App\Moderation;

use App\Entity\User;

/**
 * Feu rouge unique pour tout versement d'argent à un compte (reversement à un fournisseur, remboursement par virement…).
 *
 * Aucun module de versement n'existe encore (voir README, « Ce qui reste à raccorder ») ; le jour où il sera écrit, il DOIT
 * appeler canPayOut() avant chaque transfert : un compte dont les fonds sont gelés (gel conservatoire), banni, suspendu ou en
 * cours de suppression ne reçoit rien tant que l'équipe n'a pas levé la mesure.
 */
final class PayoutGuard
{
    public function canPayOut(User $account): bool
    {
        return $account->isActive() && !$account->isFundsFrozen();
    }
}
