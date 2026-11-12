<?php

declare(strict_types=1);

namespace WBoost\Web\Twig;

use Twig\Attribute\AsTwigFunction;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Services\AiScan\Template\UnfilledPictures;

/**
 * Whether a fill page has to ask before an export (docs/ai-scan/ux.md T4,
 * docs/architecture/ai-scan.md § "Unfilled pictures"): the number of objects
 * of the given variants that still show the project's grey stand-in. It is the
 * UPPER bound — a fillable background counts although this export may supply
 * its picture — so the page only uses it to decide whether its export forms
 * carry the `ai-scan-export-guard` controller at all; the exact answer is the
 * server's 409. Zero for every template that did not come from a scan.
 */
final readonly class AiScanExportExtension
{
    public function __construct(
        private UnfilledPictures $unfilledPictures,
    ) {
    }

    /**
     * @param iterable<mixed> $variants
     */
    #[AsTwigFunction('ai_scan_unfilled')]
    public function unfilled(iterable $variants): int
    {
        $count = 0;

        foreach ($variants as $variant) {
            if ($variant instanceof TemplateVariant) {
                $count += $this->unfilledPictures->count($variant);
            }
        }

        return $count;
    }
}
