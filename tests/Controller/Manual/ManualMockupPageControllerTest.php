<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Manual;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\DomCrawler\Form;
use WBoost\Web\Entity\ManualMockupPage;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\MockupPageLayout;

/**
 * @covers \WBoost\Web\Controller\Manual\AddManualMockupPageController
 * @covers \WBoost\Web\Controller\Manual\EditManualMockupPageController
 * @covers \WBoost\Web\Controller\Manual\DownloadMockupPageFileController
 */
final class ManualMockupPageControllerTest extends WebTestCase
{
    private const string PNG_1X1_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=';

    public function testAddPageRendersLayoutPickerAndEditor(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $crawler = $browser->request('GET', '/manual/' . TestDataFixture::MANUAL_1_ID . '/add-mockup-page');

        $this->assertResponseIsSuccessful();
        // One radio card per layout, all slot file inputs present for client-side switching.
        self::assertCount(count(MockupPageLayout::cases()), $crawler->filter('.mockup-layout-option'));
        self::assertCount(
            MockupPageLayout::maxUploadInputsCount(),
            $crawler->filter('input[type="file"][name^="manual_mockup_page_form[images]"]'),
        );
        // Plus a downloadable-attachment input per slot and one for the page.
        self::assertCount(
            MockupPageLayout::maxUploadInputsCount(),
            $crawler->filter('input[type="file"][name^="manual_mockup_page_form[imageDownloads]"]'),
        );
        self::assertCount(1, $crawler->filter('input[type="file"][name="manual_mockup_page_form[downloadFile]"]'));
    }

    public function testAddCreatesPageAndSlicesImagesToLayoutSlotCount(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $crawler = $browser->request('GET', '/manual/' . TestDataFixture::MANUAL_1_ID . '/add-mockup-page');
        $form = $crawler->filter('form[name="manual_mockup_page_form"]')->form();

        $form['manual_mockup_page_form[name]'] = 'Vizitky';
        $form['manual_mockup_page_form[layout]'] = MockupPageLayout::Layout8->value;
        $this->uploadPng($form, 'manual_mockup_page_form[images][0]');
        // Slot index 2 does not exist in layout-8 (2 slots) — picked before
        // switching to a smaller layout; it must not be persisted.
        $this->uploadPng($form, 'manual_mockup_page_form[images][2]');

        $browser->submit($form);

        $this->assertResponseRedirects('/manual/' . TestDataFixture::MANUAL_1_ID . '/mockup-pages');

        $page = $this->findPage('Vizitky');
        self::assertSame(MockupPageLayout::Layout8, $page->layout);
        self::assertCount(2, $page->images);
        self::assertIsString($page->images[0]);
        self::assertNull($page->images[1]);

        // Listing renders the canonical grid: one filled slot, one placeholder.
        $crawler = $browser->followRedirect();
        $this->assertResponseIsSuccessful();
        $card = $crawler->filter('[data-entity-id="' . $page->id->toString() . '"]');
        self::assertCount(2, $card->filter('.mockup-slot'));
        self::assertCount(1, $card->filter('.mockup-slot img'));
        self::assertCount(1, $card->filter('.mockup-slot-placeholder'));
    }

    public function testEditReplacesAndRemovesImages(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        // Create a page with two images first.
        $crawler = $browser->request('GET', '/manual/' . TestDataFixture::MANUAL_1_ID . '/add-mockup-page');
        $form = $crawler->filter('form[name="manual_mockup_page_form"]')->form();
        $form['manual_mockup_page_form[name]'] = 'Merkantilie';
        $form['manual_mockup_page_form[layout]'] = MockupPageLayout::Layout8->value;
        $this->uploadPng($form, 'manual_mockup_page_form[images][0]');
        $this->uploadPng($form, 'manual_mockup_page_form[images][1]');
        $browser->submit($form);

        $page = $this->findPage('Merkantilie');
        $originalFirstImage = $page->images[0];

        // Replace slot 1, remove slot 0.
        $crawler = $browser->request('GET', '/edit-manual-mockup-page/' . $page->id->toString());
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="manual_mockup_page_form"]')->form();
        $form['manual_mockup_page_form[name]'] = 'Merkantilie 2';
        $form['manual_mockup_page_form[removeImages][0]'] = '1';
        $this->uploadPng($form, 'manual_mockup_page_form[images][1]');
        $browser->submit($form);

        $this->assertResponseRedirects('/manual/' . TestDataFixture::MANUAL_1_ID . '/mockup-pages');

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $page = $this->findPage('Merkantilie 2');
        self::assertNull($page->images[0]);
        self::assertIsString($page->images[1]);
        self::assertNotSame($originalFirstImage, $page->images[1]);
    }

    public function testUploadOverridesRemoveFlagForSameSlot(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $crawler = $browser->request('GET', '/manual/' . TestDataFixture::MANUAL_1_ID . '/add-mockup-page');
        $form = $crawler->filter('form[name="manual_mockup_page_form"]')->form();
        $form['manual_mockup_page_form[name]'] = 'Plakáty';
        $form['manual_mockup_page_form[layout]'] = MockupPageLayout::Layout7->value;
        $this->uploadPng($form, 'manual_mockup_page_form[images][0]');
        $browser->submit($form);

        $page = $this->findPage('Plakáty');

        $crawler = $browser->request('GET', '/edit-manual-mockup-page/' . $page->id->toString());
        $form = $crawler->filter('form[name="manual_mockup_page_form"]')->form();
        $form['manual_mockup_page_form[removeImages][0]'] = '1';
        $this->uploadPng($form, 'manual_mockup_page_form[images][0]');
        $browser->submit($form);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $page = $this->findPage('Plakáty');
        self::assertIsString($page->images[0]);
    }

    public function testAttachedFilesAreStoredAndDownloadable(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $crawler = $browser->request('GET', '/manual/' . TestDataFixture::MANUAL_1_ID . '/add-mockup-page');
        $form = $crawler->filter('form[name="manual_mockup_page_form"]')->form();

        $form['manual_mockup_page_form[name]'] = 'Tiskoviny';
        $form['manual_mockup_page_form[layout]'] = MockupPageLayout::Layout8->value;
        $this->uploadPng($form, 'manual_mockup_page_form[images][0]');
        $this->uploadFile($form, 'manual_mockup_page_form[downloadFile]', 'Podklady pro tisk.pdf', '%PDF-1.4 page');
        $this->uploadFile($form, 'manual_mockup_page_form[imageDownloads][0]', 'vizitka.pdf', '%PDF-1.4 slot');

        $browser->submit($form);
        $this->assertResponseRedirects('/manual/' . TestDataFixture::MANUAL_1_ID . '/mockup-pages');

        $page = $this->findPage('Tiskoviny');

        self::assertNotNull($page->downloadFile);
        self::assertSame('Podklady pro tisk.pdf', $page->downloadFile->fileName);
        self::assertSame('PDF', $page->downloadFile->format());
        self::assertStringContainsString('/pages/' . $page->id . '/files/page-', $page->downloadFile->path);

        // Per-slot files stay positionally aligned with the images.
        self::assertCount(2, $page->imageDownloads);
        $slotDownload = $page->imageDownload(0);
        self::assertNotNull($slotDownload);
        self::assertSame('vizitka.pdf', $slotDownload->fileName);
        self::assertNull($page->imageDownload(1));

        // Both are served back under the name they were uploaded with.
        $browser->request('GET', '/stahnout-mockup/' . $page->id . '/stranka');
        $this->assertResponseIsSuccessful();
        self::assertSame('%PDF-1.4 page', $browser->getResponse()->getContent());
        self::assertStringContainsString(
            'filename="Podklady pro tisk.pdf"',
            (string) $browser->getResponse()->headers->get('Content-Disposition'),
        );

        $browser->request('GET', '/stahnout-mockup/' . $page->id . '/0');
        $this->assertResponseIsSuccessful();
        self::assertSame('%PDF-1.4 slot', $browser->getResponse()->getContent());

        // A slot with no file attached is a 404, not an empty download.
        $browser->request('GET', '/stahnout-mockup/' . $page->id . '/1');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testDownloadButtonsAppearOnTheManualOnceFilesAreAttached(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $crawler = $browser->request('GET', '/manual/' . TestDataFixture::MANUAL_1_ID . '/add-mockup-page');
        $form = $crawler->filter('form[name="manual_mockup_page_form"]')->form();
        $form['manual_mockup_page_form[name]'] = 'Merch';
        $form['manual_mockup_page_form[layout]'] = MockupPageLayout::Layout8->value;
        $this->uploadPng($form, 'manual_mockup_page_form[images][0]');
        $browser->submit($form);

        $page = $this->findPage('Merch');
        $manual = $page->manual;
        $previewUrl = '/nahled-manualu/' . $manual->project->slug . '/' . $manual->slug;

        // Nothing attached yet — no download chrome anywhere on the manual.
        $crawler = $browser->request('GET', $previewUrl);
        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href^="/stahnout-mockup/' . $page->id . '"]'));

        $crawler = $browser->request('GET', '/edit-manual-mockup-page/' . $page->id);
        $form = $crawler->filter('form[name="manual_mockup_page_form"]')->form();
        $this->uploadFile($form, 'manual_mockup_page_form[downloadFile]', 'merch.zip', 'PK zip');
        $this->uploadFile($form, 'manual_mockup_page_form[imageDownloads][0]', 'triko.pdf', '%PDF slot');
        $browser->submit($form);

        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $crawler = $browser->request('GET', $previewUrl);
        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href="/stahnout-mockup/' . $page->id . '/stranka"]'));
        self::assertCount(1, $crawler->filter('a[href="/stahnout-mockup/' . $page->id . '/0"]'));
    }

    public function testRemovingAnAttachedFileAndReplacingItInTheSamePost(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $crawler = $browser->request('GET', '/manual/' . TestDataFixture::MANUAL_1_ID . '/add-mockup-page');
        $form = $crawler->filter('form[name="manual_mockup_page_form"]')->form();
        $form['manual_mockup_page_form[name]'] = 'Bannery';
        $form['manual_mockup_page_form[layout]'] = MockupPageLayout::Layout8->value;
        $this->uploadFile($form, 'manual_mockup_page_form[downloadFile]', 'stary.pdf', 'old page');
        $this->uploadFile($form, 'manual_mockup_page_form[imageDownloads][0]', 'stary-slot.pdf', 'old slot');
        $browser->submit($form);

        $page = $this->findPage('Bannery');

        $crawler = $browser->request('GET', '/edit-manual-mockup-page/' . $page->id);
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="manual_mockup_page_form"]')->form();
        // Slot 0: dropped. Page: flagged for removal but re-picked — like the
        // images, the later upload wins over the flag.
        $form['manual_mockup_page_form[removeImageDownloads][0]'] = '1';
        $form['manual_mockup_page_form[removeDownloadFile]'] = '1';
        $this->uploadFile($form, 'manual_mockup_page_form[downloadFile]', 'novy.pdf', 'new page');
        $browser->submit($form);

        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $page = $this->findPage('Bannery');
        self::assertNull($page->imageDownload(0));
        self::assertSame('novy.pdf', $page->downloadFile?->fileName);

        // The key is timestamped like every other upload here, so re-uploading
        // inside the same second reuses it — what must change is the bytes.
        $browser->request('GET', '/stahnout-mockup/' . $page->id . '/stranka');
        self::assertSame('new page', $browser->getResponse()->getContent());
    }

    public function testAttachedFileSurvivesAnEditThatTouchesNothing(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $crawler = $browser->request('GET', '/manual/' . TestDataFixture::MANUAL_1_ID . '/add-mockup-page');
        $form = $crawler->filter('form[name="manual_mockup_page_form"]')->form();
        $form['manual_mockup_page_form[name]'] = 'Polepy';
        $form['manual_mockup_page_form[layout]'] = MockupPageLayout::Layout8->value;
        $this->uploadFile($form, 'manual_mockup_page_form[imageDownloads][1]', 'polep.pdf', 'slot data');
        $browser->submit($form);

        $page = $this->findPage('Polepy');
        $originalPath = $page->imageDownload(1)?->path;

        $crawler = $browser->request('GET', '/edit-manual-mockup-page/' . $page->id);
        $form = $crawler->filter('form[name="manual_mockup_page_form"]')->form();
        $form['manual_mockup_page_form[name]'] = 'Polepy aut';
        $browser->submit($form);

        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $page = $this->findPage('Polepy aut');
        self::assertSame($originalPath, $page->imageDownload(1)?->path);
        self::assertSame('polep.pdf', $page->imageDownload(1)?->fileName);
    }

    private function findPage(string $name): ManualMockupPage
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $page = $entityManager->getRepository(ManualMockupPage::class)->findOneBy(['name' => $name]);
        assert($page instanceof ManualMockupPage);

        return $page;
    }

    private function uploadPng(Form $form, string $fieldName): void
    {
        $field = $form[$fieldName];
        assert($field instanceof FileFormField);

        $field->upload($this->temporaryPng());
    }

    /**
     * The DomCrawler sends the temp file's own basename as the client name,
     * and the client name is exactly what a download is served as — so the
     * file is created inside a throwaway directory under its real name.
     */
    private function uploadFile(Form $form, string $fieldName, string $fileName, string $contents): void
    {
        $field = $form[$fieldName];
        assert($field instanceof FileFormField);

        $directory = sys_get_temp_dir() . '/' . uniqid('mockup-file-', true);
        mkdir($directory);

        $path = $directory . '/' . $fileName;
        file_put_contents($path, $contents);

        $field->upload($path);
    }

    private function temporaryPng(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mockup') . '.png';
        file_put_contents($path, base64_decode(self::PNG_1X1_BASE64, true));

        return $path;
    }
}
