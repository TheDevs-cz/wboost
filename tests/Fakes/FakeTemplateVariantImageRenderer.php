<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Fakes;

use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Entity\CustomTemplateVariant;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Value\ResolvedImageOverrides;
use WBoost\Web\Value\ResolvedInputOverrides;

/**
 * Test renderer that emits a fixed valid 1×1 PNG. Lets the API/web tests
 * exercise the full request → processor → response pipeline without depending
 * on Gotenberg, fonts, or Minio reachability. Records every call (text AND
 * image overrides) so tests can assert what the resolver produced.
 */
final class FakeTemplateVariantImageRenderer implements TemplateVariantImageRendererInterface
{
    private const string FIXED_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=';

    /** @var array<int, array{variantId: string, texts: array<string, string>, hidden: array<string, bool>, images: array<string, array{scale: float, offsetX: float, offsetY: float, rotation: float, naturalWidth: int, naturalHeight: int}>, imagesHidden: list<string>, mode: string, strictContainerOverflow: bool}> */
    public array $calls = [];

    /**
     * When set, every render call throws this — lets tests exercise the
     * container-overflow 400 contract without a real Gotenberg round-trip.
     */
    public null|\WBoost\Web\Exceptions\ContainerOverflow $throwContainerOverflow = null;

    public function render(
        SocialNetworkTemplateVariant|CustomTemplateVariant $variant,
        ResolvedInputOverrides $overrides,
        null|ResolvedImageOverrides $imageOverrides = null,
        bool $strictContainerOverflow = false,
    ): Response {
        $this->record($variant, $overrides, $imageOverrides, 'render', $strictContainerOverflow);

        return new Response($this->png(), Response::HTTP_OK, ['Content-Type' => 'image/png']);
    }

    public function renderToBytes(
        SocialNetworkTemplateVariant|CustomTemplateVariant $variant,
        ResolvedInputOverrides $overrides,
        null|ResolvedImageOverrides $imageOverrides = null,
        bool $strictContainerOverflow = false,
    ): string {
        $this->record($variant, $overrides, $imageOverrides, 'renderToBytes', $strictContainerOverflow);

        return $this->png();
    }

    private function record(
        SocialNetworkTemplateVariant|CustomTemplateVariant $variant,
        ResolvedInputOverrides $overrides,
        null|ResolvedImageOverrides $imageOverrides,
        string $mode,
        bool $strictContainerOverflow,
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
                    'rotation' => $override->rotation,
                    'naturalWidth' => $override->naturalWidth,
                    'naturalHeight' => $override->naturalHeight,
                ];
            }

            $imagesHidden = array_keys($imageOverrides->hidden);
        }

        $this->calls[] = [
            'variantId' => $variant->id->toString(),
            'texts' => $overrides->texts,
            'hidden' => $overrides->hidden,
            'images' => $images,
            'imagesHidden' => $imagesHidden,
            'mode' => $mode,
            'strictContainerOverflow' => $strictContainerOverflow,
        ];
    }

    private function png(): string
    {
        $png = base64_decode(self::FIXED_PNG_BASE64, true);
        \assert(is_string($png));

        return $png;
    }
}
