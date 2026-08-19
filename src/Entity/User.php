<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\UniqueConstraint(name: 'user_email_unique', columns: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const ROLE_ADMIN = 'ROLE_ADMIN';
    public const ROLE_CLIENT = 'ROLE_CLIENT';
    public const ROLE_DEVELOPER = 'ROLE_DEVELOPER';
    public const ROLE_CEO = 'ROLE_CEO';

    public const THEME_LIGHT = 'light';
    public const THEME_DARK = 'dark';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $email = '';

    /**
     * @var list<string>
     */
    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private string $password = '';

    /**
     * The client this user belongs to. Null for Cylaos global admins.
     */
    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Client $client = null;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(length: 10)]
    private string $theme = self::THEME_LIGHT;

    /**
     * Password reset flow — client accounts only (see PasswordResetController).
     * Stores a hash of the token, never the raw value handed out in the email
     * link, so a database read alone can't be used to reset the password.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $resetTokenHash = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resetTokenExpiresAt = null;

    /**
     * TOTP two-factor authentication, opt-in per account (any role). The
     * secret is stored encrypted at rest (see SecretEncryptor), decrypted
     * only at verification time.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $totpSecretEncrypted = null;

    #[ORM\Column]
    private bool $totpEnabled = false;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function eraseCredentials(): void
    {
    }

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function setClient(?Client $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function isAdmin(): bool
    {
        return \in_array(self::ROLE_ADMIN, $this->roles, true);
    }

    public function isDeveloper(): bool
    {
        return \in_array(self::ROLE_DEVELOPER, $this->roles, true);
    }

    public function isCeo(): bool
    {
        return \in_array(self::ROLE_CEO, $this->roles, true);
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getTheme(): string
    {
        return $this->theme;
    }

    public function setTheme(string $theme): static
    {
        $this->theme = \in_array($theme, [self::THEME_LIGHT, self::THEME_DARK], true) ? $theme : self::THEME_LIGHT;

        return $this;
    }

    public function getResetTokenHash(): ?string
    {
        return $this->resetTokenHash;
    }

    public function getResetTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->resetTokenExpiresAt;
    }

    public function setResetToken(?string $hash, ?\DateTimeImmutable $expiresAt): static
    {
        $this->resetTokenHash = $hash;
        $this->resetTokenExpiresAt = $expiresAt;

        return $this;
    }

    public function getTotpSecretEncrypted(): ?string
    {
        return $this->totpSecretEncrypted;
    }

    public function setTotpSecretEncrypted(?string $totpSecretEncrypted): static
    {
        $this->totpSecretEncrypted = $totpSecretEncrypted;

        return $this;
    }

    public function isTotpEnabled(): bool
    {
        return $this->totpEnabled;
    }

    public function setTotpEnabled(bool $totpEnabled): static
    {
        $this->totpEnabled = $totpEnabled;

        return $this;
    }
}
