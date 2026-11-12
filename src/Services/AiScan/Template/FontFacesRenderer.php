<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan\Template;

use Ramsey\Uuid\UuidInterface;
use Sensiolabs\GotenbergBundle\Enumeration\ScreenshotFormat;
use Sensiolabs\GotenbergBundle\Exception\ClientException;
use Sensiolabs\GotenbergBundle\GotenbergScreenshotInterface;
use Sensiolabs\GotenbergBundle\Processor\InMemoryProcessor;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use WBoost\Web\Exceptions\TemplateRenderUnavailable;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Services\SocialNetwork\AssetInliner;

/**
 * One Gotenberg screenshot of a fixed grid: one row per `(face, line)`, black
 * on white, each row `2 × fontSize` tall with the text's baseline area
 * vertically centred and a fixed left padding. The geometry of every cell is
 * returned with the picture, so the scanner knows where to measure without
 * guessing — and the bytes go back to it untouched.
 *
 * Fonts are inlined as data URIs, exactly like the template render (headless
 * Chromium has no route to storage). A face whose file cannot be read is left
 * out of the answer rather than rendered in a fallback face — a wrong sample
 * would be ranked as if it were the real face.
 */
final readonly class FontFacesRenderer implements FontFacesRendererInterface
{
    private const int PADDING = 24;
    private const int MAX_WIDTH = 8000;

    public function __construct(
        private GotenbergScreenshotInterface $gotenberg,
        private GetFonts $getFonts,
        private AssetInliner $assetInliner,
    ) {
    }

    public function render(UuidInterface $projectId, array $lines, null|array $faces, int $fontSize): array
    {
        $rowHeight = $fontSize * 2;
        $fontFaces = [];
        $cells = [];
        $y = 0;

        foreach ($this->getFonts->allForProject($projectId) as $font) {
            foreach ($font->faces as $face) {
                $family = $font->faceFamily($face);

                if (($faces !== null && !in_array($family, $faces, true)) || count($fontFaces) >= self::MAX_FACES) {
                    continue;
                }

                $source = $this->assetInliner->inlineFont($face->filePath);

                if ($source === null) {
                    continue;
                }

                $alias = 'f' . count($fontFaces);
                $fontFaces[] = ['alias' => $alias, 'src' => $source];

                foreach ($lines as $index => $line) {
                    $cells[] = [
                        'face' => $family,
                        'alias' => $alias,
                        'line' => $index,
                        'text' => $line,
                        'x' => 0,
                        'y' => $y,
                        'height' => $rowHeight,
                        'fontSize' => $fontSize,
                    ];
                    $y += $rowHeight;
                }
            }
        }

        $longest = max(array_map(mb_strlen(...), $lines === [] ? [''] : $lines));
        // Wide enough for the widest plausible face (≈ 1 em per character);
        // a row never wraps, anything beyond is clipped.
        $width = min(self::MAX_WIDTH, 2 * self::PADDING + (int) ceil($longest * $fontSize * 1.1));
        $height = max($rowHeight, $y);

        if ($fontFaces === []) {
            return ['bytes' => '', 'contentType' => 'image/webp', 'width' => $width, 'height' => 0, 'cells' => []];
        }

        if (\PHP_SAPI !== 'cli') {
            set_time_limit(60);
        }

        try {
            $bytes = $this->gotenberg->html()
                ->content('api/font_faces_render.html.twig', [
                    'font_faces' => $fontFaces,
                    'cells' => $cells,
                    'width' => $width,
                    'height' => $height,
                    'padding' => self::PADDING,
                ])
                ->width($width)
                ->height($height)
                ->clip(true)
                ->format(ScreenshotFormat::Webp)
                ->waitForExpression('window.facesRendered === true')
                ->generate()
                ->processor(new InMemoryProcessor())
                ->process();
        } catch (TransportExceptionInterface $exception) {
            throw TemplateRenderUnavailable::timedOut($exception);
        } catch (ClientException $exception) {
            throw TemplateRenderUnavailable::timedOut($exception);
        }

        assert(is_string($bytes));

        return [
            'bytes' => $bytes,
            'contentType' => 'image/webp',
            'width' => $width,
            'height' => $height,
            'cells' => array_map(
                static fn (array $cell): array => [
                    'face' => $cell['face'],
                    'line' => $cell['line'],
                    'x' => $cell['x'],
                    'y' => $cell['y'],
                    'width' => $width,
                    'height' => $cell['height'],
                    'fontSize' => $cell['fontSize'],
                ],
                $cells,
            ),
        ];
    }
}
