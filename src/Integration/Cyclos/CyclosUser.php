<?php

namespace App\Integration\Cyclos;

final readonly class CyclosUser
{
    public function __construct(
        public string $id,
        public ?string $groupInternalName,
    ) {
    }
}
