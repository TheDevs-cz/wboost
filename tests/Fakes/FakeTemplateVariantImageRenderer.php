<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Fakes;

use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Value\CanvasSlice;
use WBoost\Web\Value\RenderImageFormat;
use WBoost\Web\Value\ResolvedImageOverrides;
use WBoost\Web\Value\ResolvedInputOverrides;

/**
 * Test renderer that emits a fixed valid 1×1 image IN THE REQUESTED FORMAT.
 * Lets the API/web tests exercise the full request → processor → response
 * pipeline without depending on Gotenberg, fonts, or Minio reachability.
 * Records every call (text AND image overrides, plus the format) so tests can
 * assert what the resolver produced.
 *
 * **Emitting format-matching bytes is load-bearing, not a nicety.** This fake
 * used to return a PNG unconditionally. If it still did, every
 * `assertStringStartsWith("\x89PNG", ...)` guarding the export/download/ZIP
 * contract would stay green even after someone flipped an export path to WebP —
 * the bytes would say PNG while the `Content-Type` said WebP, and the tests
 * that exist precisely to catch that would pass. Keep these in sync with
 * {@see RenderImageFormat}.
 */
final class FakeTemplateVariantImageRenderer implements TemplateVariantImageRendererInterface
{
    private const string FIXED_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=';

    /** Real, decodable 1×1 VP8 WebP (44 bytes): `RIFF` + `WEBP` + `VP8 `. */
    private const string FIXED_WEBP_BASE64 = 'UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAwA0JaQAA3AA/vuUAAA=';

    /** @var array<int, array{variantId: string, texts: array<string, string>, richTexts: array<string, list<array{text: string, fontFamily: null|string, color: null|string, underline: bool}>>, hidden: array<string, bool>, images: array<string, array{scale: float, offsetX: float, offsetY: float, offsetXRatio: null|float, offsetYRatio: null|float, rotation: float, naturalWidth: int, naturalHeight: int}>, imagesHidden: list<string>, mode: string, strictContainerOverflow: bool, format: string}> */
    public array $calls = [];

    /**
     * When set, every render call throws this — lets tests exercise the
     * container-overflow 400 contract without a real Gotenberg round-trip.
     */
    public null|\WBoost\Web\Exceptions\ContainerOverflow $throwContainerOverflow = null;

    public function render(
        TemplateVariant $variant,
        ResolvedInputOverrides $overrides,
        null|ResolvedImageOverrides $imageOverrides = null,
        bool $strictContainerOverflow = false,
        null|CanvasSlice $slice = null,
        RenderImageFormat $format = RenderImageFormat::Png,
    ): Response {
        $this->record($variant, $overrides, $imageOverrides, 'render', $strictContainerOverflow, $format);

        return new Response($this->bytes($format), Response::HTTP_OK, ['Content-Type' => $format->contentType()]);
    }

    public function renderToBytes(
        TemplateVariant $variant,
        ResolvedInputOverrides $overrides,
        null|ResolvedImageOverrides $imageOverrides = null,
        bool $strictContainerOverflow = false,
        null|CanvasSlice $slice = null,
        RenderImageFormat $format = RenderImageFormat::Png,
    ): string {
        $this->record($variant, $overrides, $imageOverrides, 'renderToBytes', $strictContainerOverflow, $format);

        return $this->bytes($format);
    }

    private function record(
        TemplateVariant $variant,
        ResolvedInputOverrides $overrides,
        null|ResolvedImageOverrides $imageOverrides,
        string $mode,
        bool $strictContainerOverflow,
        RenderImageFormat $format,
    ): void {
        if ($this->throwContainerOverflow !== null && $strictContainerOverflow) {
            throw $this->throwContainerOverflow;
        }

        $images = [];
        $imagesHidden = [];

        if ($imageOverrides !== null) {
            foreach ($imageOverrides->images as $inputId => $override) {
                $images[$inputId] = [
                    'scale' => $override->scale,
                    'offsetX' => $override->offsetX,
                    'offsetY' => $override->offsetY,
                    'offsetXRatio' => $override->offsetXRatio,
                    'offsetYRatio' => $override->offsetYRatio,
                    'rotation' => $override->rotation,
                    'naturalWidth' => $override->naturalWidth,
                    'naturalHeight' => $override->naturalHeight,
                ];
            }

            $imagesHidden = array_keys($imageOverrides->hidden);
        }

        $richTexts = [];
        foreach ($overrides->richTexts as $inputId => $richText) {
            $richTexts[$inputId] = $richText->toArray();
        }

        $this->calls[] = [
            'variantId' => $variant->id->toString(),
            'texts' => $overrides->texts,
            'richTexts' => $richTexts,
            'hidden' => $overrides->hidden,
            'images' => $images,
            'imagesHidden' => $imagesHidden,
            'mode' => $mode,
            'strictContainerOverflow' => $strictContainerOverflow,
            'format' => $format->value,
        ];
    }

    private function bytes(RenderImageFormat $format): string
    {
        $decoded = base64_decode(match ($format) {
            RenderImageFormat::Png => self::FIXED_PNG_BASE64,
            RenderImageFormat::Webp => self::FIXED_WEBP_BASE64,
        }, true);
        \assert(is_string($decoded));

        return $decoded;
    }
}
