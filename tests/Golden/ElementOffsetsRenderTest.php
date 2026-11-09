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
 * Real pixels for "Uživatel může posunout" (M2): the prod "Výsledky turnaje"
 * design rendered through the actual render template + Fabric +
 * container_layout.js in Gotenberg's Chromium.
 *
 * - The headline (a free text) moved 60 px left and the results ROOT
 *   container moved 250 px down render EXACTLY like the same design drawn at
 *   those positions — the bake is a plain translation that happens before
 *   the container layout, so the engine treats a moved root like a designed
 *   one.
 * - The moved root now reaches past the "Sestava" root's anchor, so the
 *   engine pushes "Sestava" down (sibling collision-push) — visible as the
 *   label leaving its designed band.
 * - Without offsets, a design carrying `userMove` renders pixel-identically
 *   to the same design without it (the allowance alone moves nothing).
 *
 * `vendor/bin/phpunit --group gotenberg --filter ElementOffsetsRender`
 *
 * @covers \WBoost\Web\Services\Editor\TemplateVariantImageRenderer
 * @covers \WBoost\Web\Services\SocialNetwork\ResolveElementOffsets
 */
#[Group('gotenberg')]
final class ElementOffsetsRenderTest extends KernelTestCase
{
    private const float DOWN = 250.0;
    private const float LEFT = -60.0;

    /** "Sestava:" label of the second root — its designed band, left of every row. */
    private const array SESTAVA_BAND = [112, 858, 140, 40];

    public function testAMovedTextAndAMovedRootRenderLikeTheDesignDrawnThere(): void
    {
        $template = $this->template();

        $moved = $this->render($this->movable($template), (new ResolvedInputOverrides([], []))->withOffsets([
            ContainerFixtures::TURNAJ_HEADLINE => ['x' => self::LEFT, 'y' => 0.0],
            ContainerFixtures::TURNAJ_ROOT => ['x' => 0.0, 'y' => self::DOWN],
        ]));
        $drawnThere = $this->render($this->drawnAtTheMovedPositions($template), new ResolvedInputOverrides([], []));
        $unmoved = $this->render(ContainerFixtures::variant(ContainerFixtures::TURNAJ, $template), new ResolvedInputOverrides([], []));

        self::assertLessThan(1e-9, self::mse($moved, $drawnThere), 'the moved render ≠ the design drawn at the moved positions');
        self::assertGreaterThan(1e-6, self::mse($moved, $unmoved), 'positive control: the move changes the pixels');

        // The push: "Sestava" left its designed band (only the rows' move
        // can have done that — the rows never paint at x < 267).
        self::assertGreaterThan(1e-4, self::mse(self::crop($moved, self::SESTAVA_BAND), self::crop($unmoved, self::SESTAVA_BAND)), 'the moved root pushes the lower root');
    }

    public function testAnAllowanceAloneMovesNothing(): void
    {
        $template = $this->template();

        self::assertLessThan(1e-9, self::mse(
            $this->render($this->movable($template), new ResolvedInputOverrides([], [])),
            $this->render(ContainerFixtures::variant(ContainerFixtures::TURNAJ, $template), new ResolvedInputOverrides([], [])),
        ));
    }

    // --- fixtures ----------------------------------------------------------

    private function movable(Template $template): TemplateVariant
    {
        return ContainerFixtures::variant(ContainerFixtures::TURNAJ, $template, static function (array $canvas): array {
            $any = static fn (bool $vertical): array => ['vertical' => $vertical, 'horizontal' => !$vertical, 'maxUp' => null, 'maxDown' => null, 'maxLeft' => null, 'maxRight' => null];
            /** @var list<array<string, mixed>> $containers */
            $containers = $canvas['containers'];
            foreach ($containers as $i => $container) {
                if ($container['id'] === ContainerFixtures::TURNAJ_ROOT) {
                    $containers[$i]['userMove'] = $any(true);
                }
            }
            $canvas['containers'] = $containers;
            /** @var list<array<string, mixed>> $objects */
            $objects = $canvas['objects'];
            foreach ($objects as $i => $object) {
                if (($object['inputId'] ?? null) === ContainerFixtures::TURNAJ_HEADLINE) {
                    $objects[$i]['userMove'] = $any(false);
                }
            }
            $canvas['objects'] = $objects;

            return $canvas;
        });
    }

    /**
     * The expectation, hand-made: the headline's `left` and every deep
     * member of the root (its rows' texts) translated in the stored JSON.
     */
    private function drawnAtTheMovedPositions(Template $template): TemplateVariant
    {
        return ContainerFixtures::variant(ContainerFixtures::TURNAJ, $template, static function (array $canvas): array {
            /** @var list<array{id: string, memberInputIds: list<string>, memberContainerIds?: list<string>}> $containers */
            $containers = $canvas['containers'];
            $byId = array_column($containers, null, 'id');
            $rootMembers = [];
            foreach ($byId[ContainerFixtures::TURNAJ_ROOT]['memberContainerIds'] ?? [] as $rowId) {
                array_push($rootMembers, ...$byId[$rowId]['memberInputIds']);
            }
            self::assertCount(10, $rootMembers, 'five rows × score + opponent');

            /** @var list<array{inputId?: string, left: float|int, top: float|int}> $objects */
            $objects = $canvas['objects'];
            foreach ($objects as $i => $object) {
                $id = $object['inputId'] ?? null;
                if ($id === ContainerFixtures::TURNAJ_HEADLINE) {
                    $objects[$i]['left'] = $object['left'] + self::LEFT;
                }
                if (in_array($id, $rootMembers, true)) {
                    $objects[$i]['top'] = $object['top'] + self::DOWN;
                }
            }
            $canvas['objects'] = $objects;

            return $canvas;
        });
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

    /**
     * @param array{int, int, int, int} $box x, y, width, height
     */
    private static function crop(Imagick $image, array $box): Imagick
    {
        $copy = clone $image;
        $copy->cropImage($box[2], $box[3], $box[0], $box[1]);
        $copy->setImagePage(0, 0, 0, 0);

        return $copy;
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
