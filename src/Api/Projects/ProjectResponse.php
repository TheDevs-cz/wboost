<?php

declare(strict_types=1);

namespace WBoost\Web\Api\Projects;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use DateTimeImmutable;

#[ApiResource(
    shortName: 'Project',
    operations: [
        new GetCollection(
            uriTemplate: '/projects',
            provider: ProjectsProvider::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            paginationEnabled: false,
        ),
    ],
)]
final readonly class ProjectResponse
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public DateTimeImmutable $createdAt,
        public int $manualsCount,
        public int $sharedWithCount,
    ) {
    }
}
