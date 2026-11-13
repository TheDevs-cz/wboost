<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Calendar;

use Doctrine\ORM\EntityManagerInterface;
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

    /**
     * The candidate's picture goes to the AI provider: what the customer put
     * into the calendar must not be in it. Pixel for pixel, a calendar full
     * of content gives the candidate an empty one gives.
     */
    public function testACandidateShowsNothingOfTheCalendarsContent(): void
    {
        $container = self::getContainer();
        $calendars = $container->get(CalendarRepository::class);
        $entityManager = $container->get(EntityManagerInterface::class);
        $calendar = $calendars->get(Uuid::fromString(TestDataFixture::CALENDAR_1_ID));
        $candidate = new CalendarCandidate(
            $container->get(CalendarModelBridge::class),
            $container->get(CalendarCandidatePicture::class),
            $container->get(CalendarDocument::class),
        );
        $commands = [
            ['op' => 'preset', 'preset' => 'photoLeft'],
            ['op' => 'configure', 'features' => ['events' => true]],
        ];

        $empty = $candidate->render($calendar, $commands);
        self::assertFalse($empty['blocked'], (string) json_encode($empty['issues']));

        $entityManager->getConnection()->executeStatement(
            'UPDATE calendar SET content = CAST(:content AS JSONB) WHERE id = :id',
            ['id' => TestDataFixture::CALENDAR_1_ID, 'content' => json_encode([
                'v' => 1,
                'events' => [
                    'e1' => ['date' => '2027-01-11', 'name' => 'Schůze zastupitelstva obce', 'club' => 'c1', 'icon' => null],
                    'e2' => ['date' => '2027-01-14', 'name' => 'Hasičský ples v sokolovně', 'club' => null, 'icon' => null],
                ],
                'clubs' => ['c1' => ['code' => 'SDH', 'name' => 'Sbor dobrovolných hasičů', 'pos' => 0]],
                'names' => ['n1' => ['date' => '2027-01-13', 'name' => 'Bohumil Novák']],
                'pageCaptions' => ['w02' => ['text' => 'Pohled na náves od kostela']],
            ])],
        );
        $entityManager->refresh($calendar);
        self::assertArrayHasKey('events', $calendar->content);

        $full = $candidate->render($calendar, $commands);
        self::assertFalse($full['blocked'], (string) json_encode($full['issues']));
        self::assertIsString($empty['bytes']);
        self::assertIsString($full['bytes']);
        self::assertSame(0.0, self::difference($empty['bytes'], $full['bytes']), 'the same pixels with and without the calendar\'s content');

        // The ordinary render of that week DOES show it — the content is real and drawable.
        $renderer = $container->get(CalendarPageRenderer::class);
        $document = $container->get(CalendarDocument::class)->calendar($calendar);
        $design = json_decode((string) json_encode($empty['answer']['layout']), false);
        self::assertInstanceOf(\stdClass::class, $design);
        $document['layout'] = $design;
        $document['preset'] = 'photoLeft';
        $document['features'] = (object) (['events' => true] + (array) $document['features']);
        $own = $renderer->renderDocument($calendar, $document, 'w02', sampleTexts: true, longEdge: CalendarCandidatePicture::LONG_EDGE, format: RenderImageFormat::Webp);
        self::assertGreaterThan(0.0005, self::difference($empty['bytes'], $own->bytes), 'the calendar\'s own render differs: its events, names and caption are drawn');
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
