<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Flyer;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Entity\FlyerTemplateVariant;
use WBoost\Web\Repository\FlyerTemplateVariantRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

/**
 * @covers \WBoost\Web\Controller\Flyer\FlyerTemplateVariantEditorController
 * @covers \WBoost\Web\MessageHandler\Flyer\EditFlyerTemplateVariantCanvasHandler
 */
final class FlyerTemplateVariantEditorControllerTest extends WebTestCase
{
    private function editorUrl(): string
    {
        return '/flyer-template-variant/' . TestDataFixture::FLYER_TEMPLATE_VARIANT_1_ID . '/editor';
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
        // The canvas is sized by the FlyerDimension in PIXELS (A4 mm at 300 DPI).
        self::assertSelectorExists('canvas#c[width="2480"][height="3508"]');
        // Gallery folder toggle in the image-properties panel.
        self::assertSelectorExists('#image-dir-' . TestDataFixture::FILE_DIRECTORY_ALLOWED_ID);
        self::assertSelectorExists('[data-canvas-editor-target="imageInputs"]');
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
        self::assertSame('flyers/preview/' . TestDataFixture::FLYER_TEMPLATE_VARIANT_1_ID . '.png', $variant->previewImagePath);
    }

    public function testFillPageRendersFlyerVariantFiller(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', '/flyer-template-variant/' . TestDataFixture::FLYER_TEMPLATE_VARIANT_1_ID . '/export');

        self::assertResponseIsSuccessful();
        // The variant has image placeholders → the hybrid fill canvas is rendered
        // and the form posts to the flyer download route.
        self::assertSelectorExists('[data-controller~="variant-image-fill"]');
        self::assertSelectorExists('form[action$="/flyer-template-variant/' . TestDataFixture::FLYER_TEMPLATE_VARIANT_1_ID . '/download"]');
    }

    private function loadVariant(): FlyerTemplateVariant
    {
        $repository = self::getContainer()->get(FlyerTemplateVariantRepository::class);

        return $repository->get(Uuid::fromString(TestDataFixture::FLYER_TEMPLATE_VARIANT_1_ID));
    }
}
