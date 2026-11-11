<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Calendar;

use Sensiolabs\GotenbergBundle\Enumeration\ScreenshotFormat;
use Sensiolabs\GotenbergBundle\Exception\ClientException;
use Sensiolabs\GotenbergBundle\GotenbergScreenshotInterface;
use Sensiolabs\GotenbergBundle\Processor\InMemoryProcessor;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Exceptions\CalendarModelRefused;
use WBoost\Web\Exceptions\CalendarModelUnavailable;
use WBoost\Web\Exceptions\TemplateRenderUnavailable;
use WBoost\Web\Services\Editor\TemplateVariantImageRenderer;
use WBoost\Web\Value\Calendar\CalendarPageRender;
use WBoost\Web\Value\RenderImageFormat;

/**
 * One calendar page as a picture, without a browser session: the calendar's
 * own renderer (`renderPage(doc, page, opts) → SVG`) run inside Gotenberg's
 * headless Chromium — the vehicle the template renders use
 * ({@see TemplateVariantImageRenderer}).
 *
 * Self-contained like those: the ES modules travel in an import map of
 * `data:` URLs ({@see CalendarModuleInliner}), the project fonts and every
 * picture the page shows as data URIs ({@see CalendarRenderAssets}) — Chromium there
 * has no route to Minio and needs none.
 *
 * The page list, the mm view box and the warnings come from
 * {@see CalendarModelBridge} (the same JavaScript, in node), because a
 * screenshot call has no channel to hand data back.
 *
 * Returns BYTES — never a streamed response (FrankenPHP: a flushing
 * `StreamedResponse` corrupts the next request).
 */
final class CalendarPageRenderer
{
    /** Longest edge of the screenshot in px (a 310 mm page then is ~5 px per mm, ~130 dpi). */
    public const int DEFAULT_LONG_EDGE = 1600;

    public const int MAX_LONG_EDGE = 3000;

    public function __construct(
        private readonly GotenbergScreenshotInterface $gotenberg,
        private readonly CalendarModelBridge $model,
        private readonly CalendarModuleInliner $modules,
        private readonly CalendarDocument $document,
        private readonly CalendarRenderAssets $assets,
    ) {
    }

    /**
     * @param string $pageKey `cover`, `pre`, `w01`…`w53`, `post`, `sp:<id>`, `back`
     * @param bool $bleed include the bleed around the trim
     * @param bool $marks include the bleed AND the crop marks
     * @param bool $sampleTexts draw the designer's sample texts (caption / QR placeholders) as step 3 does
     *
     * @throws CalendarModelRefused `no_layout`, `unknown_page`
     * @throws CalendarModelUnavailable
     * @throws TemplateRenderUnavailable the renderer is busy or down
     */
    public function render(Calendar $calendar, string $pageKey, bool $bleed = false, bool $marks = false, bool $sampleTexts = false, int $longEdge = self::DEFAULT_LONG_EDGE, RenderImageFormat $format = RenderImageFormat::Png): CalendarPageRender
    {
        $model = $this->model->run($calendar, pages: true, warnings: true, view: ['bleed' => $bleed || $marks, 'marks' => $marks]);

        if (!$model->hasLayout()) {
            throw new CalendarModelRefused('no_layout', 'The calendar has no layout yet, so there is nothing to draw. Choose one first: set_calendar_layout with preset "photoLeft", "photoRight" (7 days) or "twoSided" (14 days).');
        }

        $pages = $model->pages ?? [];
        $page = null;

        foreach ($pages as $candidate) {
            if ($candidate['key'] === $pageKey) {
                $page = $candidate;
            }
        }

        if ($page === null || $model->view === null) {
            throw new CalendarModelRefused('unknown_page', sprintf(
                'The calendar has no page "%s". Its pages: %s.',
                mb_substr($pageKey, 0, 60),
                implode(', ', array_column($pages, 'key')),
            ));
        }

        $longEdge = max(200, min(self::MAX_LONG_EDGE, $longEdge));
        $view = $model->view;
        $scale = $longEdge / max($view['w'], $view['h']);
        $width = max(1, (int) round($view['w'] * $scale));
        $height = max(1, (int) round($view['h'] * $scale));

        $boot = [
            'calendar' => $this->document->calendar($calendar),
            'assets' => $this->assets->inlined($calendar, [$pageKey]),
            'env' => [
                'fonts' => $this->document->fonts($calendar->project),
                'palette' => $this->document->palette($calendar->project),
                'currentYear' => $this->document->currentYear(),
            ],
            'pageKey' => $pageKey,
            'bleed' => $bleed || $marks,
            'marks' => $marks,
            'placeholders' => $sampleTexts ? 'all' : 'frames',
        ];

        if (\PHP_SAPI !== 'cli') {
            // Wall time under FrankenPHP: see TemplateVariantImageRenderer.
            set_time_limit(60);
        }

        try {
            $bytes = $this->gotenberg->html()
                ->content('calendar/headless_render.html.twig', [
                    'import_map' => $this->modules->importMap(),
                    'boot' => CalendarDocument::encode($boot),
                    'font_faces' => json_encode($this->assets->fontFaces($calendar, $model->design), JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES),
                ])
                ->width($width)
                ->height($height)
                ->clip(true)
                ->format(match ($format) {
                    RenderImageFormat::Png => ScreenshotFormat::Png,
                    RenderImageFormat::Webp => ScreenshotFormat::Webp,
                })
                ->waitForExpression('window.calendarRendered === true')
                // A render error is thrown as an uncaught exception: Gotenberg
                // then answers 409 with its text instead of a blank picture.
                ->failOnConsoleExceptions()
                ->generate()
                ->processor(new InMemoryProcessor())
                ->process();
        } catch (TransportExceptionInterface $exception) {
            throw TemplateRenderUnavailable::timedOut($exception);
        } catch (ClientException $exception) {
            if (TemplateVariantImageRenderer::isRendererOverloaded($exception)) {
                throw TemplateRenderUnavailable::timedOut($exception);
            }

            throw new \RuntimeException('The calendar page could not be rendered: ' . mb_substr(self::errorBody($exception), 0, 400), 0, $exception);
        }

        if (!is_string($bytes) || $bytes === '') {
            throw new \RuntimeException('The calendar page render came back empty.');
        }

        return new CalendarPageRender(
            bytes: $bytes,
            width: $width,
            height: $height,
            page: $page,
            pages: $pages,
            warnings: array_values(array_filter($model->warnings ?? [], static fn (array $warning): bool => $warning['page'] === $pageKey)),
            view: $view,
        );
    }

    public static function errorBody(ClientException $exception): string
    {
        $previous = $exception->getPrevious();

        if ($previous instanceof HttpExceptionInterface) {
            try {
                return $previous->getResponse()->getContent(false);
            } catch (\Throwable) {
                // fall through to the wrapper's message
            }
        }

        return $exception->getMessage();
    }
}
