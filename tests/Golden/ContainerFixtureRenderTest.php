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
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Tests\ContainerFixtures;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\ResolvedImageOverrides;
use WBoost\Web\Value\ResolvedInputOverrides;

/**
 * Real-pixel half of the container-rows safety net (P0): on the two prod
 * fixtures, a render with a nested row (or a column slot) HIDDEN is
 * pixel-identical to a render of the same design WITHOUT that row — the
 * invariant "materialise max rows, hide the inactive ones" rests on, proven
 * through the actual render template + Fabric + container_layout.js in
 * Gotenberg's Chromium (the engine-only half is ContainerFixtureLayoutTest).
 *
 * `vendor/bin/phpunit --group gotenberg --filter ContainerFixtureRender`
 *
 * @covers \WBoost\Web\Services\Editor\TemplateVariantImageRenderer
 */
#[Group('gotenberg')]
final class ContainerFixtureRenderTest extends KernelTestCase
{
    public function testAHiddenTournamentRowRendersLikeNoRow(): void
    {
        $template = $this->template();

        $hidden = $this->render(ContainerFixtures::variant(ContainerFixtures::TURNAJ, $template), [
            ContainerFixtures::TURNAJ_SKORE_3 => true,
            ContainerFixtures::TURNAJ_SOUPER_3 => true,
        ]);
        $absent = $this->render(ContainerFixtures::variantWithoutContainer(ContainerFixtures::TURNAJ, $template, ContainerFixtures::TURNAJ_ROW_3), []);
        $designed = $this->render(ContainerFixtures::variant(ContainerFixtures::TURNAJ, $template), []);

        self::assertLessThan(1e-9, self::mse($hidden, $absent), 'hidden row ≠ absent row');
        self::assertGreaterThan(1e-6, self::mse($hidden, $designed), 'positive control: hiding the row must change the pixels');
    }

    public function testAHiddenScorerRendersLikeNoScorer(): void
    {
        $template = $this->template();
        $id = ContainerFixtures::VYSLEDEK_D_STRELEC_3;

        $hidden = $this->render(ContainerFixtures::variant(ContainerFixtures::VYSLEDEK, $template), [$id => true]);
        $absent = $this->render(ContainerFixtures::variant(
            ContainerFixtures::VYSLEDEK,
            $template,
            static function (array $canvas) use ($id): array {
                /** @var list<array<string, mixed>> $objects */
                $objects = $canvas['objects'];
                $canvas['objects'] = array_values(array_filter($objects, static fn (array $o): bool => ($o['inputId'] ?? null) !== $id));
                /** @var list<array{memberInputIds: list<string>}> $containers */
                $containers = $canvas['containers'];
                foreach ($containers as $i => $container) {
                    $containers[$i]['memberInputIds'] = array_values(array_diff($container['memberInputIds'], [$id]));
                }
                $canvas['containers'] = $containers;

                return $canvas;
            },
            [$id],
        ), []);
        $designed = $this->render(ContainerFixtures::variant(ContainerFixtures::VYSLEDEK, $template), []);

        self::assertLessThan(1e-9, self::mse($hidden, $absent), 'hidden scorer ≠ absent scorer');
        self::assertGreaterThan(1e-6, self::mse($hidden, $designed), 'positive control');
    }

    private function template(): Template
    {
        self::bootKernel();

        return self::getContainer()->get(TemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID))
            ->template;
    }

    /**
     * @param array<string, bool> $hidden
     */
    private function render(TemplateVariant $variant, array $hidden): Imagick
    {
        $png = $this->realRenderer()->renderToBytes(
            $variant,
            new ResolvedInputOverrides([], $hidden),
            ResolvedImageOverrides::none(),
        );

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
