<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Project;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\FileSource;

/**
 * The standalone gallery management page renders the Project:ImageGallery
 * component full-page and is gated to project editors.
 */
final class ProjectGalleryControllerTest extends WebTestCase
{
    private const string URL = '/project/' . TestDataFixture::PROJECT_1_ID . '/gallery';

    public function testRedirectsGuestToLogin(): void
    {
        $client = self::createClient();
        $client->request('GET', self::URL);

        self::assertResponseRedirects();
    }

    public function testRendersForProjectOwner(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        // The gallery Live Component is rendered standalone (no modal chrome).
        self::assertSelectorExists('[data-controller~="image-gallery"]');
    }

    /**
     * The tile caption is what tells two look-alike thumbnails apart: the
     * uploaded file name (extension kept in its own span so a truncated name
     * keeps its tail) and the pixel size with a format badge. Uploads from
     * before the name was recorded say so instead of showing nothing.
     */
    public function testTilesShowFileNameAndPixelSize(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $project = self::getContainer()->get(ProjectRepository::class)->get(Uuid::fromString(TestDataFixture::PROJECT_1_ID));
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new FileUpload(
            Uuid::uuid4(),
            $project,
            new DateTimeImmutable(),
            FileSource::ProjectImage,
            'fixtures/named-background.png',
            null,
            'pozadi-tmave-modre.png',
            1080,
            1350,
        ));
        // A pre-2026-09 row: no name recorded, and (never written to storage)
        // no size the lazy backfill could read either.
        $legacyPath = 'fixtures/legacy-' . Uuid::uuid4()->toString() . '.jpg';
        $em->persist(new FileUpload(Uuid::uuid4(), $project, new DateTimeImmutable(), FileSource::ProjectImage, $legacyPath));
        $em->flush();

        $crawler = $client->request('GET', self::URL);

        self::assertResponseIsSuccessful();

        $named = $crawler->filter('.gallery-asset')->reduce(
            static fn ($node): bool => str_contains($node->text(), 'pozadi-tmave-modre'),
        )->first();
        self::assertCount(1, $named);
        self::assertSame('pozadi-tmave-modre', $named->filter('.gallery-asset__name-base')->text());
        self::assertSame('.png', $named->filter('.gallery-asset__name-ext')->text());
        self::assertSame('1080 × 1350 px', $named->filter('.gallery-asset__size')->text());
        self::assertSame('PNG', $named->filter('.gallery-asset__format')->text());
        self::assertStringContainsString('pozadi-tmave-modre.png · 1080 × 1350 px · PNG', (string) $named->filter('[title]')->first()->attr('title'));

        $legacy = $crawler->filter(sprintf('.gallery-asset[data-storage-path="%s"]', $legacyPath));
        self::assertCount(1, $legacy);
        self::assertSame('Bez názvu', $legacy->filter('.gallery-asset__name-missing')->text());
        self::assertSame('', $legacy->filter('.gallery-asset__size')->text());
        self::assertSame('JPG', $legacy->filter('.gallery-asset__format')->text());
    }

    public function testForbiddenForNonEditor(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_2_EMAIL);

        $client->request('GET', self::URL);

        self::assertResponseStatusCodeSame(403);
    }
}
