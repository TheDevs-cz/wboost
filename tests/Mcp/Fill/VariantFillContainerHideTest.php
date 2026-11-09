<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Fill;

use Mcp\Exception\ToolCallException;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Mcp\Fill\VariantFill;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;

/**
 * render_variant / export_variant accept a whole-container hide in `inputs`
 * (keyed by the container id), refuse it on a non-hidable container, and do
 * not report a container id as an "unknown input".
 *
 * @covers \WBoost\Web\Mcp\Fill\VariantFill
 */
final class VariantFillContainerHideTest extends KernelTestCase
{
    public function testHidesAHidableContainerAndRefusesAFixedOne(): void
    {
        self::bootKernel();
        $variant = self::getContainer()->get(TemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID));
        $variant->canvas = (string) json_encode(['objects' => [], 'containers' => [
            ['id' => 'bubble', 'maxHeight' => 100, 'memberInputIds' => ['a', 'b'], 'hidable' => true],
            ['id' => 'fixed', 'maxHeight' => 100, 'memberInputIds' => ['c', 'd']],
        ]]);

        $fill = self::getContainer()->get(VariantFill::class);

        self::assertSame(['bubble'], $fill->texts($variant, ['bubble' => ['hide' => true]])->hiddenContainerIds);
        self::assertSame([], VariantFill::warnings($variant, ['bubble' => ['hide' => true]], []));

        try {
            $fill->texts($variant, ['fixed' => ['hide' => true]]);
            self::fail('Expected the non-hidable container to be refused.');
        } catch (ToolCallException $refusal) {
            self::assertStringContainsString('cannot be hidden', $refusal->getMessage());
        }
    }
}
