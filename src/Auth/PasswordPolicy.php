<?php

namespace App\Auth;

use App\Http\ValidationErrors;
use App\Validator\StrongPassword;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Règles de force d'un mot de passe, au même endroit pour toute l'application
 * (réinitialisation, activation d'un compte administrateur, changement depuis les réglages du compte…).
 *
 *   - client / fournisseur : 10 caractères minimum ;
 *   - administrateur       : 14 caractères minimum ET 3 familles de caractères (minuscules, majuscules, chiffres, symboles) ;
 *   - jamais le début de l'adresse e-mail, jamais un mot de passe très courant ;
 *   - jamais un mot de passe apparu dans une fuite publique (service « Have I Been Pwned », en k-anonymat :
 *     seuls 5 caractères de l'empreinte du mot de passe quittent le serveur ; en cas de panne du service on laisse passer).
 */
final class PasswordPolicy
{
    public function __construct(private readonly ValidatorInterface $validator)
    {
    }

    /**
     * Messages d'erreur (déjà traduits) ; liste vide = mot de passe accepté.
     *
     * @return list<string>
     */
    public function errors(string $password, string $email, bool $isAdmin): array
    {
        $constraints = [
            new Assert\NotBlank(message: 'field.required'),
            $isAdmin ? new StrongPassword(min: 14, complexity: true, email: $email) : new StrongPassword(min: 10, email: $email),
            new Assert\NotCompromisedPassword(message: 'password.compromised', skipOnError: true),
        ];

        $violations = $this->validator->validate($password, $constraints);

        return array_values(ValidationErrors::toArray($violations)['_'] ?? []);
    }
}
