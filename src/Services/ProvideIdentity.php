<?php

declare(strict_types=1);

namespace WBoost\Web\Services;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

readonly class ProvideIdentity
{
    public function next(): UuidInterface
    {
        return Uuid::uuid7();
    }
}
