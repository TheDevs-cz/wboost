<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan\Template;

use Psr\Clock\ClockInterface;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Value\BackgroundMode;
use WBoost\Web\Value\DimensionUnit;
use WBoost\Web\Value\TemplateDimension;

/**
 * The template variant a scan's candidates are validated and rendered on —
 * **in memory only**. A scan must not create a template until the user
 * accepts the proposal, yet the whole design pipeline (preflight, compiler,
 * renderer) speaks `TemplateVariant`. So this builds a `Template` and a
 * `TemplateVariant` that are never handed to Doctrine: no `persist()`, and
 * nothing managed points at them (the `Project` they reference owns no
 * collection of templates), so no `flush()` anywhere can write them.
 *
 * Layer background mode, like every variant created today.
 */
final readonly class TransientVariant
{
    public function __construct(
        private ProvideIdentity $provideIdentity,
        private ClockInterface $clock,
    ) {
    }

    public function for(Project $project, int $width, int $height): TemplateVariant
    {
        $now = $this->clock->now();

        $template = new Template(
            $this->provideIdentity->next(),
            $project,
            null,
            $now,
            'AI sken',
            null,
            0,
        );

        return new TemplateVariant(
            $this->provideIdentity->next(),
            $template,
            new TemplateDimension(DimensionUnit::Px, (float) $width, (float) $height),
            null,
            $now,
            BackgroundMode::Layer,
        );
    }
}
