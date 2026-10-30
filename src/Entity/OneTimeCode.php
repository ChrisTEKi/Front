<?php

namespace App\Entity;

use App\Enum\OtpChannel;
use App\Enum\OtpPurpose;
use App\Repository\OneTimeCodeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Code à usage unique (OTP) — table `one_time_codes`.
 *
 * Sert à TOUT ce qui demande une preuve : vérifier un e-mail, un téléphone, une 2FA de connexion,
 * un mot de passe oublié, un numéro Mobile Money, une invitation d'administrateur…
 *
 * SÉCURITÉ :
 *  - Le code en clair n'est JAMAIS stocké : seulement son empreinte HMAC-SHA256 (`code_hash`).
 *  - Il expire (`expires_at`), ne sert qu'une fois (`consumed_at`) et est limité en essais (`attempts`).
 *  - Il est lié à une finalité (`purpose`) ET à une destination (`target`) : un code envoyé à
 *    l'ancien numéro ne valide pas un nouveau numéro.
 */
#[ORM\Entity(repositoryClass: OneTimeCodeRepository::class)]
#[ORM\Table(name: 'one_time_codes', options: ['comment' => 'Codes à usage unique (OTP) : vérification e-mail/SMS, 2FA, mot de passe oublié, numéro Mobile Money, invitation admin. Stockés HACHÉS.'])]
// Retrouver rapidement le code actif d'un utilisateur pour une finalité donnée.
#[ORM\Index(name: 'idx_otp_user_purpose', columns: ['user_id', 'purpose'])]
// Faciliter le nettoyage des codes périmés.
#[ORM\Index(name: 'idx_otp_expires_at', columns: ['expires_at'])]
class OneTimeCode
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** Propriétaire du code. Si le compte est supprimé, ses codes le sont aussi (ON DELETE CASCADE). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** À quoi sert ce code (voir OtpPurpose). */
    #[ORM\Column(type: Types::STRING, length: 32, enumType: OtpPurpose::class)]
    private OtpPurpose $purpose;

    /** Par où il a été envoyé : e-mail ou SMS. */
    #[ORM\Column(type: Types::STRING, length: 8, enumType: OtpChannel::class)]
    private OtpChannel $channel;

    /** Destination exacte : l'adresse e-mail ou le numéro E.164 auquel le code a été envoyé. */
    #[ORM\Column(type: Types::STRING, length: 255, options: ['comment' => 'Adresse e-mail ou numéro E.164 auquel le code a été envoyé'])]
    private string $target;

    /** Empreinte HMAC-SHA256 du code (64 caractères hexadécimaux). */
    #[ORM\Column(name: 'code_hash', type: Types::STRING, length: 64, options: ['comment' => 'Empreinte HMAC-SHA256 du code (le code en clair n\'est jamais stocké)'])]
    private string $codeHash;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** Après cette date, le code est refusé. */
    #[ORM\Column(name: 'expires_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    /** Renseigné dès que le code a été utilisé avec succès (ou remplacé par un nouveau) : il ne sert plus. */
    #[ORM\Column(name: 'consumed_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true, options: ['comment' => 'Date d\'utilisation (ou d\'invalidation) : un code ne sert qu\'une fois'])]
    private ?\DateTimeImmutable $consumedAt = null;

    /** Nombre de saisies incorrectes déjà faites sur ce code. */
    #[ORM\Column(type: Types::SMALLINT, options: ['comment' => 'Saisies incorrectes déjà faites', 'default' => 0])]
    private int $attempts = 0;

    /** Plafond d'essais : au-delà, le code est définitivement bloqué (anti force-brute des 10^6 combinaisons). */
    #[ORM\Column(name: 'max_attempts', type: Types::SMALLINT)]
    private int $maxAttempts;

    public function __construct(
        User $user,
        OtpPurpose $purpose,
        OtpChannel $channel,
        string $target,
        string $codeHash,
        \DateTimeImmutable $now,
        int $ttlSeconds,
        int $maxAttempts,
    ) {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->purpose = $purpose;
        $this->channel = $channel;
        $this->target = $target;
        $this->codeHash = $codeHash;
        $this->createdAt = $now;
        $this->expiresAt = $now->modify('+'.$ttlSeconds.' seconds');
        $this->maxAttempts = $maxAttempts;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getPurpose(): OtpPurpose
    {
        return $this->purpose;
    }

    public function getChannel(): OtpChannel
    {
        return $this->channel;
    }

    public function getTarget(): string
    {
        return $this->target;
    }

    public function getCodeHash(): string
    {
        return $this->codeHash;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function isConsumed(): bool
    {
        return null !== $this->consumedAt;
    }

    /** Trop d'essais ratés : le code est mort même s'il n'a pas expiré. */
    public function isLocked(): bool
    {
        return $this->attempts >= $this->maxAttempts;
    }

    /** Ce code peut-il encore être testé ? (ni expiré, ni utilisé, ni bloqué) */
    public function isUsable(\DateTimeImmutable $now): bool
    {
        return !$this->isConsumed() && !$this->isExpired($now) && !$this->isLocked();
    }

    /** Enregistre une saisie incorrecte. */
    public function registerFailedAttempt(): void
    {
        ++$this->attempts;
    }

    /** Marque le code comme utilisé : il ne pourra plus jamais resservir. */
    public function consume(\DateTimeImmutable $now): void
    {
        $this->consumedAt = $now;
    }
}
