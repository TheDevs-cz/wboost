<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\Template;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Entity\TemplateExportVersion;
use WBoost\Web\Message\Template\PinTemplateExportVersion;
use WBoost\Web\Message\Template\RecordTemplateExportVersion;
use WBoost\Web\MessageHandler\Template\PinTemplateExportVersionHandler;
use WBoost\Web\MessageHandler\Template\RecordTemplateExportVersionHandler;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\ExportChannel;
use WBoost\Web\Value\ExportFillValues;

/**
 * @covers \WBoost\Web\MessageHandler\Template\RecordTemplateExportVersionHandler
 * @covers \WBoost\Web\Repository\TemplateExportVersionRepository
 * @covers \WBoost\Web\MessageHandler\Template\PinTemplateExportVersionHandler
 */
final class RecordTemplateExportVersionHandlerTest extends KernelTestCase
{
    public function testCreatesAVariantVersionAndBumpsTheDuplicateInsteadOfDuplicating(): void
    {
        $handler = self::getContainer()->get(RecordTemplateExportVersionHandler::class);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $variantId = Uuid::fromString(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID);

        $fill = ExportFillValues::fromVariantWebForm(
            [TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID => 'Hello'],
            [],
            [],
        );

        $handler(new RecordTemplateExportVersion($variantId, null, Uuid::fromString(TestDataFixture::USER_1_ID), ExportChannel::Web, $fill));
        $entityManager->flush();

        /** @var list<TemplateExportVersion> $versions */
        $versions = $entityManager->getRepository(TemplateExportVersion::class)->findBy(['variant' => $variantId->toString()]);
        self::assertCount(1, $versions);
        $version = $versions[0];
        self::assertSame(TestDataFixture::CUSTOM_TEMPLATE_1_ID, $version->template->id->toString());
        self::assertNull($version->group);
        self::assertSame(ExportChannel::Web, $version->channel);
        self::assertSame(1, $version->exportCount);
        self::assertNotNull($version->exportedBy);
        self::assertSame(TestDataFixture::USER_1_ID, $version->exportedBy->id->toString());
        self::assertSame($fill->toArray(), $version->fillValues->toArray());

        // The SAME fill again — same hash even with reordered keys — bumps.
        $sameFillReordered = ExportFillValues::fromApiRequest(
            [TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID => 'Hello'],
            [],
        );
        self::assertSame($fill->hash(), $sameFillReordered->hash());

        $handler(new RecordTemplateExportVersion($variantId, null, Uuid::fromString(TestDataFixture::ADMIN_USER_ID), ExportChannel::Api, $sameFillReordered));
        $entityManager->flush();

        $versions = $entityManager->getRepository(TemplateExportVersion::class)->findBy(['variant' => $variantId->toString()]);
        self::assertCount(1, $versions);
        self::assertSame(2, $versions[0]->exportCount);
        self::assertSame(ExportChannel::Api, $versions[0]->channel);
        self::assertNotNull($versions[0]->exportedBy);
        self::assertSame(TestDataFixture::ADMIN_USER_ID, $versions[0]->exportedBy->id->toString());

        // A DIFFERENT fill is a new history entry.
        $handler(new RecordTemplateExportVersion($variantId, null, null, ExportChannel::Web, ExportFillValues::fromVariantWebForm(
            [TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID => 'Different'],
            [],
            [],
        )));
        $entityManager->flush();

        self::assertCount(2, $entityManager->getRepository(TemplateExportVersion::class)->findBy(['variant' => $variantId->toString()]));
    }

    public function testGroupVersionResolvesTheGroupsTemplate(): void
    {
        $handler = self::getContainer()->get(RecordTemplateExportVersionHandler::class);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $groupId = Uuid::fromString(TestDataFixture::TEMPLATE_GROUP_1_ID);

        $handler(new RecordTemplateExportVersion(null, $groupId, null, ExportChannel::Web, ExportFillValues::fromGroupWebForm(
            [TestDataFixture::GROUP_SHARED_INPUT_ID => 'Letní kampaň'],
            [],
            [],
            [TestDataFixture::GROUPED_PRESET_VARIANT_ID => [TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID => ['scale' => '1.5']]],
        )));
        $entityManager->flush();

        /** @var list<TemplateExportVersion> $versions */
        $versions = $entityManager->getRepository(TemplateExportVersion::class)->findBy(['group' => $groupId->toString()]);
        self::assertCount(1, $versions);
        self::assertNull($versions[0]->variant);
        self::assertSame(TestDataFixture::GROUPED_TEMPLATE_ID, $versions[0]->template->id->toString());
        self::assertSame(
            [TestDataFixture::GROUPED_PRESET_VARIANT_ID => [TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID => ['scale' => 1.5]]],
            $versions[0]->fillValues->toArray()['placements'],
        );

        // Missing subjects must be a silent no-op (versioning never breaks an
        // export, and the subject can race a delete).
        $handler(new RecordTemplateExportVersion(Uuid::uuid4(), null, null, ExportChannel::Web, ExportFillValues::fromVariantWebForm([], [], [])));
        $handler(new RecordTemplateExportVersion(null, Uuid::uuid4(), null, ExportChannel::Web, ExportFillValues::fromVariantWebForm([], [], [])));
        $entityManager->flush();
    }

    public function testHistoryIsPrunedToTheCapExceptPinnedVersions(): void
    {
        $handler = self::getContainer()->get(RecordTemplateExportVersionHandler::class);
        $pinHandler = self::getContainer()->get(PinTemplateExportVersionHandler::class);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $variantId = Uuid::fromString(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID);

        // The very first (= oldest) fill gets pinned before the flood.
        $handler(new RecordTemplateExportVersion($variantId, null, null, ExportChannel::Web, ExportFillValues::fromVariantWebForm(
            [TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID => 'Pinned keeper'],
            [],
            [],
        )));
        $entityManager->flush();
        /** @var list<TemplateExportVersion> $initial */
        $initial = $entityManager->getRepository(TemplateExportVersion::class)->findBy(['variant' => $variantId->toString()]);
        self::assertCount(1, $initial);
        $pinned = $initial[0];
        // Strictly the oldest: the flood below lands within the same second,
        // and the prune orders by lastExportedAt.
        $pinned->lastExportedAt = new DateTimeImmutable('-1 day');
        $pinHandler(new PinTemplateExportVersion($pinned->id, true));
        $entityManager->flush();
        self::assertTrue($pinned->isPinned());

        for ($i = 0; $i < RecordTemplateExportVersionHandler::MAX_VERSIONS + 3; $i++) {
            $handler(new RecordTemplateExportVersion($variantId, null, null, ExportChannel::Web, ExportFillValues::fromVariantWebForm(
                [TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID => "Fill $i"],
                [],
                [],
            )));
            $entityManager->flush();
        }

        /** @var list<TemplateExportVersion> $remaining */
        $remaining = $entityManager->getRepository(TemplateExportVersion::class)->findBy(['variant' => $variantId->toString()]);
        // The cap counts UNPINNED versions; the pinned one rides outside it.
        self::assertCount(RecordTemplateExportVersionHandler::MAX_VERSIONS + 1, $remaining);
        $survivorIds = array_map(static fn (TemplateExportVersion $version): string => $version->id->toString(), $remaining);
        self::assertContains($pinned->id->toString(), $survivorIds);

        // Unpinning puts it back under the cap: the next export prunes it
        // (it is the oldest by far).
        $pinHandler(new PinTemplateExportVersion($pinned->id, false));
        $entityManager->flush();
        $handler(new RecordTemplateExportVersion($variantId, null, null, ExportChannel::Web, ExportFillValues::fromVariantWebForm(
            [TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID => 'One more'],
            [],
            [],
        )));
        $entityManager->flush();

        $remaining = $entityManager->getRepository(TemplateExportVersion::class)->findBy(['variant' => $variantId->toString()]);
        self::assertCount(RecordTemplateExportVersionHandler::MAX_VERSIONS, $remaining);
        self::assertNotContains(
            $pinned->id->toString(),
            array_map(static fn (TemplateExportVersion $version): string => $version->id->toString(), $remaining),
        );
    }
}
