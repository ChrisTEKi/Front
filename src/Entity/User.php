<?php

namespace App\Entity;

use App\Enum\AccountStatus;
use App\Enum\AccountType;
use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Compte utilisateur : clients, fournisseurs ET administrateurs partagent cette table.
 *
 * Table PostgreSQL : `users`.
 *  - Les données propres à un type de compte vivent dans des tables liées 1-1 :
 *      supplier_profiles (fournisseurs), admin_profiles (admins).
 *  - Les secrets (mot de passe) sont stockés HACHÉS (Argon2id), jamais en clair.
 *
 * L'entité implémente les interfaces de sécurité de Symfony :
 *  - UserInterface                    : identité + rôles (obligatoire pour être "connectable"),
 *  - PasswordAuthenticatedUserInterface : expose le mot de passe haché pour la vérification,
 *  - EquatableInterface               : permet de déconnecter automatiquement un utilisateur dont
 *                                       le compte a changé (mot de passe modifié, compte suspendu…).
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users', options: ['comment' => 'Comptes utilisateurs : clients, fournisseurs et administrateurs (un seul compte par e-mail).'])]
// Un e-mail = un compte (l'adresse est toujours enregistrée en minuscules, voir setEmail()).
#[ORM\UniqueConstraint(name: 'uniq_users_email', columns: ['email'])]
// Un numéro de téléphone VÉRIFIÉ ne peut appartenir qu'à un seul compte (index unique partiel PostgreSQL).
#[ORM\UniqueConstraint(name: 'uniq_users_verified_phone', columns: ['phone'], options: ['where' => '(phone_verified_at IS NOT NULL)'])]
// Index pour filtrer vite par type de compte (exports, listes admin).
#[ORM\Index(name: 'idx_users_account_type', columns: ['account_type'])]
// Index pour que la purge quotidienne retrouve vite les comptes à supprimer.
#[ORM\Index(name: 'idx_users_deletion_scheduled_at', columns: ['deletion_scheduled_at'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    /** Durée de la période de grâce avant suppression définitive d'un compte. */
    public const DELETION_GRACE_DAYS = 30;

    /**
     * Identifiant unique : UUID v7 (triable par date de création, impossible à deviner
     * contrairement à un entier auto-incrémenté 1, 2, 3…). Stocké en type natif `uuid` de PostgreSQL.
     */
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** Adresse e-mail = identifiant de connexion. Toujours en minuscules. */
    #[ORM\Column(type: Types::STRING, length: 180, options: ['comment' => 'Adresse e-mail en minuscules = identifiant de connexion'])]
    private string $email;

    /**
     * Mot de passe HACHÉ (Argon2id). NULL pour un compte créé uniquement via Google/Apple
     * (il peut en définir un plus tard via « mot de passe oublié »).
     */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true, options: ['comment' => 'Mot de passe haché Argon2id (jamais en clair). NULL = compte créé via Google/Apple'])]
    private ?string $password = null;

    /** Nom complet (client / gérant du fournisseur / admin). */
    #[ORM\Column(name: 'full_name', type: Types::STRING, length: 150)]
    private string $fullName;

    /**
     * Téléphone mobile au format international E.164 (ex. +261341234567), ou NULL.
     * Facultatif à l'inscription client, mais obligatoire ET vérifié avant la 1re commande.
     */
    #[ORM\Column(type: Types::STRING, length: 20, nullable: true, options: ['comment' => 'Mobile au format E.164 (+261…). Obligatoire et vérifié avant la 1re commande'])]
    private ?string $phone = null;

    /** Date de vérification de l'e-mail (code OTP validé). NULL = pas encore vérifié. */
    #[ORM\Column(name: 'email_verified_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true, options: ['comment' => 'Date de validation du code OTP e-mail (NULL = non vérifié)'])]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    /** Date de vérification du téléphone (code SMS validé). NULL = pas encore vérifié. */
    #[ORM\Column(name: 'phone_verified_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true, options: ['comment' => 'Date de validation du code OTP SMS (NULL = non vérifié)'])]
    private ?\DateTimeImmutable $phoneVerifiedAt = null;

    /** Client, fournisseur ou admin (voir AccountType). */
    #[ORM\Column(name: 'account_type', type: Types::STRING, length: 16, enumType: AccountType::class, options: ['comment' => 'client | supplier | admin'])]
    private AccountType $accountType;

    /**
     * Rôles de sécurité Symfony, stockés en JSONB (ex. ["ROLE_CLIENT"]).
     * Écrits uniquement par les fabriques ci-dessous et par AdminAccountService.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON, options: ['comment' => 'Rôles Symfony Security (ROLE_CLIENT, ROLE_SUPPLIER, ROLE_ADMIN, ROLE_SUPER_ADMIN)', 'jsonb' => true])]
    private array $roles = [];

    /** Actif / suspendu / en cours de suppression. */
    #[ORM\Column(type: Types::STRING, length: 20, enumType: AccountStatus::class, options: ['comment' => 'active | suspended | pending_deletion'])]
    private AccountStatus $status = AccountStatus::Active;

    /** Langue préférée (fr, en, es) : utilisée pour l'interface ET pour les e-mails. */
    #[ORM\Column(type: Types::STRING, length: 5, options: ['default' => 'fr'])]
    private string $locale = 'fr';

    /** 2FA activée par e-mail : un code est envoyé par e-mail à chaque connexion. */
    #[ORM\Column(name: 'two_factor_email_enabled', type: Types::BOOLEAN, options: ['comment' => '2FA par code e-mail à chaque connexion', 'default' => false])]
    private bool $twoFactorEmailEnabled = false;

    /** 2FA activée par SMS : un code est envoyé par SMS à chaque connexion (téléphone vérifié requis). */
    #[ORM\Column(name: 'two_factor_sms_enabled', type: Types::BOOLEAN, options: ['comment' => '2FA par code SMS à chaque connexion (téléphone vérifié requis)', 'default' => false])]
    private bool $twoFactorSmsEnabled = false;

    /** Date de création du compte. */
    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** Dernière modification du compte. */
    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** Dernière connexion réussie. */
    #[ORM\Column(name: 'last_login_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    /** Date du dernier changement de mot de passe. */
    #[ORM\Column(name: 'password_changed_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $passwordChangedAt = null;

    /** Date à laquelle le client a demandé la suppression de son compte (NULL = pas de demande). */
    #[ORM\Column(name: 'deletion_requested_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletionRequestedAt = null;

    /**
     * Date de la dernière modification de la photo de profil (NULL = pas de photo). L'image elle-même est dans la table
     * `user_avatars` : cette colonne évite une requête supplémentaire à chaque page pour savoir s'il y a une photo.
     */
    #[ORM\Column(name: 'avatar_updated_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true, options: ['comment' => 'Date de la dernière photo de profil (NULL = aucune). L\'image est dans user_avatars'])]
    private ?\DateTimeImmutable $avatarUpdatedAt = null;

    /** Date de suppression définitive programmée (demande + 30 jours). La purge l'efface ensuite. */
    #[ORM\Column(name: 'deletion_scheduled_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true, options: ['comment' => 'Suppression définitive programmée (demande + 30 jours), exécutée par app:accounts:purge'])]
    private ?\DateTimeImmutable $deletionScheduledAt = null;

    /**
     * Constructeur privé : on crée toujours un utilisateur via une fabrique nommée
     * (registerClient, registerSupplier, createAdmin) pour garantir un état cohérent.
     */
    private function __construct(string $email, string $fullName, AccountType $type, string $locale, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();                 // identifiant généré côté PHP (pas besoin d'un aller-retour en base)
        $this->setEmail($email);
        $this->fullName = trim($fullName);
        $this->accountType = $type;
        $this->locale = $locale;
        $this->createdAt = $now;
        $this->updatedAt = $now;
        // Le rôle de base découle du type de compte.
        $this->roles = match ($type) {
            AccountType::Client => ['ROLE_CLIENT'],
            AccountType::Supplier => ['ROLE_SUPPLIER'],
            AccountType::Admin => ['ROLE_ADMIN'],
        };
    }

    // ------------------------------------------------------------------ Fabriques

    /** Crée un compte CLIENT (e-mail à vérifier, téléphone facultatif). */
    public static function registerClient(string $email, string $fullName, ?string $phoneE164, string $locale, \DateTimeImmutable $now): self
    {
        $user = new self($email, $fullName, AccountType::Client, $locale, $now);
        $user->phone = $phoneE164;

        return $user;
    }

    /** Crée un compte FOURNISSEUR (e-mail ET téléphone obligatoires, approbation admin requise). */
    public static function registerSupplier(string $email, string $fullName, string $phoneE164, string $locale, \DateTimeImmutable $now): self
    {
        $user = new self($email, $fullName, AccountType::Supplier, $locale, $now);
        $user->phone = $phoneE164;

        return $user;
    }

    /**
     * Crée un compte ADMINISTRATEUR. Appelé uniquement par AdminAccountService (Super Admin).
     * Le mot de passe est défini plus tard par l'admin lui-même via le lien d'invitation.
     */
    public static function createAdmin(string $email, string $fullName, string $locale, bool $isSuperAdmin, \DateTimeImmutable $now): self
    {
        $user = new self($email, $fullName, AccountType::Admin, $locale, $now);
        if ($isSuperAdmin) {
            $user->roles = ['ROLE_SUPER_ADMIN'];
        }

        return $user;
    }

    // ------------------------------------------------------------------ Interfaces de sécurité Symfony

    /** Identifiant utilisé par Symfony Security pour retrouver l'utilisateur : son e-mail. */
    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * Rôles Symfony de l'utilisateur. ROLE_USER est toujours ajouté : il signifie « connecté ».
     * La hiérarchie (security.yaml) fait que ROLE_SUPER_ADMIN inclut ROLE_ADMIN.
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    /** Mot de passe haché (jamais en clair). Peut être NULL pour un compte « social ». */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    /**
     * Requis par UserInterface en Symfony 7.x ; supprimé de l'interface en Symfony 8.
     * Nous ne gardons aucun mot de passe en clair dans l'objet : il n'y a donc rien à effacer.
     */
    #[\Deprecated('Inutile : aucun secret en clair n\'est conservé sur l\'objet.')]
    public function eraseCredentials(): void
    {
    }

    /**
     * Symfony stocke l'utilisateur en session. On ne sérialise que le STRICT nécessaire :
     * l'objet complet est rechargé depuis la base à chaque requête (refreshUser).
     * Le hash du mot de passe n'est pas stocké tel quel mais sous forme d'empreinte courte (crc32c) :
     * si le mot de passe change, l'empreinte diffère et la session est invalidée.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'roles' => $this->roles,
            'status' => $this->status->value,
            'passwordFingerprint' => hash('crc32c', (string) $this->password),
        ];
    }

    /**
     * Reconstruction depuis la session (voir __serialize). L'objet obtenu est un « squelette » :
     * Symfony le remplace aussitôt par l'utilisateur frais lu en base.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $this->id = $data['id'];
        $this->email = $data['email'];
        $this->roles = $data['roles'];
        $this->status = AccountStatus::from($data['status']);
        // On place l'empreinte dans `password` UNIQUEMENT pour la comparaison dans isEqualTo().
        $this->password = $data['passwordFingerprint'];
    }

    /**
     * Compare l'utilisateur de la session (« $this », squelette) avec l'utilisateur frais de la base.
     * Retourne FALSE → Symfony déconnecte la personne. Cas couverts :
     *  - mot de passe changé (on invalide les autres sessions),
     *  - compte suspendu ou en cours de suppression,
     *  - rôles modifiés,
     *  - e-mail modifié.
     */
    public function isEqualTo(UserInterface $user): bool
    {
        if (!$user instanceof self) {
            return false;
        }

        return $this->id->equals($user->id)
            && $this->email === $user->email
            && $this->roles === $user->roles
            && $this->status === $user->status
            // $this->password contient l'empreinte (cf. __unserialize) ; on la compare à celle du hash frais.
            && $this->password === hash('crc32c', (string) $user->password);
    }

    // ------------------------------------------------------------------ Accesseurs (lecture)

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getFullName(): string
    {
        return $this->fullName;
    }

    /** Téléphone au format E.164 (+261…), ou null. */
    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function getAccountType(): AccountType
    {
        return $this->accountType;
    }

    public function getStatus(): AccountStatus
    {
        return $this->status;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function getEmailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function getPhoneVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->phoneVerifiedAt;
    }

    public function getPasswordChangedAt(): ?\DateTimeImmutable
    {
        return $this->passwordChangedAt;
    }

    public function getDeletionRequestedAt(): ?\DateTimeImmutable
    {
        return $this->deletionRequestedAt;
    }

    public function getDeletionScheduledAt(): ?\DateTimeImmutable
    {
        return $this->deletionScheduledAt;
    }

    // ------------------------------------------------------------------ Questions « métier » (is…)

    public function isClient(): bool
    {
        return AccountType::Client === $this->accountType;
    }

    public function isSupplier(): bool
    {
        return AccountType::Supplier === $this->accountType;
    }

    public function isAdmin(): bool
    {
        return AccountType::Admin === $this->accountType;
    }

    /** Le Super Admin possède tous les droits (il est le seul à pouvoir créer d'autres admins). */
    public function isSuperAdmin(): bool
    {
        return \in_array('ROLE_SUPER_ADMIN', $this->roles, true);
    }

    /** Utilisé par les templates : user.isEmailVerified. */
    public function isEmailVerified(): bool
    {
        return null !== $this->emailVerifiedAt;
    }

    /** Utilisé par les templates : user.isPhoneVerified. */
    public function isPhoneVerified(): bool
    {
        return null !== $this->phoneVerifiedAt && null !== $this->phone;
    }

    /** Le compte possède au moins UN moyen de contact vérifié (e-mail OU téléphone). */
    public function hasVerifiedContact(): bool
    {
        return $this->isEmailVerified() || $this->isPhoneVerified();
    }

    public function isActive(): bool
    {
        return AccountStatus::Active === $this->status;
    }

    public function isSuspended(): bool
    {
        return AccountStatus::Suspended === $this->status;
    }

    public function isPendingDeletion(): bool
    {
        return AccountStatus::PendingDeletion === $this->status;
    }

    public function hasPassword(): bool
    {
        return null !== $this->password;
    }

    public function isTwoFactorEmailEnabled(): bool
    {
        return $this->twoFactorEmailEnabled;
    }

    public function isTwoFactorSmsEnabled(): bool
    {
        return $this->twoFactorSmsEnabled;
    }

    /**
     * La 2FA est-elle ACTIVE pour ce compte (utilisé par les templates : user.twoFactorEnabled) ?
     * - Les administrateurs sont TOUJOURS protégés par la 2FA par e-mail (obligatoire, non désactivable).
     * - Les autres : si le client a activé au moins un canal.
     */
    public function isTwoFactorEnabled(): bool
    {
        return $this->isAdmin() || $this->twoFactorEmailEnabled || $this->twoFactorSmsEnabled;
    }

    /**
     * Canaux disponibles pour recevoir le code 2FA à la connexion, par ordre de préférence.
     * Le SMS n'est proposé que si le téléphone est vérifié.
     *
     * @return list<\App\Enum\OtpChannel>
     */
    public function twoFactorChannels(): array
    {
        $channels = [];
        // Un admin reçoit toujours son code par e-mail, même sans préférence enregistrée.
        if ($this->isAdmin() || $this->twoFactorEmailEnabled) {
            $channels[] = \App\Enum\OtpChannel::Email;
        }
        if ($this->twoFactorSmsEnabled && $this->isPhoneVerified()) {
            $channels[] = \App\Enum\OtpChannel::Sms;
        }

        return $channels;
    }

    // ------------------------------------------------------------------ Modifications (écriture)

    /** Enregistre l'e-mail en minuscules et sans espaces : « Foo@Mail.COM » et « foo@mail.com » sont le même compte. */
    public function setEmail(string $email): void
    {
        $this->email = mb_strtolower(trim($email));
    }

    public function setFullName(string $fullName): void
    {
        $this->fullName = trim($fullName);
    }

    /**
     * Change le téléphone. Un nouveau numéro n'est JAMAIS considéré comme vérifié :
     * la vérification précédente est annulée, et la 2FA par SMS est coupée (plus de numéro fiable).
     */
    public function changePhone(?string $phoneE164): void
    {
        if ($phoneE164 === $this->phone) {
            return; // rien ne change
        }
        $this->phone = $phoneE164;
        $this->phoneVerifiedAt = null;
        $this->twoFactorSmsEnabled = false;
    }

    public function setPasswordHash(string $hash, \DateTimeImmutable $now): void
    {
        $this->password = $hash;
        $this->passwordChangedAt = $now;
    }

    /**
     * Supprime le mot de passe. Utilisé quand un compte NON vérifié est « repris » par une connexion Google/Apple
     * (voir SocialAuthService) : sans cela, la personne qui avait créé ce compte avec l'e-mail d'un tiers (« pré-piratage »)
     * pourrait encore s'y connecter avec le mot de passe qu'elle avait choisi.
     * Changer `password_changed_at` invalide aussi les sessions existantes (l'empreinte du hash change).
     */
    public function removePassword(\DateTimeImmutable $now): void
    {
        $this->password = null;
        $this->passwordChangedAt = $now;
    }

    /**
     * Remplace l'adresse e-mail une fois la nouvelle adresse CONFIRMÉE par code (voir ContactChangeService).
     * La nouvelle adresse est donc vérifiée d'office. L'empreinte de session (isEqualTo) comprend l'e-mail : les AUTRES
     * sessions ouvertes sont fermées, ce qui est voulu après un changement d'identifiant de connexion.
     */
    public function changeEmail(string $newEmail, \DateTimeImmutable $now): void
    {
        $this->setEmail($newEmail);
        $this->emailVerifiedAt = $now;
    }

    public function getAvatarUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->avatarUpdatedAt;
    }

    public function hasAvatar(): bool
    {
        return null !== $this->avatarUpdatedAt;
    }

    /** Date de la photo, ou null quand la personne n'en a pas (la suppression de la photo remet null). */
    public function setAvatarUpdatedAt(?\DateTimeImmutable $at): void
    {
        $this->avatarUpdatedAt = $at;
    }

    /**
     * Version courte de la photo, ajoutée à son adresse (`/profil/photo?v=…`) : elle change à chaque nouvelle photo, ce qui
     * force le navigateur à la recharger, et elle dépend du compte (deux personnes sur le même navigateur ne se voient jamais
     * la photo de l'autre dans le cache).
     */
    public function avatarVersion(): ?string
    {
        return null === $this->avatarUpdatedAt ? null : substr(hash('sha256', $this->id->toRfc4122().'|'.$this->avatarUpdatedAt->getTimestamp()), 0, 12);
    }

    public function markEmailVerified(\DateTimeImmutable $now): void
    {
        $this->emailVerifiedAt ??= $now;
    }

    public function markPhoneVerified(\DateTimeImmutable $now): void
    {
        $this->phoneVerifiedAt ??= $now;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    public function setTwoFactor(bool $email, bool $sms): void
    {
        $this->twoFactorEmailEnabled = $email;
        // La 2FA par SMS n'a de sens qu'avec un téléphone vérifié.
        $this->twoFactorSmsEnabled = $sms && $this->isPhoneVerified();
    }

    public function recordLogin(\DateTimeImmutable $now): void
    {
        $this->lastLoginAt = $now;
    }

    public function suspend(): void
    {
        $this->status = AccountStatus::Suspended;
    }

    public function reactivate(): void
    {
        $this->status = AccountStatus::Active;
        $this->deletionRequestedAt = null;
        $this->deletionScheduledAt = null;
    }

    /** Démarre la période de grâce de 30 jours avant suppression définitive. */
    public function requestDeletion(\DateTimeImmutable $now): void
    {
        $this->status = AccountStatus::PendingDeletion;
        $this->deletionRequestedAt = $now;
        $this->deletionScheduledAt = $now->modify('+'.self::DELETION_GRACE_DAYS.' days');
    }

    /** Annule la demande de suppression (le client s'est reconnecté et a choisi de restaurer son compte). */
    public function cancelDeletion(): void
    {
        $this->status = AccountStatus::Active;
        $this->deletionRequestedAt = null;
        $this->deletionScheduledAt = null;
    }

    public function touch(\DateTimeImmutable $now): void
    {
        $this->updatedAt = $now;
    }
}
