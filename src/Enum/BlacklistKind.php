<?php

namespace App\Enum;

/**
 * Nature d'une entrée de la liste noire (colonne `blacklist_entries.kind`).
 *
 * La valeur est toujours stockée sous forme NORMALISÉE (voir App\Moderation\BlacklistService::normalize) pour que
 * « Jean.Dupont+x@Gmail.com » et « jean.dupont@gmail.com » soient la même entrée.
 */
enum BlacklistKind: string
{
    /** Adresse e-mail (minuscules, sans « +étiquette »). */
    case Email = 'email';

    /** Numéro de téléphone au format international E.164. */
    case Phone = 'phone';

    /** Nom et prénom (sans accents, minuscules, espaces simplifiés). */
    case Name = 'name';

    /** Raison sociale d'un fournisseur (même normalisation que le nom). */
    case Company = 'company';

    /** Adresse IP (technique) : bloque l'inscription depuis cette adresse. */
    case Ip = 'ip';
}
