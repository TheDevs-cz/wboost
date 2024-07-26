<?php

declare(strict_types=1);

namespace WBoost\Web\Message;

readonly final class AddImageColorsToProject
{
    public function __construct(
        public string $projectId,
        public string $imagePath,
    ) {
    }
}
