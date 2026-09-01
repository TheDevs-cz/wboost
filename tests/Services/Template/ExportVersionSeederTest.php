<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Template;

use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Entity\TemplateExportVersion;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\Template\ExportVersionSeeder;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\ExportChannel;
use WBoost\Web\Value\ExportFillValues;

/**
 * The LENIENCY contract of version re-loading: whatever no longer applies —
 * deleted inputs, a locked input, a trashed or disallowed picture, a
 * transform the slot no longer permits, a rich envelope on a plain input —
 * silently falls back to the designed state instead of breaking the page or
 * seeding values the re-export would 400 on.
 *
 * @covers \WBoost\Web\Services\Template\ExportVersionSeeder
 */
final class ExportVersionSeederTest extends KernelTestCase
{
    public function testTextSeedingFiltersAndDegradesToTheInputDefinitions(): void
    {
        $envelope = '{"runs":[{"text":"Styled "},{"text":"text","underline":true}]}';

        $seed = $this->seedFor(ExportFillValues::fromVariantWebForm(
            [
                TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID => $envelope,
                TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_TAGLINE_ID => $envelope,
                TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_LOCKED_ID => 'Locked inputs are never seeded',
                'ffffffff-ffff-ffff-ffff-ffffffffffff' => 'Deleted input is dropped',
            ],
            [
                TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_BADGE_ID => '1',
                TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID => '1',
            ],
            [],
        ));

        self::assertSame(
            [
                // headline IS rich → the envelope survives verbatim.
                TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID => $envelope,
                // tagline is plain → the envelope degrades to its text concat.
                TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_TAGLINE_ID => 'Styled text',
            ],
            $seed['textValues'],
        );

        // Only the hidable input (badge) keeps its hide flag — headline is
        // not hidable, so its stored flag is dropped.
        self::assertSame([TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_BADGE_ID => true], $seed['hiddenValues']);
    }

    public function testImageSeedingMirrorsTheResolversValidationLeniently(): void
    {
        $seed = $this->seedFor(ExportFillValues::fromVariantWebForm([], [], [
            TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_IMAGE_PHOTO_ID => [
                'imageId' => TestDataFixture::FILE_IN_ALLOWED_ID,
                'scale' => '1.5',
                'offsetX' => '12.5',
                'rotation' => '45',
            ],
            // Slot allows nothing: the picture seeds, the transform is
            // stripped (a stored scale would 400 the re-export).
            TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_IMAGE_LOCKED_ID => [
                'imageId' => TestDataFixture::FILE_IN_ALLOWED_ID,
                'scale' => '2',
            ],
        ]));

        $photo = $seed['imageValues'][TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_IMAGE_PHOTO_ID];
        self::assertSame(TestDataFixture::FILE_IN_ALLOWED_ID, $photo['imageId']);
        self::assertIsString($photo['url']);
        self::assertSame(1.5, $photo['scale']);
        self::assertSame(12.5, $photo['offsetX']);
        self::assertSame(45.0, $photo['rotation']);

        self::assertSame(
            ['imageId', 'url'],
            array_keys($seed['imageValues'][TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_IMAGE_LOCKED_ID]),
        );
    }

    public function testUnusablePicturesAreDroppedAndHideOnlySurvivesOnHidableSlots(): void
    {
        $droppedTrashed = $this->seedFor(ExportFillValues::fromVariantWebForm([], [], [
            TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_IMAGE_PHOTO_ID => TestDataFixture::FILE_TRASHED_ID,
        ]));
        self::assertSame([], $droppedTrashed['imageValues']);

        $droppedDisallowed = $this->seedFor(ExportFillValues::fromVariantWebForm([], [], [
            // The photo slot only allows FILE_DIRECTORY_ALLOWED — a file that
            // was moved to another folder since the export must not seed.
            TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_IMAGE_PHOTO_ID => TestDataFixture::FILE_IN_OTHER_ID,
        ]));
        self::assertSame([], $droppedDisallowed['imageValues']);

        $hidden = $this->seedFor(ExportFillValues::fromVariantWebForm([], [], [
            TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_IMAGE_PHOTO_ID => ['hide' => '1'],
            // Not hidable → the stored hide is dropped entirely.
            TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_IMAGE_LOCKED_ID => ['hide' => '1'],
        ]));
        self::assertSame(
            [TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_IMAGE_PHOTO_ID => ['hide' => true]],
            $hidden['imageValues'],
        );
    }

    /**
     * @return array{textValues: array<string, string>, hiddenValues: array<string, bool>, imageValues: array<string, array<string, mixed>>}
     */
    private function seedFor(ExportFillValues $fillValues): array
    {
        $seeder = self::getContainer()->get(ExportVersionSeeder::class);
        $variant = $this->variant();

        $version = new TemplateExportVersion(
            Uuid::uuid4(),
            $variant->template,
            $variant,
            null,
            null,
            ExportChannel::Web,
            $fillValues,
            $fillValues->hash(),
            new \DateTimeImmutable(),
        );

        return $seeder->forVariant($version, $variant);
    }

    private function variant(): TemplateVariant
    {
        return self::getContainer()->get(TemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID));
    }
}
