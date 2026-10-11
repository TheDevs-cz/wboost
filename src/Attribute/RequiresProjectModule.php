<?php

declare(strict_types=1);

namespace WBoost\Web\Attribute;

use WBoost\Web\Value\ProjectModule;

/**
 * Marks a module's landing page. When the controller's {@see \WBoost\Web\Entity\Project}
 * argument doesn't have the module switched on, the request is redirected to
 * the project's "Objevte další moduly" page instead (see
 * {@see \WBoost\Web\Services\ProjectModule\ProjectModuleGuardListener}).
 *
 * Only the landing pages carry it on purpose: switching a module off is about
 * decluttering the UI, not access control, so item-level pages, the render
 * pipeline and the API keep working regardless.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class RequiresProjectModule
{
    public function __construct(
        public ProjectModule $module,
    ) {
    }
}
