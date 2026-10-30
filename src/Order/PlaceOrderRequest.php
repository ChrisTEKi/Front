<?php

namespace App\Order;

use App\Enum\PaymentMethod;
use App\Validator\MobilePhone;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Données envoyées par la page de commande, avec leurs règles de validation.
 *
 * Aucun prix ni aucun total n'est lu ici : seuls les identifiants de produits et les quantités viennent du navigateur.
 * Les prix sont relus côté serveur (CatalogInterface).
 */
final class PlaceOrderRequest
{
    /** Formules de paiement de la maquette : « installments » (3x/4x), « mobile_money », « card », « cash_on_delivery ». */
    public const METHODS = ['installments', 'mobile_money', 'card', 'cash_on_delivery'];

    /** @var list<array{ref: string, quantity: int}> */
    public array $items = [];

    #[Assert\NotBlank(message: 'field.required')]
    #[Assert\Length(max: 150, maxMessage: 'field.too_long')]
    public string $fullName = '';

    /** Téléphone du destinataire (facultatif : à défaut, celui du compte). */
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
    public string $courierNote = '';

    /**
     * Enregistrer cette adresse dans le carnet d'adresses du client (case cochée par défaut sur la page de commande).
     * Absent de la requête = oui : les anciens appels continuent de mémoriser l'adresse comme avant.
     */
    public bool $saveAddress = true;

    /** En faire l'adresse par défaut (proposée en premier aux prochaines commandes). */
    public bool $makeDefaultAddress = false;

    #[Assert\Choice(choices: self::METHODS, message: 'field.required')]
    public string $method = '';

    /** 3 ou 4 pour « installments » ; ignoré sinon (comptant = 1). */
    public int $installments = 1;

    /** Pour « installments » : « mobile_money » ou « card ». */
    public string $channel = 'mobile_money';

    /** Numéro Mobile Money saisi (format libre) quand le paiement passe par Mobile Money. */
    public string $mobileMoneyNumber = '';

    /** Jeton anti-doublon généré par le navigateur pour cette tentative. */
    #[Assert\Length(max: 64, maxMessage: 'field.too_long')]
    #[Assert\Regex(pattern: '/^[A-Za-z0-9_-]*$/', message: 'field.required')]
    public string $idempotencyKey = '';

    /**
     * @param array<string, mixed> $data Contenu JSON de la requête :
     *                                   {items:[{ref,quantity}], address:{fullName,phone,street,city,postalCode,courierNote,save,makeDefault},
     *                                    payment:{method,installments,channel,mobileMoneyNumber}, idempotencyKey}
     */
    public static function fromArray(array $data): self
    {
        $dto = new self();

        $items = [];
        if (\is_array($data['items'] ?? null)) {
            foreach ($data['items'] as $line) {
                if (!\is_array($line)) {
                    continue;
                }
                $quantity = $line['quantity'] ?? 0;
                $items[] = [
                    'ref' => self::str($line, 'ref'),
                    // Seuls les entiers sont acceptés ; « 2.5 » ou « abc » donnent 0, refusé ensuite.
                    'quantity' => \is_int($quantity) ? $quantity : (\is_string($quantity) && ctype_digit($quantity) ? (int) $quantity : 0),
                ];
            }
        }
        $dto->items = $items;

        $address = \is_array($data['address'] ?? null) ? $data['address'] : [];
        $dto->fullName = self::str($address, 'fullName');
        $dto->phone = self::str($address, 'phone');
        $dto->street = self::str($address, 'street');
        $dto->city = self::str($address, 'city');
        $dto->postalCode = self::str($address, 'postalCode');
        $dto->courierNote = self::str($address, 'courierNote');
        $dto->saveAddress = false !== ($address['save'] ?? true);          // seul un « false » explicite désactive l'enregistrement
        $dto->makeDefaultAddress = true === ($address['makeDefault'] ?? false); // seul un « true » explicite active l'option

        $payment = \is_array($data['payment'] ?? null) ? $data['payment'] : [];
        $dto->method = self::str($payment, 'method');
        $installments = $payment['installments'] ?? 1;
        $dto->installments = \is_int($installments) ? $installments : (\is_string($installments) && ctype_digit($installments) ? (int) $installments : 0);
        $dto->channel = '' !== self::str($payment, 'channel') ? self::str($payment, 'channel') : 'mobile_money';
        $dto->mobileMoneyNumber = self::str($payment, 'mobileMoneyNumber');

        $dto->idempotencyKey = self::str($data, 'idempotencyKey');

        return $dto;
    }

    /** Moyen de paiement enregistré en base, déduit de la formule choisie. */
    public function paymentMethod(): PaymentMethod
    {
        return match ($this->method) {
            'card' => PaymentMethod::Card,
            'cash_on_delivery' => PaymentMethod::CashOnDelivery,
            'installments' => 'card' === $this->channel ? PaymentMethod::Card : PaymentMethod::MobileMoney,
            default => PaymentMethod::MobileMoney,
        };
    }

    /** Nombre d'échéances : 3 ou 4 pour « installments », sinon 1 (comptant). */
    public function installmentsCount(): int
    {
        return 'installments' === $this->method ? $this->installments : 1;
    }

    /** Ce paiement passe-t-il par un numéro Mobile Money (à vérifier par SMS avant) ? */
    public function usesMobileMoney(): bool
    {
        return PaymentMethod::MobileMoney === $this->paymentMethod();
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function str(array $data, string $key): string
    {
        return \is_scalar($data[$key] ?? null) ? trim((string) $data[$key]) : '';
    }
}
