<?php

namespace App\Auth\Exception;

/**
 * Le code a bien été généré mais n'a pas pu être ACHEMINÉ (serveur SMTP ou passerelle SMS en panne).
 * Le contrôleur répond « service momentanément indisponible » ; aucun détail technique n'est montré à l'utilisateur.
 */
final class OtpDeliveryFailedException extends \RuntimeException
{
}
