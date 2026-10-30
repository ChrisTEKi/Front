<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Contrainte « mot de passe solide », applicable à un champ : #[StrongPassword] ou #[StrongPassword(min: 14, complexity: true)].
 *
 * Règles (inspirées des recommandations NIST/ANSSI : la LONGUEUR compte plus que les symboles imposés) :
 *   - longueur minimale (10 par défaut pour un client, 14 pour un administrateur) ;
 *   - option « complexity » (administrateurs) : au moins 3 familles parmi minuscules / majuscules / chiffres / symboles ;
 *   - ne doit pas contenir la partie locale de l'e-mail (« jean » dans jean@mail.com) ni être un mot de passe notoire ;
 *   - la vérification « déjà fuité sur Internet » est faite par la contrainte standard NotCompromisedPassword (voir DTO).
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class StrongPassword extends Constraint
{
    public string $tooShortMessage = 'password.too_short';
    public string $tooLongMessage = 'password.too_long';
    public string $weakMessage = 'password.not_complex';
    public string $containsEmailMessage = 'password.contains_email';
    public string $commonMessage = 'password.too_common';

    public function __construct(
        public int $min = 10,
        public int $max = 128,
        public bool $complexity = false,
        /** E-mail à ne pas retrouver dans le mot de passe (si null : lu sur l'objet validé, propriété « email »). */
        public ?string $email = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(groups: $groups, payload: $payload);
    }
}
