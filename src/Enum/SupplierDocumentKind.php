<?php

namespace App\Enum;

/**
 * Nature d'une pièce justificative déposée à l'inscription d'un fournisseur : une seule de chaque nature par compte.
 *
 *   - Identity : pièce d'identité valide du responsable (CIN, passeport ou permis) ;
 *   - Tax      : justificatif NIF / STAT de l'entreprise.
 */
enum SupplierDocumentKind: string
{
    case Identity = 'identity';
    case Tax = 'tax';

    /** Nom du champ du formulaire (multipart) qui porte ce fichier. */
    public function field(): string
    {
        return match ($this) {
            self::Identity => 'identityDocument',
            self::Tax => 'taxDocument',
        };
    }
}
