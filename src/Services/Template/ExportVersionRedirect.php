<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Template;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use WBoost\Web\Entity\TemplateExportVersion;

/**
 * Where a rename / pin POST sends the user back to. The forms live on three
 * surfaces (the fill page's dropdown and banner, the history page), so each
 * carries a `redirect` field with its own URL; only a LOCAL path is honoured
 * — anything else falls back to the version's history page, so the field
 * can never be turned into an open redirect.
 */
final readonly class ExportVersionRedirect
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function target(Request $request, TemplateExportVersion $version): string
    {
        $redirect = $request->request->getString('redirect');

        if (self::isLocalPath($redirect)) {
            return $redirect;
        }

        return $this->historyPage($version);
    }

    public function historyPage(TemplateExportVersion $version): string
    {
        if ($version->variant !== null) {
            return $this->urlGenerator->generate('template_variant_export_history', ['variantId' => $version->variant->id]);
        }

        if ($version->group !== null) {
            return $this->urlGenerator->generate('template_group_export_history', ['groupId' => $version->group->id]);
        }

        return $this->urlGenerator->generate('templates', ['projectId' => $version->template->project->id]);
    }

    /**
     * A same-origin path: one leading slash, not a protocol-relative `//host`
     * (or the backslash spelling browsers normalise to it), no scheme.
     */
    public static function isLocalPath(string $candidate): bool
    {
        return $candidate !== ''
            && $candidate[0] === '/'
            && !str_starts_with($candidate, '//')
            && !str_starts_with($candidate, '/\\')
            && !str_contains($candidate, "\n")
            && !str_contains($candidate, "\r");
    }
}
