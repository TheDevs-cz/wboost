<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Palette;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Services\SocialNetwork\ResolveRichTextOptions;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\ManualColor;

/**
 * "Barvy v manuálech" on Paleta barev (F7 P3): the box (VIEW read-only vs
 * EDIT), "Přidat z palety", "Odebrat z manuálu" (logo colours refused),
 * reorder (logo colours stay first), the "alespoň 3 barvy" warning, the R2
 * JSONB mirror after every write, and the old manual colours URL redirecting
 * to the box.
 *
 * Fixture: Project 1 (owner USER_1, read-only share for SHARED_USER), Manual 1
 * links red C8102E (primary, link …0100) then blue 004E7C (secondary,
 * link …0101) as added colours.
 *
 * @covers \WBoost\Web\Controller\Palette\ProjectPaletteController
 * @covers \WBoost\Web\Controller\Palette\AddManualPaletteColorsController
 * @covers \WBoost\Web\Controller\Palette\RemoveManualPaletteColorController
 * @covers \WBoost\Web\Controller\Palette\SortManualPaletteColorsController
 * @covers \WBoost\Web\Controller\Manual\ManualColorsController
 */
final class ManualPaletteColorsControllerTest extends WebTestCase
{
    private const string PAGE_URL = '/project/' . TestDataFixture::PROJECT_1_ID . '/paleta';
    private const string MANUAL_URL = '/manual/' . TestDataFixture::MANUAL_1_ID;
    private const string SECTION = '#manual-colors-' . TestDataFixture::MANUAL_1_ID;
    private const string RED_LINK_ID = '0f7c0000-0000-7000-8000-000000000100';
    private const string BLUE_LINK_ID = '0f7c0000-0000-7000-8000-000000000101';
    private const string GREEN_ID = '0f7c0000-0000-7000-8000-0000000000a1';

    public function testEditorSeesTheManualsColoursInOrderWithRolesAndTheWarning(): void
    {
        $browser = $this->editor();
        $crawler = $browser->request('GET', self::PAGE_URL);

        self::assertResponseIsSuccessful();
        self::assertSame('Barvy v manuálech', trim($crawler->filter('#manual-colors h2')->text()));

        $section = $crawler->filter(self::SECTION);
        self::assertCount(1, $section);
        self::assertSame([self::RED_LINK_ID, self::BLUE_LINK_ID], $this->rowIds($section));
        self::assertSame(['1', '2'], $section->filter('.pal-mrow [data-manual-number]')->each(static fn (Crawler $n): string => trim($n->text())));
        self::assertSame(['#C8102E', '#004E7C'], $section->filter('.pal-mrow-t b')->each(static fn (Crawler $n): string => trim($n->text())));
        // The role is the palette colour's, shown read-only, linking to its card (D2).
        self::assertSame(['Primární', 'Sekundární'], $section->filter('.pal-mrole')->each(static fn (Crawler $n): string => trim($n->text())));
        self::assertSame('#pal-' . TestDataFixture::PROJECT_1_COLOR_RED_ID, $section->filter('a.pal-mrole')->first()->attr('href'));
        self::assertCount(0, $section->filter('.pal-mrole select, select.pal-mrole'));

        // Editors: grips, menus with "Odebrat z manuálu", "Přidat z palety".
        self::assertCount(2, $section->filter('.pal-mrow .pal-grip'));
        self::assertCount(2, $section->filter('form[action$="/odebrat"]'));
        self::assertCount(1, $section->filter('[data-bs-target="#mc-add-' . TestDataFixture::MANUAL_1_ID . '"]'));
        self::assertNotNull($section->filter('ol.pal-mrows')->attr('data-sort-url'));

        // Two colours only: the warning moved here from the old colours page.
        self::assertStringContainsString('Manuál potřebuje alespoň 3 barvy', $section->filter('.pal-man-warn')->text());

        // Every palette colour is in the manual already.
        self::assertStringContainsString('Manuál už má všechny barvy palety', $crawler->filter('#mc-add-' . TestDataFixture::MANUAL_1_ID)->text());
    }

    public function testReadOnlyMemberSeesTheBoxWithoutActions(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::SHARED_USER_EMAIL);

        $crawler = $browser->request('GET', self::PAGE_URL);

        self::assertResponseIsSuccessful();
        $section = $crawler->filter(self::SECTION);
        self::assertSame([self::RED_LINK_ID, self::BLUE_LINK_ID], $this->rowIds($section));
        self::assertCount(0, $section->filter('.pal-grip, form, [data-bs-toggle], [data-sort-url], button'));
        self::assertCount(0, $crawler->filter('#mc-add-' . TestDataFixture::MANUAL_1_ID));
        self::assertCount(0, $section->filter('a.pal-man-n'), 'the manual pages are editors-only');
        self::assertStringContainsString('Požádejte grafika o doplnění', $section->filter('.pal-man-warn')->text());
    }

    public function testAddFromPaletteAppendsTheColoursMirrorsThemAndOffersThemToTemplates(): void
    {
        $browser = $this->editor();
        $this->insertGreen();

        $crawler = $browser->request('GET', self::PAGE_URL);
        $dialog = $crawler->filter('#mc-add-' . TestDataFixture::MANUAL_1_ID);
        self::assertSame([self::GREEN_ID], $dialog->filter('input[name="colors[]"]')->each(static fn (Crawler $n): string => (string) $n->attr('value')));
        self::assertStringContainsString('Nepoužitá', $dialog->text());
        // Unlinked: not a template swatch yet.
        self::assertNotContains('#2e8b57', $this->templateSwatches());

        $this->post($browser, self::MANUAL_URL . '/paleta/pridat', [
            // Already linked, unknown and another project's ids are skipped.
            'colors' => [TestDataFixture::PROJECT_1_COLOR_RED_ID, self::GREEN_ID, Uuid::uuid7()->toString(), self::GREEN_ID],
        ]);
        self::assertResponseRedirects(self::PAGE_URL . self::SECTION);

        $manual = $this->manual();
        self::assertSame(['C8102E', '004E7C', '2E8B57'], self::hexes($manual->customColors()));
        self::assertSame(['C8102E', '004E7C', '2E8B57'], self::hexes($manual->jsonbCustomColors()), 'R2 JSONB mirror');
        self::assertSame('2E8B57', $manual->color(3)->hex);
        self::assertContains('#2e8b57', $this->templateSwatches(), 'a linked palette colour is offered to templates');

        $crawler = $browser->followRedirect();
        self::assertStringContainsString('přibyla 1 barva', $crawler->filter('.alert-success')->text(''));
        $section = $crawler->filter(self::SECTION);
        self::assertSame(['1', '2', '3'], $section->filter('.pal-mrow [data-manual-number]')->each(static fn (Crawler $n): string => trim($n->text())));
        self::assertCount(0, $section->filter('.pal-man-warn'), 'three colours — no warning');
        self::assertStringContainsString('Manual 1', $crawler->filter('#pal-' . self::GREEN_ID . ' .pal-use')->text());

        // Nothing picked: a warning, nothing changes.
        $this->post($browser, self::MANUAL_URL . '/paleta/pridat', []);
        self::assertResponseRedirects(self::PAGE_URL . self::SECTION);
        self::assertCount(3, $this->manual()->customColors());
    }

    public function testRemoveDropsTheAddedColourButKeepsItInThePalette(): void
    {
        $browser = $this->editor();

        $this->post($browser, self::MANUAL_URL . '/paleta/' . self::RED_LINK_ID . '/odebrat', []);
        self::assertResponseRedirects(self::PAGE_URL . self::SECTION);

        $manual = $this->manual();
        self::assertSame(['004E7C'], self::hexes($manual->customColors()));
        self::assertSame(['004E7C'], self::hexes($manual->jsonbCustomColors()), 'R2 JSONB mirror');
        self::assertSame([], self::hexes($manual->primaryColors()), 'the primary colour page is gone');
        self::assertSame('004E7C', $manual->color(1)->hex, 'later colours move up a place');
        self::assertSame(1, self::intOf($this->connection()->fetchOne('SELECT COUNT(*) FROM project_color WHERE id = ?', [TestDataFixture::PROJECT_1_COLOR_RED_ID])));

        $crawler = $browser->request('GET', self::PAGE_URL);
        self::assertStringContainsString('Nepoužitá', $crawler->filter('#pal-' . TestDataFixture::PROJECT_1_COLOR_RED_ID . ' .pal-use')->text());
        // …and it can be picked again.
        self::assertSame([TestDataFixture::PROJECT_1_COLOR_RED_ID], $crawler->filter('#mc-add-' . TestDataFixture::MANUAL_1_ID . ' input[name="colors[]"]')->each(static fn (Crawler $n): string => (string) $n->attr('value')));

        // A link of another manual / unknown → 404.
        $this->post($browser, self::MANUAL_URL . '/paleta/' . Uuid::uuid7()->toString() . '/odebrat', []);
        self::assertResponseStatusCodeSame(404);
    }

    public function testALogoColourCannotBeRemoved(): void
    {
        $browser = $this->editor();
        $this->connection()->executeStatement('UPDATE manual_palette_color SET logo_hex = ? WHERE id = ?', ['C8102E', self::RED_LINK_ID]);

        $crawler = $browser->request('GET', self::PAGE_URL);
        $section = $crawler->filter(self::SECTION);
        self::assertSame('Z logotypu', trim($section->filter('.pal-mseg h3')->first()->text()));
        self::assertCount(1, $section->filter('form[action$="/odebrat"]'), 'only the added colour has the remove item');
        self::assertCount(2, $section->filter('ol.pal-mrows'));

        $this->post($browser, self::MANUAL_URL . '/paleta/' . self::RED_LINK_ID . '/odebrat', []);
        self::assertResponseRedirects(self::PAGE_URL . self::SECTION);
        self::assertSame(['C8102E'], self::hexes($this->manual()->detectedColors()));
        self::assertSame(2, self::intOf($this->connection()->fetchOne('SELECT COUNT(*) FROM manual_palette_color WHERE manual_id = ?', [TestDataFixture::MANUAL_1_ID])));
    }

    public function testReorderPersistsMirrorsAndAnswersTheManualOrder(): void
    {
        $browser = $this->editor();
        $token = $this->token($browser);

        $this->sort($browser, $token, [self::BLUE_LINK_ID, self::RED_LINK_ID]);
        self::assertResponseIsSuccessful();
        self::assertSame(['order' => [self::BLUE_LINK_ID, self::RED_LINK_ID]], json_decode((string) $browser->getResponse()->getContent(), true));

        $manual = $this->manual();
        self::assertSame(['004E7C', 'C8102E'], self::hexes($manual->customColors()));
        self::assertSame(['004E7C', 'C8102E'], self::hexes($manual->jsonbCustomColors()), 'R2 JSONB mirror');
        self::assertSame('004E7C', $manual->color(1)->hex);

        $crawler = $browser->request('GET', self::PAGE_URL);
        self::assertSame([self::BLUE_LINK_ID, self::RED_LINK_ID], $this->rowIds($crawler->filter(self::SECTION)));
        // The palette order (templates, print, API) is untouched.
        self::assertSame(
            [TestDataFixture::PROJECT_1_COLOR_RED_ID, TestDataFixture::PROJECT_1_COLOR_BLUE_ID],
            $crawler->filter('.pal-card')->each(static fn (Crawler $card): string => (string) $card->attr('data-color-id')),
        );
    }

    public function testReorderKeepsTheLogoColoursFirst(): void
    {
        $browser = $this->editor();
        // Red is the logo colour, blue an added one.
        $this->connection()->executeStatement('UPDATE manual_palette_color SET logo_hex = ? WHERE id = ?', ['C8102E', self::RED_LINK_ID]);
        $token = $this->token($browser);

        // A crafted request mixing the segments cannot put an added colour before the logo.
        $this->sort($browser, $token, [self::BLUE_LINK_ID, self::RED_LINK_ID]);
        self::assertResponseIsSuccessful();
        self::assertSame(['order' => [self::RED_LINK_ID, self::BLUE_LINK_ID]], json_decode((string) $browser->getResponse()->getContent(), true));
        self::assertSame('C8102E', $this->manual()->color(1)->hex);
    }

    public function testWritesNeedTheCsrfTokenAndManualEditRights(): void
    {
        $browser = $this->editor();
        $this->insertGreen();

        $browser->request('POST', self::MANUAL_URL . '/paleta/pridat', ['colors' => [self::GREEN_ID]]);
        self::assertResponseStatusCodeSame(403);
        $browser->request('POST', self::MANUAL_URL . '/paleta/' . self::RED_LINK_ID . '/odebrat');
        self::assertResponseStatusCodeSame(403);
        $browser->request('POST', self::MANUAL_URL . '/paleta/poradi', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['sorted' => [self::BLUE_LINK_ID, self::RED_LINK_ID]]));
        self::assertResponseStatusCodeSame(403);

        // The read-only member and an outsider have no edit right, token or not.
        foreach ([TestDataFixture::SHARED_USER_EMAIL, TestDataFixture::USER_2_EMAIL] as $email) {
            TestingLogin::logInAsUser($browser, $email);
            $browser->request('POST', self::MANUAL_URL . '/paleta/pridat', ['colors' => [self::GREEN_ID], '_token' => 'whatever']);
            self::assertResponseStatusCodeSame(403, $email);
            $browser->request('POST', self::MANUAL_URL . '/paleta/' . self::RED_LINK_ID . '/odebrat', ['_token' => 'whatever']);
            self::assertResponseStatusCodeSame(403, $email);
        }

        $manual = $this->manual();
        self::assertSame(['C8102E', '004E7C'], self::hexes($manual->customColors()));
    }

    public function testTheOldManualColoursUrlRedirectsToTheBox(): void
    {
        $browser = $this->editor();

        $browser->request('GET', self::MANUAL_URL . '/colors');
        self::assertResponseStatusCodeSame(302);
        self::assertResponseRedirects(self::PAGE_URL . self::SECTION);

        // Read-only members land on the read-only box too.
        TestingLogin::logInAsUser($browser, TestDataFixture::SHARED_USER_EMAIL);
        $browser->request('GET', self::MANUAL_URL . '/colors');
        self::assertResponseRedirects(self::PAGE_URL . self::SECTION);

        TestingLogin::logInAsUser($browser, TestDataFixture::USER_2_EMAIL);
        $browser->request('GET', self::MANUAL_URL . '/colors');
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheManualNavHasNoColoursTabAnyMore(): void
    {
        $browser = $this->editor();
        $crawler = $browser->request('GET', self::MANUAL_URL . '/logos');

        self::assertResponseIsSuccessful();
        $tabs = $crawler->filter('nav.man-tabs a');
        self::assertSame(['Logotypy'], $tabs->each(static fn (Crawler $tab): string => trim((string) preg_replace('~\s+\d+/\d+$~', '', trim($tab->text())))));
        self::assertCount(0, $crawler->filter('a[href$="/colors"]'));
        self::assertStringNotContainsString('Barevná paleta', $crawler->filter('nav.man-tabs')->text());
    }

    /**
     * Took over from the deleted ManualColorsControllerTest: print data and
     * the role, now edited on the palette card, reach the customer manual —
     * RAL printed only once filled, the role picks the colour page.
     */
    public function testPaletteEditsReachTheCustomerManual(): void
    {
        $browser = $this->editor();

        $browser->request('GET', '/nahled-manualu/project-1/manual-1');
        self::assertStringNotContainsString('RAL:', (string) $browser->getResponse()->getContent());

        $this->post($browser, '/paleta/barva/' . TestDataFixture::PROJECT_1_COLOR_RED_ID, ['field' => 'ral', 'value' => 'RAL 3020']);
        self::assertResponseIsSuccessful();
        $this->post($browser, '/paleta/barva/' . TestDataFixture::PROJECT_1_COLOR_RED_ID, ['field' => 'role', 'value' => 'secondary']);
        self::assertResponseIsSuccessful();

        $browser->request('GET', '/nahled-manualu/project-1/manual-1');
        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('RAL 3020', $content);
        self::assertStringNotContainsString('Primární barevná paleta', $content);
        self::assertStringContainsString('Sekundární barevná paleta', $content);
        self::assertSame(2, substr_count($content, 'class="manual-preview-color'));
    }

    private function insertGreen(): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO project_color (id, project_id, hex, hex_key, role, position, created_at) VALUES (?, ?, '2E8B57', '#2e8b57', NULL, 5, NOW())",
            [self::GREEN_ID, TestDataFixture::PROJECT_1_ID],
        );
    }

    /**
     * @return list<string>
     */
    private function templateSwatches(): array
    {
        return self::getContainer()->get(ResolveRichTextOptions::class)->forProject(Uuid::fromString(TestDataFixture::PROJECT_1_ID))->colors;
    }

    /**
     * @param list<string> $sorted
     */
    private function sort(KernelBrowser $browser, string $token, array $sorted): void
    {
        $browser->request('POST', self::MANUAL_URL . '/paleta/poradi', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], (string) json_encode([
            '_token' => $token,
            'sorted' => $sorted,
        ]));
    }

    /**
     * @return list<string>
     */
    private function rowIds(Crawler $section): array
    {
        return $section->filter('.pal-mrow')->each(static fn (Crawler $row): string => (string) $row->attr('data-link-id'));
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
        $browser->request('POST', $url, $data);
    }

    private function manual(): Manual
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $manual = $entityManager->find(Manual::class, Uuid::fromString(TestDataFixture::MANUAL_1_ID));
        self::assertInstanceOf(Manual::class, $manual);

        return $manual;
    }

    /**
     * @param array<ManualColor> $colors
     * @return list<string>
     */
    private static function hexes(array $colors): array
    {
        return array_values(array_map(static fn (ManualColor $color): string => $color->color->hex, $colors));
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
