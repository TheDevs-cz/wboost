<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Manual;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\Logo;
use WBoost\Web\Value\LogoColorVariant;
use WBoost\Web\Value\SvgImage;

/**
 * A coloured card's download links carry `colorVariant`, so the file is named
 * "<manual> - <logo> - <colour variant>.<ext>" (DownloadLogoImageController);
 * the plain base cards don't.
 *
 * @covers \WBoost\Web\Controller\Manual\ManualPreviewController
 */
final class ManualPreviewDownloadLinksTest extends WebTestCase
{
    private const string PREVIEW_URL = '/nahled-manualu/project-1/manual-1';

    public function testColouredCardsPassTheirColourVariant(): void
    {
        $browser = self::createClient();
        $this->giveManualLogos(ownPage: ['symbol']);

        $crawler = $browser->request('GET', self::PREVIEW_URL);
        self::assertResponseIsSuccessful();

        $cards = $crawler->filter('[id^="logo-card-"]');
        self::assertGreaterThan(10, $cards->count());

        $cards->each(function (Crawler $card): void {
            $id = (string) $card->attr('id');
            $colorVariant = LogoColorVariant::from((string) preg_replace('/^logo-card-\w+-/', '', $id));
            $links = $card->filter('a[href*="/stahnout-logo/"]');

            self::assertCount(3, $links, $id);
            $links->each(function (Crawler $link) use ($id, $colorVariant): void {
                self::assertStringContainsString('colorVariant=' . $colorVariant->value, (string) $link->attr('href'), $id);
            });
        });

        // the promoted symbol's own page is covered too
        self::assertCount(1, $crawler->filter('#logo-card-symbol-oneColor'));

        // base (plain, white) card: no colour part in the name
        $base = $crawler->filter('img[data-logo-slot="basic_logos.horizontal.base"]')->closest('.manual-image-wrapper');
        self::assertNotNull($base);
        self::assertStringNotContainsString('colorVariant', (string) $base->filter('a[href*="/stahnout-logo/"]')->first()->attr('href'));
    }

    /**
     * @param list<string> $ownPage
     */
    private function giveManualLogos(array $ownPage): void
    {
        /** @var FilesystemOperator $filesystem */
        $filesystem = self::getContainer()->get('oneup_flysystem.minio_filesystem');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 100"><rect width="300" height="100" fill="#123456"/></svg>';

        $images = [];
        foreach (['horizontal', 'vertical', 'horizontalWithClaim', 'verticalWithClaim', 'symbol'] as $name) {
            $filesystem->write("manuals/test/logo-$name.svg", $svg);
            $images[$name] = new SvgImage("manuals/test/logo-$name.svg", ['#123456'], [], null, null, null, in_array($name, $ownPage, true));
        }

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $manual = $entityManager->find(Manual::class, Uuid::fromString(TestDataFixture::MANUAL_1_ID));
        assert($manual instanceof Manual);

        $manual->editLogo(new Logo(...$images));

        $entityManager->flush();
        $entityManager->clear();
    }
}
