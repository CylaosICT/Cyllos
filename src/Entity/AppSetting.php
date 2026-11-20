<?php

namespace App\Entity;

use App\Repository\AppSettingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tiny key/value store for application-wide settings edited from the
 * back-office (as opposed to environment variables), e.g. the automatic
 * database backup schedule.
 */
#[ORM\Entity(repositoryClass: AppSettingRepository::class)]
class AppSetting
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 100)]
        private string $name,
        #[ORM\Column(type: 'text')]
        private string $value,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): static
    {
        $this->value = $value;

        return $this;
    }
}
