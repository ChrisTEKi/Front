<?php

namespace App\Account;

use App\Entity\Address;
use App\Phone\PhoneNumber;

/**
 * Transforme une adresse en tableau simple pour l'interface (réponses JSON de l'API et données de la page de commande).
 */
final class AddressPresenter
{
    /**
     * @return array{id: string, label: ?string, fullName: string, phone: ?string, phoneDisplay: ?string, street: string, city: string, postalCode: ?string, instructions: ?string, isDefault: bool}
     */
    public function toArray(Address $address): array
    {
        $phone = $address->getPhone();

        return [
            'id' => $address->getId()->toRfc4122(),
            'label' => $address->getLabel(),
            'fullName' => $address->getFullName(),
            'phone' => $phone,
            'phoneDisplay' => null !== $phone ? PhoneNumber::fromE164($phone)->display() : null,
            'street' => $address->getStreet(),
            'city' => $address->getCity(),
            'postalCode' => $address->getPostalCode(),
            'instructions' => $address->getInstructions(),
            'isDefault' => $address->isDefault(),
        ];
    }

    /**
     * @param iterable<Address> $addresses
     *
     * @return list<array<string, mixed>>
     */
    public function list(iterable $addresses): array
    {
        $out = [];
        foreach ($addresses as $address) {
            $out[] = $this->toArray($address);
        }

        return $out;
    }
}
