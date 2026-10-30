<?php

namespace App\Entity;

use App\Enum\SocialProvider;
use App\Repository\SocialIdentityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Lien entre un compte GoBuy et une identité Google / Apple — table `social_identities`.
 *
 * On identifie la personne par le « subject » (`sub`) fourni par le fournisseur — un identifiant
 * stable et propre à l'application — et NON par son e-mail (qui peut changer).
 * Un même compte peut avoir plusieurs identités (Google ET Apple).
 */
#[ORM\Entity(repositoryClass: SocialIdentityRepository::class)]
#[ORM\Table(name: 'social_identities', options: ['comment' => 'Identités Google / Apple rattachées à un compte (connexion rapide). Clé : (fournisseur, sub).'])]
// Une identité externe ne peut être rattachée qu'à UN seul compte.
#[ORM\UniqueConstraint(name: 'uniq_social_provider_subject', columns: ['provider', 'subject'])]
#[ORM\Index(name: 'idx_social_user', columns: ['user_id'])]
class SocialIdentity
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: SocialProvider::class)]
    private SocialProvider $provider;

    /** Identifiant stable de la personne chez le fournisseur (champ `sub` du jeton d'identité). */
    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $subject;

    /** E-mail communiqué par le fournisseur au moment du lien (information, non utilisée pour l'authentification). */
    #[ORM\Column(name: 'email_at_link', type: Types::STRING, length: 180, nullable: true)]
    private ?string $emailAtLink;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'last_login_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    public function __construct(User $user, SocialProvider $provider, string $subject, ?string $email, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->provider = $provider;
        $this->subject = $subject;
        $this->emailAtLink = $email;
        $this->createdAt = $now;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getProvider(): SocialProvider
    {
        return $this->provider;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function recordLogin(\DateTimeImmutable $now): void
    {
        $this->lastLoginAt = $now;
    }
}
