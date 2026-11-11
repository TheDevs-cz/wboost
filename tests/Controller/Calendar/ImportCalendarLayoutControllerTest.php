<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Calendar;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Repository\CalendarAssetRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\Calendar\CalendarType;

/**
 * Nahrát layout: from another calendar of the project, or from a layout
 * file. Pictures are copied under new ids; the calendar row is not changed.
 *
 * @covers \WBoost\Web\Controller\Calendar\ImportCalendarLayoutController
 * @covers \WBoost\Web\Services\Calendar\ImportCalendarLayout
 * @covers \WBoost\Web\MessageHandler\Calendar\CopyCalendarAssetHandler
 */
final class ImportCalendarLayoutControllerTest extends WebTestCase
{
    use CalendarTesting;

    private const string SOURCE_URL = '/calendar/' . TestDataFixture::CALENDAR_2_ID;
    private const string TARGET_URL = '/calendar/' . TestDataFixture::CALENDAR_1_ID;
    private const string IMPORT_URL = self::TARGET_URL . '/layout-import';

    public function testAssetsAreCopiedToNewIdsUnderTheTargetCalendar(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);
        $token = self::csrf(self::bootstrap($browser, self::TARGET_URL));

        // The source calendar's layout uses an uploaded logo (large → with a preview).
        $logoId = $this->giveSourceALogo($browser, $token);

        $browser->request('POST', self::IMPORT_URL, ['_token' => $token, 'from' => TestDataFixture::CALENDAR_2_ID]);

        self::assertResponseIsSuccessful();
        $answer = self::json($browser);
        self::assertSame(['layout', 'assets', 'name'], array_keys($answer));
        self::assertSame('Obecní kalendář', $answer['name']);

        self::assertIsArray($answer['assets']);
        self::assertCount(1, $answer['assets']);
        $newId = (string) array_key_first($answer['assets']);
        self::assertNotSame($logoId, $newId);

        $newLogo = self::dig($answer, 'assets', $newId);
        self::assertIsArray($newLogo);
        self::assertSame('logo', $newLogo['kind']);
        self::assertSame(2000, $newLogo['width']);
        self::assertSame($newId, self::dig($answer, 'layout', 'styles', 'logo', 'asset'));
        // Everything else of the layout is the source's.
        self::assertSame(197, self::dig($answer, 'layout', 'boxes', 'cal', 'x'));

        $filesystem = self::getContainer()->get(Filesystem::class);
        $copy = self::getContainer()->get(CalendarAssetRepository::class)->get(Uuid::fromString($newId));
        self::assertSame(sprintf('calendars/%s/%s.jpg', TestDataFixture::CALENDAR_1_ID, $newId), $copy->path);
        self::assertSame(sprintf('calendars/%s/%s-preview.webp', TestDataFixture::CALENDAR_1_ID, $newId), $copy->previewPath);
        self::assertTrue($filesystem->fileExists($copy->path));
        self::assertTrue($filesystem->fileExists((string) $copy->previewPath));
        self::assertSame(TestDataFixture::CALENDAR_1_ID, $copy->calendar->id->toString());

        // The target row is untouched — the workspace applies and saves the layout itself…
        $target = self::calendar(TestDataFixture::CALENDAR_1_ID);
        self::assertNull($target->layout);
        self::assertSame(0, $target->revision);
        self::assertSame(['Grafik', 'Načetl layout z „Obecní kalendář“'], self::history(TestDataFixture::CALENDAR_1_ID)[0]);

        // …which works, because the copied asset now belongs to the target.
        self::save($browser, self::TARGET_URL . '/save', ['design' => ['preset' => 'photoLeft', 'layout' => $answer['layout']]], $token);
        self::assertResponseIsSuccessful();

        // The source keeps its own file.
        $source = self::calendar(TestDataFixture::CALENDAR_2_ID);
        self::assertStringContainsString($logoId, (string) json_encode($source->layout));
    }

    public function testOtherProjectIsNotFoundAndOtherTypeIsRefused(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);
        $token = self::csrf(self::bootstrap($browser, self::TARGET_URL));

        $browser->request('POST', self::IMPORT_URL, ['_token' => $token, 'from' => TestDataFixture::CALENDAR_3_ID]);
        self::assertResponseStatusCodeSame(404);

        $browser->request('POST', self::IMPORT_URL, ['_token' => $token, 'from' => '00000000-0000-0000-0000-00000000dead']);
        self::assertResponseStatusCodeSame(404);

        $browser->request('POST', self::IMPORT_URL, ['_token' => 'wrong', 'from' => TestDataFixture::CALENDAR_2_ID]);
        self::assertResponseStatusCodeSame(403);

        // A calendar without a layout has nothing to give.
        $browser->request('POST', self::SOURCE_URL . '/layout-import', ['_token' => $token, 'from' => TestDataFixture::CALENDAR_1_ID]);
        self::assertResponseStatusCodeSame(422);

        // A 14-day target cannot take a 7-day layout.
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $target = self::calendar(TestDataFixture::CALENDAR_1_ID);
        $target->applyDesign(['type' => CalendarType::Fortnight]);
        $entityManager->flush();

        $browser->request('POST', self::IMPORT_URL, ['_token' => $token, 'from' => TestDataFixture::CALENDAR_2_ID]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'Layout je pro jiný typ kalendáře.'], self::json($browser));

        $file = sprintf('{"wboostCalendarLayout": 1, "type": "week", "layout": %s}', TestDataFixture::CALENDAR_LAYOUT_JSON);
        $browser->request('POST', self::IMPORT_URL, ['_token' => $token, 'layout' => $file]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'Layout je pro jiný typ kalendáře.'], self::json($browser));
    }

    public function testLayoutFile(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);
        $token = self::csrf(self::bootstrap($browser, self::TARGET_URL));
        $logoId = $this->giveSourceALogo($browser, $token);

        $layout = (string) json_encode(self::calendar(TestDataFixture::CALENDAR_2_ID)->layout);

        // A file of a calendar of this project: its pictures come along.
        $file = sprintf('{"wboostCalendarLayout": 1, "type": "week", "preset": "photoLeft", "bleedMm": 5, "sourceCalendar": "%s", "layout": %s}', TestDataFixture::CALENDAR_2_ID, $layout);
        $browser->request('POST', self::IMPORT_URL, ['_token' => $token, 'layout' => $file]);
        self::assertResponseIsSuccessful();
        $answer = self::json($browser);
        self::assertNull($answer['name']);
        self::assertIsArray($answer['assets']);
        self::assertCount(1, $answer['assets']);
        self::assertSame((string) array_key_first($answer['assets']), self::dig($answer, 'layout', 'styles', 'logo', 'asset'));
        self::assertSame(['Grafik', 'Načetl layout ze souboru'], self::history(TestDataFixture::CALENDAR_1_ID)[0]);
        // Empty maps of the layout stay objects in the answer.
        self::assertStringContainsString('"offsets":{}', (string) $browser->getResponse()->getContent());

        // A file from elsewhere (foreign / missing / deleted source): no pictures.
        foreach ([TestDataFixture::CALENDAR_3_ID, '00000000-0000-0000-0000-00000000dead', null] as $sourceId) {
            $file = sprintf('{"wboostCalendarLayout": 1, "type": "week", "sourceCalendar": %s, "layout": %s}', json_encode($sourceId), $layout);
            $browser->request('POST', self::IMPORT_URL, ['_token' => $token, 'layout' => $file]);
            self::assertResponseIsSuccessful();
            $answer = self::json($browser);
            self::assertSame([], $answer['assets']);
            self::assertNull(self::dig($answer, 'layout', 'styles', 'logo', 'asset'));
            self::assertStringNotContainsString($logoId, (string) $browser->getResponse()->getContent());
        }

        // Not a layout.
        foreach (['', '{not json', '[]', '{"layout": 5}', '{"layout": {"v": 1, "boxes": {}, "order": []}}', '{"layout": {"v": 3, "boxes": {}, "order": [], "styles": {}}}'] as $notALayout) {
            $browser->request('POST', self::IMPORT_URL, ['_token' => $token, 'layout' => $notALayout]);
            self::assertResponseStatusCodeSame(422, $notALayout);
            self::assertSame(['error' => 'Soubor neobsahuje layout kalendáře'], self::json($browser));
        }
    }

    /**
     * Uploads a logo into the SOURCE calendar and references it from its layout.
     */
    private function giveSourceALogo(KernelBrowser $browser, string $token): string
    {
        $logo = self::upload($browser, self::SOURCE_URL . '/asset', $token, 'logo', self::jpeg(2000, 1000), 'znak.jpg');
        self::assertResponseIsSuccessful();
        self::assertIsString($logo['id']);

        $layout = str_replace('"logo": {"asset": null', sprintf('"logo": {"asset": "%s"', $logo['id']), TestDataFixture::CALENDAR_LAYOUT_JSON);
        self::save($browser, self::SOURCE_URL . '/save', ['design' => ['layout' => json_decode($layout)]], $token);
        self::assertResponseIsSuccessful();

        return $logo['id'];
    }
}
