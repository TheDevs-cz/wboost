<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Golden;

use Imagick;
use ImagickDraw;
use ImagickPixel;
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
use WBoost\Web\Value\ResolvedImageOverride;
use WBoost\Web\Value\ResolvedImageOverrides;
use WBoost\Web\Value\ResolvedInputOverrides;

/**
 * Real-pixel half of plan P2a (image slots as container members), through
 * the actual render template + Fabric + container_layout.js in Gotenberg's
 * Chromium (the engine-only half is {@see ContainerImageSlotLayoutTest}):
 *
 * - a title above the slot wraps from 1 to 3 lines (+113 px): the FILLED
 *   slot moves down by exactly that and its picture — zoomed and panned by
 *   the user — stays where it was INSIDE the frame (ImagePlacement bakes the
 *   picture transform + an absolute frame clip; the template's frame proxy
 *   must translate both);
 * - the unfilled stand-in moves the same way;
 * - a hidden member slot collapses (fill-time, like a hidden text): under a
 *   uniform gap it renders exactly like the design WITHOUT the slot.
 *
 * `vendor/bin/phpunit --group gotenberg --filter ContainerImageSlotRender`
 *
 * @covers \WBoost\Web\Services\Editor\TemplateVariantImageRenderer
 */
#[Group('gotenberg')]
final class ContainerImageSlotRenderTest extends KernelTestCase
{
    /** The frame (x 40, y 140, 200×200) and where 2 extra title lines put it. */
    private const int FRAME_X = 40;
    private const int FRAME_Y = 140;
    private const int FRAME_SIZE = 200;
    private const int SHIFT = 113;

    public function testAFilledSlotMovesWithTheFlowAndKeepsItsPictureAlignedInTheFrame(): void
    {
        $variant = ContainerFixtures::slotVariant($this->template(), self::standIn());
        // Zoomed past the frame and panned: the clip cuts a specific window
        // of the picture, which must be the same window after the move.
        $picture = new ResolvedImageOverrides([ContainerFixtures::SLOT_IMAGE => new ResolvedImageOverride(
            self::picture(), 300, 150, 1.6, 18.0, -14.0, 0.0,
        )], []);

        $short = $this->render($variant, ['A'], $picture);
        $long = $this->render($variant, ["A\nA\nA"], $picture);

        $designedCrop = self::crop($short, self::FRAME_Y);
        $movedCrop = self::crop($long, self::FRAME_Y + self::SHIFT);

        self::assertLessThan(1e-7, self::mse($designedCrop, $movedCrop), 'the picture is not where it was inside its moved frame');
        self::assertGreaterThan(1e-3, self::mse(self::crop($short, self::FRAME_Y + self::SHIFT), $movedCrop), 'positive control: the slot really moved');
        // The old frame band above the moved slot is blank page now (the
        // clip moved with it — a stale clip would have left the top of the
        // picture painted there and cut the moved one).
        self::assertLessThan(1e-7, self::mse(self::crop($long, self::FRAME_Y, self::SHIFT), self::blank(self::SHIFT)), 'something stayed at the old frame position');
    }

    public function testAnUnfilledStandInMovesWithTheFlow(): void
    {
        $variant = ContainerFixtures::slotVariant($this->template(), self::standIn());

        $short = $this->render($variant, ['A'], ResolvedImageOverrides::none());
        $long = $this->render($variant, ["A\nA\nA"], ResolvedImageOverrides::none());

        self::assertLessThan(1e-7, self::mse(self::crop($short, self::FRAME_Y), self::crop($long, self::FRAME_Y + self::SHIFT)));
        self::assertGreaterThan(1e-3, self::mse(self::crop($short, self::FRAME_Y + self::SHIFT), self::crop($long, self::FRAME_Y + self::SHIFT)), 'positive control');
    }

    public function testAHiddenMemberSlotRendersLikeNoSlot(): void
    {
        $template = $this->template();
        $hide = new ResolvedImageOverrides([], [ContainerFixtures::SLOT_IMAGE => true]);

        $hidden = $this->render(ContainerFixtures::slotVariant($template, self::standIn(), gap: 30.0), ['A'], $hide);
        $absent = $this->render(ContainerFixtures::slotVariant($template, self::standIn(), withSlot: false, gap: 30.0), ['A'], ResolvedImageOverrides::none());
        $shown = $this->render(ContainerFixtures::slotVariant($template, self::standIn(), gap: 30.0), ['A'], ResolvedImageOverrides::none());

        self::assertLessThan(1e-9, self::mse($hidden, $absent), 'hidden slot ≠ absent slot');
        self::assertGreaterThan(1e-6, self::mse($hidden, $shown), 'positive control: hiding the slot must change the pixels');
    }

    /**
     * Under DESIGNED gaps a user-hidden slot collapses the way a hidden text
     * does (the item goes, the designed gap AFTER it stays: the caption lands
     * 40 px under the title, at 136.5) — NOT like a design-hidden / absent
     * object (which would keep the title→caption designed distance). This is
     * what the fill overlay's engine run predicts, so render and overlay agree.
     */
    public function testAHiddenMemberSlotCollapsesLikeAHiddenTextUnderDesignedGaps(): void
    {
        $template = $this->template();
        $hide = new ResolvedImageOverrides([], [ContainerFixtures::SLOT_IMAGE => true]);

        $hidden = $this->render(ContainerFixtures::slotVariant($template, self::standIn()), ['A'], $hide);
        $collapsed = $this->render(ContainerFixtures::slotVariant($template, self::standIn(), withSlot: false, captionTop: 136.5), ['A'], ResolvedImageOverrides::none());

        self::assertLessThan(1e-9, self::mse($hidden, $collapsed));
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
     * @param array{0: string} $title the title value
     */
    private function render(TemplateVariant $variant, array $title, ResolvedImageOverrides $images): Imagick
    {
        $png = $this->realRenderer()->renderToBytes(
            $variant,
            new ResolvedInputOverrides([ContainerFixtures::SLOT_TITLE => $title[0]], []),
            $images,
        );

        $image = new Imagick();
        $image->readImageBlob($png);

        return $image;
    }

    /** The slot column (x 40…240) from `$top`, `$height` px tall. */
    private static function crop(Imagick $image, int $top, int $height = self::FRAME_SIZE): Imagick
    {
        $crop = clone $image;
        $crop->cropImage(self::FRAME_SIZE, $height, self::FRAME_X, $top);
        $crop->setImagePage(0, 0, 0, 0);

        return $crop;
    }

    private static function blank(int $height): Imagick
    {
        $blank = new Imagick();
        $blank->newImage(self::FRAME_SIZE, $height, new ImagickPixel('#ffffff'));
        $blank->setImageFormat('png');

        return $blank;
    }

    /** 100×100 grey stand-in with a black bar near the top (asymmetric). */
    private static function standIn(): string
    {
        $image = new Imagick();
        $image->newImage(100, 100, new ImagickPixel('#9a9a9a'));
        $draw = new ImagickDraw();
        $draw->setFillColor(new ImagickPixel('#000000'));
        $draw->rectangle(10, 10, 60, 30);
        $image->drawImage($draw);
        $image->setImageFormat('png');

        return 'data:image/png;base64,' . base64_encode($image->getImageBlob());
    }

    /** 300×150: red left half, blue right half, a green square top-left. */
    private static function picture(): string
    {
        $image = new Imagick();
        $image->newImage(300, 150, new ImagickPixel('#d02020'));
        $draw = new ImagickDraw();
        $draw->setFillColor(new ImagickPixel('#2030d0'));
        $draw->rectangle(150, 0, 299, 149);
        $draw->setFillColor(new ImagickPixel('#20b040'));
        $draw->rectangle(20, 20, 70, 70);
        $image->drawImage($draw);
        $image->setImageFormat('png');

        return 'data:image/png;base64,' . base64_encode($image->getImageBlob());
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
