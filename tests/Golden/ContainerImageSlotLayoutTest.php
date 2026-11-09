<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Golden;

use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\SocialNetwork\FillTextPlaceholders;
use WBoost\Web\Tests\ContainerFixtures;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;

/**
 * Container-rows plan P2a: a fillable image SLOT is a container member — the
 * REAL shared engine (assets/editor/container_layout.js, run in node through
 * tests/Fixtures/containers/layout-harness.cjs, the fill overlay's POJO
 * path) flows it as a fixed box: pushed by a growing text above, pushing
 * what is below, collapsing when hidden (exactly like an absent slot), and
 * riding along with — and collapsing with — a text it overlaps.
 *
 * The real-pixel half (Gotenberg, the render template's frame proxy) is
 * {@see ContainerImageSlotRenderTest}.
 *
 * @covers \WBoost\Web\Services\SocialNetwork\FillTextPlaceholders
 */
final class ContainerImageSlotLayoutTest extends KernelTestCase
{
    private const string STAND_IN = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    public function testTheSlotShipsAsAFlaggedFixedBoxInTheLayoutData(): void
    {
        $layout = $this->layoutData(ContainerFixtures::slotVariant($this->template(), self::STAND_IN));

        self::assertSame([
            ContainerFixtures::SLOT_IMAGE => [
                'frame' => ['x' => 40.0, 'y' => 140.0, 'width' => 200.0, 'height' => 200.0],
                'slot' => true,
            ],
        ], $layout['decorations']);
    }

    public function testAGrowingTextAboveMovesTheSlotAndTheSlotPushesTheTextBelow(): void
    {
        $layout = $this->layoutData(ContainerFixtures::slotVariant($this->template(), self::STAND_IN));

        $designed = $this->runEngine($layout);
        self::assertSame(['top' => 140, 'visible' => true], $designed['objects'][ContainerFixtures::SLOT_IMAGE]);

        // The title wraps to three lines: +2 lines = +113 px for everything below.
        $grown = $this->runEngine($layout, [ContainerFixtures::SLOT_TITLE => 3 * ContainerFixtures::SLOT_LINE]);
        self::assertSame(['top' => 253, 'visible' => true], $grown['objects'][ContainerFixtures::SLOT_IMAGE]);
        self::assertSame(493, $this->tops($grown)[ContainerFixtures::SLOT_CAPTION]);
    }

    /**
     * Under a uniform gap (the repeatable-rows set-up) a hidden slot is
     * indistinguishable from an absent one — the invariant P0 pinned for
     * hidden text rows, now for a slot.
     */
    public function testAHiddenSlotCollapsesLikeAnAbsentSlot(): void
    {
        $template = $this->template();

        $hidden = $this->runEngine(
            $this->layoutData(ContainerFixtures::slotVariant($template, self::STAND_IN, gap: 30.0)),
            [],
            [ContainerFixtures::SLOT_IMAGE],
        );
        $absent = $this->runEngine($this->layoutData(ContainerFixtures::slotVariant($template, self::STAND_IN, withSlot: false, gap: 30.0)));

        self::assertFalse($hidden['objects'][ContainerFixtures::SLOT_IMAGE]['visible']);
        self::assertSame($this->tops($absent), $this->tops($hidden));
        self::assertLessThan(380, $this->tops($hidden)[ContainerFixtures::SLOT_CAPTION], 'positive control: the caption moved up into the slot\'s space');
    }

    /** Designed gaps: the hidden slot's item goes, the gap after it stays. */
    public function testAHiddenSlotUnderDesignedGapsCollapsesLikeAHiddenText(): void
    {
        $hidden = $this->runEngine(
            $this->layoutData(ContainerFixtures::slotVariant($this->template(), self::STAND_IN)),
            [],
            [ContainerFixtures::SLOT_IMAGE],
        );

        self::assertSame(136.5, $this->tops($hidden)[ContainerFixtures::SLOT_CAPTION]);
    }

    public function testASlotOverlappingATextRidesAlongAndCollapsesWithIt(): void
    {
        // Slot at y 360…560 overlaps the caption (380…436.5) → its attachment.
        $layout = $this->layoutData(ContainerFixtures::slotVariant($this->template(), self::STAND_IN, slotTop: 360.0));

        $grown = $this->runEngine($layout, [ContainerFixtures::SLOT_TITLE => 3 * ContainerFixtures::SLOT_LINE]);
        self::assertSame(['top' => 473, 'visible' => true], $grown['objects'][ContainerFixtures::SLOT_IMAGE], 'rides along with the caption (+113)');

        $captionHidden = $this->runEngine($layout, [], [ContainerFixtures::SLOT_CAPTION]);
        self::assertFalse($captionHidden['objects'][ContainerFixtures::SLOT_IMAGE]['visible'], 'collapses with its host text');
    }

    // --- helpers -----------------------------------------------------------

    private function template(): Template
    {
        self::bootKernel();

        return self::getContainer()->get(TemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID))
            ->template;
    }

    /**
     * @return array<string, mixed>
     */
    private function layoutData(TemplateVariant $variant): array
    {
        return self::getContainer()->get(FillTextPlaceholders::class)->layoutData($variant);
    }

    /**
     * @param array<string, mixed> $layout
     * @param array<string, float> $heights
     * @param list<string> $hidden
     * @return array{results: list<array<string, mixed>>, objects: array<string, array{top: float|int, visible: bool}>}
     */
    private function runEngine(array $layout, array $heights = [], array $hidden = []): array
    {
        $node = (new ExecutableFinder())->find('node');
        if ($node === null) {
            self::markTestSkipped('node is not installed');
        }

        $projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
        $process = new Process([$node, $projectDir . '/tests/Fixtures/containers/layout-harness.cjs']);
        $process->setInput(json_encode([
            'engine' => $projectDir . '/assets/editor/container_layout.js',
            'layout' => $layout,
            'heights' => (object) $heights,
            'hidden' => $hidden,
            'reportObjects' => true,
        ], JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var array{results: list<array<string, mixed>>, objects: array<string, array{top: float|int, visible: bool}>} $output */
        $output = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $output;
    }

    /**
     * inputId → final top of every text in a root's flow.
     *
     * @param array{results: list<array<string, mixed>>, objects: array<string, array{top: float|int, visible: bool}>} $output
     * @return array<string, null|float|int>
     */
    private function tops(array $output): array
    {
        $tops = [];
        foreach ($output['results'] as $result) {
            /** @var list<array{inputId: string, top: null|float|int}> $flow */
            $flow = $result['textFlow'] ?? [];
            foreach ($flow as $entry) {
                $tops[$entry['inputId']] = $entry['top'];
            }
        }
        ksort($tops);

        return $tops;
    }
}
