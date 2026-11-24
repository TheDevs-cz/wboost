<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Golden;

use Imagick;
use PHPUnit\Framework\Attributes\Group;
use Ramsey\Uuid\Uuid;
use Sensiolabs\GotenbergBundle\GotenbergScreenshotInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\Editor\TemplateVariantImageRenderer;
use WBoost\Web\Services\SocialNetwork\AssetInliner;
use WBoost\Web\Services\SocialNetwork\CanvasPlaceholderGeometry;
use WBoost\Web\Services\SocialNetwork\ImagePlacement;
use WBoost\Web\Services\SocialNetwork\TextInputObjectBinder;
use WBoost\Web\Services\Template\RepeatableRows;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Tests\ContainerFixtures;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\ResolvedImageOverrides;
use WBoost\Web\Value\ResolvedInputOverrides;

/**
 * Real pixels for "Navázat pod" (container `follows`): the prod "Výsledky
 * turnaje" design with „Sestava a branky“ ATTACHED 20 px below the rows,
 * rendered through the actual render template + Fabric + container_layout.js
 * in Gotenberg's Chromium, equals the same design WITHOUT the link where the
 * footer was hand-placed 20 px under the last row — at 1 row (pulled up
 * ~450 px from where it was drawn) and at 3 rows.
 *
 * The hand design also drops the rows root's `spaceAfter` (75): unlinked,
 * that clearance would collision-push the hand-placed footer; linked, the
 * link's gap replaces it.
 *
 * `vendor/bin/phpunit --group gotenberg --filter ContainerFollowsRender`
 *
 * @covers \WBoost\Web\Services\Editor\TemplateVariantImageRenderer
 */
#[Group('gotenberg')]
final class ContainerFollowsRenderTest extends KernelTestCase
{
    private const string ROOT = '22ddf6ee-8309-45d5-8664-fe8533e14e4f';
    private const string ROW = '88eb8d49-2bc3-42d0-a6a4-ebdce5c65d7b';
    private const array FOOTER_MEMBERS = ['318d2075-13cf-4cfb-aaf0-d6ccacda8e26', '812fbf9c-19b2-4d1b-9d10-20d5d373dfd7'];
    /** Designed top of the footer's first member. */
    private const float FOOTER_DESIGNED_TOP = 859.678;
    /** Engine-measured (ContainerFollowsLayoutTest): last row's flow bottom + 20. */
    private const array FOOTER_LINKED_TOP = [1 => 410.213, 3 => 539.483];

    public function testAttachedFooterRendersLikeTheHandPlacedOne(): void
    {
        $template = $this->template();
        foreach (self::FOOTER_LINKED_TOP as $count => $top) {
            $overrides = (new ResolvedInputOverrides([], []))->withRowCounts([self::ROW => $count]);
            $linked = $this->render($this->variant($template, false, 0.0), $overrides);
            $hand = $this->render($this->variant($template, true, $top - self::FOOTER_DESIGNED_TOP), $overrides);

            self::assertLessThan(1e-9, self::mse($linked, $hand), sprintf('attached footer ≠ hand-placed footer at %d rows', $count));
        }

        // Positive control: the unlinked design (footer where it was drawn) differs.
        $overrides = (new ResolvedInputOverrides([], []))->withRowCounts([self::ROW => 1]);
        self::assertGreaterThan(1e-6, self::mse(
            $this->render($this->variant($template, false, 0.0), $overrides),
            $this->render($this->variant($template, true, 0.0), $overrides),
        ));
    }

    private function variant(Template $template, bool $hand, float $footerShift): TemplateVariant
    {
        $raw = ContainerFixtures::raw('turnaj-follows');
        if ($hand) {
            /** @var list<array<string, mixed>> $containers */
            $containers = $raw['canvas']['containers'];
            foreach ($containers as $i => $container) {
                unset($containers[$i]['follows']);
                if ($container['id'] === self::ROOT) {
                    unset($containers[$i]['spaceAfter']);
                }
            }
            $raw['canvas']['containers'] = $containers;

            /** @var list<array<string, mixed>> $objects */
            $objects = $raw['canvas']['objects'];
            foreach ($objects as $i => $object) {
                if (in_array($object['inputId'] ?? null, self::FOOTER_MEMBERS, true) && is_numeric($object['top'] ?? null)) {
                    $objects[$i]['top'] = (float) $object['top'] + $footerShift;
                }
            }
            $raw['canvas']['objects'] = $objects;
        }

        return RepeatableRows::expand(ContainerFixtures::variantFromRaw(['canvas' => $raw['canvas'], 'inputs' => $raw['inputs'], 'imageInputs' => $raw['imageInputs']], $template, $raw['width'], $raw['height']));
    }

    private function template(): Template
    {
        self::bootKernel();

        return self::getContainer()->get(TemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID))
            ->template;
    }

    private function render(TemplateVariant $variant, ResolvedInputOverrides $overrides): Imagick
    {
        $png = $this->realRenderer()->renderToBytes($variant, $overrides, ResolvedImageOverrides::none());

        $image = new Imagick();
        $image->readImageBlob($png);

        return $image;
    }

    private static function mse(Imagick $a, Imagick $b): float
    {
        self::assertSame([$a->getImageWidth(), $a->getImageHeight()], [$b->getImageWidth(), $b->getImageHeight()]);
        $result = $a->compareImages($b, Imagick::METRIC_MEANSQUAREERROR);

        return (float) $result[1];
    }

    private function realRenderer(): TemplateVariantImageRenderer
    {
        $container = self::getContainer();
        $geometry = new CanvasPlaceholderGeometry();
        $projectDir = (string) $container->getParameter('kernel.project_dir');

        return new TemplateVariantImageRenderer(
            $container->get(GotenbergScreenshotInterface::class),
            $container->get(GetFonts::class),
            $container->get(AssetInliner::class),
            $geometry,
            new TextInputObjectBinder($geometry),
            new ImagePlacement(),
            $container->get(UploaderHelper::class),
            $projectDir . '/assets/fabric/fabric-7.3.1.min.js',
            $projectDir . '/assets/editor/fabric_break_word.js',
            $projectDir . '/assets/editor/container_layout.js',
            $projectDir . '/assets/editor/rich_text_runs.js',
            $projectDir . '/assets/editor/rich_text_blocks.js',
            new TagAwareAdapter(new ArrayAdapter()),
        );
    }
}
