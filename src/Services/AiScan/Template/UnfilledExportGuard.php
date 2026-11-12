<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan\Template;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\TemplateVariant;

/**
 * The one rule every export path applies to {@see UnfilledPictures} (owner
 * decision O11): an export that would show the grey stand-in an AI scan left
 * behind is never produced silently.
 *
 * - the **web** asks first — {@see webConfirmation()} answers a "needs
 *   confirmation" result (409) until the form carries
 *   `allowUnfilled=1` ({@see CONFIRM_FIELD});
 * - the **REST API** and **MCP** refuse — {@see apiRefusal()} /
 *   {@see mcpRefusal()} — unless the caller passes `allowUnfilled: true`.
 *
 * All three are ANSWERS (a Response / a message for the tool's own refusal),
 * never exceptions for the error handler: an unfilled picture is an expected
 * state of a scanned template, not a fault.
 *
 * An export is described as a list of `[variant, images]` pairs — one per
 * variant / page rendered, `images` being the image values THAT render uses
 * (keyed by image input id, any shape the fill accepts). Previews are not
 * exports and never come here.
 *
 * @phpstan-type Exports list<array{TemplateVariant, array<array-key, mixed>}>
 */
final readonly class UnfilledExportGuard
{
    public const string CONFIRM_FIELD = 'allowUnfilled';

    public function __construct(
        private UnfilledPictures $unfilledPictures,
        private UrlGeneratorInterface $urlGenerator,
        private Environment $twig,
    ) {
    }

    /**
     * The web's "needs confirmation" answer, or null when the export may go
     * on (nothing unfilled, or the form confirmed with `allowUnfilled=1`).
     *
     * JSON (409 `{ok: false, code, count, confirmField, editorUrl}`) for a
     * request that asks for JSON — `Accept: application/json`, an XHR, or an
     * endpoint that only ever speaks JSON (`$json`); otherwise the export's
     * usual failure page with the Czech reason, also 409.
     *
     * @param Exports $exports
     */
    public function webConfirmation(Request $request, Project $project, string $backUrl, array $exports, bool $json = false): null|Response
    {
        if ($request->request->getBoolean(self::CONFIRM_FIELD)) {
            return null;
        }

        $found = $this->inspect($exports);

        if ($found === null) {
            return null;
        }

        if ($json || $request->isXmlHttpRequest() || in_array('application/json', $request->getAcceptableContentTypes(), true)) {
            return new JsonResponse([
                'ok' => false,
                'code' => UnfilledPictures::CODE,
                'count' => $found['count'],
                'confirmField' => self::CONFIRM_FIELD,
                'editorUrl' => $this->editorUrl($found['variant'], UrlGeneratorInterface::ABSOLUTE_PATH),
            ], Response::HTTP_CONFLICT);
        }

        return new Response($this->twig->render('export_failed.html.twig', [
            'project' => $project,
            'menu_item' => 'templates',
            'reason' => self::czechReason($found['count']),
            'back_url' => $backUrl,
        ]), Response::HTTP_CONFLICT);
    }

    /**
     * The REST API's structured 400 (`{error, code: "unfilled_pictures",
     * count, variantId, editorUrl}`), or null when the export may go on.
     *
     * @param Exports $exports
     */
    public function apiRefusal(bool $allowUnfilled, array $exports): null|JsonResponse
    {
        $found = $allowUnfilled ? null : $this->inspect($exports);

        if ($found === null) {
            return null;
        }

        return new JsonResponse([
            'error' => sprintf(
                'The export would show %s left by an AI scan. Fill %s in the editor, or send "allowUnfilled": true to export anyway.',
                self::englishCount($found['count']),
                $found['count'] === 1 ? 'it' : 'them',
            ),
            'code' => UnfilledPictures::CODE,
            'count' => $found['count'],
            'variantId' => $found['variant']->id->toString(),
            'editorUrl' => $this->editorUrl($found['variant'], UrlGeneratorInterface::ABSOLUTE_URL),
        ], Response::HTTP_BAD_REQUEST);
    }

    /**
     * The sentence an MCP export tool refuses with (it starts with the code),
     * or null when the export may go on.
     *
     * @param Exports $exports
     */
    public function mcpRefusal(bool $allowUnfilled, array $exports): null|string
    {
        $found = $allowUnfilled ? null : $this->inspect($exports);

        if ($found === null) {
            return null;
        }

        return sprintf(
            '%s: this export would show %s left by an AI scan where a picture is still missing. Nothing was exported. Fill %s in the editor (editorUrl: %s), or call again with allowUnfilled: true to export with the grey stand-in%s.',
            UnfilledPictures::CODE,
            self::englishCount($found['count']),
            $found['count'] === 1 ? 'it' : 'them',
            $this->editorUrl($found['variant'], UrlGeneratorInterface::ABSOLUTE_URL),
            $found['count'] === 1 ? '' : 's',
        );
    }

    /**
     * `Šablona má …` — the confirmation's text (ux.md §T4, plural forms §2.4).
     */
    public static function czechReason(int $count): string
    {
        return match (true) {
            $count === 1 => 'Šablona má 1 nedoplněný obrázek. V exportu bude na jeho místě šedý zástupný obrázek. Doplňte ho v editoru, nebo exportujte přesto.',
            $count >= 2 && $count <= 4 => sprintf('Šablona má %d nedoplněné obrázky. V exportu budou na jejich místě šedé zástupné obrázky. Doplňte je v editoru, nebo exportujte přesto.', $count),
            default => sprintf('Šablona má %1$d nedoplněných obrázků. V exportu bude na jejich místě %1$d šedých zástupných obrázků. Doplňte je v editoru, nebo exportujte přesto.', $count),
        };
    }

    /**
     * How many stand-in pictures the whole export would show, and the first
     * variant showing one (where the editor link leads) — null for none.
     *
     * @param Exports $exports
     * @return null|array{count: int, variant: TemplateVariant}
     */
    private function inspect(array $exports): null|array
    {
        $count = 0;
        $first = null;

        foreach ($exports as [$variant, $images]) {
            $unfilled = $this->unfilledPictures->forExport($variant, $images);

            if ($unfilled > 0) {
                $count += $unfilled;
                $first ??= $variant;
            }
        }

        return $first === null ? null : ['count' => $count, 'variant' => $first];
    }

    private function editorUrl(TemplateVariant $variant, int $referenceType): string
    {
        return $this->urlGenerator->generate('template_variant_editor', ['variantId' => $variant->id->toString()], $referenceType);
    }

    private static function englishCount(int $count): string
    {
        return $count === 1 ? '1 grey stand-in picture' : sprintf('%d grey stand-in pictures', $count);
    }
}
