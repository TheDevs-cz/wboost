<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan;

use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use WBoost\Web\Entity\AiScan;
use WBoost\Web\Entity\Project;

/**
 * Absolute links to the pages of AI scan — for e-mails and for MCP answers.
 *
 * The pages themselves belong to the wizard. Their route names are a
 * contract: **`ai_scan_page`** (`projectId`, `scanId`) and
 * **`ai_scan_history`** (`projectId`). While a route does not exist yet the
 * link opens the project's template list instead of failing — an e-mail
 * button only ever opens a page.
 */
final readonly class AiScanUrls
{
    public const string ROUTE_SCAN = 'ai_scan_page';
    public const string ROUTE_HISTORY = 'ai_scan_history';

    public function __construct(
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function scan(AiScan $scan): string
    {
        $projectId = $scan->project->id->toString();

        try {
            return $this->urls->generate(self::ROUTE_SCAN, ['projectId' => $projectId, 'scanId' => $scan->id->toString()], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (RouteNotFoundException) {
            return $this->templates($scan->project);
        }
    }

    public function templates(Project $project): string
    {
        return $this->urls->generate('templates', ['projectId' => $project->id->toString()], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /** Where an admin switches AI scan on for the project. */
    public function projectSwitch(Project $project): string
    {
        return $this->urls->generate('edit_project', ['id' => $project->id->toString()], UrlGeneratorInterface::ABSOLUTE_URL) . '#project-modules';
    }

    public function editor(string $variantId): string
    {
        return $this->urls->generate('template_variant_editor', ['variantId' => $variantId], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
