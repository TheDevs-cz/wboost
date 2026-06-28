<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\Usage;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Entity\ExportEvent;
use WBoost\Web\Message\Usage\RecordTemplateExport;
use WBoost\Web\MessageHandler\Usage\RecordTemplateExportHandler;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\ExportChannel;
use WBoost\Web\Value\ExportedTemplateType;

final class RecordTemplateExportHandlerTest extends KernelTestCase
{
    public function testPersistsExportEventWithDenormalisedLabels(): void
    {
        $handler = self::getContainer()->get(RecordTemplateExportHandler::class);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $variantId = Uuid::uuid4();

        $handler(new RecordTemplateExport(
            ExportedTemplateType::CustomTemplate,
            ExportChannel::Api,
            Uuid::fromString(TestDataFixture::CUSTOM_TEMPLATE_1_ID),
            'Letáček A4',
            $variantId,
            Uuid::fromString(TestDataFixture::PROJECT_1_ID),
            'Projekt číslo 1',
            Uuid::fromString(TestDataFixture::USER_1_ID),
            TestDataFixture::USER_1_EMAIL,
            Uuid::fromString(TestDataFixture::USER_1_ID),
        ));

        $entityManager->flush();

        $event = $entityManager->getRepository(ExportEvent::class)->findOneBy(['variantId' => $variantId]);

        self::assertInstanceOf(ExportEvent::class, $event);
        self::assertSame(ExportedTemplateType::CustomTemplate, $event->templateType);
        self::assertSame(ExportChannel::Api, $event->channel);
        self::assertSame('Letáček A4', $event->templateName);
        self::assertSame('Projekt číslo 1', $event->projectName);
        self::assertSame(TestDataFixture::USER_1_EMAIL, $event->ownerEmail);
        self::assertNotNull($event->triggeredByUserId);
        self::assertSame(TestDataFixture::USER_1_ID, $event->triggeredByUserId->toString());
    }
}
