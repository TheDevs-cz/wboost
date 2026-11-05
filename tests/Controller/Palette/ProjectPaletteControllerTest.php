<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Palette;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Entity\Project;
use WBoost\Web\Query\GetProjectCardFacts;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\ManualColor;

/**
 * "Paleta barev" (F7 P2): the page (VIEW read-only vs EDIT), the autosave
 * endpoint, reorder, add, the delete guard, the sidebar item and the
 * overview chips.
 *
 * Fixture: Project 1 (owner USER_1, read-only share for SHARED_USER) has red
 * C8102E (primary) + blue 004E7C (secondary), both added colours of Manual 1.
 *
 * @covers \WBoost\Web\Controller\Palette\ProjectPaletteController
 * @covers \WBoost\Web\Controller\Palette\EditProjectColorController
 * @covers \WBoost\Web\Controller\Palette\SortProjectColorsController
 * @covers \WBoost\Web\Controller\Palette\AddProjectColorController
 * @covers \WBoost\Web\Controller\Palette\DeleteProjectColorController
 */
final class ProjectPaletteControllerTest extends WebTestCase
{
    private const string PAGE_URL = '/project/' . TestDataFixture::PROJECT_1_ID . '/paleta';
    private const string BLUE_URL = '/paleta/barva/' . TestDataFixture::PROJECT_1_COLOR_BLUE_ID;

    public function testEditorGetsEditableCardsAndTheSidebarItem(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $crawler = $browser->request('GET', self::PAGE_URL);

        self::assertResponseIsSuccessful();
        self::assertSame('Paleta barev', trim($crawler->filter('h1.page-title')->text()));
        self::assertCount(2, $crawler->filter('.pal-card[data-url]'));
        self::assertCount(1, $crawler->filter('.pal-nav .pal-add button'));
        self::assertCount(2, $crawler->filter('.pal-card input[data-field="hex"]'));
        self::assertCount(2, $crawler->filter('.pal-card .pal-grip'));
        self::assertStringContainsString('Manual 1', $crawler->filter('#pal-' . TestDataFixture::PROJECT_1_COLOR_BLUE_ID . ' .pal-use')->text());
        // Both colours lack CMYK.
        self::assertStringContainsString('2 barvám chybí', $crawler->filter('.pal-note')->text());

        $nav = $crawler->filter('nav[aria-label="Moduly projektu"] a[title="Paleta barev"]');
        self::assertCount(1, $nav);
        self::assertSame('page', $nav->attr('aria-current'));
    }

    public function testReadOnlyMemberSeesDisabledFieldsAndTheAskTheDesignerWarning(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::SHARED_USER_EMAIL);

        $crawler = $browser->request('GET', self::PAGE_URL);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.pal-page.is-readonly'));
        self::assertCount(2, $crawler->filter('.pal-card'));
        self::assertCount(0, $crawler->filter('.pal-card[data-url]'));
        self::assertCount(0, $crawler->filter('.pal-add, .pal-grip, .pal-calc, .pal-card [data-bs-toggle="dropdown"], .pal-page .modal'));
        self::assertCount(0, $crawler->filter('.pal-card input:not([disabled]), .pal-card select:not([disabled])'));
        // Empty print fields: peach dashed; the warning names whom to ask.
        self::assertGreaterThan(0, $crawler->filter('.pal-card .ro-miss')->count());
        self::assertStringContainsString('Některé hodnoty u barev nejsou specifikované', $crawler->filter('.alert.c-warning')->text());
        self::assertStringContainsString('Požádejte grafika o doplnění', $crawler->filter('.alert.c-warning')->text());
        self::assertCount(1, $crawler->filter('nav[aria-label="Moduly projektu"] a[title="Paleta barev"]'));
    }

    public function testOutsiderIsForbidden(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_2_EMAIL);

        $browser->request('GET', self::PAGE_URL);
        self::assertResponseStatusCodeSame(403);
    }

    public function testPantoneAutosavesAndAnswersTheStoredColour(): void
    {
        $browser = $this->editor();

        $this->post($browser, self::BLUE_URL, ['field' => 'pantone', 'value' => ' 2945 C ']);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $browser->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame([
            'id' => TestDataFixture::PROJECT_1_COLOR_BLUE_ID,
            'hex' => '004E7C',
            'rgb' => [0, 78, 124],
            'name' => null,
            'role' => 'secondary',
            'cmyk' => null,
            'pantone' => '2945 C',
            'ral' => null,
        ], $payload['color']);
        self::assertSame('2945 C', $this->manual()->customColors()[1]->pantone);
        self::assertSame('2945 C', $this->manual()->jsonbCustomColors()[1]->pantone);
    }

    public function testRoleChangePropagatesToTheLinkedManualsColourPages(): void
    {
        $browser = $this->editor();

        $this->post($browser, self::BLUE_URL, ['field' => 'role', 'value' => 'primary']);
        self::assertResponseIsSuccessful();

        $manual = $this->manual();
        self::assertSame(['C8102E', '004E7C'], array_map(static fn (ManualColor $c): string => $c->color->hex, $manual->primaryColors()));
        self::assertSame([], $manual->secondaryColors());

        // "Bez role" clears it.
        $this->post($browser, self::BLUE_URL, ['field' => 'role', 'value' => '']);
        self::assertResponseIsSuccessful();
        self::assertSame(['C8102E'], array_map(static fn (ManualColor $c): string => $c->color->hex, $this->manual()->primaryColors()));
        self::assertNull($this->manual()->customColors()[1]->type);
    }

    public function testCmykTupleIsSavedAndAnAllBlankTupleClearsIt(): void
    {
        $browser = $this->editor();

        $this->post($browser, self::BLUE_URL, ['field' => 'cmyk', 'cmyk' => ['100', '37', '0', '51']]);
        self::assertResponseIsSuccessful();
        self::assertSame(['100', '37', '0', '51'], $this->manual()->customColors()[1]->cmyk);

        $this->post($browser, self::BLUE_URL, ['field' => 'cmyk', 'cmyk' => ['', ' ', '', '']]);
        self::assertResponseIsSuccessful();
        self::assertNull($this->manual()->customColors()[1]->cmyk);
    }

    public function testInvalidInputIsRejected(): void
    {
        $browser = $this->editor();

        foreach ([['field' => 'hex', 'value' => 'zzz'], ['field' => 'hex', 'value' => '#12345'], ['field' => 'role', 'value' => 'accent'], ['field' => 'nope', 'value' => 'x'], ['field' => 'cmyk', 'cmyk' => ['1', '2', '3']]] as $data) {
            $this->post($browser, self::BLUE_URL, $data);
            self::assertResponseStatusCodeSame(400, (string) json_encode($data));
        }

        self::assertSame('004E7C', $this->manual()->customColors()[1]->color->hex);
    }

    public function testHexOfALogoColourIsLockedOnThePageAndOnTheEndpoint(): void
    {
        $browser = $this->editor();
        $this->connection()->executeStatement('UPDATE manual_palette_color SET logo_hex = ? WHERE project_color_id = ?', ['004E7C', TestDataFixture::PROJECT_1_COLOR_BLUE_ID]);

        $crawler = $browser->request('GET', self::PAGE_URL);
        $card = $crawler->filter('#pal-' . TestDataFixture::PROJECT_1_COLOR_BLUE_ID);
        self::assertCount(0, $card->filter('input[data-field="hex"]'));
        self::assertSame('HEX určuje logotyp', $card->filter('.pal-hex-ro.locked')->attr('title'));
        self::assertStringContainsString('Z logotypu', $card->filter('.pal-src')->text());
        self::assertStringContainsString('Barvu nelze smazat', $crawler->filter('#pal-del-' . TestDataFixture::PROJECT_1_COLOR_BLUE_ID)->text());

        $this->post($browser, self::BLUE_URL, ['field' => 'hex', 'value' => '#111111']);
        self::assertResponseStatusCodeSame(400);
        $error = json_decode((string) $browser->getResponse()->getContent(), true);
        self::assertIsArray($error);
        self::assertIsString($error['error'] ?? null);
        self::assertStringContainsString('HEX určuje logotyp', $error['error']);
        self::assertSame('004E7C', $this->connection()->fetchOne('SELECT hex FROM project_color WHERE id = ?', [TestDataFixture::PROJECT_1_COLOR_BLUE_ID]));

        // Logo colours are refused by the delete endpoint too.
        $this->post($browser, self::BLUE_URL . '/smazat', ['removeFromManuals' => '1']);
        self::assertResponseRedirects(self::PAGE_URL);
        self::assertSame(1, self::intOf($this->connection()->fetchOne('SELECT COUNT(*) FROM project_color WHERE id = ?', [TestDataFixture::PROJECT_1_COLOR_BLUE_ID])));
    }

    public function testWritesNeedTheCsrfTokenAndEditRights(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);
        $browser->request('POST', self::BLUE_URL, ['field' => 'pantone', 'value' => 'X']);
        self::assertResponseStatusCodeSame(403);
        $browser->request('POST', self::PAGE_URL . '/pridat');
        self::assertResponseStatusCodeSame(403);
        $browser->request('POST', self::PAGE_URL . '/poradi', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['sorted' => [TestDataFixture::PROJECT_1_COLOR_BLUE_ID]]));
        self::assertResponseStatusCodeSame(403);
        $browser->request('POST', self::BLUE_URL . '/smazat', ['removeFromManuals' => '1']);
        self::assertResponseStatusCodeSame(403);

        // The read-only member has no edit right even with a token of their own.
        TestingLogin::logInAsUser($browser, TestDataFixture::SHARED_USER_EMAIL);
        $browser->request('GET', self::PAGE_URL);
        $browser->request('POST', self::BLUE_URL, ['field' => 'pantone', 'value' => 'X', '_token' => 'whatever']);
        self::assertResponseStatusCodeSame(403);

        self::assertNull($this->connection()->fetchOne('SELECT pantone FROM project_color WHERE id = ?', [TestDataFixture::PROJECT_1_COLOR_BLUE_ID]));
    }

    public function testReorderPersistsAndAnswersThePaletteOrder(): void
    {
        $browser = $this->editor();
        $token = $this->token($browser);

        $browser->request('POST', self::PAGE_URL . '/poradi', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            '_token' => $token,
            'sorted' => [TestDataFixture::PROJECT_1_COLOR_BLUE_ID, TestDataFixture::PROJECT_1_COLOR_RED_ID],
        ]));

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['order' => [TestDataFixture::PROJECT_1_COLOR_BLUE_ID, TestDataFixture::PROJECT_1_COLOR_RED_ID]],
            json_decode((string) $browser->getResponse()->getContent(), true),
        );

        $crawler = $browser->request('GET', self::PAGE_URL);
        self::assertSame(
            [TestDataFixture::PROJECT_1_COLOR_BLUE_ID, TestDataFixture::PROJECT_1_COLOR_RED_ID],
            $crawler->filter('.pal-card')->each(static fn ($card): string => (string) $card->attr('data-color-id')),
        );
    }

    public function testAddedColourIsUnusedAndDeletesWithoutConfirmationOfManuals(): void
    {
        $browser = $this->editor();

        $this->post($browser, self::PAGE_URL . '/pridat', []);
        self::assertResponseRedirects();
        $location = (string) $browser->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('~/paleta\?nova=([0-9a-f-]{36})#pal-\1$~', $location);
        self::assertSame(1, preg_match('~nova=([0-9a-f-]{36})~', $location, $matches));
        $newId = $matches[1];

        $crawler = $browser->followRedirect();
        $card = $crawler->filter('#pal-' . $newId);
        self::assertStringContainsString('flash', (string) $card->attr('class'));
        self::assertStringContainsString('Nepoužitá', $card->filter('.pal-use')->text());
        self::assertSame('#1F3B5F', $card->filter('input[data-field="hex"]')->attr('value'));
        self::assertStringContainsString('Žádný manuál barvu nepoužívá', $crawler->filter('#pal-del-' . $newId)->text());

        $this->post($browser, '/paleta/barva/' . $newId . '/smazat', []);
        self::assertResponseRedirects(self::PAGE_URL);
        self::assertSame(0, self::intOf($this->connection()->fetchOne('SELECT COUNT(*) FROM project_color WHERE id = ?', [$newId])));
    }

    public function testDeletingAColourManualsAddedNeedsTheRemoveFromManualsConfirmation(): void
    {
        $browser = $this->editor();

        $crawler = $browser->request('GET', self::PAGE_URL);
        $dialog = $crawler->filter('#pal-del-' . TestDataFixture::PROJECT_1_COLOR_RED_ID);
        self::assertStringContainsString('Manual 1', $dialog->text());
        self::assertSame('Odebrat z manuálů a smazat', trim($dialog->filter('button[type="submit"]')->text()));

        $this->post($browser, '/paleta/barva/' . TestDataFixture::PROJECT_1_COLOR_RED_ID . '/smazat', []);
        self::assertResponseRedirects(self::PAGE_URL);
        self::assertCount(2, $this->manual()->customColors());

        $this->post($browser, '/paleta/barva/' . TestDataFixture::PROJECT_1_COLOR_RED_ID . '/smazat', ['removeFromManuals' => '1']);
        self::assertResponseRedirects(self::PAGE_URL);
        self::assertSame(['004E7C'], array_map(static fn (ManualColor $c): string => $c->color->hex, $this->manual()->customColors()));
        self::assertSame(['004E7C'], array_map(static fn (ManualColor $c): string => $c->color->hex, $this->manual()->jsonbCustomColors()));
    }

    public function testOverviewChipsShowThePaletteAndLinkToIt(): void
    {
        $browser = $this->editor();
        $crawler = $browser->request('GET', '/project/' . TestDataFixture::PROJECT_1_ID);

        self::assertResponseIsSuccessful();
        self::assertSame(['#C8102E', '#004E7C'], $crawler->filter('.pd-pal .pd-sw')->each(static fn ($chip): string => trim($chip->text())));
        self::assertSame(self::PAGE_URL, $crawler->filter('.pd-pal a.pd-pall')->attr('href'));
        self::assertSame('Upravit paletu barev', $crawler->filter('.pd-pal a.pd-pall')->attr('aria-label'));

        TestingLogin::logInAsUser($browser, TestDataFixture::SHARED_USER_EMAIL);
        $crawler = $browser->request('GET', '/project/' . TestDataFixture::PROJECT_1_ID);
        self::assertSame('Zobrazit paletu barev', $crawler->filter('.pd-pal a.pd-pall')->attr('aria-label'));
    }

    public function testCardFactsHasPaletteFollowsTheProjectPalette(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $projects = [
            $entityManager->find(Project::class, Uuid::fromString(TestDataFixture::PROJECT_1_ID)),
            $entityManager->find(Project::class, Uuid::fromString(TestDataFixture::PROJECT_2_ID)),
        ];
        $projects = array_values(array_filter($projects, static fn (?Project $p): bool => $p !== null));

        $facts = self::getContainer()->get(GetProjectCardFacts::class)->forProjects($projects);
        self::assertTrue($facts[TestDataFixture::PROJECT_1_ID]->hasPalette);
        self::assertFalse($facts[TestDataFixture::PROJECT_2_ID]->hasPalette);

        // An unlinked palette colour counts too (the palette page lists it).
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO project_color (id, project_id, hex, hex_key, position, created_at) VALUES (?, ?, 'ABCDEF', '#abcdef', 0, NOW())",
            [Uuid::uuid7()->toString(), TestDataFixture::PROJECT_2_ID],
        );
        $facts = self::getContainer()->get(GetProjectCardFacts::class)->forProjects($projects);
        self::assertTrue($facts[TestDataFixture::PROJECT_2_ID]->hasPalette);
    }

    private function editor(): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        return $browser;
    }

    private function token(KernelBrowser $browser): string
    {
        $crawler = $browser->request('GET', self::PAGE_URL);
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('[data-project-palette-csrf-value]')->attr('data-project-palette-csrf-value');
        self::assertIsString($token);

        return $token;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function post(KernelBrowser $browser, string $url, array $data): void
    {
        $data['_token'] = $this->token($browser);
        $browser->request('POST', $url, $data, [], ['HTTP_ACCEPT' => 'application/json']);
    }

    private function manual(): Manual
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $manual = $entityManager->find(Manual::class, Uuid::fromString(TestDataFixture::MANUAL_1_ID));
        self::assertInstanceOf(Manual::class, $manual);

        return $manual;
    }

    private static function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : -1;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
