<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Calendar;

use PHPUnit\Framework\Attributes\Group;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Repository\CalendarRepository;
use WBoost\Web\Services\AiScan\Calendar\CalendarCandidate;
use WBoost\Web\Services\AiScan\Calendar\CalendarCandidatePicture;
use WBoost\Web\Services\Calendar\CalendarDocument;
use WBoost\Web\Services\Calendar\CalendarModelBridge;
use WBoost\Web\Services\Calendar\CalendarPageRenderer;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\RenderImageFormat;

/**
 * The render of an UNSAVED calendar document (S1, architecture.md §4.8)
 * through the real Gotenberg:
 *
 * - for the stored document it is the picture `render()` gives;
 * - a candidate of an AI scan is drawn from model commands without the
 *   calendar changing.
 *
 *     docker compose exec web vendor/bin/phpunit --group gotenberg --filter CalendarRenderDocument
 *
 * @covers \WBoost\Web\Services\Calendar\CalendarPageRenderer
 * @covers \WBoost\Web\Services\AiScan\Calendar\CalendarCandidate
 */
#[Group('gotenberg')]
final class CalendarRenderDocumentGotenbergTest extends KernelTestCase
{
    public function testTheStoredDocumentRendersAsTheStoredCalendar(): void
    {
        $container = self::getContainer();
        $calendar = $container->get(CalendarRepository::class)->get(Uuid::fromString(TestDataFixture::CALENDAR_2_ID));
        $renderer = $container->get(CalendarPageRenderer::class);

        $stored = $renderer->render($calendar, 'w02', sampleTexts: true, longEdge: 800, format: RenderImageFormat::Png);
        $document = $renderer->renderDocument($calendar, $container->get(CalendarDocument::class)->calendar($calendar), 'w02', sampleTexts: true, longEdge: 800, format: RenderImageFormat::Png);

        self::assertSame([$stored->width, $stored->height, $stored->view, $stored->page], [$document->width, $document->height, $document->view, $document->page]);
        self::assertSame(0.0, self::difference($stored->bytes, $document->bytes), 'the same pixels');
    }

    public function testACandidateIsDrawnFromCommandsAndTheCalendarStaysAsItWas(): void
    {
        $container = self::getContainer();
        $calendar = $container->get(CalendarRepository::class)->get(Uuid::fromString(TestDataFixture::CALENDAR_1_ID));
        $candidate = new CalendarCandidate(
            $container->get(CalendarModelBridge::class),
            $container->get(CalendarCandidatePicture::class),
            $container->get(CalendarDocument::class),
        );

        $navy = $candidate->render($calendar, [
            ['op' => 'preset', 'preset' => 'photoLeft'],
            ['op' => 'layout', 'styles' => ['head' => ['bg' => ['color' => '#1F3A5F']]]],
        ]);
        $plain = $candidate->render($calendar, [['op' => 'preset', 'preset' => 'photoLeft']]);

        self::assertFalse($navy['blocked'], (string) json_encode($navy['issues']));
        self::assertSame([1600, 748], [$navy['width'], $navy['height']]);
        self::assertIsString($navy['bytes']);
        self::assertIsString($plain['bytes']);
        self::assertGreaterThan(0.001, self::difference($navy['bytes'], $plain['bytes']), 'the header colour of the commands is in the picture');

        // Nothing was saved.
        self::assertNull($calendar->layout);
        self::assertSame(0, $calendar->revision);
    }

    /** Mean absolute difference of two pictures of the same size, 0…1. */
    private static function difference(string $a, string $b): float
    {
        $first = new \Imagick();
        $first->readImageBlob($a);
        $second = new \Imagick();
        $second->readImageBlob($b);

        self::assertSame([$first->getImageWidth(), $first->getImageHeight()], [$second->getImageWidth(), $second->getImageHeight()]);

        /** @var array{0: \Imagick, 1: float} $result */
        $result = $first->compareImages($second, \Imagick::METRIC_MEANABSOLUTEERROR);

        return $result[1];
    }
}
