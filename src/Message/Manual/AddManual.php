<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Manual;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\ManualType;

readonly final class AddManual
{
    public function __construct(
        public UuidInterface $manualId,
        public UuidInterface $projectId,
        public ManualType $type,
        public string $name,
    ) {
    }
}
