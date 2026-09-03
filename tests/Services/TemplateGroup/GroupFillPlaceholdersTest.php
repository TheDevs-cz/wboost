<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\TemplateGroup;

use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\TemplateGroup\GroupFillPlaceholders;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;

/**
 * The group fill page builds its popovers from the SAME per-input builder
 * the single-variant page uses (FillTextPlaceholders), unified over the
 * member dimensions — so a rich input, a checklist component or a sampled
 * input on a synchronized template gets exactly the editor it gets on a
 * single variant (the "no WYSIWYG on grouped templates" report).
 *
 * @covers \WBoost\Web\Services\TemplateGroup\GroupFillPlaceholders
 * @covers \WBoost\Web\Services\SocialNetwork\FillTextPlaceholders
 */
final class GroupFillPlaceholdersTest extends KernelTestCase
{
    public function testUnifiedTextPlaceholdersCarryTheSinglePageEditorData(): void
    {
        self::bootKernel();
        $service = self::getContainer()->get(GroupFillPlaceholders::class);

        // Two "dimensions" that share nothing but stand in for a group's
        // members: the feature-complete orientation variant (plain sampled
        // input, rich list input, checklist component) and a variant with a
        // rich headline of its own.
        $orientation = $this->variant(TestDataFixture::ORIENTATION_VARIANT_ID);
        $custom = $this->variant(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID);

        $placeholders = $service->textPlaceholders(
            [$orientation, $custom],
            [TestDataFixture::ORIENTATION_INPUT_INTRO_ID => 'Typed by the user'],
            [TestDataFixture::ORIENTATION_INPUT_BULLETS_ID => true],
            [],
        );

        /** @var array<string, array<string, mixed>> $byId */
        $byId = [];
        foreach ($placeholders as $placeholder) {
            /** @var string $inputId */
            $inputId = $placeholder['inputId'];
            $byId[$inputId] = $placeholder;
        }

        // One entry per distinct input, in first-seen order: the first
        // dimension's inputs, then whatever the second one adds.
        self::assertSame(
            [
                TestDataFixture::ORIENTATION_INPUT_INTRO_ID,
                TestDataFixture::ORIENTATION_INPUT_BULLETS_ID,
                TestDataFixture::ORIENTATION_INPUT_CHECKLIST_ID,
            ],
            array_slice(array_keys($byId), 0, 3),
        );
        self::assertArrayHasKey(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID, $byId);
        self::assertCount(count(array_unique(array_keys($byId))), $byId);

        // A seeded value wins over the sample; the hide state rides along.
        $intro = $byId[TestDataFixture::ORIENTATION_INPUT_INTRO_ID];
        self::assertSame('Typed by the user', $intro['value']);
        self::assertFalse($intro['richText']);
        self::assertFalse($intro['hidden']);
        self::assertNotNull($intro['frame'], 'the frame comes from the dimension that carries the input');

        // A rich input renders the WYSIWYG: flagged rich, lists + checkboxes
        // per its definition, seeded with (empty) runs — never raw JSON.
        $bullets = $byId[TestDataFixture::ORIENTATION_INPUT_BULLETS_ID];
        self::assertTrue($bullets['richText']);
        self::assertTrue($bullets['lists']);
        self::assertTrue($bullets['listCheckboxes']);
        self::assertSame([], $bullets['runs']);
        self::assertTrue($bullets['hidden']);

        // The checklist component: its items derive from the sample envelope
        // (checked = 'cbx'), the capability that is off stays off.
        $tasks = $byId[TestDataFixture::ORIENTATION_INPUT_CHECKLIST_ID];
        self::assertTrue($tasks['checklist']);
        self::assertFalse($tasks['checklistAdd']);
        self::assertSame(
            [['text' => 'First task', 'checked' => false], ['text' => 'Second task', 'checked' => true]],
            $tasks['checklistItems'],
        );
        self::assertSame('{"runs":[{"text":"First task\nSecond task"}],"lines":["cb","cbx"]}', $tasks['value']);

        // The second dimension's own rich input joins the unified list.
        self::assertTrue($byId[TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID]['richText']);

        // The reflow payload of ONE dimension carries that dimension's frames.
        $layout = $service->layoutData($orientation);
        $layoutInputs = $layout['inputs'];
        self::assertIsArray($layoutInputs);
        self::assertArrayHasKey(TestDataFixture::ORIENTATION_INPUT_INTRO_ID, $layoutInputs);
        self::assertArrayNotHasKey(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID, $layoutInputs);

        // The layers panel lists the first dimension's fillable placeholders.
        $layers = $service->layers([$orientation, $custom], []);
        $layerIds = array_map(static fn (array $layer): string => $layer['inputId'], $layers);
        self::assertContains(TestDataFixture::ORIENTATION_INPUT_INTRO_ID, $layerIds);
        self::assertContains(TestDataFixture::ORIENTATION_IMAGE_FREE_ID, $layerIds);
        self::assertNotContains(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID, $layerIds);
    }

    private function variant(string $id): TemplateVariant
    {
        return self::getContainer()->get(TemplateVariantRepository::class)->get(Uuid::fromString($id));
    }
}
