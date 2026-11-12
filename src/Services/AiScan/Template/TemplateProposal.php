<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan\Template;

use WBoost\Web\Entity\Project;
use WBoost\Web\Mcp\Design\CandidateRenderer;
use WBoost\Web\Mcp\Design\DesignPreflight;
use WBoost\Web\Services\AiScan\AiScanStandIn;
use WBoost\Web\Value\AiScan\AiScanLimits;
use WBoost\Web\Value\RenderImageFormat;

/**
 * The template side of a scan's proposal: a candidate exists only as a design
 * document the APP validated and rendered itself.
 *
 * {@see candidate()} runs the same pass `preview_design` runs (parse → fit →
 * lint → compile) on a {@see TransientVariant} sized by the document's own
 * canvas, then one render through {@see CandidateRenderer} — which persists
 * nothing. So a scan in progress leaves no template, no variant, no thumbnail
 * and no cache entry behind.
 *
 * {@see proposal()} is what `finish` stores: the kept candidate's document
 * plus what the scanner measured on its render.
 */
final readonly class TemplateProposal
{
    public function __construct(
        private TransientVariant $transientVariant,
        private DesignPreflight $preflight,
        private CandidateRenderer $renderer,
        private AiScanStandIn $standIn,
    ) {
    }

    /**
     * @return array{issues: list<array<string, mixed>>, blocked: bool, bytes: null|string, width: null|int, height: null|int}
     *
     * @throws \WBoost\Web\Exceptions\TemplateRenderUnavailable when the renderer is overloaded / unreachable
     */
    public function candidate(Project $project, mixed $design): array
    {
        $size = self::canvasSize($design);

        if ($size === null) {
            return self::blocked([[
                'severity' => 'error',
                'stage' => 'parse',
                'code' => 'canvas_size',
                'path' => 'canvas',
                'message' => sprintf(
                    'canvas.width and canvas.height must be whole pixels between 1 and %d (the long edge of a template made from a scan).',
                    AiScanLimits::TEMPLATE_MAX_LONG_EDGE,
                ),
            ]]);
        }

        $variant = $this->transientVariant->for($project, $size[0], $size[1]);
        $review = $this->preflight->review($variant, $design, $this->standIn->assets());

        if ($review->isBlocked() || $review->compiled === null) {
            return self::blocked($review->toArray());
        }

        return [
            'issues' => $review->toArray(),
            'blocked' => false,
            'bytes' => $this->renderer->renderToBytes($variant, $review->compiled, format: RenderImageFormat::Webp),
            'width' => $size[0],
            'height' => $size[1],
        ];
    }

    /**
     * @param array<string, mixed> $candidate the stored candidate entry (`n`, `design`, `width`, `height`)
     * @param array<string, mixed> $measured what the scanner measured on the kept render: boxes, colours, line widths
     * @return array<string, mixed>
     */
    public static function proposal(array $candidate, array $measured): array
    {
        return [
            'kind' => 'template',
            'candidate' => $candidate['n'] ?? null,
            'width' => $candidate['width'] ?? null,
            'height' => $candidate['height'] ?? null,
            'document' => $candidate['design'] ?? null,
            'measured' => $measured === [] ? new \stdClass() : $measured,
        ];
    }

    /**
     * @return null|array{int, int}
     */
    private static function canvasSize(mixed $design): null|array
    {
        $canvas = is_array($design) ? ($design['canvas'] ?? null) : null;
        $width = is_array($canvas) ? ($canvas['width'] ?? null) : null;
        $height = is_array($canvas) ? ($canvas['height'] ?? null) : null;

        if (!is_int($width) || !is_int($height) || $width < 1 || $height < 1 || max($width, $height) > AiScanLimits::TEMPLATE_MAX_LONG_EDGE) {
            return null;
        }

        return [$width, $height];
    }

    /**
     * @param list<array<string, mixed>> $issues
     * @return array{issues: list<array<string, mixed>>, blocked: bool, bytes: null|string, width: null|int, height: null|int}
     */
    private static function blocked(array $issues): array
    {
        return ['issues' => $issues, 'blocked' => true, 'bytes' => null, 'width' => null, 'height' => null];
    }
}
