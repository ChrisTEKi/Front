<?php

namespace App\Notification;

/**
 * Un e-mail transactionnel n'a pas pu partir (SMTP indisponible, adresse refusée…).
 * Enveloppe l'exception technique d'origine (getPrevious()) pour les journaux.
 */
final class MailDeliveryException extends \RuntimeException
{
}
