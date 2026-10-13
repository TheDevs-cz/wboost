<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Template;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Entity\Template;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

final class DuplicateTemplateControllerTest extends WebTestCase
{
    public function testGroupedTemplateCardOffersDuplicateAndLandsInTheCopysGroupEditor(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $copyUrl = '/template/' . TestDataFixture::GROUPED_TEMPLATE_ID . '/copy';

        $crawler = $client->request('GET', '/project/' . TestDataFixture::PROJECT_1_ID . '/templates');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href="' . $copyUrl . '"]'));

        $client->request('GET', $copyUrl);
        self::assertResponseRedirects();

        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#^/template-group/[0-9a-f-]{36}/editor$#', $location);
        self::assertStringNotContainsString(TestDataFixture::TEMPLATE_GROUP_1_ID, $location, 'The copy gets its own group.');

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $copies = $entityManager->getRepository(Template::class)->findBy(['name' => 'Group Campaign (kopie)']);
        self::assertCount(1, $copies);
        self::assertNotNull($copies[0]->group);
        self::assertStringContainsString($copies[0]->group->id->toString(), $location);

        $client->request('GET', $location);
        self::assertResponseIsSuccessful();
    }

    public function testNonDesignerCannotDuplicateGroupedTemplate(): void
    {
        $client = self::createClient();
        // Owner of project 1 (template_edit) but without ROLE_DESIGNER.
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', '/template/' . TestDataFixture::GROUPED_TEMPLATE_ID . '/copy');
        self::assertResponseStatusCodeSame(403);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(0, $entityManager->getRepository(Template::class)->findBy(['name' => 'Group Campaign (kopie)']));
    }
}
