<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Query;

use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Query\GetFontUsage;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;

/**
 * @covers \WBoost\Web\Query\GetFontUsage
 * @covers \WBoost\Web\Value\FontUsage
 */
final class GetFontUsageTest extends KernelTestCase
{
    public function testScansCanvasFontsAllowlistsAndReportsMissingFamilies(): void
    {
        $usage = self::getContainer()->get(GetFontUsage::class)->forProject(Uuid::fromString(TestDataFixture::PROJECT_1_ID));

        // The social variant's headline is designed in Rubik Bold; its
        // tagline's allowlist opens Rubik Bold too — one template, counted once.
        $bold = $usage->sitesFor('Rubik (Rubik Bold)');
        self::assertNotSame([], $bold);
        $variantIds = array_map(static fn ($site): string => $site->variantId, $bold);
        self::assertContains(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID, $variantIds);
        self::assertSame(count(array_unique(array_map(static fn ($site): string => $site->templateId, $bold))), $usage->templatesCountFor('Rubik (Rubik Bold)'));
        self::assertNotSame([], $usage->templateNamesFor(['Rubik (Rubik Bold)']));

        // Project faces are never "missing"; a family no face satisfies is.
        self::assertArrayNotHasKey('Rubik (Rubik Bold)', $usage->missing);
        foreach (array_keys($usage->missing) as $family) {
            self::assertStringStartsNotWith('Rubik', $family);
        }
        self::assertSame($usage->missing !== [], $usage->hasMissing());
        self::assertSame([], $usage->sitesFor('Nope (Nope Regular)'));
    }
}
