<?php

namespace App\Auth\Dto;

use App\Auth\SupplierApplication;
use App\Validator\MobilePhone;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Inscription FOURNISSEUR : mêmes champs qu'un client + informations sur l'entreprise,
 * et le téléphone devient OBLIGATOIRE (il sera vérifié par SMS avant l'accès).
 */
final class RegisterSupplierData extends RegisterClientData
{
    #[Assert\NotBlank(message: 'field.required')]
    #[MobilePhone]
    public string $phone = '';

    #[Assert\NotBlank(message: 'field.required')]
    #[Assert\Length(min: 2, max: 150, minMessage: 'field.too_short', maxMessage: 'field.too_long')]
    public string $companyName = '';

    #[Assert\NotBlank(message: 'field.required')]
    #[Assert\Length(min: 5, max: 255, minMessage: 'field.too_short', maxMessage: 'field.too_long')]
    public string $companyAddress = '';

    #[Assert\Length(max: 1000, maxMessage: 'field.too_long')]
    public string $companyDescription = '';

    /**
     * Réponses détaillées de l'assistant « Devenir partenaire » (déjà nettoyées : voir SupplierApplication::sanitize).
     * Facultatif : un dossier envoyé par l'ancien formulaire (sans ces réponses) reste valide.
     *
     * @var array<string, mixed>|null
     */
    public ?array $application = null;

    public static function fromArray(array $data): static
    {
        $dto = parent::fromArray($data);
        $dto->companyName = self::str($data, 'companyName');
        $dto->companyAddress = self::str($data, 'companyAddress');
        $dto->companyDescription = self::str($data, 'companyDescription');
        $dto->application = SupplierApplication::sanitize($data['application'] ?? null);

        return $dto;
    }
}
