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
 * The manual preview renders "Šířka loga v manuálu" as a scale of the card's
 * DEFAULT logo size: `--logo-scale` on the `<img>`, which app.css multiplies
 * into the default width and height cap. 100 % / no value = no `--logo-scale`.
 *
 * @covers \WBoost\Web\Controller\Manual\ManualPreviewController
 */
final class ManualPreviewLogoScaleTest extends WebTestCase
{
    private const string PREVIEW_URL = '/nahled-manualu/project-1/manual-1';

    public function testScalesRelativeToTheDefault(): void
    {
        $browser = self::createClient();
        $this->giveManualLogos(horizontalWidth: 50, symbolWidth: null, slotWidths: [
            'basic_logos.horizontal.base' => 150,
            'symbol.symbol.base' => 200,
            'symbol.symbol.darkBackground' => 100,
        ]);

        $crawler = $browser->request('GET', self::PREVIEW_URL);
        self::assertResponseIsSuccessful();

        // the variant's 50 % reaches every card without its own value
        self::assertSame('--logo-scale: 0.5', $this->style($crawler, 'horizontal_backgrounds.horizontal.darkBackground'));
        // a card's own value wins
        self::assertSame('--logo-scale: 1.5', $this->style($crawler, 'basic_logos.horizontal.base'));
        self::assertSame('--logo-scale: 2', $this->style($crawler, 'symbol.symbol.base'));
        // 100 % and no value at all both mean the default: no scale at all
        self::assertNull($this->style($crawler, 'symbol.symbol.darkBackground'));
        self::assertNull($this->style($crawler, 'symbol.symbol.lightBackground'));
    }

    /**
     * The `--logo-scale` declaration of a card's `<img>`, or null. The inline
     * style also carries `--logo-ratio` (the protective-zone card's input).
     */
    private function style(Crawler $crawler, string $slot): null|string
    {
        $img = $crawler->filter(sprintf('img[data-logo-slot="%s"]', $slot));
        self::assertCount(1, $img, $slot);

        foreach (explode(';', (string) $img->attr('style')) as $declaration) {
            if (str_starts_with(trim($declaration), '--logo-scale:')) {
                return trim($declaration);
            }
        }

        return null;
    }

    /**
     * @param array<string, int> $slotWidths
     */
    private function giveManualLogos(null|int $horizontalWidth, null|int $symbolWidth, array $slotWidths): void
    {
        /** @var FilesystemOperator $filesystem */
        $filesystem = self::getContainer()->get('oneup_flysystem.minio_filesystem');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 100"><rect width="300" height="100" fill="#123456"/></svg>';

        foreach (['horizontal', 'vertical', 'symbol'] as $name) {
            $filesystem->write("manuals/test/logo-$name.svg", $svg);
        }

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $manual = $entityManager->find(Manual::class, Uuid::fromString(TestDataFixture::MANUAL_1_ID));
        assert($manual instanceof Manual);

        $manual->editLogo(new Logo(
            horizontal: new SvgImage('manuals/test/logo-horizontal.svg', ['#123456'], [], null, null, $horizontalWidth),
            vertical: new SvgImage('manuals/test/logo-vertical.svg', ['#123456'], [], null, null, null),
            horizontalWithClaim: null,
            verticalWithClaim: null,
            symbol: new SvgImage('manuals/test/logo-symbol.svg', ['#123456'], [], null, null, $symbolWidth),
        ));

        foreach ($slotWidths as $slot => $width) {
            $manual->editLogoSlotWidth($slot, $width);
        }

        $entityManager->flush();
        $entityManager->clear();
    }
}
