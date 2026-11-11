<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Calendar;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Repository\CalendarRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

/**
 * Projekt › Kalendáře: the list, create and delete.
 *
 * @covers \WBoost\Web\Controller\Calendar\CalendarsController
 * @covers \WBoost\Web\Controller\Calendar\CreateCalendarController
 * @covers \WBoost\Web\Controller\Calendar\DeleteCalendarController
 */
final class CalendarsControllerTest extends WebTestCase
{
    use CalendarTesting;

    private const string LIST_URL = '/project/' . TestDataFixture::PROJECT_1_ID . '/calendars';
    private const string CREATE_URL = self::LIST_URL . '/create';

    public function testListRendersACardPerCalendarWithItsBootstrap(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $crawler = $browser->request('GET', self::LIST_URL);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Kalendáře');
        self::assertSelectorExists('[data-controller="calendar-list"]');
        self::assertCount(2, $crawler->filter('.cal-card'));
        self::assertSelectorExists('.sb a[aria-current="page"][title="Kalendáře"]');

        // One primary action; cards link to the workspace.
        self::assertSelectorExists(sprintf('form[action="%s"] button[data-cal-action="create"]', self::CREATE_URL));
        self::assertSelectorExists(sprintf('.cal-card a[href="/calendar/%s"][aria-label="Otevřít Stolní kalendář 2027"]', TestDataFixture::CALENDAR_1_ID));

        // The unfinished one carries the pill, the finished one does not.
        $unfinished = $crawler->filter(sprintf('.cal-card[data-calendar-id="%s"]', TestDataFixture::CALENDAR_1_ID));
        $finished = $crawler->filter(sprintf('.cal-card[data-calendar-id="%s"]', TestDataFixture::CALENDAR_2_ID));
        self::assertStringContainsString('Rozpracovaný vzhled', $unfinished->text());
        self::assertStringNotContainsString('Rozpracovaný vzhled', $finished->text());

        // History chip only where there is history; who = Grafik.
        self::assertStringContainsString('Grafik', $unfinished->filter('[data-cal-action="history"]')->text());
        self::assertCount(0, $finished->filter('[data-cal-action="history"]'));

        // The card carries addresses only; its document is fetched when the
        // card scrolls into view (testCardDocumentIsFetchedLazily).
        /** @var array<string, mixed> $doc */
        $doc = json_decode($finished->filter('script[data-calendar-list-target="doc"]')->text(null, false), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame([
            'urls' => [
                'workspace' => '/calendar/' . TestDataFixture::CALENDAR_2_ID,
                'history' => '/calendar/' . TestDataFixture::CALENDAR_2_ID . '/history',
                'card' => '/calendar/' . TestDataFixture::CALENDAR_2_ID . '/card',
            ],
        ], $doc);

        /** @var array<string, mixed> $env */
        $env = json_decode($crawler->filter('script[data-calendar-list-target="env"]')->text(null, false), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('Project 1', $env['projectName']);
        self::assertSame(10_000_000, $env['maxFileSize']);
        self::assertSame(['#C8102E', '#004E7C'], $env['palette']);
        self::assertIsArray($env['fonts']);
        self::assertContains('Rubik (Rubik Bold)', array_column($env['fonts'], 'family'));

        // Delete is asked for in a dialog and posts with a CSRF token.
        self::assertSelectorExists(sprintf('#cal-delete-%1$s form[action="/calendar/%1$s/delete"] input[name="_token"]', TestDataFixture::CALENDAR_1_ID));
    }

    /**
     * The list used to embed every calendar's whole document (content of up
     * to 2 MB each) and to run one assets query per calendar. Its size and
     * query count no longer depend on what the calendars hold.
     */
    public function testCardDocumentIsFetchedLazily(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $workspace = '/calendar/' . TestDataFixture::CALENDAR_2_ID;
        $token = self::csrf(self::bootstrap($browser, $workspace));
        $photo = self::upload($browser, $workspace . '/asset', $token, 'photo', self::jpeg(40, 20), 'naves.jpg');
        self::assertIsString($photo['id']);
        self::save($browser, $workspace . '/save', ['content' => [
            ['c' => 'pageCaptions', 'id' => 'w05', 'v' => ['text' => 'Popisek-jen-v-dokumentu'], 'label' => '5. týden'],
            ['c' => 'pagePhotos', 'id' => 'w05', 'v' => ['asset' => $photo['id'], 'zoom' => 1, 'px' => 0.5, 'py' => 0.5], 'label' => '5. týden'],
        ]], $token);
        self::assertResponseIsSuccessful();

        $browser->enableProfiler();
        $browser->request('GET', self::LIST_URL);
        self::assertResponseIsSuccessful();
        $html = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString('Popisek-jen-v-dokumentu', $html);
        self::assertStringNotContainsString($photo['id'], $html);
        self::assertStringNotContainsString('"layout"', $html);

        $profile = $browser->getProfile();
        self::assertInstanceOf(\Symfony\Component\HttpKernel\Profiler\Profile::class, $profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(\Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector::class, $collector);
        $assetQueries = 0;
        $allQueries = 0;

        foreach ($collector->getQueries() as $connectionQueries) {
            self::assertIsIterable($connectionQueries);

            foreach ($connectionQueries as $query) {
                $sql = is_array($query) ? ($query['sql'] ?? '') : '';
                $assetQueries += is_string($sql) && str_contains($sql, 'calendar_asset') ? 1 : 0;
                $allQueries++;
            }
        }

        self::assertGreaterThan(0, $allQueries, 'The profiler saw the queries of the request.');
        self::assertSame(0, $assetQueries, 'The list reads no assets.');

        // The card's own document: what the workspace bootstrap carries, minus the session.
        $browser->request('GET', $workspace . '/card');
        self::assertResponseIsSuccessful();
        $card = self::json($browser);
        self::assertSame(['calendar', 'assets'], array_keys($card));
        self::assertSame(TestDataFixture::CALENDAR_2_ID, self::dig($card, 'calendar', 'id'));
        self::assertSame('photoLeft', self::dig($card, 'calendar', 'preset'));
        self::assertSame('Popisek-jen-v-dokumentu', self::dig($card, 'calendar', 'content', 'pageCaptions', 'w05', 'text'));
        self::assertSame('naves.jpg', self::dig($card, 'assets', $photo['id'], 'name'));
        self::assertStringNotContainsString(TestDataFixture::CALENDAR_2_TOKEN, (string) $browser->getResponse()->getContent());

        // A read-only member may read it; a stranger may not.
        TestingLogin::logInAsUser($browser, TestDataFixture::SHARED_USER_EMAIL);
        $browser->request('GET', $workspace . '/card');
        self::assertResponseIsSuccessful();

        TestingLogin::logInAsUser($browser, TestDataFixture::USER_2_EMAIL);
        $browser->request('GET', $workspace . '/card');
        self::assertResponseStatusCodeSame(403);
    }

    public function testEmptyStateAndModuleGuard(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_2_EMAIL);

        // Project 2 does not have the module: the landing page sends to "Objevte další moduly".
        $projectList = '/project/' . TestDataFixture::PROJECT_2_ID . '/calendars';
        $browser->request('GET', $projectList);
        self::assertResponseRedirects('/project/' . TestDataFixture::PROJECT_2_ID . '/modules');

        // Somebody else's project.
        $browser->request('GET', self::LIST_URL);
        self::assertResponseStatusCodeSame(403);

        // A project with the module and no calendars shows the empty state.
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        foreach ([TestDataFixture::CALENDAR_1_ID, TestDataFixture::CALENDAR_2_ID] as $id) {
            $calendar = $entityManager->find(Calendar::class, Uuid::fromString($id));
            self::assertNotNull($calendar);
            $entityManager->remove($calendar);
        }

        $entityManager->flush();

        $browser->request('GET', self::LIST_URL);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.empty h2', 'Zatím tu není žádný kalendář');
        self::assertSelectorExists('.empty button[data-cal-action="create"]');
        self::assertSelectorNotExists('.cal-card');
    }

    public function testReadOnlyMemberSeesTheListWithoutCreateOrDelete(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::SHARED_USER_EMAIL);

        $crawler = $browser->request('GET', self::LIST_URL);

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('.cal-card'));
        self::assertSelectorNotExists('[data-cal-action="create"]');
        self::assertSelectorNotExists('.cal-del');
        self::assertSelectorNotExists('[id^="cal-delete-"]');
    }

    public function testCreateNeedsEditAndACsrfToken(): void
    {
        $browser = self::createClient();

        TestingLogin::logInAsUser($browser, TestDataFixture::SHARED_USER_EMAIL);
        $browser->request('POST', self::CREATE_URL, ['_token' => 'whatever']);
        self::assertResponseStatusCodeSame(403);

        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);
        $browser->request('POST', self::CREATE_URL, ['_token' => 'wrong']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'Platnost formuláře vypršela. Obnovte stránku.'], self::json($browser));

        self::assertCount(2, self::getContainer()->get(CalendarRepository::class)->forProject(Uuid::fromString(TestDataFixture::PROJECT_1_ID)));
    }

    public function testCreateMakesNextYearsCalendarAndOpensItsWorkspace(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $crawler = $browser->request('GET', self::LIST_URL);
        $browser->submit($crawler->filter(sprintf('form[action="%s"]', self::CREATE_URL))->first()->form());

        self::assertResponseRedirects();
        $location = (string) $browser->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('~^/calendar/[0-9a-f-]{36}$~', $location);

        $nextYear = (int) self::getContainer()->get(ClockInterface::class)->now()->format('Y') + 1;
        $bootstrap = self::bootstrap($browser, $location);

        self::assertSame('designer', $bootstrap['mode']);
        /** @var array<string, mixed> $calendar */
        $calendar = $bootstrap['calendar'];
        self::assertSame(sprintf('Stolní kalendář %d', $nextYear), $calendar['name']);
        self::assertSame($nextYear, $calendar['year']);
        self::assertSame('week', $calendar['type']);
        self::assertSame(1, $calendar['step']);
        self::assertFalse($calendar['finished']);
        self::assertNull($calendar['preset']);
        self::assertNull($calendar['layout']);
        self::assertSame(0, $calendar['revision']);

        // Four built-in waste kinds, switched off — nothing else.
        self::assertIsArray($calendar['content']);
        self::assertSame(['v', 'wasteKinds'], array_keys($calendar['content']));
        $kinds = self::dig($calendar, 'content', 'wasteKinds');
        self::assertIsArray($kinds);
        self::assertEqualsCanonicalizing(['kom', 'bio', 'plast', 'papir'], array_keys($kinds));
        self::assertFalse(self::dig($kinds, 'kom', 'on'));
        self::assertSame('Komunální odpad', self::dig($kinds, 'kom', 'name'));

        $id = substr($location, strlen('/calendar/'));
        self::assertSame([['Grafik', 'Vytvořil kalendář']], self::history($id));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', self::calendar($id)->clientToken);
    }

    public function testDeleteNeedsEditAndCsrfAndRemovesRowAndStorage(): void
    {
        $browser = self::createClient();
        $deleteUrl = '/calendar/' . TestDataFixture::CALENDAR_1_ID . '/delete';

        TestingLogin::logInAsUser($browser, TestDataFixture::SHARED_USER_EMAIL);
        $browser->request('POST', $deleteUrl, ['_token' => 'whatever']);
        self::assertResponseStatusCodeSame(403);

        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);
        $browser->request('POST', $deleteUrl, ['_token' => 'wrong']);
        self::assertResponseStatusCodeSame(403);
        self::assertNotNull(self::getContainer()->get(EntityManagerInterface::class)->find(Calendar::class, Uuid::fromString(TestDataFixture::CALENDAR_1_ID)));

        // An uploaded file must go with the calendar.
        $bootstrap = self::bootstrap($browser, '/calendar/' . TestDataFixture::CALENDAR_1_ID);
        $asset = self::upload($browser, '/calendar/' . TestDataFixture::CALENDAR_1_ID . '/asset', self::csrf($bootstrap), 'photo', self::jpeg(40, 20), 'a.jpg');
        self::assertResponseIsSuccessful();
        self::assertIsString($asset['id']);

        $filesystem = self::getContainer()->get(Filesystem::class);
        $path = sprintf('calendars/%s/%s.jpg', TestDataFixture::CALENDAR_1_ID, $asset['id']);
        self::assertTrue($filesystem->fileExists($path));

        $crawler = $browser->request('GET', self::LIST_URL);
        $browser->submit($crawler->filter(sprintf('form[action="%s"]', $deleteUrl))->form());

        self::assertResponseRedirects(self::LIST_URL);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        self::assertNull($entityManager->find(Calendar::class, Uuid::fromString(TestDataFixture::CALENDAR_1_ID)));
        self::assertFalse($filesystem->fileExists($path));

        $browser->followRedirect();
        self::assertAnySelectorTextContains('.alert-success', 'Kalendář „Stolní kalendář 2027“ smazán');
    }
}
