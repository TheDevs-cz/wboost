<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\TemplateGroup;

use League\Flysystem\Filesystem;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

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
                'socialDimensions' => ['1:1', '9:16'],
                '_token' => $token,
            ],
        ], [
            'template_group_form' => [
                'commonBackground' => $this->pngUpload(),
            ],
        ]);

        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#^/template-group/[0-9a-f-]{36}/editor$#', $location);

        // The freshly created group editor renders with two social tabs.
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Wizard Group');
    }

    public function testValidationFailsWhenSelectedDimensionHasNoBackground(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $crawler = $client->request('GET', $this->wizardUrl());
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('input[name="template_group_form[_token]"]')->attr('value');
        self::assertIsString($token);

        $client->request('POST', $this->wizardUrl(), [
            'template_group_form' => [
                'name' => 'No Background Group',
                'socialDimensions' => ['1:1'],
                '_token' => $token,
            ],
        ]);

        // Invalid form → the wizard re-renders with 422, no redirect.
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Nahrajte pozadí');
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

        $client->request('GET', $this->wizardUrl() . '?sourceModule=social&sourceVariantId=' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Výchozí design');
        self::assertSelectorExists('input[name="template_group_form[name]"][value="Insta Template 1"]');
        // The source variant's dimension comes pre-checked.
        self::assertSelectorExists('input[name="template_group_form[socialDimensions][]"][value="1:1"][checked]');
        // The source travels through the submit as hidden fields.
        self::assertSelectorExists('input[name="template_group_form[sourceModule]"][value="social"]');
        self::assertSelectorExists(sprintf(
            'input[name="template_group_form[sourceVariantId]"][value="%s"]',
            TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID,
        ));
    }

    public function testCreateFromSourceNeedsNoBackgroundUploads(): void
    {
        $client = self::createClient();

        // The handler copies the source variant's background — its file must
        // exist in storage.
        self::getContainer()->get(Filesystem::class)->write('fixtures/bg-1.png', 'source-bytes');

        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $sourceUrl = $this->wizardUrl() . '?sourceModule=social&sourceVariantId=' . TestDataFixture::GROUPED_SOCIAL_VARIANT_ID;
        $crawler = $client->request('GET', $sourceUrl);
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('input[name="template_group_form[_token]"]')->attr('value');
        self::assertIsString($token);

        $client->request('POST', $this->wizardUrl(), [
            'template_group_form' => [
                'name' => 'Seeded From Existing',
                'socialDimensions' => ['9:16'],
                'sourceModule' => 'social',
                'sourceVariantId' => TestDataFixture::GROUPED_SOCIAL_VARIANT_ID,
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
        $client->request('GET', $this->wizardUrl() . '?sourceModule=social&sourceVariantId=' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_2_ID);
        self::assertResponseStatusCodeSame(404);

        // Same tamper via the hidden fields on submit: 404 before dispatch.
        $crawler = $client->request('GET', $this->wizardUrl());
        $token = $crawler->filter('input[name="template_group_form[_token]"]')->attr('value');
        self::assertIsString($token);

        $client->request('POST', $this->wizardUrl(), [
            'template_group_form' => [
                'name' => 'Tampered',
                'socialDimensions' => ['1:1'],
                'sourceModule' => 'social',
                'sourceVariantId' => TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_2_ID,
                '_token' => $token,
            ],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    private function pngUpload(): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'png');
        self::assertIsString($tmp);

        $bytes = base64_decode(self::PNG_1X1_BASE64, true);
        self::assertIsString($bytes);
        file_put_contents($tmp, $bytes);

        return new UploadedFile($tmp, 'background.png', 'image/png', null, true);
    }
}
