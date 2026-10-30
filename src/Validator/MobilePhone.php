<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Le champ doit être un numéro de téléphone MOBILE valide (malgache 03x xx xxx xx, ou international +…).
 * Un champ vide est accepté (c'est NotBlank qui rend le numéro obligatoire, ex. pour un fournisseur).
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class MobilePhone extends Constraint
{
    public string $message = 'phone.invalid';
}
