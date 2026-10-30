<?php

namespace App\Auth\Dto;

use App\Validator\MobilePhone;
use App\Validator\StrongPassword;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Données du formulaire d'inscription CLIENT, avec leurs règles de validation.
 * Les messages sont des CLÉS de traduction (domaine « validators »), traduites dans la langue de l'utilisateur.
 */
class RegisterClientData
{
    #[Assert\NotBlank(message: 'field.required')]
    #[Assert\Length(min: 2, max: 100, minMessage: 'name.too_short', maxMessage: 'field.too_long')]
    public string $fullName = '';

    #[Assert\NotBlank(message: 'field.required')]
    #[Assert\Length(max: 180, maxMessage: 'field.too_long')]
    #[Assert\Email(message: 'email.invalid')]
    public string $email = '';

    #[Assert\NotBlank(message: 'field.required')]
    #[StrongPassword(min: 10)]
    // Refuse les mots de passe déjà apparus dans des fuites publiques (service « Have I Been Pwned », en k-anonymat :
    // seuls 5 caractères de l'empreinte du mot de passe quittent le serveur). En cas de panne du service : on laisse passer.
    #[Assert\NotCompromisedPassword(message: 'password.compromised', skipOnError: true)]
    public string $password = '';

    /** Facultatif pour un client. S'il est renseigné, il doit être un mobile valide (et sera proposé à la vérification par SMS). */
    #[MobilePhone]
    public string $phone = '';

    /**
     * Adresse de livraison, FACULTATIVE à l'inscription : si la rue est renseignée, la ville l'est aussi. Elle devient l'adresse
     * par défaut proposée à la première commande (le client pourra en ajouter d'autres et changer à la commande).
     */
    #[Assert\Length(max: 255, maxMessage: 'field.too_long')]
    public string $street = '';

    #[Assert\Length(max: 100, maxMessage: 'field.too_long')]
    public string $city = '';

    #[Assert\Length(max: 10, maxMessage: 'field.too_long')]
    public string $postalCode = '';

    /** Une adresse a été saisie (au moins la rue) : elle sera enregistrée avec le compte. */
    public function hasAddress(): bool
    {
        return '' !== $this->street;
    }

    #[Assert\Callback]
    public function validateAddress(ExecutionContextInterface $context): void
    {
        // Rue renseignée sans ville : on demande la ville. (Ville seule, sans rue : pas d'adresse, rien à contrôler.)
        if ('' !== $this->street && '' === $this->city) {
            $context->buildViolation('field.required')->atPath('city')->addViolation();
        }
    }

    /**
     * @param array<string, mixed> $data Contenu JSON de la requête
     */
    public static function fromArray(array $data): static
    {
        $dto = new static();
        $dto->fullName = self::str($data, 'fullName');
        $dto->email = mb_strtolower(self::str($data, 'email'));
        $dto->password = (string) ($data['password'] ?? ''); // jamais « trim » : les espaces font partie d'un mot de passe
        $dto->phone = self::str($data, 'phone');
        $dto->street = self::str($data, 'street');
        $dto->city = self::str($data, 'city');
        $dto->postalCode = self::str($data, 'postalCode');

        return $dto;
    }

    /** Lit une valeur texte « nettoyée » (espaces retirés aux extrémités). Un tableau ou un objet donne une chaîne vide. */
    protected static function str(array $data, string $key): string
    {
        return \is_scalar($data[$key] ?? null) ? trim((string) $data[$key]) : '';
    }
}
