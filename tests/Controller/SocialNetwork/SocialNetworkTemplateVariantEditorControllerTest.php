<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\SocialNetwork;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Repository\SocialNetworkTemplateVariantRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

/**
 * @covers \WBoost\Web\Controller\SocialNetwork\SocialNetworkTemplateVariantEditorController
 * @covers \WBoost\Web\MessageHandler\SocialNetwork\EditSocialNetworkTemplateVariantCanvasHandler
 */
final class SocialNetworkTemplateVariantEditorControllerTest extends WebTestCase
{
    private function editorUrl(): string
    {
        return '/social-network-template-variant/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/editor';
    }

    public function testEditorPageRendersImagePlaceholderPanelForOwner(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', $this->editorUrl());

        self::assertResponseIsSuccessful();
        // The new image-properties controller + panel must be wired in.
        self::assertSelectorExists('[data-controller~="canvas-image-properties"]');
        self::assertSelectorExists('#image-controls [data-canvas-image-properties-target="placeholder"]');
        // The per-placeholder folder toggle for the project's ALLOWED gallery folder.
        self::assertSelectorExists('#image-dir-' . TestDataFixture::FILE_DIRECTORY_ALLOWED_ID);
        // The hidden imageInputs form field the orchestrator writes on save.
        self::assertSelectorExists('[data-canvas-editor-target="imageInputs"]');
    }

    public function testEditorPageForbiddenForOtherUser(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_2_EMAIL);

        $client->request('GET', $this->editorUrl());

        self::assertResponseStatusCodeSame(403);
    }

    public function testSubmitPersistsImageInputs(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $crawler = $client->request('GET', $this->editorUrl());
        self::assertResponseIsSuccessful();

        // Derive the form's field prefix + CSRF token from the rendered page.
        $imageInputsName = $crawler->filter('[data-canvas-editor-target="imageInputs"]')->attr('name');
        self::assertIsString($imageInputsName);
        $prefix = substr($imageInputsName, 0, (int) strpos($imageInputsName, '['));
        $token = $crawler->filter('input[name="' . $prefix . '[_token]"]')->attr('value');
        self::assertIsString($token);

        $inputId = '00000000-0000-4000-8000-0000000000aa';
        $canvas = json_encode([
            'version' => '5.2.4',
            'objects' => [[
                'type' => 'Image',
                'inputId' => $inputId,
                'imagePlaceholder' => true,
                'left' => 10, 'top' => 10, 'width' => 100, 'height' => 100,
                'scaleX' => 1, 'scaleY' => 1, 'originX' => 'left', 'originY' => 'top',
            ]],
            'backgroundImage' => null,
        ], JSON_THROW_ON_ERROR);
        $imageInputs = json_encode([[
            'inputId' => $inputId,
            'name' => 'Logo',
            'description' => 'Your logo',
            'allowMove' => true,
            'allowResize' => false,
            'allowRotate' => true,
            'hidable' => true,
            'allowedDirectoryIds' => [TestDataFixture::FILE_DIRECTORY_ALLOWED_ID],
        ]], JSON_THROW_ON_ERROR);

        $client->request('POST', $this->editorUrl(), [
            $prefix => [
                'canvas' => $canvas,
                'textInputs' => '[]',
                'imageInputs' => $imageInputs,
                // The orchestrator always sends a non-empty preview data URI; an
                // empty string maps to null and trips the controller's assert.
                'imagePreview' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=',
                '_token' => $token,
            ],
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $variant = $this->loadVariant();

        self::assertCount(1, $variant->imageInputs);
        $input = $variant->imageInputs[0];
        self::assertSame($inputId, $input->inputId);
        self::assertSame('Logo', $input->name);
        self::assertSame('Your logo', $input->description);
        self::assertTrue($input->allowMove);
        self::assertFalse($input->allowResize);
        self::assertTrue($input->allowRotate);
        self::assertTrue($input->hidable);
        self::assertSame([TestDataFixture::FILE_DIRECTORY_ALLOWED_ID], $input->allowedDirectoryIds);
    }

    private function loadVariant(): SocialNetworkTemplateVariant
    {
        $repository = self::getContainer()->get(SocialNetworkTemplateVariantRepository::class);

        return $repository->get(Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID));
    }
}
