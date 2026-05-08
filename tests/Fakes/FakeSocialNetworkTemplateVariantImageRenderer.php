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
    /** @var array<int, array{variantId: string, texts: array<int, string>, hidden: array<int, bool>}> */
    public array $calls = [];

    public function render(SocialNetworkTemplateVariant $variant, ResolvedInputOverrides $overrides): Response
    {
        $this->calls[] = [
            'variantId' => $variant->id->toString(),
            'texts' => $overrides->texts,
            'hidden' => $overrides->hidden,
        ];

        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=',
            true,
        );
        \assert(is_string($png));

        return new Response($png, Response::HTTP_OK, ['Content-Type' => 'image/png']);
    }
}
