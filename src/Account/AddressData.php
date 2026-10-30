<?php

namespace App\Account;

use App\Validator\MobilePhone;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Données d'une adresse de livraison saisies dans « Mon compte › Mes adresses », avec leurs règles de validation.
 * Les messages sont des CLÉS de traduction (domaine « validators »), traduites dans la langue de la personne.
 */
final class AddressData
{
    /** Nom donné à l'adresse pour s'y retrouver (« Maison », « Bureau »…). Facultatif. */
    #[Assert\Length(max: 40, maxMessage: 'field.too_long')]
    public string $label = '';

    #[Assert\NotBlank(message: 'field.required')]
    #[Assert\Length(max: 150, maxMessage: 'field.too_long')]
    public string $fullName = '';

    /** Téléphone du destinataire : facultatif ici (à défaut, celui du compte sera utilisé à la commande). */
    #[MobilePhone]
    public string $phone = '';

    #[Assert\NotBlank(message: 'field.required')]
    #[Assert\Length(max: 255, maxMessage: 'field.too_long')]
    public string $street = '';

    #[Assert\NotBlank(message: 'field.required')]
    #[Assert\Length(max: 100, maxMessage: 'field.too_long')]
    public string $city = '';

    #[Assert\Length(max: 10, maxMessage: 'field.too_long')]
    public string $postalCode = '';

    #[Assert\Length(max: 500, maxMessage: 'field.too_long')]
    public string $instructions = '';

    /** Faire de cette adresse l'adresse proposée en premier à la commande. */
    public bool $makeDefault = false;

    /**
     * @param array<string, mixed> $data Contenu JSON de la requête
     */
    public static function fromArray(array $data): self
    {
        $dto = new self();
        $dto->label = self::str($data, 'label');
        $dto->fullName = self::str($data, 'fullName');
        $dto->phone = self::str($data, 'phone');
        $dto->street = self::str($data, 'street');
        $dto->city = self::str($data, 'city');
        $dto->postalCode = self::str($data, 'postalCode');
        $dto->instructions = self::str($data, 'instructions');
        $dto->makeDefault = true === ($data['makeDefault'] ?? null); // seul un vrai « true » active l'option

        return $dto;
    }

    /** @param array<string, mixed> $data */
    private static function str(array $data, string $key): string
    {
        return \is_scalar($data[$key] ?? null) ? trim((string) $data[$key]) : '';
    }
}
