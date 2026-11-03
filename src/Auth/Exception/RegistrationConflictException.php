<?php

namespace App\Auth\Exception;

/**
 * L'inscription est refusée parce que l'adresse e-mail et/ou le numéro de téléphone saisis appartiennent déjà à un autre compte.
 *
 * Cette exception n'est JAMAIS levée à l'intérieur d'une transaction Doctrine (elle fermerait l'EntityManager) :
 * RegistrationService la lève après coup, une fois la transaction terminée.
 */
final class RegistrationConflictException extends \RuntimeException
{
    /**
     * @param list<string> $fields Champs en conflit : « email » et/ou « phone » (noms des champs du formulaire)
     */
    public function __construct(public readonly array $fields)
    {
        parent::__construct('Inscription refusée : '.implode(' et ', $fields).' déjà utilisé(s) par un autre compte.');
    }
}
