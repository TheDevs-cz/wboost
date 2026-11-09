<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Manual;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Entity\FileDirectory;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Entity\ManualContentPage;
use WBoost\Web\Entity\ManualMockupPage;
use WBoost\Web\Entity\Project;
use WBoost\Web\Repository\ManualContentPageRepository;
use WBoost\Web\Services\Manual\ManualGalleryFolders;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\FileSource;
use WBoost\Web\Value\ManualContent\ContentImageFit;
use WBoost\Web\Value\ManualContent\ContentSegment;
use WBoost\Web\Value\ManualContent\ImageElement;
use WBoost\Web\Value\ManualContent\ManualContentPageRole;
use WBoost\Web\Value\MockupPageLayout;

/**
 * Content pages P3, editor side: both manual editors pick pictures from the
 * project gallery (opened in `Manuál / <manual>`), uploads land in the
 * gallery, a pick is validated against the manual's project server-side,
 * and a picture moved to the Koš disappears for readers (stand-in for
 * editors).
 *
 * @covers \WBoost\Web\MessageHandler\Manual\SaveManualContentPageHandler
 * @covers \WBoost\Web\Controller\Manual\UploadManualContentImageController
 * @covers \WBoost\Web\Services\Manual\MockupPageSlotImages
 * @covers \WBoost\Web\Services\Manual\ManualImageAvailability
 */
final class ManualGalleryPickerTest extends WebTestCase
{
    private const string EDITOR_URL = '/manual/' . TestDataFixture::MANUAL_1_ID . '/uvodni-stranka';
    private const string UPLOAD_URL = '/manual/' . TestDataFixture::MANUAL_1_ID . '/obsah/obrazek';
    private const string PREVIEW_URL = '/nahled-manualu/project-1/manual-1';

    /** 1×1 transparent PNG. */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAAASUVORK5CYII=';

    /** @var list<string> */
    private array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            try {
                $this->filesystem()->delete($path);
            } catch (\Throwable) {
            }
        }
        $this->written = [];

        parent::tearDown();
    }

    public function testTheEditorOpensTheGalleryInTheManualFolder(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $crawler = $browser->request('GET', self::EDITOR_URL);
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('#manualGalleryModal [data-controller~="image-gallery"]'));
        self::assertSame('#manualGalleryModal', $crawler->filter('[data-controller="manual-content-editor"]')->attr('data-manual-content-editor-gallery-modal-value'));
        // The modal is NOT inside a form.
        self::assertCount(0, $crawler->filter('form #manualGalleryModal'));

        $folder = $this->manualFolder();
        self::assertNotNull($folder);
        self::assertSame('Manual 1', $folder->name);
        self::assertStringContainsString('Manual 1', $crawler->filter('#manualGalleryModal')->text());
    }

    public function testAGalleryPickIsResolvedServerSide(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);
        $file = $this->galleryFile(TestDataFixture::PROJECT_1_ID);

        // The client's path is only a snapshot — the server takes the row's.
        $this->save($browser, [['id' => 'i', 'type' => 'image', 'path' => 'manuals/' . TestDataFixture::MANUAL_1_ID . '/content/whatever.png', 'galleryImageId' => $file->id->toString(), 'fit' => 'natural', 'alt' => 'Z galerie']]);
        self::assertResponseIsSuccessful();

        $image = $this->introPage()?->imageElements()[0] ?? null;
        self::assertNotNull($image);
        self::assertSame($file->path, $image->path);
        self::assertSame($file->id->toString(), $image->galleryImageId);

        $browser->getCookieJar()->clear();
        $crawler = $browser->request('GET', self::PREVIEW_URL);
        self::assertNotSame('', $file->path);
        self::assertStringEndsWith($file->path, (string) $crawler->filter('#page-1 .mcp-img img')->attr('src'));
    }

    public function testAPickFromAnotherProjectIsRejected(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);
        $foreign = $this->galleryFile(TestDataFixture::PROJECT_2_ID);

        $this->save($browser, [['id' => 'i', 'type' => 'image', 'path' => 'manuals/' . TestDataFixture::MANUAL_1_ID . '/content/x.png', 'galleryImageId' => $foreign->id->toString(), 'fit' => 'natural']]);
        self::assertResponseStatusCodeSame(422);

        // …nor by its key alone.
        $this->save($browser, [['id' => 'i', 'type' => 'image', 'path' => $foreign->path, 'fit' => 'natural']]);
        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->introPage());
    }

    public function testAnUploadBecomesAGalleryPictureInTheManualFolder(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $tmp = tempnam(sys_get_temp_dir(), 'mcp') . '.png';
        file_put_contents($tmp, base64_decode(self::PNG));
        $browser->request('POST', self::UPLOAD_URL, ['_token' => $this->token($browser)], [
            'image' => new UploadedFile($tmp, 'Logo firmy.png', 'image/png', null, true),
        ], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        /** @var array{path: string, galleryImageId: string} $json */
        $json = json_decode((string) $browser->getResponse()->getContent(), true);
        $this->written[] = $json['path'];

        $this->em()->clear();
        $row = $this->em()->find(FileUpload::class, Uuid::fromString($json['galleryImageId']));
        self::assertNotNull($row);
        self::assertSame($json['path'], $row->path);
        self::assertSame('Logo firmy.png', $row->originalName);
        self::assertSame(1, $row->width);
        self::assertSame($this->manualFolder()?->id->toString(), $row->directory?->id->toString());
    }

    public function testATrashedPictureIsHiddenFromReadersAndFlaggedForEditors(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);
        $file = $this->galleryFile(TestDataFixture::PROJECT_1_ID);
        $this->save($browser, [['id' => 'i', 'type' => 'image', 'path' => $file->path, 'galleryImageId' => $file->id->toString(), 'fit' => 'natural']]);
        self::assertResponseIsSuccessful();

        $this->em()->getConnection()->executeStatement('UPDATE file_upload SET deleted_at = NOW() WHERE id = ?', [$file->id->toString()]);

        $crawler = $browser->request('GET', self::PREVIEW_URL);
        self::assertCount(0, $crawler->filter('#page-1 .mcp-img img'));
        self::assertStringContainsString('v koši galerie', $crawler->filter('#page-1 .manual-img-standin')->text());

        $crawler = $browser->request('GET', self::EDITOR_URL);
        /** @var array{segments: list<array{elements: list<array{unavailable: null|string}>}>} $state */
        $state = json_decode((string) $crawler->filter('[data-controller="manual-content-editor"]')->attr('data-manual-content-editor-state-value'), true);
        self::assertSame('trashed', $state['segments'][0]['elements'][0]['unavailable']);

        $browser->getCookieJar()->clear();
        $crawler = $browser->request('GET', self::PREVIEW_URL);
        self::assertCount(0, $crawler->filter('.mcp-img img'));
        self::assertCount(0, $crawler->filter('.manual-img-standin'));
    }

    /**
     * Content pages P4: a page whose ONLY content is an unavailable picture
     * (Koš, or its gallery row gone) renders nothing for readers, so it is
     * skipped and takes no page number. Editors keep it, with the stand-in.
     */
    public function testAPageWhoseOnlyPictureIsUnavailableIsSkippedForReaders(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);
        $file = $this->galleryFile(TestDataFixture::PROJECT_1_ID);
        $this->save($browser, [['id' => 'i', 'type' => 'image', 'path' => $file->path, 'galleryImageId' => $file->id->toString(), 'fit' => 'natural']]);
        self::assertResponseIsSuccessful();

        $this->em()->getConnection()->executeStatement('UPDATE file_upload SET deleted_at = NOW() WHERE id = ?', [$file->id->toString()]);

        $crawler = $browser->request('GET', self::PREVIEW_URL);
        self::assertCount(1, $crawler->filter('#page-1 .mcp-page .manual-img-standin'));
        $editorPages = $crawler->filter('.manual-page')->count();

        $browser->getCookieJar()->clear();
        $crawler = $browser->request('GET', self::PREVIEW_URL);
        self::assertCount(0, $crawler->filter('.mcp-page'));
        self::assertCount($editorPages - 1, $crawler->filter('.manual-page'));
        self::assertCount(0, $crawler->filter('#page-' . $editorPages));

        // A heading next to the same picture is reader content again.
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);
        $this->save($browser, [
            ['id' => 'h', 'type' => 'heading', 'level' => 1, 'text' => 'Vítejte'],
            ['id' => 'i', 'type' => 'image', 'path' => $file->path, 'galleryImageId' => $file->id->toString(), 'fit' => 'natural'],
        ]);
        self::assertResponseIsSuccessful();
        $browser->getCookieJar()->clear();
        $crawler = $browser->request('GET', self::PREVIEW_URL);
        self::assertCount(1, $crawler->filter('#page-1 .mcp-page'));
        self::assertCount(0, $crawler->filter('.manual-img-standin'));
    }

    public function testACustomPageWhoseOnlyPictureLostItsGalleryRowIsSkippedForReaders(): void
    {
        $browser = self::createClient();
        $manual = $this->em()->find(Manual::class, Uuid::fromString(TestDataFixture::MANUAL_1_ID));
        self::assertNotNull($manual);
        $page = new ManualContentPage(Uuid::uuid7(), $manual, ManualContentPageRole::Custom, 'Ztracený obrázek', MockupPageLayout::Layout7, [
            new ContentSegment(elements: [
                new ImageElement('i', 'manuals/' . TestDataFixture::MANUAL_1_ID . '/content/gone.png', ContentImageFit::Natural, '', Uuid::uuid7()->toString()),
            ]),
        ], 1000, new DateTimeImmutable());
        $this->em()->persist($page);
        $this->em()->flush();

        $browser->request('GET', self::PREVIEW_URL);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Ztracený obrázek', (string) $browser->getResponse()->getContent());

        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);
        $crawler = $browser->request('GET', self::PREVIEW_URL);
        self::assertStringContainsString('Ztracený obrázek', $crawler->filter('.manual-page')->last()->text());
        self::assertCount(1, $crawler->filter('.manual-page')->last()->filter('.manual-img-standin'));
    }

    public function testAMockupSlotTakesAGalleryPickOfItsOwnProjectOnly(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);
        $own = $this->galleryFile(TestDataFixture::PROJECT_1_ID);
        $foreign = $this->galleryFile(TestDataFixture::PROJECT_2_ID);

        $crawler = $browser->request('GET', '/manual/' . TestDataFixture::MANUAL_1_ID . '/add-mockup-page');
        self::assertResponseIsSuccessful();
        self::assertCount(MockupPageLayout::maxUploadInputsCount(), $crawler->filter('input[name^="manual_mockup_page_form[galleryImages]"]'));
        self::assertCount(1, $crawler->filter('#manualGalleryModal'));
        self::assertCount(0, $crawler->filter('form #manualGalleryModal'));

        $form = $crawler->filter('form[name="manual_mockup_page_form"]')->form();
        $form['manual_mockup_page_form[name]'] = 'Z galerie';
        $form['manual_mockup_page_form[layout]'] = MockupPageLayout::Layout8->value;
        $form['manual_mockup_page_form[galleryImages][0]'] = $own->id->toString();
        $form['manual_mockup_page_form[galleryImages][1]'] = $foreign->id->toString();
        $browser->submit($form);
        self::assertResponseRedirects();

        $page = $this->mockupPage('Z galerie');
        self::assertSame([$own->path, null], $page->images);

        // An upload for the same slot wins over a pick; it lands in the gallery.
        $crawler = $browser->request('GET', '/edit-manual-mockup-page/' . $page->id->toString());
        $form = $crawler->filter('form[name="manual_mockup_page_form"]')->form();
        $form['manual_mockup_page_form[galleryImages][1]'] = $own->id->toString();
        $tmp = tempnam(sys_get_temp_dir(), 'mock') . '.png';
        file_put_contents($tmp, base64_decode(self::PNG));
        $field = $form['manual_mockup_page_form[images][1]'];
        assert($field instanceof FileFormField);
        $field->upload($tmp);
        $browser->submit($form);
        self::assertResponseRedirects();

        $page = $this->mockupPage('Z galerie');
        self::assertSame($own->path, $page->images[0]);
        self::assertIsString($page->images[1]);
        self::assertStringStartsWith('manuals/' . TestDataFixture::MANUAL_1_ID . '/pages/', $page->images[1]);
        $this->written[] = $page->images[1];

        $row = $this->em()->getRepository(FileUpload::class)->findOneBy(['path' => $page->images[1]]);
        self::assertInstanceOf(FileUpload::class, $row);
        self::assertSame($this->manualFolder()?->id->toString(), $row->directory?->id->toString());
    }

    /**
     * @param list<array<string, mixed>> $elements
     */
    private function save(KernelBrowser $browser, array $elements): void
    {
        $browser->request('POST', self::EDITOR_URL, [
            '_token' => $this->token($browser),
            'payload' => (string) json_encode(['name' => 'Úvod', 'layout' => 'layout-7', 'segments' => [['elements' => $elements]]]),
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

    private function galleryFile(string $projectId): FileUpload
    {
        $project = $this->em()->find(Project::class, Uuid::fromString($projectId));
        self::assertNotNull($project);
        $id = Uuid::uuid7();
        $path = sprintf('file-upload/%s/%s.png', $projectId, $id->toString());
        $this->filesystem()->write($path, (string) base64_decode(self::PNG));
        $this->written[] = $path;

        $file = new FileUpload($id, $project, new DateTimeImmutable(), FileSource::ProjectImage, $path, null, 'obrazek.png', 1, 1);
        $this->em()->persist($file);
        $this->em()->flush();

        return $file;
    }

    private function manualFolder(): null|FileDirectory
    {
        $folder = $this->em()->getRepository(FileDirectory::class)->findOneBy(['systemKey' => ManualGalleryFolders::manualKey(Uuid::fromString(TestDataFixture::MANUAL_1_ID))]);

        return $folder instanceof FileDirectory ? $folder : null;
    }

    private function introPage(): null|ManualContentPage
    {
        $this->em()->clear();

        return self::getContainer()->get(ManualContentPageRepository::class)->introFor(Uuid::fromString(TestDataFixture::MANUAL_1_ID));
    }

    private function mockupPage(string $name): ManualMockupPage
    {
        $this->em()->clear();
        $page = $this->em()->getRepository(ManualMockupPage::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(ManualMockupPage::class, $page);

        return $page;
    }

    private function filesystem(): Filesystem
    {
        return self::getContainer()->get('oneup_flysystem.minio_filesystem');
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
