<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Manual;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Entity\Font;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Entity\ManualContentPage;
use WBoost\Web\Entity\ManualFont;
use WBoost\Web\Repository\ManualContentPageRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\ManualContent\RichTextElement;
use WBoost\Web\Value\ManualFontType;

/**
 * The intro content page: editor (GET), save (one JSON POST), picture upload,
 * and what the public manual renders from it.
 *
 * @covers \WBoost\Web\Controller\Manual\ManualIntroPageController
 * @covers \WBoost\Web\Controller\Manual\SaveManualIntroPageController
 * @covers \WBoost\Web\Controller\Manual\UploadManualContentImageController
 */
final class ManualIntroPageTest extends WebTestCase
{
    private const string EDITOR_URL = '/manual/' . TestDataFixture::MANUAL_1_ID . '/uvodni-stranka';
    private const string UPLOAD_URL = '/manual/' . TestDataFixture::MANUAL_1_ID . '/obsah/obrazek';
    private const string PREVIEW_URL = '/nahled-manualu/project-1/manual-1';
    private const string IMAGE = 'manuals/' . TestDataFixture::MANUAL_1_ID . '/content/test.png';

    /** 1×1 transparent PNG. */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    public function testOwnerOpensTheEditorWithTheDefaultsWhileNoIntroPageExists(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $crawler = $browser->request('GET', self::EDITOR_URL);

        self::assertResponseIsSuccessful();
        $editor = $crawler->filter('[data-controller="manual-content-editor"]');
        self::assertCount(1, $editor);

        $state = json_decode((string) $editor->attr('data-manual-content-editor-state-value'), true);
        self::assertSame(['name' => 'Úvod', 'layout' => 'layout-7', 'segments' => [['verticalAlign' => 'top', 'elements' => []]]], $state);

        // Palette colours of project 1 are what the colour picker offers.
        /** @var list<array{color: string, name: string}> $colors */
        $colors = json_decode((string) $editor->attr('data-manual-content-editor-colors-value'), true);
        self::assertSame(['#c8102e', '#004e7c'], array_column($colors, 'color'));
        self::assertSame('10000000', $editor->attr('data-manual-content-editor-max-file-size-value'));

        // The tab is in the manual nav.
        self::assertSelectorExists('.man-tabs a[href="' . self::EDITOR_URL . '"][aria-current="page"]');
    }

    public function testOnlyEditorsOfTheManualGetIn(): void
    {
        $browser = self::createClient();

        TestingLogin::logInAsUser($browser, TestDataFixture::USER_2_EMAIL);
        $browser->request('GET', self::EDITOR_URL);
        self::assertResponseStatusCodeSame(403);

        // Read-only share: may view the project, not edit the manual.
        TestingLogin::logInAsUser($browser, TestDataFixture::SHARED_USER_EMAIL);
        $browser->request('GET', self::EDITOR_URL);
        self::assertResponseStatusCodeSame(403);

        $browser->request('POST', self::EDITOR_URL, ['payload' => '{}']);
        self::assertResponseStatusCodeSame(403);

        $browser->request('POST', self::UPLOAD_URL);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnUntouchedManualWithoutIntroImageHasNoIntroPage(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', self::PREVIEW_URL);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.mcp-page'));
    }

    public function testSaveCreatesTheIntroPageWhichTheManualRendersFirst(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);
        $this->addRubikToManual();
        $this->writeImage(self::IMAGE);

        $this->save($browser, [
            'name' => 'Vítejte',
            'layout' => 'layout-8',
            'segments' => [
                ['verticalAlign' => 'center', 'elements' => [
                    ['id' => 'h', 'type' => 'heading', 'level' => 1, 'text' => 'Náš <příběh>'],
                    ['id' => 't', 'type' => 'richText', 'doc' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
                        ['type' => 'text', 'text' => 'Rubik červeně', 'marks' => [
                            ['type' => 'font', 'attrs' => ['family' => 'Rubik (Rubik Bold)']],
                            ['type' => 'color', 'attrs' => ['color' => '#C8102E']],
                        ]],
                        ['type' => 'text', 'text' => ' cizí', 'marks' => [
                            ['type' => 'bold'],
                            ['type' => 'font', 'attrs' => ['family' => 'Comic Sans']],
                            ['type' => 'color', 'attrs' => ['color' => '#00ff00']],
                        ]],
                    ]]]]],
                ]],
                ['elements' => [
                    ['id' => 'i', 'type' => 'image', 'path' => self::IMAGE, 'fit' => 'cover', 'alt' => 'Ukázka', 'url' => 'ignored'],
                ]],
            ],
        ]);

        self::assertResponseIsSuccessful();
        /** @var array{preview: string, state: array{name: string}} $json */
        $json = json_decode((string) $browser->getResponse()->getContent(), true);
        self::assertStringContainsString('mcp-page', $json['preview']);
        self::assertSame('Vítejte', $json['state']['name']);

        $page = $this->introPage();
        self::assertNotNull($page);
        self::assertSame('Vítejte', $page->name);
        self::assertSame(0, $page->position);

        // Fonts / colours the manual does not allow were dropped at save.
        $text = $page->segment(0)->elements[1];
        self::assertInstanceOf(RichTextElement::class, $text);
        /** @var list<array{marks: list<array<string, mixed>>}> $inline */
        $inline = $text->doc->blocks()[0]['content'] ?? [];
        self::assertSame([['type' => 'bold']], $inline[1]['marks']);
        self::assertCount(2, $inline[0]['marks']);

        $crawler = $browser->request('GET', self::PREVIEW_URL);
        self::assertResponseIsSuccessful();

        $intro = $crawler->filter('#page-1');
        self::assertStringContainsString('Vítejte', $intro->filter('.page-heading')->text());
        self::assertSame('Náš <příběh>', $intro->filter('h2.mcp-h1')->text());
        self::assertSame('font-family: "Rubik (Rubik Bold)"; color: #c8102e', $intro->filter('.mcp-rt p > span')->attr('style'));
        self::assertSame('Rubik červeně', $intro->filter('.mcp-rt p > span')->text());
        self::assertSame(' cizí', $intro->filter('.mcp-rt p > strong')->text(normalizeWhitespace: false));
        self::assertCount(0, $intro->filter('.mcp-rt p > strong span'));
        self::assertSame('Ukázka', $intro->filter('.mcp-img-cover img')->attr('alt'));
        self::assertStringContainsString('aspect-ratio: 908 / 798', (string) $intro->filter('.mcp-img-cover')->attr('style'));
        // The editor pencil on the page heading for an admin.
        self::assertCount(1, $intro->filter('a[href="' . self::EDITOR_URL . '"]'));

        // Saving again updates the same intro, never creates a second one.
        $this->save($browser, ['name' => 'Úvod', 'layout' => 'layout-7', 'segments' => [['elements' => []]]]);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->introCount());
        self::assertSame('Úvod', $this->introPage()?->name);

        // Emptied → the manual shows no intro page again.
        $crawler = $browser->request('GET', self::PREVIEW_URL);
        self::assertCount(0, $crawler->filter('.mcp-page'));
    }

    public function testReadersSeeNoEditPencil(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);
        $this->save($browser, ['name' => 'Úvod', 'layout' => 'layout-7', 'segments' => [['elements' => [['id' => 'h', 'type' => 'heading', 'level' => 2, 'text' => 'Ahoj']]]]]);
        self::assertResponseIsSuccessful();

        $browser->getCookieJar()->clear();
        $crawler = $browser->request('GET', self::PREVIEW_URL);

        self::assertSame('Ahoj', $crawler->filter('#page-1 h3.mcp-h2')->text());
        self::assertCount(0, $crawler->filter('a[href="' . self::EDITOR_URL . '"]'));
    }

    public function testRejectsAPictureOfAnotherManual(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $this->save($browser, ['name' => 'Úvod', 'layout' => 'layout-7', 'segments' => [['elements' => [
            ['id' => 'i', 'type' => 'image', 'path' => 'manuals/' . TestDataFixture::MANUAL_2_ID . '/intro-1.png', 'fit' => 'natural'],
        ]]]]);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->introPage());
    }

    public function testRejectsAnInvalidPayload(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $this->save($browser, ['name' => 'Úvod', 'layout' => 'layout-7', 'segments' => [['elements' => [
            ['id' => 't', 'type' => 'richText', 'doc' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]],
            ]]]]],
        ]]]]);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->introPage());
    }

    public function testRejectsAMissingCsrfToken(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $browser->request('POST', self::EDITOR_URL, ['payload' => (string) json_encode(['name' => 'x', 'layout' => 'layout-7', 'segments' => [['elements' => []]]])]);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->introPage());
    }

    public function testUploadStoresThePictureUnderTheManual(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $file = tempnam(sys_get_temp_dir(), 'mcp') . '.png';
        file_put_contents($file, base64_decode(self::PNG));

        $browser->request('POST', self::UPLOAD_URL, ['_token' => $this->token($browser)], [
            'image' => new UploadedFile($file, 'logo.png', 'image/png', null, true),
        ], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        /** @var array{path: non-empty-string, url: string, width: int} $json */
        $json = json_decode((string) $browser->getResponse()->getContent(), true);
        self::assertMatchesRegularExpression('~^manuals/' . TestDataFixture::MANUAL_1_ID . '/content/[0-9a-f-]{36}\.png$~', $json['path']);
        self::assertStringEndsWith($json['path'], $json['url']);
        self::assertSame(1, $json['width']);
        self::assertTrue($this->filesystem()->fileExists($json['path']));

        // …and the uploaded key is accepted by the save.
        $this->save($browser, ['name' => 'Úvod', 'layout' => 'layout-7', 'segments' => [['elements' => [
            ['id' => 'i', 'type' => 'image', 'path' => $json['path'], 'fit' => 'natural'],
        ]]]]);
        self::assertResponseIsSuccessful();

        $this->filesystem()->delete($json['path']);
    }

    public function testUploadRejectsANonImage(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $file = tempnam(sys_get_temp_dir(), 'mcp') . '.txt';
        file_put_contents($file, 'not a picture');

        $browser->request('POST', self::UPLOAD_URL, ['_token' => $this->token($browser)], [
            'image' => new UploadedFile($file, 'notes.txt', 'text/plain', null, true),
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function save(KernelBrowser $browser, array $state): void
    {
        $browser->request('POST', self::EDITOR_URL, [
            '_token' => $this->token($browser),
            'payload' => (string) json_encode($state),
        ], [], ['HTTP_ACCEPT' => 'application/json']);
    }

    private function token(KernelBrowser $browser): string
    {
        $crawler = $browser->request('GET', self::EDITOR_URL);
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('[data-manual-content-editor-csrf-value]')->attr('data-manual-content-editor-csrf-value');
        self::assertIsString($token);

        return $token;
    }

    private function introPage(): null|ManualContentPage
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(ManualContentPageRepository::class)->introFor(Uuid::fromString(TestDataFixture::MANUAL_1_ID));
    }

    private function introCount(): int
    {
        $count = self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne("SELECT COUNT(*) FROM manual_content_page WHERE role = 'intro'");

        return is_numeric($count) ? (int) $count : -1;
    }

    private function addRubikToManual(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $manual = $entityManager->find(Manual::class, Uuid::fromString(TestDataFixture::MANUAL_1_ID));
        $font = $entityManager->find(Font::class, Uuid::fromString(TestDataFixture::FONT_RUBIK_ID));
        self::assertNotNull($manual);
        self::assertNotNull($font);

        $entityManager->persist(new ManualFont(Uuid::uuid7(), $manual, $font, ManualFontType::Primary, null, 0, new DateTimeImmutable(), $font->faceNames()));
        $entityManager->flush();
    }

    private function writeImage(string $path): void
    {
        $this->filesystem()->write($path, (string) base64_decode(self::PNG));
    }

    private function filesystem(): Filesystem
    {
        return self::getContainer()->get('oneup_flysystem.minio_filesystem');
    }
}
