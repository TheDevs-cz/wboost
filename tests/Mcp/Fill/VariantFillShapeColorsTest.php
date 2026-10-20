<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Fill;

use Mcp\Exception\ToolCallException;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Mcp\Fill\VariantFill;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\ResolvedInputOverrides;

/**
 * render_variant / export_variant resolve `shapeColors` STRICTLY, like the
 * REST export, and phrase a refusal so the agent can act on it: the code plus
 * the colours the shape accepts.
 *
 * @covers \WBoost\Web\Mcp\Fill\VariantFill
 */
final class VariantFillShapeColorsTest extends KernelTestCase
{
    private const string SHAPE_ID = '55555555-5555-4555-8555-555555555555';

    public function testValidPickIsAppliedAndAnUnlistedOneIsRefusedWithItsOptions(): void
    {
        self::bootKernel();
        $variant = self::getContainer()->get(TemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID));
        $variant->canvas = (string) json_encode(['objects' => [[
            'type' => 'Rect', 'width' => 10, 'height' => 10, 'fill' => '#112233',
            'inputId' => self::SHAPE_ID, 'colorChoice' => 'fill', 'allowedColors' => ['#c8102e'],
        ]]]);

        $fill = self::getContainer()->get(VariantFill::class);
        $none = new ResolvedInputOverrides([], []);

        self::assertSame(
            [self::SHAPE_ID => '#c8102e'],
            $fill->withShapeColors($variant, $none, [self::SHAPE_ID => '#C8102E'])->shapeColors,
        );
        self::assertSame(
            ['These shapeColors ids match no recolourable shape on this variant and were ignored: nope. Shapes are addressed by describe_variant shapeInputs[].id.'],
            VariantFill::warnings($variant, [], [], ['nope' => '#fff']),
        );

        try {
            $fill->withShapeColors($variant, $none, [self::SHAPE_ID => '#000000']);
            self::fail('Expected the unlisted colour to be refused.');
        } catch (ToolCallException $refusal) {
            self::assertStringContainsString('(shape_color_not_allowed)', $refusal->getMessage());
            self::assertStringContainsString('#112233, #c8102e', $refusal->getMessage());
        }
    }
}
