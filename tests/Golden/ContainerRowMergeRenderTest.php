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
use WBoost\Web\Tests\RowMergeFixtures;
use WBoost\Web\Value\ResolvedImageOverrides;
use WBoost\Web\Value\ResolvedInputOverrides;

/**
 * Real pixels for "Sloučit do opakovaného řádku" (container-rows P6): the
 * prod fixtures merged by the REAL editor module ({@see RowMergeFixtures}),
 * rendered through the actual render template + Fabric + container_layout.js
 * in Gotenberg's Chromium with the SAME fill the hand-numbered design got —
 * the old ids, untranslated — equal the hand-numbered design.
 *
 * Expected deltas: none for Výsledky turnaje (its narrower "Skóre 3" box
 * paints the same short score, see ContainerRowsRenderTest). Výsledek's
 * hosté scorers 2–4 sit 1.85 px right of scorer 1 in the hand design; the
 * merged rows take row 1's position (the editor lists that nudge in its
 * confirm) — normalised on the hand side below, like P2 does.
 *
 * `vendor/bin/phpunit --group gotenberg --filter ContainerRowMergeRender`
 *
 * @covers \WBoost\Web\Services\Template\RepeatableRows
 * @covers \WBoost\Web\Services\Editor\TemplateVariantImageRenderer
 */
#[Group('gotenberg')]
final class ContainerRowMergeRenderTest extends KernelTestCase
{
    public function testMergedTurnajRendersLikeTheHandDesign(): void
    {
        $template = $this->template();
        $texts = RowMergeFixtures::designedTexts(ContainerFixtures::TURNAJ);

        $merged = RepeatableRows::expand(ContainerFixtures::variantFromRaw(RowMergeFixtures::turnajMerged(), $template));
        $hand = ContainerFixtures::variant(ContainerFixtures::TURNAJ, $template);

        // No count sent: the merged row's default is N = 5.
        $five = $this->render($merged, new ResolvedInputOverrides($texts, []));
        self::assertLessThan(1e-9, self::mse($five, $this->render($hand, new ResolvedInputOverrides($texts, []))), 'merged 5 rows ≠ the hand design');

        // Cut to three rows: the old rows 4–5 disappear exactly like removing them.
        $three = $this->render($merged, (new ResolvedInputOverrides($texts, []))->withRowCounts([RowMergeFixtures::TURNAJ_ROWS[0] => 3]));
        $handThree = ContainerFixtures::variantWithoutContainers(ContainerFixtures::TURNAJ, $template, array_slice(RowMergeFixtures::TURNAJ_ROWS, 3));
        self::assertLessThan(1e-9, self::mse($three, $this->render($handThree, new ResolvedInputOverrides($texts, []))), 'merged 3 rows ≠ the hand design cut to 3');
        self::assertGreaterThan(1e-6, self::mse($three, $five), 'positive control');
    }

    public function testMergedVysledekRendersLikeTheHandColumns(): void
    {
        $template = $this->template();
        $texts = [];
        foreach (RowMergeFixtures::VYSLEDEK_D as $k => $id) {
            $texts[$id] = sprintf('%d" Domácí %d', 10 + $k, $k + 1);
        }
        foreach (RowMergeFixtures::VYSLEDEK_H as $k => $id) {
            $texts[$id] = sprintf('%d" Host %d', 50 + $k, $k + 1);
        }
        // A pre-merge fill that hid the fourth home scorer.
        $hidden = [RowMergeFixtures::VYSLEDEK_D[3] => true];

        $merged = RepeatableRows::expand(ContainerFixtures::variantFromRaw(RowMergeFixtures::vysledekMerged(), $template));
        $hand = ContainerFixtures::variant(ContainerFixtures::VYSLEDEK, $template);
        foreach (array_slice(RowMergeFixtures::VYSLEDEK_H, 1) as $id) {
            $hand = $this->withLeft($hand, $id, 582.4525);
        }

        self::assertLessThan(
            1e-9,
            self::mse($this->render($merged, new ResolvedInputOverrides($texts, $hidden)), $this->render($hand, new ResolvedInputOverrides($texts, $hidden))),
            'merged scorer columns ≠ the hand columns',
        );
    }

    // --- rendering ---------------------------------------------------------

    private function withLeft(TemplateVariant $variant, string $inputId, float $left): TemplateVariant
    {
        /** @var array{objects: list<array<string, mixed>>} $canvas */
        $canvas = json_decode($variant->canvas, true, flags: JSON_THROW_ON_ERROR);
        foreach ($canvas['objects'] as $i => $object) {
            if (($object['inputId'] ?? null) === $inputId) {
                $canvas['objects'][$i]['left'] = $left;
            }
        }
        $variant->editCanvas(json_encode($canvas, JSON_THROW_ON_ERROR), $variant->inputs, null, $variant->imageInputs);

        return $variant;
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
