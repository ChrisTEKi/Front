<?php

namespace App\Notification;

use App\Auth\Exception\OtpDeliveryFailedException;
use App\Entity\User;
use App\Enum\OtpChannel;
use App\Enum\OtpPurpose;

/**
 * « Comment un code arrive chez l'utilisateur ».
 *
 * OtpService génère et contrôle les codes ; il ne sait PAS les envoyer. Il délègue à cette interface :
 *  - en production : MailerSmsOtpDelivery (e-mail via Symfony Mailer, SMS via Symfony Notifier),
 *  - dans les tests : une implémentation en mémoire qui garde les codes pour que le test les relise.
 * Séparer les deux rend la logique de sécurité testable sans serveur de mail ni opérateur SMS.
 */
interface OtpDeliveryInterface
{
    /**
     * @param string $target Adresse e-mail ou numéro E.164 de destination
     * @param string $code   Code en CLAIR (6 chiffres, ou long jeton pour une invitation) : il ne doit être ni journalisé ni stocké
     *
     * @throws OtpDeliveryFailedException si l'acheminement échoue
     */
    public function deliver(User $user, OtpPurpose $purpose, OtpChannel $channel, string $target, string $code, \DateTimeImmutable $expiresAt): void;
}
