<?php

namespace App\Http;

use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Convertit la liste d'erreurs de validation Symfony en structure JSON simple pour l'interface :
 *   { "email": ["Adresse e-mail invalide"], "password": ["Au moins 10 caractères", …] }
 * Les messages sont déjà TRADUITS (dans la langue de la requête) par le validateur.
 */
final class ValidationErrors
{
    private function __construct()
    {
    }

    /**
     * @return array<string, list<string>>
     */
    public static function toArray(ConstraintViolationListInterface $violations): array
    {
        $errors = [];
        foreach ($violations as $violation) {
            // « propertyPath » = nom du champ (ex. « email »).
            $errors[$violation->getPropertyPath() ?: '_'][] = (string) $violation->getMessage();
        }

        return $errors;
    }
}
