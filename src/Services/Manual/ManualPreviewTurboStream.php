<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Manual;

use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\UX\Turbo\TurboBundle;
use Symfony\UX\Turbo\TurboStreamResponse;
use Twig\Environment;
use WBoost\Web\Entity\Manual;
use WBoost\Web\FormData\LogoColorsEditForm;
use WBoost\Web\FormData\ManualPageTextFormData;
use WBoost\Web\Query\GetManualFonts;
use WBoost\Web\Query\GetManualMockupPages;
use WBoost\Web\Repository\ManualContentPageRepository;
use WBoost\Web\Value\LogoColorVariant;
use WBoost\Web\Value\LogoTypeVariant;
use WBoost\Web\Value\ManualPage;

/**
 * The manual preview's pencil modals save IN PLACE. Their forms are
 * Turbo-enabled (`data-turbo="true"` under the site-wide
 * `<html data-turbo="false">`), so the POST arrives with the Turbo Stream
 * media type first in `Accept` — and instead of the redirect, which reloaded
 * a manual dozens of screens long at its top, the answer is a stream that
 * replaces what the save changed and re-renders the modal's body:
 *
 * - **regions outside the modal** are cut out of a full render of the preview
 *   itself (`regions()`), not re-rendered from partials: the logo cards are
 *   inlined ~30× in `manual_preview.html.twig` with per-page wrapper classes
 *   (and the page description's column class is the caller's), so the page
 *   is the only source that knows their exact markup. What lands is
 *   byte-for-byte what a reload would show.
 * - **the modal body** is rendered from its partial — with the submitted form
 *   on a validation error (status 422, the modal stays open), with a fresh
 *   form after a save (normalized values, e.g. a cleared field shows its
 *   placeholder again).
 *
 * The modal element itself is NEVER part of a stream (it lives outside every
 * replaced region): replacing an open Bootstrap modal leaves its backdrop and
 * `body.modal-open` behind. `modal_controller.js` closes it on a successful
 * `turbo:submit-end` instead.
 */
final readonly class ManualPreviewTurboStream
{
    public function __construct(
        private Environment $twig,
        private ManualEditForms $forms,
        private GetManualFonts $getManualFonts,
        private GetManualMockupPages $getManualMockupPages,
        private ManualContentPageRepository $contentPageRepository,
        private ResolveManualContentStyles $resolveContentStyles,
    ) {
    }

    public static function wants(Request $request): bool
    {
        return TurboBundle::STREAM_FORMAT === $request->getPreferredFormat();
    }

    /**
     * What `manual_preview.html.twig` renders with — shared with the preview
     * controller so a region cut out of a stream render is the page's own.
     *
     * @return array<string, mixed>
     */
    public function previewParameters(Manual $manual): array
    {
        return [
            'project' => $manual->project,
            'manual' => $manual,
            'fonts' => $this->getManualFonts->allForManual($manual->id),
            'mockup_pages' => $this->getManualMockupPages->allForManual($manual->id),
            'intro_page' => $this->contentPageRepository->introFor($manual->id),
            'content_styles' => $this->resolveContentStyles->forManual($manual),
        ];
    }

    public static function titleId(ManualPage $page): string
    {
        return 'manual-page-title-' . $page->value;
    }

    public static function descriptionId(ManualPage $page): string
    {
        return 'manual-page-description-' . $page->value;
    }

    public static function pageTextBodyId(ManualPage $page): string
    {
        return 'manual-page-text-form-' . $page->value;
    }

    public function pageTextSaved(Request $request, Manual $manual, ManualPage $page): Response
    {
        $regions = $this->regions($manual, [self::titleId($page), self::descriptionId($page)]);
        $regions[self::pageTextBodyId($page)] = $this->pageTextBody($page, $this->forms->pageText($manual, $page));

        return $this->stream($request, $regions);
    }

    /**
     * @param FormInterface<ManualPageTextFormData> $form the submitted, invalid form
     */
    public function pageTextInvalid(Request $request, ManualPage $page, FormInterface $form): Response
    {
        return $this->stream($request, [
            self::pageTextBodyId($page) => $this->pageTextBody($page, $form),
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function logoColorsSaved(Request $request, Manual $manual, LogoTypeVariant $logoVariant, LogoColorVariant $colorVariant): Response
    {
        $regions = $this->regions($manual, [LogoColorsEditForm::cardId($logoVariant, $colorVariant)]);
        $regions[LogoColorsEditForm::bodyId($logoVariant, $colorVariant)] = $this->logoColorsBody(
            $this->forms->logoColors($manual, $logoVariant, $colorVariant),
        );

        return $this->stream($request, $regions);
    }

    public function logoColorsInvalid(Request $request, LogoColorsEditForm $edit): Response
    {
        return $this->stream($request, [
            LogoColorsEditForm::bodyId($edit->logoVariant, $edit->colorVariant) => $this->logoColorsBody($edit),
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Renders the whole preview and returns the outer HTML of each element
     * with one of `$ids` (the first, should an id repeat — the stream's
     * `targets="#id"` then replaces every copy). An id the page does not
     * render (a card of a logo variant the manual has no image for) is left
     * out, so the stream never names a target that is not there.
     *
     * @param list<string> $ids
     * @return array<string, string>
     */
    public function regions(Manual $manual, array $ids): array
    {
        $html = $this->twig->render('manual_preview.html.twig', $this->previewParameters($manual));
        $document = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);

        $regions = [];

        foreach ($ids as $id) {
            $element = $document->getElementById($id);

            if ($element !== null) {
                $regions[$id] = $document->saveHtml($element);
            }
        }

        return $regions;
    }

    /**
     * @param FormInterface<ManualPageTextFormData> $form
     */
    private function pageTextBody(ManualPage $page, FormInterface $form): string
    {
        return $this->twig->render('_manual_page_text_form_body.html.twig', [
            'page' => $page,
            'form' => $form->createView(),
        ]);
    }

    private function logoColorsBody(LogoColorsEditForm $edit): string
    {
        return $this->twig->render('_manual_logo_colors_form_body.html.twig', [
            'edit' => $edit,
            'form' => $edit->form->createView(),
        ]);
    }

    /**
     * @param array<string, string> $regions element id => its new outer HTML
     */
    private function stream(Request $request, array $regions, int $status = Response::HTTP_OK): Response
    {
        $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

        return new TurboStreamResponse(
            $this->twig->render('_manual_preview_regions.stream.html.twig', ['regions' => $regions]),
            $status,
        );
    }
}
