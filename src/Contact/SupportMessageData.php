<?php

namespace App\Contact;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Message envoyé au support depuis le site, avec ses règles de validation.
 *
 * Quatre types de messages (champ « kind ») :
 *   - feedback : avis rapide du tiroir « Feedback » (seul le message est obligatoire, l'humeur est facultative) ;
 *   - contact  : « Envoyez-nous un message rapide » (nom + moyen de contact + demande) ;
 *   - claim    : formulaire de réclamation (en plus : numéro de commande + motif) ;
 *   - job      : candidature spontanée (nom + moyen de contact + présentation).
 *
 * Les messages d'erreur sont des CLÉS de traduction (domaine « validators »), traduites dans la langue de la requête.
 * Rien n'est enregistré en base : le message part par e-mail vers l'équipe (voir SupportMessageMailer).
 */
final class SupportMessageData
{
    public const KINDS = ['feedback', 'contact', 'claim', 'job'];
    public const MOODS = ['happy', 'neutral', 'sad'];
    public const REASONS = ['damaged', 'delay', 'missing', 'refund', 'other'];

    #[Assert\Choice(choices: self::KINDS, message: 'field.invalid')]
    public string $kind = '';

    #[Assert\Length(max: 100, maxMessage: 'field.too_long')]
    public string $name = '';

    /** Adresse e-mail OU numéro de téléphone où l'équipe peut répondre. */
    #[Assert\Length(max: 180, maxMessage: 'field.too_long')]
    public string $contact = '';

    /** Numéro de commande concerné (réclamation). */
    #[Assert\Length(max: 40, maxMessage: 'field.too_long')]
    public string $order = '';

    #[Assert\Choice(choices: self::REASONS, message: 'field.invalid')]
    public ?string $reason = null;

    #[Assert\Choice(choices: self::MOODS, message: 'field.invalid')]
    public ?string $mood = null;

    #[Assert\NotBlank(message: 'field.required')]
    #[Assert\Length(min: 5, max: 2000, minMessage: 'field.too_short', maxMessage: 'field.too_long')]
    public string $message = '';

    /**
     * @param array<string, mixed> $data Contenu JSON de la requête
     */
    public static function fromArray(array $data): self
    {
        $dto = new self();
        $dto->kind = self::str($data, 'kind');
        $dto->name = self::str($data, 'name');
        $dto->contact = self::str($data, 'contact');
        $dto->order = self::str($data, 'order');
        $dto->reason = '' === ($reason = self::str($data, 'reason')) ? null : $reason;
        $dto->mood = '' === ($mood = self::str($data, 'mood')) ? null : $mood;
        $dto->message = self::str($data, 'message');

        return $dto;
    }

    /** Règles qui dépendent du type de message (le nom et le contact ne sont pas demandés pour un simple avis). */
    #[Assert\Callback]
    public function validateByKind(ExecutionContextInterface $context): void
    {
        if ('feedback' === $this->kind || !\in_array($this->kind, self::KINDS, true)) {
            return; // avis : anonyme possible ; type inconnu : déjà signalé par la contrainte Choice
        }

        if (mb_strlen($this->name) < 2) {
            $context->buildViolation('name.too_short')->atPath('name')->addViolation();
        }

        if ('' === $this->contact) {
            $context->buildViolation('field.required')->atPath('contact')->addViolation();
        } elseif (!self::looksLikeEmailOrPhone($this->contact)) {
            $context->buildViolation('contact.invalid')->atPath('contact')->addViolation();
        }

        if ('claim' === $this->kind) {
            if ('' === $this->order) {
                $context->buildViolation('field.required')->atPath('order')->addViolation();
            }
            if (null === $this->reason) {
                $context->buildViolation('field.required')->atPath('reason')->addViolation();
            }
        }
    }

    /** true si le contact est une adresse e-mail valide, ou un numéro de téléphone plausible (6 à 15 chiffres). */
    public function contactIsEmail(): bool
    {
        return str_contains($this->contact, '@') && false !== filter_var($this->contact, \FILTER_VALIDATE_EMAIL);
    }

    private static function looksLikeEmailOrPhone(string $value): bool
    {
        if (str_contains($value, '@')) {
            return false !== filter_var($value, \FILTER_VALIDATE_EMAIL);
        }
        // Un téléphone : chiffres, espaces, « + », tirets, points, parenthèses ; entre 6 et 15 chiffres au total.
        if (1 !== preg_match('/^[+0-9 ().-]+$/', $value)) {
            return false;
        }
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return \strlen($digits) >= 6 && \strlen($digits) <= 15;
    }

    private static function str(array $data, string $key): string
    {
        return \is_scalar($data[$key] ?? null) ? trim((string) $data[$key]) : '';
    }
}
