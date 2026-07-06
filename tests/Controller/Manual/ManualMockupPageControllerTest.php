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
        self::assertCount(MockupPageLayout::maxUploadInputsCount(), $crawler->filter('input[type="file"]'));
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

    private function temporaryPng(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mockup') . '.png';
        file_put_contents($path, base64_decode(self::PNG_1X1_BASE64, true));

        return $path;
    }
}
