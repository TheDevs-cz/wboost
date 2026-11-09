<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\TemplateDocumentExportVersion;
use WBoost\Web\Entity\TemplateExportVersion;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TemplateDocumentTestData;
use WBoost\Web\Tests\TestingLogin;

/**
 * Every web download is named after what the user exported — never the
 * variant UUID — as `<template>[ - <size>][ - <page>][ - <version>].<ext>`,
 * with the Czech name in `filename*` and an ASCII `filename` fallback.
 *
 * @covers \WBoost\Web\Services\Template\TemplateExportFileNames
 * @covers \WBoost\Web\Value\DownloadFileName
 */
final class ExportFileNameTest extends WebTestCase
{
    public function testSingleSizeTemplateIsNamedAfterTheTemplate(): void
    {
        $client = $this->client();

        $client->request('POST', '/template-variant/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/download');

        self::assertResponseIsSuccessful();
        self::assertSame('attachment; filename="Insta Template 1.png"', $client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testATemplateWithSeveralSizesAddsTheSize(): void
    {
        $client = $this->client();

        $client->request('POST', '/template-variant/' . TestDataFixture::UNGROUPED_VARIANT_ON_GROUPED_TEMPLATE_ID . '/download');

        self::assertResponseIsSuccessful();
        self::assertSame('attachment; filename="Group Campaign - 9x16.png"', $client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testCzechNameKeepsDiacriticsWithAnAsciiFallback(): void
    {
        $client = $this->client();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $template = $em->find(Template::class, Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_1_ID));
        self::assertInstanceOf(Template::class, $template);
        $template->name = 'Úvodní strana';
        $em->flush();

        $client->request('POST', '/template-variant/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/download');

        self::assertResponseIsSuccessful();
        self::assertSame(
            "attachment; filename=\"Uvodni strana.png\"; filename*=utf-8''%C3%9Avodn%C3%AD%20strana.png",
            $client->getResponse()->headers->get('Content-Disposition'),
        );
    }

    public function testANamedExportVersionIsAppended(): void
    {
        $client = $this->client();
        $client->disableReboot();
        $fill = ['textValues' => [TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID => 'Výsledek zápasu']];
        $url = '/template-variant/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/download';

        $client->request('POST', $url, $fill);
        self::assertResponseIsSuccessful();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $version = $em->getRepository(TemplateExportVersion::class)->findOneBy(['variant' => TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID]);
        self::assertInstanceOf(TemplateExportVersion::class, $version);
        $version->rename('Sparta doma');
        $em->flush();

        // The same fill lands on (bumps) the named version → its name is used.
        $client->request('POST', $url, $fill);
        self::assertResponseIsSuccessful();
        self::assertSame('attachment; filename="Insta Template 1 - Sparta doma.png"', $client->getResponse()->headers->get('Content-Disposition'));

        // A different fill is a new, unnamed version → no stale name.
        $client->request('POST', $url, ['textValues' => [TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID => 'Jiný text']]);
        self::assertResponseIsSuccessful();
        self::assertSame('attachment; filename="Insta Template 1.png"', $client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testANamedGroupVersionIsAppendedToTheZipAndTheSingleDimension(): void
    {
        $client = $this->client();
        $client->disableReboot();
        $fill = ['textValues' => [TestDataFixture::GROUP_SHARED_INPUT_ID => 'Letní kampaň']];

        $client->request('POST', '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/export', $fill);
        self::assertResponseIsSuccessful();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $version = $em->getRepository(TemplateExportVersion::class)->findOneBy(['group' => TestDataFixture::TEMPLATE_GROUP_1_ID]);
        self::assertInstanceOf(TemplateExportVersion::class, $version);
        $version->rename('Léto');
        $em->flush();

        $client->request('POST', '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/export', $fill);
        self::assertResponseIsSuccessful();
        self::assertSame(
            "attachment; filename=\"Group Campaign - Leto.zip\"; filename*=utf-8''Group%20Campaign%20-%20L%C3%A9to.zip",
            $client->getResponse()->headers->get('Content-Disposition'),
        );

        $client->request('POST', '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/export/' . TestDataFixture::GROUPED_PRESET_VARIANT_ID, $fill);
        self::assertResponseIsSuccessful();
        self::assertSame(
            "attachment; filename=\"Group Campaign - 1x1 - Leto.png\"; filename*=utf-8''Group%20Campaign%20-%201x1%20-%20L%C3%A9to.png",
            $client->getResponse()->headers->get('Content-Disposition'),
        );
    }

    public function testAPageOfADocumentIsNamedDocumentThenPage(): void
    {
        $client = $this->client();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $first = TemplateDocumentTestData::a4Template($em, 'Úvodní strana');
        $second = TemplateDocumentTestData::a4Template($em, 'Ceník');
        TemplateDocumentTestData::document($em, self::getContainer()->get(MessageBusInterface::class), [$first['template'], $second['template']], 'Nabídka 2027');

        $client->request('POST', '/template-variant/' . $first['variant'] . '/download');

        self::assertResponseIsSuccessful();
        self::assertSame(
            "attachment; filename=\"Nabidka 2027 - Uvodni strana.png\"; filename*=utf-8''Nab%C3%ADdka%202027%20-%20%C3%9Avodn%C3%AD%20strana.png",
            $client->getResponse()->headers->get('Content-Disposition'),
        );
    }

    public function testANamedDocumentVersionIsAppended(): void
    {
        $client = $this->client();
        $client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $page = TemplateDocumentTestData::a4Template($em, 'Úvod');
        $documentId = TemplateDocumentTestData::document($em, self::getContainer()->get(MessageBusInterface::class), [$page['template']], 'Ceník');

        $client->request('POST', '/template-document/' . $documentId . '/export', ['format' => 'pdf-screen']);
        self::assertResponseIsSuccessful();

        $version = $em->getRepository(TemplateDocumentExportVersion::class)->findOneBy(['document' => $documentId->toString()]);
        self::assertInstanceOf(TemplateDocumentExportVersion::class, $version);
        $version->rename('Říjen');
        $em->flush();

        $client->request('POST', '/template-document/' . $documentId . '/export', ['format' => 'pdf-screen']);
        self::assertResponseIsSuccessful();
        self::assertSame(
            "attachment; filename=\"Cenik - Rijen.pdf\"; filename*=utf-8''Cen%C3%ADk%20-%20%C5%98%C3%ADjen.pdf",
            $client->getResponse()->headers->get('Content-Disposition'),
        );
    }

    private function client(): KernelBrowser
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        return $client;
    }
}
