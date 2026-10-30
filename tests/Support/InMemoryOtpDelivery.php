<?php

namespace App\Tests\Support;

use App\Entity\User;
use App\Enum\OtpChannel;
use App\Enum\OtpPurpose;
use App\Notification\OtpDeliveryInterface;

/**
 * « Faux » acheminement des codes pour les tests : au lieu d'envoyer un e-mail/SMS, il garde les codes en mémoire
 * pour que le test les relise comme le ferait l'utilisateur dans sa boîte de réception.
 */
final class InMemoryOtpDelivery implements OtpDeliveryInterface
{
    /** @var list<array{email: string, purpose: OtpPurpose, channel: OtpChannel, target: string, code: string}> */
    public array $sent = [];

    /** Passer à true pour simuler une panne du fournisseur d'e-mail/SMS. */
    public bool $fail = false;

    public function deliver(User $user, OtpPurpose $purpose, OtpChannel $channel, string $target, string $code, \DateTimeImmutable $expiresAt): void
    {
        if ($this->fail) {
            throw new \App\Auth\Exception\OtpDeliveryFailedException('Panne simulée.');
        }
        $this->sent[] = ['email' => $user->getEmail(), 'purpose' => $purpose, 'channel' => $channel, 'target' => $target, 'code' => $code];
    }

    /** Dernier code envoyé pour une finalité (et, si précisé, un canal) ; échoue si aucun. */
    public function lastCode(OtpPurpose $purpose, ?OtpChannel $channel = null): string
    {
        foreach (array_reverse($this->sent) as $item) {
            if ($item['purpose'] === $purpose && (null === $channel || $item['channel'] === $channel)) {
                return $item['code'];
            }
        }

        throw new \LogicException(sprintf('Aucun code « %s » envoyé.', $purpose->value));
    }

    public function count(?OtpPurpose $purpose = null): int
    {
        return null === $purpose ? \count($this->sent) : \count(array_filter($this->sent, static fn (array $i): bool => $i['purpose'] === $purpose));
    }
}
