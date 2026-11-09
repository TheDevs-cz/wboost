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
 * Real pixels for repeatable rows (container-rows plan P2): "Výsledky
 * turnaje" re-authored as ONE repeatable row (max 5) and rendered at count N
 * through the actual render template + Fabric + container_layout.js in
 * Gotenberg's Chromium equals the prod 5-row hand-numbered design cut to N
 * rows, given the same texts per row. Same for the "Výsledek" single-text
 * scorer columns.
 *
 * Expected deltas: none. The hand design's only per-row difference besides
 * texts and samples — its "Skóre 3" box is 108.2 px wide where every other
 * score box (and so every materialised row) is 150 — paints nothing
 * different (a short, left-aligned score fits either box). The Výsledek
 * columns carry one designed nudge (H-Střelec 2 sits 1.85 px right of
 * H-Střelec 1, which the materialised rows copy) — normalised below.
 *
 * `vendor/bin/phpunit --group gotenberg --filter ContainerRowsRender`
 *
 * @covers \WBoost\Web\Services\Template\RepeatableRows
 * @covers \WBoost\Web\Services\Editor\TemplateVariantImageRenderer
 */
#[Group('gotenberg')]
final class ContainerRowsRenderTest extends KernelTestCase
{
    public function testFiveRepeatedRowsRenderLikeTheHandNumberedFiveRows(): void
    {
        $template = $this->template();
        [$repeated, $hand] = $this->turnajPair($template, 5);

        $rows = $this->render($this->turnajRepeatable($template), $repeated);
        $handFive = $this->render($this->handTurnaj($template, 5), $hand);

        self::assertLessThan(1e-9, self::mse($rows, $handFive), '5 repeated rows ≠ the prod hand-numbered design');
    }

    public function testThreeRepeatedRowsRenderLikeTheHandDesignCutToThreeRows(): void
    {
        $template = $this->template();
        [$repeated, $hand] = $this->turnajPair($template, 3);

        $rows = $this->render($this->turnajRepeatable($template), $repeated);
        $handThree = $this->render($this->handTurnaj($template, 3), $hand);
        self::assertLessThan(1e-9, self::mse($rows, $handThree), '3 repeated rows ≠ the hand design cut to 3');

        // Positive control: one more row changes the pixels.
        [$four] = $this->turnajPair($template, 4);
        self::assertGreaterThan(1e-6, self::mse($rows, $this->render($this->turnajRepeatable($template), $four)));
    }

    public function testRepeatedSingleTextScorersRenderLikeTheHandColumns(): void
    {
        $template = $this->template();
        /** @var list<array{memberInputIds: list<string>}> $definitions */
        $definitions = ContainerFixtures::raw(ContainerFixtures::VYSLEDEK)['canvas']['containers'];
        $columns = [
            ContainerFixtures::VYSLEDEK_D_ROW => $definitions[0]['memberInputIds'],
            ContainerFixtures::VYSLEDEK_H_ROW => $definitions[1]['memberInputIds'],
        ];

        $repeatedTexts = [];
        $handTexts = [];
        $dropped = [];
        foreach ($columns as $rowId => $scorers) {
            foreach ($scorers as $k => $scorerId) {
                if ($k >= 2) {
                    $dropped[] = $scorerId;
                    continue;
                }
                $repeatedTexts[RepeatableRows::slotId($rowId, $scorers[0], $k)] = sprintf('Střelec %d', $k + 1);
                $handTexts[$scorerId] = sprintf('Střelec %d', $k + 1);
            }
        }

        $repeated = RepeatableRows::expand(ContainerFixtures::variantFromRaw(ContainerFixtures::vysledekRepeatableRaw(max: 4), $template));
        $rows = $this->render($repeated, (new ResolvedInputOverrides($repeatedTexts, []))->withRowCounts([
            ContainerFixtures::VYSLEDEK_D_ROW => 2,
            ContainerFixtures::VYSLEDEK_H_ROW => 2,
        ]));

        $hand = ContainerFixtures::variant(
            ContainerFixtures::VYSLEDEK,
            $template,
            static function (array $canvas) use ($dropped): array {
                /** @var list<array<string, mixed>> $objects */
                $objects = $canvas['objects'];
                $canvas['objects'] = array_values(array_filter($objects, static fn (array $o): bool => !in_array($o['inputId'] ?? null, $dropped, true)));
                /** @var list<array{memberInputIds: list<string>}> $containers */
                $containers = $canvas['containers'];
                foreach ($containers as $i => $container) {
                    $containers[$i]['memberInputIds'] = array_values(array_diff($container['memberInputIds'], $dropped));
                }
                $canvas['containers'] = $containers;

                return $canvas;
            },
            $dropped,
        );
        // The hand H-Střelec 2 sits 1.85 px further right than H-Střelec 1
        // (584.30 vs 582.45 — a designed nudge); a materialised row copies
        // row 1. Normalise it like the score width above.
        $hand = $this->withLeft($hand, $columns[ContainerFixtures::VYSLEDEK_H_ROW][1], 582.4525);

        self::assertLessThan(1e-9, self::mse($rows, $this->render($hand, new ResolvedInputOverrides($handTexts, []))), '2 repeated scorers ≠ the hand columns');
    }

    // --- fixtures ----------------------------------------------------------

    /**
     * Per row k < $count the hand design's own texts, addressed to the
     * repeated row's derived ids (repeated) and to row k's ids (hand).
     *
     * @return array{ResolvedInputOverrides, ResolvedInputOverrides}
     */
    private function turnajPair(Template $template, int $count): array
    {
        $raw = ContainerFixtures::raw(ContainerFixtures::TURNAJ);
        /** @var list<array{id: string, memberInputIds: list<string>, memberContainerIds: list<string>}> $containers */
        $containers = $raw['canvas']['containers'];
        $byId = array_column($containers, null, 'id');
        $texts = [];
        /** @var list<array<string, mixed>> $objects */
        $objects = $raw['canvas']['objects'];
        foreach ($objects as $object) {
            if (is_string($object['inputId'] ?? null) && is_string($object['text'] ?? null)) {
                $texts[$object['inputId']] = $object['text'];
            }
        }

        $repeated = [];
        $hand = [];
        $template1 = $byId[ContainerFixtures::TURNAJ_ROW_1]['memberInputIds'];
        foreach (array_slice($byId[ContainerFixtures::TURNAJ_ROOT]['memberContainerIds'], 0, $count) as $k => $rowId) {
            foreach ($byId[$rowId]['memberInputIds'] as $i => $memberId) {
                $repeated[RepeatableRows::slotId(ContainerFixtures::TURNAJ_ROW_1, $template1[$i], $k)] = $texts[$memberId];
                $hand[$memberId] = $texts[$memberId];
            }
        }

        return [
            (new ResolvedInputOverrides($repeated, []))->withRowCounts([ContainerFixtures::TURNAJ_ROW_1 => $count]),
            new ResolvedInputOverrides($hand, []),
        ];
    }

    private function turnajRepeatable(Template $template): TemplateVariant
    {
        return RepeatableRows::expand(ContainerFixtures::variantFromRaw(ContainerFixtures::turnajRepeatableRaw(max: 5), $template));
    }

    private function handTurnaj(Template $template, int $count): TemplateVariant
    {
        /** @var list<array{id: string, memberContainerIds: list<string>}> $containers */
        $containers = ContainerFixtures::raw(ContainerFixtures::TURNAJ)['canvas']['containers'];
        $byId = array_column($containers, null, 'id');

        return $count === 5
            ? ContainerFixtures::variant(ContainerFixtures::TURNAJ, $template)
            : ContainerFixtures::variantWithoutContainers(ContainerFixtures::TURNAJ, $template, array_slice($byId[ContainerFixtures::TURNAJ_ROOT]['memberContainerIds'], $count));
    }

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

    // --- rendering ---------------------------------------------------------

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
