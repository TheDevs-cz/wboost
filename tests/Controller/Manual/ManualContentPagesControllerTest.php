<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Manual;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\MockupPageLayout;

/**
 * Content pages P2 over HTTP: "Přidat stránku → Obsahová stránka", the
 * page-id editor + save, rename, delete, the mixed Stránky list + its sort
 * endpoint, and the public manual rendering both kinds in one order.
 *
 * @covers \WBoost\Web\Controller\Manual\AddManualContentPageController
 * @covers \WBoost\Web\Controller\Manual\ManualContentPageController
 * @covers \WBoost\Web\Controller\Manual\RenameManualContentPageController
 * @covers \WBoost\Web\Controller\Manual\DeleteManualContentPageController
 * @covers \WBoost\Web\Controller\Manual\SortManualPagesController
 * @covers \WBoost\Web\Controller\Manual\ManualMockupPagesController
 */
final class ManualContentPagesControllerTest extends WebTestCase
{
    private const string LIST_URL = '/manual/' . TestDataFixture::MANUAL_1_ID . '/mockup-pages';
    private const string ADD_URL = '/manual/' . TestDataFixture::MANUAL_1_ID . '/stranky/nova';
    private const string SORT_URL = '/manual/' . TestDataFixture::MANUAL_1_ID . '/stranky/poradi';
    private const string PREVIEW_URL = '/nahled-manualu/project-1/manual-1';
    private const string MOCKUP_A = '00000000-0000-0000-0000-0000000008a1';
    private const string MOCKUP_B = '00000000-0000-0000-0000-0000000008a2';
    private const string INTRO = '00000000-0000-0000-0000-0000000008c0';

    public function testAddOpensTheEditorOfTheNewPageAtTheEndOfTheList(): void
    {
        $browser = $this->owner();
        // The Stránky tab is a brand manual's (fixture manuals are logo ones).
        $this->connection()->executeStatement("UPDATE manual SET type = 'brand' WHERE id = ?", [TestDataFixture::MANUAL_1_ID]);
        $this->insertMockup(self::MOCKUP_A, 0, 'Vizitky');

        $pageId = $this->add($browser, 'Tón komunikace', 'layout-8');

        self::assertSame(1, $this->connection()->fetchOne('SELECT position FROM manual_content_page WHERE id = ?', [$pageId]));

        $crawler = $browser->request('GET', '/manual/stranka/' . $pageId);
        self::assertResponseIsSuccessful();
        $editor = $crawler->filter('[data-controller="manual-content-editor"]');
        self::assertSame('/manual/stranka/' . $pageId, $editor->attr('data-manual-content-editor-save-url-value'));
        /** @var array{name: string, layout: string, segments: list<mixed>} $state */
        $state = json_decode((string) $editor->attr('data-manual-content-editor-state-value'), true);
        self::assertSame('Tón komunikace', $state['name']);
        self::assertSame('layout-8', $state['layout']);
        self::assertCount(2, $state['segments']);
        // The Stránky tab is the current one, not Úvodní stránka.
        self::assertSelectorExists('.man-tabs a[href="' . self::LIST_URL . '"][aria-current="page"]');
        self::assertSelectorTextContains('.man-tabs a[href="' . self::LIST_URL . '"] .pd-n', '2');
        // An empty page previews as "not shown in the manual".
        self::assertSelectorTextContains('.mce-preview', 'Stránka se v manuálu nezobrazuje');
    }

    public function testAddRefusesABlankNameAndAnInvalidToken(): void
    {
        $browser = $this->owner();

        $browser->request('POST', self::ADD_URL, ['_token' => $this->token($browser), 'name' => '   ', 'layout' => 'layout-7']);
        self::assertResponseRedirects(self::LIST_URL);

        $browser->request('POST', self::ADD_URL, ['_token' => 'nope', 'name' => 'Stránka', 'layout' => 'layout-7']);
        self::assertResponseRedirects(self::LIST_URL);

        self::assertSame(0, $this->customCount());
    }

    public function testSaveByPageIdAndTheIntroIdRedirectsToItsTab(): void
    {
        $browser = $this->owner();
        $pageId = $this->add($browser, 'Hodnoty', 'layout-7');

        $browser->request('POST', '/manual/stranka/' . $pageId, [
            '_token' => $this->token($browser),
            'payload' => (string) json_encode(['name' => 'Naše hodnoty', 'layout' => 'layout-7', 'segments' => [['elements' => [
                ['id' => 'h', 'type' => 'heading', 'level' => 1, 'text' => 'Poctivost'],
            ]]]]),
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        /** @var array{preview: string, state: array{name: string}} $json */
        $json = json_decode((string) $browser->getResponse()->getContent(), true);
        self::assertSame('Naše hodnoty', $json['state']['name']);
        self::assertStringContainsString('Poctivost', $json['preview']);
        // A custom page's number depends on the built-in pages: not previewed.
        self::assertStringNotContainsString('>01<', $json['preview']);
        self::assertSame('custom', $this->connection()->fetchOne('SELECT role FROM manual_content_page WHERE id = ?', [$pageId]));

        $this->insertIntro();
        $browser->request('GET', '/manual/stranka/' . self::INTRO);
        self::assertResponseRedirects('/manual/' . TestDataFixture::MANUAL_1_ID . '/uvodni-stranka');
    }

    public function testRenameAndDelete(): void
    {
        $browser = $this->owner();
        $pageId = $this->add($browser, 'Původní', 'layout-7');
        $token = $this->token($browser);

        $browser->request('POST', '/manual/stranka/' . $pageId . '/prejmenovat', ['_token' => $token, 'name' => 'Nový název']);
        self::assertResponseRedirects(self::LIST_URL);
        self::assertSame('Nový název', $this->connection()->fetchOne('SELECT name FROM manual_content_page WHERE id = ?', [$pageId]));

        // Deleting needs the token…
        $browser->request('POST', '/manual/stranka/' . $pageId . '/smazat', ['_token' => 'nope']);
        self::assertResponseRedirects(self::LIST_URL);
        self::assertSame(1, $this->customCount());

        // …and is POST only.
        $browser->request('GET', '/manual/stranka/' . $pageId . '/smazat');
        self::assertResponseStatusCodeSame(405);

        $browser->request('POST', '/manual/stranka/' . $pageId . '/smazat', ['_token' => $token]);
        self::assertResponseRedirects(self::LIST_URL);
        self::assertSame(0, $this->customCount());
    }

    public function testTheIntroCannotBeDeleted(): void
    {
        $browser = $this->owner();
        $this->insertIntro();

        $browser->request('POST', '/manual/stranka/' . self::INTRO . '/smazat', ['_token' => $this->token($browser)]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(1, $this->connection()->fetchOne("SELECT COUNT(*) FROM manual_content_page WHERE role = 'intro'"));
    }

    public function testOnlyEditorsOfTheManualGetIn(): void
    {
        $owner = $this->owner();
        $pageId = $this->add($owner, 'Soukromá', 'layout-7');
        $token = $this->token($owner);

        foreach ([TestDataFixture::USER_2_EMAIL, TestDataFixture::SHARED_USER_EMAIL] as $email) {
            TestingLogin::logInAsUser($owner, $email);

            $owner->request('GET', '/manual/stranka/' . $pageId);
            self::assertResponseStatusCodeSame(403);
            $owner->request('POST', '/manual/stranka/' . $pageId, ['_token' => $token, 'payload' => '{}']);
            self::assertResponseStatusCodeSame(403);
            $owner->request('POST', '/manual/stranka/' . $pageId . '/prejmenovat', ['_token' => $token, 'name' => 'X']);
            self::assertResponseStatusCodeSame(403);
            $owner->request('POST', '/manual/stranka/' . $pageId . '/smazat', ['_token' => $token]);
            self::assertResponseStatusCodeSame(403);
            $owner->request('POST', self::ADD_URL, ['_token' => $token, 'name' => 'X']);
            self::assertResponseStatusCodeSame(403);
            $owner->request('POST', self::SORT_URL, content: (string) json_encode(['sorted' => ['content:' . $pageId]]));
            self::assertResponseStatusCodeSame(403);
        }

        self::assertSame(1, $this->customCount());

        // An admin may.
        TestingLogin::logInAsUser($owner, TestDataFixture::ADMIN_USER_EMAIL);
        $owner->request('GET', '/manual/stranka/' . $pageId);
        self::assertResponseIsSuccessful();
    }

    public function testTheListMixesBothKindsAndSortsThemTogether(): void
    {
        $browser = $this->owner();
        $this->insertMockup(self::MOCKUP_A, 0, 'Vizitky');
        $this->insertMockup(self::MOCKUP_B, 1, 'Merch');
        $this->insertIntro();
        $pageId = $this->add($browser, 'Tón komunikace', 'layout-7');

        $crawler = $browser->request('GET', self::LIST_URL);
        self::assertResponseIsSuccessful();

        // One list for both kinds (intro not in it), typed ids, same order in the rail.
        $expected = ['mockup:' . self::MOCKUP_A, 'mockup:' . self::MOCKUP_B, 'content:' . $pageId];
        self::assertSame($expected, $crawler->filter('ol.man-pages > li.man-pg')->each(static fn ($li): string => (string) $li->attr('data-entity-id')));
        self::assertSame($expected, $crawler->filter('ol.man-thumbs > li.man-th')->each(static fn ($li): string => (string) $li->attr('data-page-id')));
        self::assertCount(1, $crawler->filter('ol.man-pages[data-dragula-sort-url-value="' . self::SORT_URL . '"]'));
        self::assertSelectorExists('.man-mock[data-manual-mockup-pages-sort-url-value="' . self::SORT_URL . '"]');

        // Kind badges, the empty marker, the content card's edit link + dialogs.
        $content = $crawler->filter('li.man-pg-content');
        self::assertSame('Obsah', $content->filter('.man-kind')->first()->text());
        self::assertSame('Prázdná', $content->filter('.man-kind.pch')->text());
        self::assertCount(1, $content->filter('a.man-pgv[href="/manual/stranka/' . $pageId . '"]'));
        self::assertCount(1, $content->filter('.mcp-thumb .mockup-slot'));
        self::assertSame('Mockupy', $crawler->filter('li.man-pg-mockup .man-kind')->first()->text());
        self::assertCount(1, $crawler->filter('#man-rename-' . $pageId . ' form[action="/manual/stranka/' . $pageId . '/prejmenovat"]'));
        self::assertCount(1, $crawler->filter('#man-delete-' . $pageId . ' form[action="/manual/stranka/' . $pageId . '/smazat"]'));
        self::assertCount(1, $crawler->filter('#man-add-content form[action="' . self::ADD_URL . '"] input[name="layout"][value="layout-7"][checked]'));

        $browser->request('POST', self::SORT_URL, content: (string) json_encode(['sorted' => ['mockup:' . self::MOCKUP_B, 'content:' . $pageId, 'mockup:' . self::MOCKUP_A]]));
        self::assertResponseIsSuccessful();

        $crawler = $browser->request('GET', self::LIST_URL);
        self::assertSame(
            ['mockup:' . self::MOCKUP_B, 'content:' . $pageId, 'mockup:' . self::MOCKUP_A],
            $crawler->filter('ol.man-pages > li.man-pg')->each(static fn ($li): string => (string) $li->attr('data-entity-id')),
        );
    }

    public function testThePublicManualRendersBothKindsInOrderWithRunningNumbers(): void
    {
        $browser = $this->owner();
        $token = $this->token($browser);
        $this->insertMockup(self::MOCKUP_A, 0, 'Vizitky');
        $this->insertMockup(self::MOCKUP_B, 3, 'Merch');
        $filled = $this->add($browser, 'Tón komunikace', 'layout-7');   // position 4
        $empty = $this->add($browser, 'Rozpracovaná', 'layout-7');      // position 5, never filled
        $browser->request('POST', '/manual/stranka/' . $filled, [
            '_token' => $token,
            'payload' => (string) json_encode(['name' => 'Tón komunikace', 'layout' => 'layout-7', 'segments' => [['elements' => [
                ['id' => 'h', 'type' => 'heading', 'level' => 1, 'text' => 'Mluvíme lidsky'],
            ]]]]),
        ]);
        self::assertResponseIsSuccessful();

        // Content page between the two mockup pages.
        $browser->request('POST', self::SORT_URL, content: (string) json_encode(['sorted' => ['mockup:' . self::MOCKUP_A, 'content:' . $filled, 'content:' . $empty, 'mockup:' . self::MOCKUP_B]]));
        self::assertResponseIsSuccessful();

        $crawler = $browser->request('GET', self::PREVIEW_URL);
        self::assertResponseIsSuccessful();

        /** @var list<array{string, string, string}> $headings */
        $headings = $crawler->filter('.manual-page')->each(static fn ($page): array => [
            (string) $page->attr('id'),
            trim($page->filter('.page-heading span')->first()->text()),
            trim($page->filter('.page-heading .fw-bold')->text()),
        ]);
        $tail = array_slice($headings, -3);

        self::assertSame(['Vizitky', 'Tón komunikace', 'Merch'], array_column($tail, 2));
        // Running numbers continue the built-in pages, the empty page takes none.
        $first = (int) $tail[0][1];
        self::assertSame([sprintf('%02d', $first), sprintf('%02d', $first + 1), sprintf('%02d', $first + 2)], array_column($tail, 1));
        self::assertSame(['page-' . $first, 'page-' . ($first + 1), 'page-' . ($first + 2)], array_column($tail, 0));
        self::assertSame(count($headings), $first + 2);
        self::assertStringNotContainsString('Rozpracovaná', (string) $crawler->filter('body')->html());

        $contentPage = $crawler->filter('#page-' . ($first + 1));
        self::assertSame('Mluvíme lidsky', $contentPage->filter('h2.mcp-h1')->text());
        // The admin's pencil leads to the page's editor.
        self::assertCount(1, $contentPage->filter('a[href="/manual/stranka/' . $filled . '"]'));

        $browser->getCookieJar()->clear();
        $crawler = $browser->request('GET', self::PREVIEW_URL);
        self::assertCount(0, $crawler->filter('a[href="/manual/stranka/' . $filled . '"]'));
    }

    private function owner(): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        return $browser;
    }

    private function add(KernelBrowser $browser, string $name, string $layout): string
    {
        $browser->request('POST', self::ADD_URL, ['_token' => $this->token($browser), 'name' => $name, 'layout' => $layout]);
        self::assertResponseRedirects();

        $location = (string) $browser->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('~^/manual/stranka/[0-9a-f-]{36}$~', $location);

        return substr($location, strlen('/manual/stranka/'));
    }

    private function token(KernelBrowser $browser): string
    {
        $crawler = $browser->request('GET', self::LIST_URL);
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('#man-add-content input[name="_token"]')->attr('value');
        self::assertIsString($token);

        return $token;
    }

    private function insertMockup(string $id, int $position, string $name): void
    {
        $this->connection()->insert('manual_mockup_page', [
            'id' => $id,
            'created_at' => '2026-11-05 10:00:00',
            'layout' => MockupPageLayout::cases()[0]->value,
            'name' => $name,
            'manual_id' => TestDataFixture::MANUAL_1_ID,
            'images' => '[]',
            'position' => $position,
            'image_downloads' => '[]',
        ]);
    }

    private function insertIntro(): void
    {
        $this->connection()->insert('manual_content_page', [
            'id' => self::INTRO,
            'manual_id' => TestDataFixture::MANUAL_1_ID,
            'role' => 'intro',
            'name' => 'Úvod',
            'layout' => 'layout-7',
            'segments' => '[{"verticalAlign":"top","elements":[]}]',
            'position' => 0,
            'created_at' => '2026-11-09 12:00:00',
            'updated_at' => '2026-11-09 12:00:00',
        ]);
    }

    private function customCount(): int
    {
        $count = $this->connection()->fetchOne("SELECT COUNT(*) FROM manual_content_page WHERE role = 'custom'");
        self::assertIsInt($count);

        return $count;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }
}
