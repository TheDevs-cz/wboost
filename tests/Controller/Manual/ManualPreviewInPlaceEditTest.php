<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Manual;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\Logo;
use WBoost\Web\Value\ManualPage;
use WBoost\Web\Value\SvgImage;

/**
 * The manual preview's pencil modals save IN PLACE: their forms are
 * Turbo-enabled and a Turbo POST is answered with a stream replacing what the
 * save changed + the modal's body — never the modal itself (an open Bootstrap
 * modal replaced by a stream leaves its backdrop behind). A plain POST keeps
 * the redirect, anchored on the edited element.
 *
 * @covers \WBoost\Web\Controller\Manual\EditManualPageTextController
 * @covers \WBoost\Web\Controller\Manual\EditManualLogoColorsMappingController
 * @covers \WBoost\Web\Services\Manual\ManualPreviewTurboStream
 * @covers \WBoost\Web\Services\Manual\ManualEditForms
 */
final class ManualPreviewInPlaceEditTest extends WebTestCase
{
    private const string PREVIEW_URL = '/nahled-manualu/project-1/manual-1';

    private const array TURBO = ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml'];

    public function testPageTextSaveAnswersWithAStreamReplacingTheHeadingAndDescription(): void
    {
        $browser = $this->adminBrowser();
        $form = $this->pageTextForm($browser->request('GET', self::PREVIEW_URL), ManualPage::PrimaryColors, 'Naše barvy', 'Nový popis.');

        $browser->submit($form, [], self::TURBO);

        self::assertResponseStatusCodeSame(200);
        self::assertStringStartsWith('text/vnd.turbo-stream.html', (string) $browser->getResponse()->headers->get('Content-Type'));

        $streams = $this->streams($browser);
        self::assertSame([
            '#manual-page-title-primary_colors',
            '#manual-page-description-primary_colors',
            '#manual-page-text-form-primary_colors',
        ], array_keys($streams));

        self::assertStringContainsString('Naše barvy', $streams['#manual-page-title-primary_colors']);
        self::assertStringContainsString('<p>Nový popis.</p>', $streams['#manual-page-description-primary_colors']);
        // The description keeps the column the page gave it.
        self::assertStringContainsString('class="page-description col-3', $streams['#manual-page-description-primary_colors']);
        // The fresh modal body carries the saved values and its own CSRF field.
        self::assertStringContainsString('value="Naše barvy"', $streams['#manual-page-text-form-primary_colors']);
        self::assertStringContainsString('name="manual_page_text_primary_colors[_token]"', $streams['#manual-page-text-form-primary_colors']);
        // The modal element itself is never streamed.
        self::assertStringNotContainsString('class="modal ', (string) $browser->getResponse()->getContent());

        self::assertSame('Naše barvy', $this->manual()->pageTextTitleOverride(ManualPage::PrimaryColors));
    }

    public function testPageTextPlainPostRedirectsBackToTheHeading(): void
    {
        $browser = $this->adminBrowser();
        $form = $this->pageTextForm($browser->request('GET', self::PREVIEW_URL), ManualPage::SecondaryColors, 'Doplňkové', '');

        $browser->submit($form);

        self::assertResponseRedirects(self::PREVIEW_URL . '#manual-page-title-secondary_colors');
        self::assertSame('Doplňkové', $this->manual()->pageTextTitleOverride(ManualPage::SecondaryColors));
    }

    public function testPageTextValidationErrorStaysInTheModal(): void
    {
        $browser = $this->adminBrowser();
        $form = $this->pageTextForm($browser->request('GET', self::PREVIEW_URL), ManualPage::PrimaryColors, str_repeat('x', 300), '');

        $browser->submit($form, [], self::TURBO);

        self::assertResponseStatusCodeSame(422);
        $streams = $this->streams($browser);
        self::assertSame(['#manual-page-text-form-primary_colors'], array_keys($streams));
        self::assertStringContainsString('invalid-feedback', $streams['#manual-page-text-form-primary_colors']);
        self::assertNull($this->manual()->pageTextTitleOverride(ManualPage::PrimaryColors));
    }

    public function testPageTextIsForEditorsOnly(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_2_EMAIL);

        $browser->request('POST', '/manual/' . TestDataFixture::MANUAL_1_ID . '/texty-stranky/primary_colors', [
            'manual_page_text_primary_colors' => ['title' => 'Hacked'],
        ], [], self::TURBO);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->manual()->pageTextTitleOverride(ManualPage::PrimaryColors));
    }

    public function testPreviewRendersTurboFormsAndTheColourModalsOutsideTheCards(): void
    {
        $browser = $this->adminBrowser();
        $this->giveManualLogos();
        $crawler = $browser->request('GET', self::PREVIEW_URL);
        self::assertResponseIsSuccessful();

        // Opt-in under `<html data-turbo="false">`.
        self::assertSame('true', $crawler->filter('#manualPageTextModal-primary_colors form')->attr('data-turbo'));
        self::assertSame('true', $crawler->filter('#logoColorsModal-horizontal-darkBackground form')->attr('data-turbo'));

        // A stream replaces whole cards — none may contain a modal.
        self::assertCount(1, $crawler->filter('#logo-card-horizontal-darkBackground'));
        self::assertCount(0, $crawler->filter('.manual-image-wrapper .modal'));
        self::assertCount(1, $crawler->filter('#logo-card-horizontal-darkBackground [data-bs-target="#logoColorsModal-horizontal-darkBackground"]'));

        // A modal per colour variant of every logo the manual has an image
        // for — and none for a missing one.
        self::assertCount(5, $crawler->filter('[id^="logoColorsModal-horizontal-"]'));
        self::assertCount(0, $crawler->filter('[id^="logoColorsModal-horizontalWithClaim-"]'));

        // A reader gets none of it.
        $browser->getCookieJar()->clear();
        $crawler = $browser->request('GET', self::PREVIEW_URL);
        self::assertCount(0, $crawler->filter('.modal'));
    }

    public function testLogoColorsSaveAnswersWithAStreamReplacingTheCard(): void
    {
        $browser = $this->adminBrowser();
        $this->giveManualLogos();
        $form = $this->logoColorsForm($browser->request('GET', self::PREVIEW_URL), 'horizontal', 'darkBackground', [
            'background' => 'ABCDEF',
            'colors' => ['ABC123' => 'FF0000'],
            'opacities' => ['ABC123' => '50'],
        ]);

        $browser->submit($form, [], self::TURBO);

        self::assertResponseStatusCodeSame(200);
        $streams = $this->streams($browser);
        self::assertSame([
            '#logo-card-horizontal-darkBackground',
            '#logo-colors-form-horizontal-darkBackground',
        ], array_keys($streams));

        $card = new Crawler($streams['#logo-card-horizontal-darkBackground']);
        $wrapper = $card->filter('#logo-card-horizontal-darkBackground');
        self::assertCount(1, $wrapper);
        self::assertStringContainsString('#ABCDEF', (string) $wrapper->attr('style'));
        // Same card the page renders: pencils, download links, the recoloured logo.
        self::assertCount(1, $wrapper->filter('img[data-logo-slot="horizontal_backgrounds.horizontal.darkBackground"]'));
        self::assertCount(1, $wrapper->filter('[data-bs-target="#logoColorsModal-horizontal-darkBackground"]'));
        self::assertStringContainsString('background=ABCDEF', urldecode((string) $wrapper->filter('.dropdown-menu a')->first()->attr('href')));
        self::assertCount(0, $wrapper->filter('.modal'));

        self::assertStringContainsString('value="FF0000"', $streams['#logo-colors-form-horizontal-darkBackground']);
        self::assertStringContainsString('value="50"', $streams['#logo-colors-form-horizontal-darkBackground']);

        self::assertSame('ABCDEF', $this->manual()->logoBackground('horizontal', 'darkBackground'));
        self::assertSame(['ABC123' => 50], $this->manual()->logoColorOpacities('horizontal', 'darkBackground'));
    }

    public function testLogoColorsPlainPostRedirectsBackToTheCard(): void
    {
        $browser = $this->adminBrowser();
        $this->giveManualLogos();
        $form = $this->logoColorsForm($browser->request('GET', self::PREVIEW_URL), 'symbol', 'whiteBackground', [
            'background' => 'EEEEEE',
        ]);

        $browser->submit($form);

        self::assertResponseRedirects(self::PREVIEW_URL . '#logo-card-symbol-whiteBackground');
        self::assertSame('EEEEEE', $this->manual()->logoBackground('symbol', 'whiteBackground'));
    }

    public function testLogoColorsValidationErrorStaysInTheModal(): void
    {
        $browser = $this->adminBrowser();
        $this->giveManualLogos();
        $before = $this->manual()->logoBackground('horizontal', 'darkBackground');
        $form = $this->logoColorsForm($browser->request('GET', self::PREVIEW_URL), 'horizontal', 'darkBackground', [
            'background' => 'not-a-colour',
        ]);

        $browser->submit($form, [], self::TURBO);

        self::assertResponseStatusCodeSame(422);
        $streams = $this->streams($browser);
        self::assertSame(['#logo-colors-form-horizontal-darkBackground'], array_keys($streams));
        self::assertStringContainsString('invalid-feedback', $streams['#logo-colors-form-horizontal-darkBackground']);
        self::assertSame($before, $this->manual()->logoBackground('horizontal', 'darkBackground'));
    }

    public function testLogoColorsOfAMissingLogoVariantIsNotFound(): void
    {
        $browser = $this->adminBrowser();
        $this->giveManualLogos();

        $browser->request('POST', '/manual/' . TestDataFixture::MANUAL_1_ID . '/mapovani-barev-loga/horizontalWithClaim/darkBackground', [], [], self::TURBO);

        self::assertResponseStatusCodeSame(404);
    }

    private function adminBrowser(): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        return $browser;
    }

    private function pageTextForm(Crawler $crawler, ManualPage $page, string $title, string $description): Form
    {
        $name = 'manual_page_text_' . $page->value;

        return $crawler->filter('#manualPageTextModal-' . $page->value . ' form')->form([
            $name . '[title]' => $title,
            $name . '[description]' => $description,
        ]);
    }

    /**
     * @param array{background?: string, colors?: array<string, string>, opacities?: array<string, string>} $values
     */
    private function logoColorsForm(Crawler $crawler, string $logoVariant, string $colorVariant, array $values): Form
    {
        $name = sprintf('logo_colors_%s_%s', $logoVariant, $colorVariant);
        $form = $crawler->filter(sprintf('#logoColorsModal-%s-%s form', $logoVariant, $colorVariant))->form();

        if (isset($values['background'])) {
            $form[$name . '[background]'] = $values['background'];
        }

        foreach ($values['colors'] ?? [] as $from => $to) {
            $form[sprintf('%s[colors][%s]', $name, $from)] = $to;
        }

        foreach ($values['opacities'] ?? [] as $from => $percent) {
            $form[sprintf('%s[opacities][%s]', $name, $from)] = $percent;
        }

        return $form;
    }

    /**
     * @return array<string, string> `targets` selector => the template's HTML, in stream order
     */
    private function streams(KernelBrowser $browser): array
    {
        $streams = [];
        $crawler = new Crawler((string) $browser->getResponse()->getContent());

        foreach ($crawler->filter('turbo-stream') as $stream) {
            assert($stream instanceof \DOMElement);
            self::assertSame('replace', $stream->getAttribute('action'));
            $template = (new Crawler($stream))->filter('template');
            $streams[$stream->getAttribute('targets')] = $template->html();
        }

        return $streams;
    }

    private function giveManualLogos(): void
    {
        /** @var FilesystemOperator $filesystem */
        $filesystem = self::getContainer()->get('oneup_flysystem.minio_filesystem');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 100"><rect width="300" height="100" fill="#ABC123"/></svg>';

        foreach (['horizontal', 'vertical', 'symbol'] as $name) {
            $filesystem->write("manuals/test/logo-$name.svg", $svg);
        }

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $manual = $entityManager->find(Manual::class, Uuid::fromString(TestDataFixture::MANUAL_1_ID));
        assert($manual instanceof Manual);

        $manual->editLogo(new Logo(
            horizontal: new SvgImage('manuals/test/logo-horizontal.svg', ['#ABC123']),
            vertical: new SvgImage('manuals/test/logo-vertical.svg', ['#ABC123']),
            horizontalWithClaim: null,
            verticalWithClaim: null,
            symbol: new SvgImage('manuals/test/logo-symbol.svg', ['#ABC123']),
        ));

        $entityManager->flush();
        $entityManager->clear();
    }

    private function manual(): Manual
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $manual = $entityManager->find(Manual::class, Uuid::fromString(TestDataFixture::MANUAL_1_ID));
        assert($manual instanceof Manual);

        return $manual;
    }
}
