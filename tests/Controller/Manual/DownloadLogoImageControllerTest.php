<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Manual;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\Logo;
use WBoost\Web\Value\SvgImage;

/**
 * A downloaded logo is named `<manual> - <logo variant>[ - <colour variant>].<ext>`
 * — never the project slug + the internal variant key.
 *
 * @covers \WBoost\Web\Controller\Manual\DownloadLogoImageController
 */
final class DownloadLogoImageControllerTest extends WebTestCase
{
    public function testNamedAfterTheManualAndTheLogoVariant(): void
    {
        $client = $this->clientWithLogo('Vizuální styl Čeladná');

        $client->request('GET', '/stahnout-logo/' . TestDataFixture::MANUAL_1_ID . '/horizontal.svg');

        self::assertResponseIsSuccessful();
        self::assertSame(
            "attachment; filename=\"Vizualni styl Celadna - Horizontalni logotyp.svg\"; filename*=utf-8''Vizu%C3%A1ln%C3%AD%20styl%20%C4%8Celadn%C3%A1%20-%20Horizont%C3%A1ln%C3%AD%20logotyp.svg",
            $client->getResponse()->headers->get('Content-Disposition'),
        );
    }

    public function testTheColourVariantIsAddedWhenTheLinkNamesIt(): void
    {
        $client = $this->clientWithLogo('Manual 1');

        $client->request('GET', '/stahnout-logo/' . TestDataFixture::MANUAL_1_ID . '/symbol.svg?colorVariant=darkBackground&background=000000');

        self::assertResponseIsSuccessful();
        self::assertSame(
            "attachment; filename=\"Manual 1 - Symbol - na tmavem pozadi.svg\"; filename*=utf-8''Manual%201%20-%20Symbol%20-%20na%20tmav%C3%A9m%20pozad%C3%AD.svg",
            $client->getResponse()->headers->get('Content-Disposition'),
        );

        // The name is not part of the cached render: same file, other name.
        $client->request('GET', '/stahnout-logo/' . TestDataFixture::MANUAL_1_ID . '/symbol.svg?colorVariant=bogus&background=000000');
        self::assertResponseIsSuccessful();
        self::assertSame('attachment; filename="Manual 1 - Symbol.svg"', $client->getResponse()->headers->get('Content-Disposition'));
    }

    private function clientWithLogo(string $manualName): KernelBrowser
    {
        $client = self::createClient();

        /** @var FilesystemOperator $filesystem */
        $filesystem = self::getContainer()->get('oneup_flysystem.minio_filesystem');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 100"><rect width="300" height="100" fill="#123456"/></svg>';
        $filesystem->write('manuals/test/download-logo.svg', $svg);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $manual = $entityManager->find(Manual::class, Uuid::fromString(TestDataFixture::MANUAL_1_ID));
        self::assertInstanceOf(Manual::class, $manual);
        $manual->edit($manual->type, $manualName);
        $manual->editLogo(new Logo(
            horizontal: new SvgImage('manuals/test/download-logo.svg', ['#123456']),
            vertical: null,
            horizontalWithClaim: null,
            verticalWithClaim: null,
            symbol: new SvgImage('manuals/test/download-logo.svg', ['#123456']),
        ));
        $entityManager->flush();
        $entityManager->clear();

        return $client;
    }
}
