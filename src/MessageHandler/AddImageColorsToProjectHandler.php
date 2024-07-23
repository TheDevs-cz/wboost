<?php

declare(strict_types=1);

namespace BrandManuals\Web\MessageHandler;

use BrandManuals\Web\Message\AddImageColorsToProject;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AddImageColorsToProjectHandler
{
    public function __invoke(AddImageColorsToProject $message): void
    {

    }
}
