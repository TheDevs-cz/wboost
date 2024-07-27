<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Project;

use Ramsey\Uuid\UuidInterface;

readonly final class AddImageColorsToProject
{
    public function __construct(
        public UuidInterface $projectId,
        public string $imagePath,
    ) {
    }
}
