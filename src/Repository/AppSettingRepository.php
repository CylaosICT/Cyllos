<?php

namespace App\Repository;

use App\Entity\AppSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AppSetting>
 */
class AppSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AppSetting::class);
    }

    public function get(string $name, string $default = ''): string
    {
        return $this->find($name)?->getValue() ?? $default;
    }

    public function set(string $name, string $value): void
    {
        $setting = $this->find($name);
        if ($setting === null) {
            $this->getEntityManager()->persist(new AppSetting($name, $value));
        } else {
            $setting->setValue($value);
        }
        $this->getEntityManager()->flush();
    }
}
