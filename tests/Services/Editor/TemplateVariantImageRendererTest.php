<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Editor;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemReader;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Sensiolabs\GotenbergBundle\GotenbergScreenshotInterface;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Services\SocialNetwork\AssetInliner;
use WBoost\Web\Services\SocialNetwork\CanvasPlaceholderGeometry;
use WBoost\Web\Services\SocialNetwork\ImagePlacement;
use WBoost\Web\Services\SocialNetwork\TextInputObjectBinder;
use WBoost\Web\Services\Editor\TemplateVariantImageRenderer;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\EditorTextInput;

/**
 * Unit coverage for the renderer's canvas-JSON preparation — specifically the
 * inputId re-binding that heals variants whose canvas textboxes lost their
 * `inputId` custom property (Fabric v7 migration fallout: re-saved by a broken
 * editor build after the inputId migration ran) while inputs[] kept it.
 *
 * Without the re-bind, the render template's override-by-inputId lookup matches
 * nothing and placeholders render verbatim — exactly the reported "input value
 * is not applied in the export / preview" bug. The web/API tests cannot catch
 * this because they swap in a FakeRenderer that never builds the canvas JSON.
 *
 * @covers \WBoost\Web\Services\Editor\TemplateVariantImageRenderer
 */
final class TemplateVariantImageRendererTest extends TestCase
{
    public function testAlignTextboxInputIdsBindsTextboxesToInputsPositionally(): void
    {
        $canvas = [
            'objects' => [
                ['type' => 'Textbox', 'text' => 'Abc'],                          // migrated: no inputId
                ['type' => 'Image', 'src' => 'x'],                               // decorative: never an input
                ['type' => 'textbox', 'text' => 'Two', 'inputId' => 'stale-id'], // drifted id (lowercase type)
            ],
        ];

        $result = $this->invokeAlign($canvas, [
            $this->input('11111111-1111-4111-8111-111111111111'),
            $this->input('22222222-2222-4222-8222-222222222222'),
        ]);

        $objects = $result['objects'];
        self::assertIsArray($objects);

        // 1st Textbox ↔ inputs[0]: missing id stamped from the input.
        self::assertIsArray($objects[0]);
        self::assertSame('11111111-1111-4111-8111-111111111111', $objects[0]['inputId'] ?? null);

        // The image is skipped entirely — it is not part of inputs[].
        self::assertIsArray($objects[1]);
        self::assertArrayNotHasKey('inputId', $objects[1]);

        // 2nd Textbox ↔ inputs[1]: a drifted id is overwritten so the override
        // key (which comes from inputs[]) always resolves. Type match is
        // case-insensitive (Fabric v7 emits "Textbox", v5 emitted "textbox").
        self::assertIsArray($objects[2]);
        self::assertSame('22222222-2222-4222-8222-222222222222', $objects[2]['inputId'] ?? null);
    }

    public function testAlignTextboxInputIdsLeavesAlreadySyncedCanvasUnchanged(): void
    {
        $canvas = [
            'objects' => [
                ['type' => 'Textbox', 'text' => 'Abc', 'inputId' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'],
            ],
        ];

        $result = $this->invokeAlign($canvas, [$this->input('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')]);

        self::assertSame($canvas, $result);
    }

    public function testAlignTextboxInputIdsIsNoOpWithoutObjects(): void
    {
        $canvas = ['version' => '5.2.4'];

        self::assertSame($canvas, $this->invokeAlign($canvas, [$this->input('11111111-1111-4111-8111-111111111111')]));
    }

    /**
     * @param array<string, mixed> $canvas
     * @param array<EditorTextInput> $inputs
     * @return array<string, mixed>
     */
    private function invokeAlign(array $canvas, array $inputs): array
    {
        // These collaborators are not exercised by the canvas-JSON prep path
        // under test, so they are inert stubs (not mocks with expectations).
        $geometry = new CanvasPlaceholderGeometry();
        $renderer = new TemplateVariantImageRenderer(
            $this->createStub(GotenbergScreenshotInterface::class),
            new GetFonts($this->createStub(EntityManagerInterface::class)),
            new AssetInliner($this->createStub(FilesystemReader::class)),
            $geometry,
            new TextInputObjectBinder($geometry),
            new ImagePlacement(),
            new UploaderHelper('http://assets.test/bucket'),
            '/nonexistent/fabric.js',
        );

        $method = new ReflectionMethod($renderer, 'alignTextboxInputIds');

        /** @var array<string, mixed> $result */
        $result = $method->invoke($renderer, $canvas, $inputs);

        return $result;
    }

    private function input(string $inputId): EditorTextInput
    {
        return new EditorTextInput($inputId, 'Name', null, false, false, null, false);
    }
}
