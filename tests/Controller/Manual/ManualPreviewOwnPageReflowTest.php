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
use WBoost\Web\Value\SvgImage;

/**
 * When a logo variant is promoted to its own page, the shared pages it leaves
 * must not keep an empty column: the remaining variant's cards re-flow to the
 * full width. With both variants present the layout is untouched.
 *
 * @covers \WBoost\Web\Controller\Manual\ManualPreviewController
 */
final class ManualPreviewOwnPageReflowTest extends WebTestCase
{
    private const string PREVIEW_URL = '/nahled-manualu/project-1/manual-1';

    public function testBothVariantsKeepTheTwoColumnLayout(): void
    {
        $browser = self::createClient();
        $this->giveManualLogos([]);

        $crawler = $browser->request('GET', self::PREVIEW_URL);
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('.manual-logo-reflow'));
        // one column per variant, three cards stacked in each
        self::assertSame('col-sm-6', $this->column($crawler, 'horizontal_backgrounds.horizontal.darkBackground'));
        self::assertCount(3, $this->cell($crawler, 'horizontal_backgrounds.horizontal.darkBackground')->filter('[data-logo-slot]'));
        self::assertSame('col-6', $this->column($crawler, 'basic_logos.vertical.base'));
        self::assertSame('col-6', $this->column($crawler, 'logos_with_claim.verticalWithClaim.base'));
    }

    public function testRemainingCardsReflowWhenTheClaimVariantsArePromoted(): void
    {
        $browser = self::createClient();
        $this->giveManualLogos(['horizontalWithClaim', 'verticalWithClaim']);

        $crawler = $browser->request('GET', self::PREVIEW_URL);
        self::assertResponseIsSuccessful();

        foreach (['horizontal_backgrounds.horizontal', 'vertical_backgrounds.vertical'] as $prefix) {
            foreach (['darkBackground', 'lightBackground', 'oneColor'] as $colorVariant) {
                $slot = $prefix . '.' . $colorVariant;
                // every card gets its own half-width cell of the grid …
                self::assertSame('col-sm-6', $this->column($crawler, $slot), $slot);
                self::assertCount(1, $this->cell($crawler, $slot)->filter('[data-logo-slot]'), $slot);
                // … inside a row marked as re-flowed
                self::assertStringContainsString('manual-logo-reflow', (string) $this->cell($crawler, $slot)->ancestors()->first()->attr('class'));
            }
        }

        self::assertCount(0, $crawler->filter('[data-logo-slot^="horizontal_backgrounds.horizontalWithClaim."]'));
        // basic_logos still has both vertical and symbol → unchanged
        self::assertSame('col-6', $this->column($crawler, 'basic_logos.vertical.base'));
    }

    public function testALoneCardOnTheBasicAndClaimPagesTakesTheFullWidth(): void
    {
        $browser = self::createClient();
        $this->giveManualLogos(['vertical', 'horizontalWithClaim']);

        $crawler = $browser->request('GET', self::PREVIEW_URL);
        self::assertResponseIsSuccessful();

        self::assertSame('col-12', $this->column($crawler, 'basic_logos.symbol.base'));
        self::assertSame('col-12', $this->column($crawler, 'logos_with_claim.verticalWithClaim.base'));
    }

    private function cell(Crawler $crawler, string $slot): Crawler
    {
        $img = $crawler->filter(sprintf('img[data-logo-slot="%s"]', $slot));
        self::assertCount(1, $img, $slot);

        return $img->closest('[class*="col-"]') ?? self::fail('No column for ' . $slot);
    }

    private function column(Crawler $crawler, string $slot): string
    {
        return trim((string) $this->cell($crawler, $slot)->attr('class'));
    }

    /**
     * Every variant uploaded; the listed ones promoted to their own page.
     *
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
