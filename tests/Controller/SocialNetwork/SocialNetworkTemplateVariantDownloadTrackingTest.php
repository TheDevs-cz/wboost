<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\SocialNetwork;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Entity\ExportEvent;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\ExportChannel;
use WBoost\Web\Value\ExportedTemplateType;

/**
 * Pins the usage-tracking wiring: a successful web download must record exactly
 * one {@see ExportEvent} with the correct denormalised labels and channel.
 *
 * @covers \WBoost\Web\Controller\SocialNetwork\SocialNetworkTemplateVariantDownloadController
 * @covers \WBoost\Web\Services\Usage\RecordExportUsage
 */
final class SocialNetworkTemplateVariantDownloadTrackingTest extends WebTestCase
{
    public function testWebDownloadRecordsExportEvent(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request(
            'POST',
            '/social-network-template-variant/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/download',
        );

        self::assertResponseIsSuccessful();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $event = $entityManager->getRepository(ExportEvent::class)->findOneBy([
            'variantId' => Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID),
        ]);

        self::assertInstanceOf(ExportEvent::class, $event);
        self::assertSame(ExportedTemplateType::SocialNetwork, $event->templateType);
        self::assertSame(ExportChannel::Web, $event->channel);
        self::assertSame(TestDataFixture::USER_1_EMAIL, $event->ownerEmail);
        self::assertSame(TestDataFixture::PROJECT_1_ID, $event->projectId->toString());
        self::assertNotNull($event->triggeredByUserId);
        self::assertSame(TestDataFixture::USER_1_ID, $event->triggeredByUserId->toString());
    }
}
