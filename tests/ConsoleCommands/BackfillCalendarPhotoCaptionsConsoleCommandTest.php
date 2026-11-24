<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\ConsoleCommands;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Entity\CalendarAsset;
use WBoost\Web\MessageHandler\Calendar\BackfillCalendarPhotoCaptionsHandler;
use WBoost\Web\Repository\CalendarHistoryRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\Calendar\CalendarAssetKind;
use WBoost\Web\Value\Calendar\CalendarAuthorKind;

/**
 * Feedback K18: existing week photos with an empty caption get the caption a
 * new upload would get — the embedded description, else the file name by the
 * browser's own rule (`model/photo-caption.js`, run through node). Dry run by
 * default; a filled caption is never touched.
 *
 * @covers \WBoost\Web\ConsoleCommands\BackfillCalendarPhotoCaptionsConsoleCommand
 * @covers \WBoost\Web\MessageHandler\Calendar\BackfillCalendarPhotoCaptionsHandler
 */
final class BackfillCalendarPhotoCaptionsConsoleCommandTest extends KernelTestCase
{
    public function testDryRunListsAndApplyFillsOnlyEmptyCaptions(): void
    {
        $calendarId = TestDataFixture::CALENDAR_1_ID;
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $calendar = $em->find(Calendar::class, Uuid::fromString($calendarId));
        self::assertInstanceOf(Calendar::class, $calendar);

        $photo = fn (string $name, null|string $description = null): string => $this->asset($calendar, $name, $description);
        $content = $calendar->content;
        $content['pagePhotos'] = [
            'pre' => ['asset' => $photo('Freddy.jpeg'), 'zoom' => 1, 'px' => 0.5, 'py' => 0.5],
            'w01' => ['asset' => $photo('Foto.png'), 'zoom' => 1, 'px' => 0.5, 'py' => 0.5],
            'w02' => ['asset' => $photo('IMG_1234.jpg'), 'zoom' => 1, 'px' => 0.5, 'py' => 0.5],
            'w03' => ['asset' => $photo('scan.jpg', 'Kostel sv. Jana'), 'zoom' => 1, 'px' => 0.5, 'py' => 0.5],
            'w04' => ['asset' => $photo('Jaro.jpg'), 'zoom' => 1, 'px' => 0.5, 'py' => 0.5],
            'w05' => ['asset' => $photo('03_Babí_léto.jpg'), 'zoom' => 1, 'px' => 0.5, 'py' => 0.5],
        ];
        $content['pageCaptions'] = ['w04' => ['text' => 'Zima na návsi'], 'w05' => ['text' => '  ']];
        $calendar->replaceContent($content);
        $em->flush();
        $revision = $calendar->revision;

        // dry run (the default): listed, nothing written
        $tester = $this->tester();
        $tester->execute(['--calendar' => $calendarId]);
        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertStringContainsString('Freddy.jpeg', $display);
        self::assertStringContainsString('Dry run', $display);
        self::assertStringContainsString('4 caption(s) in 1 calendar(s)', $display);
        self::assertSame(['w04' => ['text' => 'Zima na návsi'], 'w05' => ['text' => '  ']], $this->reload($calendarId)->content['pageCaptions']);

        // apply
        $tester->execute(['--calendar' => $calendarId, '--apply' => true]);
        $tester->assertCommandIsSuccessful();
        $after = $this->reload($calendarId);
        self::assertSame([
            'pre' => ['text' => 'Freddy'],
            'w01' => ['text' => 'Foto'],
            'w03' => ['text' => 'Kostel sv. Jana'],
            'w04' => ['text' => 'Zima na návsi'],
            'w05' => ['text' => 'Babí léto'],
        ], self::sorted($after->content['pageCaptions']));
        self::assertSame($revision + 1, $after->revision);
        $newest = self::getContainer()->get(CalendarHistoryRepository::class)->latest(Uuid::fromString($calendarId), 1)[0];
        self::assertSame(BackfillCalendarPhotoCaptionsHandler::HISTORY_SENTENCE . ' (4)', $newest->description);
        self::assertSame(CalendarAuthorKind::Designer, $newest->authorKind);

        // a second run finds nothing
        $tester->execute(['--calendar' => $calendarId, '--apply' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('0 caption(s) in 0 calendar(s)', $tester->getDisplay());
        self::assertSame($revision + 1, $this->reload($calendarId)->revision);
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::bootKernel());

        return new CommandTester($application->find('app:calendar:backfill-photo-captions'));
    }

    private function reload(string $calendarId): Calendar
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $calendar = $em->find(Calendar::class, Uuid::fromString($calendarId));
        self::assertInstanceOf(Calendar::class, $calendar);

        return $calendar;
    }

    private function asset(Calendar $calendar, string $name, null|string $description): string
    {
        $id = Uuid::uuid7();
        self::getContainer()->get(EntityManagerInterface::class)->persist(new CalendarAsset(
            $id,
            $calendar,
            CalendarAssetKind::Photo,
            sprintf('calendars/%s/%s.jpg', $calendar->id->toString(), $id->toString()),
            null,
            'image/jpeg',
            $name,
            1600,
            1000,
            $description,
            5,
            CalendarAuthorKind::Designer,
            null,
            new \DateTimeImmutable(),
        ));

        return $id->toString();
    }

    /**
     * @param mixed $items
     *
     * @return array<mixed>
     */
    private static function sorted(mixed $items): array
    {
        self::assertIsArray($items);
        ksort($items);

        return $items;
    }
}
