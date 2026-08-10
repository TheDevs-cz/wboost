<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Template;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Entity\Project;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\FileSource;

/**
 * @covers \WBoost\Web\Controller\Template\AddTemplateVariantController
 */
final class AddTemplateVariantControllerTest extends WebTestCase
{
    private const string PNG_1X1_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private function addUrl(): string
    {
        return '/template/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_1_ID . '/add-variant';
    }

    public function testPageRendersWithGalleryBackgroundPicker(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $client->request('GET', $this->addUrl());

        self::assertResponseIsSuccessful();
        // The background field is the gallery picker, not a raw file input.
        self::assertSelectorExists('[data-controller="background-picker"]');
        self::assertSelectorExists('#backgroundGalleryModal');
        self::assertSelectorNotExists('input[type="file"][name*="backgroundImage"]');
    }

    public function testSubmitWithGalleryPickReferencesTheGalleryFile(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $crawler = $client->request('GET', $this->addUrl());
        $token = $crawler->filter('input[name="template_variant_form[_token]"]')->attr('value');
        self::assertIsString($token);

        [$fileId, $galleryPath] = $this->seedGalleryFile();

        $client->request('POST', $this->addUrl(), [
            'template_variant_form' => [
                'unit' => 'px',
                'width' => '1080',
                'height' => '1080',
                'backgroundImageId' => $fileId,
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#^/template-variant/[0-9a-f-]{36}/editor$#', $location);

        $variantId = Uuid::fromString(substr($location, strlen('/template-variant/'), 36));
        $variant = self::getContainer()->get(TemplateVariantRepository::class)->get($variantId);
        self::assertSame($galleryPath, $variant->backgroundImage, 'the picked gallery file is referenced, not copied');
    }

    /**
     * @return array{string, string} [file id, storage path]
     */
    private function seedGalleryFile(): array
    {
        $id = Uuid::uuid4();
        $path = "fixtures/gallery-bg-$id.png";

        $bytes = base64_decode(self::PNG_1X1_BASE64, true);
        self::assertIsString($bytes);
        self::getContainer()->get(Filesystem::class)->write($path, $bytes);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $project = $em->find(Project::class, Uuid::fromString(TestDataFixture::PROJECT_1_ID));
        self::assertNotNull($project);

        $em->persist(new FileUpload(
            $id,
            $project,
            new \DateTimeImmutable('2026-01-01 12:00:00'),
            FileSource::ProjectImage,
            $path,
        ));
        $em->flush();

        return [$id->toString(), $path];
    }
}
