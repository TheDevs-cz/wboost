<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\TemplateGroup;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\TemplateGroup;
use WBoost\Web\Query\GetTemplateGroupMembers;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\BackgroundMode;
use WBoost\Web\Value\FileSource;

/**
 * @covers \WBoost\Web\Controller\TemplateGroup\AddTemplateGroupController
 */
final class AddTemplateGroupControllerTest extends WebTestCase
{
    private const string PNG_1X1_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private function wizardUrl(): string
    {
        return '/project/' . TestDataFixture::PROJECT_1_ID . '/add-template-group';
    }

    private function groupByName(string $name): TemplateGroup
    {
        $group = self::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(TemplateGroup::class)
            ->findOneBy(['name' => $name]);
        self::assertInstanceOf(TemplateGroup::class, $group);

        return $group;
    }

    public function testWizardCreatesGroupAndRedirectsToGroupEditor(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $crawler = $client->request('GET', $this->wizardUrl());
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('input[name="template_group_form[_token]"]')->attr('value');
        self::assertIsString($token);

        $client->request('POST', $this->wizardUrl(), [
            'template_group_form' => [
                'name' => 'Wizard Group',
                'presetDimensions' => ['1:1', '9:16'],
                // The background picker widget posts the picked GALLERY
                // file's id — never a raw file upload.
                'commonBackground' => $this->seedGalleryFile(),
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#^/template-group/[0-9a-f-]{36}/editor$#', $location);

        // The freshly created group editor renders with two preset tabs.
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Wizard Group');
    }

    public function testSelectedDimensionWithoutBackgroundIsAllowed(): void
    {
        // Backgrounds are optional since the background-as-layer rework: a
        // dimension without an upload starts without a background (layer mode,
        // transparent render) instead of failing validation.
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $crawler = $client->request('GET', $this->wizardUrl());
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('input[name="template_group_form[_token]"]')->attr('value');
        self::assertIsString($token);

        $client->request('POST', $this->wizardUrl(), [
            'template_group_form' => [
                'name' => 'No Background Group',
                'presetDimensions' => ['1:1'],
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects();

        $group = $this->groupByName('No Background Group');
        $members = self::getContainer()->get(GetTemplateGroupMembers::class);
        $variants = $members->variants($group->id);

        self::assertCount(1, $variants);
        self::assertSame(BackgroundMode::Layer, $variants[0]->backgroundMode);
        self::assertNull($variants[0]->backgroundImage);
        self::assertSame('{}', $variants[0]->canvas, 'no background, no source design → the canvas stays empty');
    }

    public function testForbiddenForOwnerWithoutDesignerRole(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', $this->wizardUrl());

        self::assertResponseStatusCodeSame(403);
    }

    public function testWizardPrefillsFromSourceTemplate(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $client->request('GET', $this->wizardUrl() . '?sourceVariantId=' . TestDataFixture::GROUPED_PRESET_VARIANT_ID);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Výchozí design');
        self::assertSelectorExists('input[name="template_group_form[name]"][value="Group Campaign"]');
        // The source variant's preset dimension comes pre-checked.
        self::assertSelectorExists('input[name="template_group_form[presetDimensions][]"][value="1:1"][checked]');
        // The source travels through the submit as a hidden field.
        self::assertSelectorExists(sprintf(
            'input[name="template_group_form[sourceVariantId]"][value="%s"]',
            TestDataFixture::GROUPED_PRESET_VARIANT_ID,
        ));
    }

    public function testCreateFromSourceNeedsNoBackgroundUploads(): void
    {
        $client = self::createClient();

        // The handler copies the source variant's background — its file must
        // exist in storage.
        self::getContainer()->get(Filesystem::class)->write('fixtures/bg-1.png', 'source-bytes');

        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $sourceUrl = $this->wizardUrl() . '?sourceVariantId=' . TestDataFixture::GROUPED_PRESET_VARIANT_ID;
        $crawler = $client->request('GET', $sourceUrl);
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('input[name="template_group_form[_token]"]')->attr('value');
        self::assertIsString($token);

        $client->request('POST', $this->wizardUrl(), [
            'template_group_form' => [
                'name' => 'Seeded From Existing',
                'presetDimensions' => ['9:16'],
                'sourceVariantId' => TestDataFixture::GROUPED_PRESET_VARIANT_ID,
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#^/template-group/[0-9a-f-]{36}/editor$#', $location);

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Seeded From Existing');
    }

    public function testSourceFromForeignProjectIs404(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        // Picker link tampered to a variant of another project.
        $client->request('GET', $this->wizardUrl() . '?sourceVariantId=' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_2_ID);
        self::assertResponseStatusCodeSame(404);

        // Same tamper via the hidden field on submit: 404 before dispatch.
        $crawler = $client->request('GET', $this->wizardUrl());
        $token = $crawler->filter('input[name="template_group_form[_token]"]')->attr('value');
        self::assertIsString($token);

        $client->request('POST', $this->wizardUrl(), [
            'template_group_form' => [
                'name' => 'Tampered',
                'presetDimensions' => ['1:1'],
                'sourceVariantId' => TestDataFixture::CUSTOM_TEMPLATE_VARIANT_2_ID,
                '_token' => $token,
            ],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    /** Seeds a project-gallery FileUpload (row + object bytes) and returns
     *  its id — the wire value the background picker submits. */
    private function seedGalleryFile(): string
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

        return $id->toString();
    }
}
