<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Fakes;

use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Services\SocialNetwork\SocialNetworkTemplateVariantImageRendererInterface;
use WBoost\Web\Value\ResolvedInputOverrides;

/**
 * Test renderer that emits a fixed valid 1×1 PNG. Lets the API/web tests
 * exercise the full request → processor → response pipeline without depending
 * on Gotenberg, fonts, or Minio reachability.
 */
final class FakeSocialNetworkTemplateVariantImageRenderer implements SocialNetworkTemplateVariantImageRendererInterface
{
    private const string FIXED_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=';

    /** @var array<int, array{variantId: string, texts: array<string, string>, hidden: array<string, bool>, mode: string}> */
    public array $calls = [];

    public function render(SocialNetworkTemplateVariant $variant, ResolvedInputOverrides $overrides): Response
    {
        $this->record($variant, $overrides, 'render');

        return new Response($this->png(), Response::HTTP_OK, ['Content-Type' => 'image/png']);
    }

    public function renderToBytes(SocialNetworkTemplateVariant $variant, ResolvedInputOverrides $overrides): string
    {
        $this->record($variant, $overrides, 'renderToBytes');

        return $this->png();
    }

    private function record(SocialNetworkTemplateVariant $variant, ResolvedInputOverrides $overrides, string $mode): void
    {
        $this->calls[] = [
            'variantId' => $variant->id->toString(),
            'texts' => $overrides->texts,
            'hidden' => $overrides->hidden,
            'mode' => $mode,
        ];
    }

    private function png(): string
    {
        $png = base64_decode(self::FIXED_PNG_BASE64, true);
        \assert(is_string($png));

        return $png;
    }
}
