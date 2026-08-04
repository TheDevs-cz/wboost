<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Template;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

/**
 * @covers \WBoost\Web\Controller\Template\TemplateVariantEditorController
 * @covers \WBoost\Web\MessageHandler\Template\EditTemplateVariantCanvasHandler
 */
final class TemplateVariantEditorControllerTest extends WebTestCase
{
    private function editorUrl(): string
    {
        return '/template-variant/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/editor';
    }

    public function testEditorPageRendersSharedCanvasEditorWithPixelDimensions(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', $this->editorUrl());

        self::assertResponseIsSuccessful();
        // The shared editor wires the same Stimulus controllers as the social module.
        self::assertSelectorExists('[data-controller~="canvas-editor"]');
        self::assertSelectorExists('[data-controller~="canvas-image-properties"]');
        // The canvas is sized by the TemplateDimension in PIXELS (A4 mm at 300 DPI).
        self::assertSelectorExists('canvas#c[width="2480"][height="3508"]');
        // Gallery folder toggle in the image-properties panel.
        self::assertSelectorExists('#image-dir-' . TestDataFixture::FILE_DIRECTORY_ALLOWED_ID);
        self::assertSelectorExists('[data-canvas-editor-target="imageInputs"]');
    }

    /**
     * The image-placeholder metadata control lives in the floating image
     * popover anchored to the selected element (ported from the retired
     * social editor suite — same shared editor template).
     */
    public function testEditorPageRendersImagePlaceholderControlInFloatingPopover(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', $this->editorUrl());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-canvas-floating-toolbar-target="imagePopover"] [data-canvas-image-properties-target="placeholder"]');
    }

    public function testGroupedVariantRedirectsToGroupEditor(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', '/template-variant/' . TestDataFixture::GROUPED_PRESET_VARIANT_ID . '/editor');

        // Group-created variants are designed only in the group editor.
        self::assertResponseRedirects('/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/editor');
    }

    public function testEditorPageForbiddenForOtherUser(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_2_EMAIL);

        $client->request('GET', $this->editorUrl());

        self::assertResponseStatusCodeSame(403);
    }

    public function testSubmitPersistsCanvasAndInputs(): void
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

        $inputId = '00000000-0000-4000-8000-0000000000bb';
        $canvas = json_encode([
            'version' => '5.2.4',
            'objects' => [[
                'type' => 'Textbox',
                'inputId' => $inputId,
                'text' => 'Nadpis',
                'left' => 10, 'top' => 10, 'width' => 300,
            ]],
            'backgroundImage' => null,
        ], JSON_THROW_ON_ERROR);
        $textInputs = json_encode([[
            'inputId' => $inputId,
            'name' => 'Nadpis',
            'maxLength' => 40,
            'locked' => false,
            'uppercase' => false,
            'description' => null,
            'hidable' => false,
        ]], JSON_THROW_ON_ERROR);

        $client->request('POST', $this->editorUrl(), [
            $prefix => [
                'canvas' => $canvas,
                'textInputs' => $textInputs,
                'imageInputs' => '[]',
                'imagePreview' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=',
                '_token' => $token,
            ],
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $variant = $this->loadVariant();

        self::assertStringContainsString('Nadpis', $variant->canvas);
        self::assertCount(1, $variant->inputs);
        self::assertSame($inputId, $variant->inputs[0]->inputId);
        self::assertSame(40, $variant->inputs[0]->maxLength);
        self::assertSame('custom-templates/preview/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '.png', $variant->previewImagePath);
    }

    /**
     * Ported from the retired social editor suite: submitting the editor form
     * persists image-placeholder metadata (the `imageInputs` JSONB column)
     * alongside the canvas.
     */
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

    public function testFillPageRendersTemplateVariantFiller(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', '/template-variant/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/export');

        self::assertResponseIsSuccessful();
        // The variant has image placeholders → the hybrid fill canvas is rendered
        // and the form posts to the template download route.
        self::assertSelectorExists('[data-controller~="variant-image-fill"]');
        self::assertSelectorExists('form[action$="/template-variant/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/download"]');
    }

    private function loadVariant(): TemplateVariant
    {
        $repository = self::getContainer()->get(TemplateVariantRepository::class);

        return $repository->get(Uuid::fromString(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID));
    }
}
