<?php

namespace App\Auth\Exception;

/**
 * L'inscription est refusée parce qu'une donnée saisie figure sur la liste noire (e-mail, téléphone, nom, raison sociale ou IP).
 *
 * Le message montré à la personne est TOUJOURS le même : on ne dit pas quelle donnée est en cause (cela renseignerait un
 * fraudeur sur la façon de contourner le blocage). Les natures en cause ne figurent que dans le journal d'audit.
 *
 * Jamais levée à l'intérieur d'une transaction Doctrine : RegistrationService la lève avant d'ouvrir la moindre écriture.
 */
final class RegistrationBlockedException extends \RuntimeException
{
    /** @param list<string> $kinds natures en cause (« email », « phone », « name », « company », « ip ») */
    public function __construct(public readonly array $kinds)
    {
        parent::__construct('Inscription refusée par la liste noire : '.implode(', ', $kinds).'.');
    }
}
