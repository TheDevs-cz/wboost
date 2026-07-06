<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\TemplateGroup;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

/**
 * @covers \WBoost\Web\Controller\TemplateGroup\TemplateGroupsController
 */
final class TemplateGroupsControllerTest extends WebTestCase
{
    private function listUrl(): string
    {
        return '/project/' . TestDataFixture::PROJECT_1_ID . '/template-groups';
    }

    public function testListsGroupsWithMemberSummaryAsAdmin(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $client->request('GET', $this->listUrl());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Group Campaign');
        self::assertSelectorExists('a[href="/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/editor"]');
        // Delete dialog offers both modes.
        self::assertSelectorExists('form[action="/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/delete"] button[name="deleteTemplates"][value="0"]');
        self::assertSelectorExists('form[action="/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/delete"] button[name="deleteTemplates"][value="1"]');
    }

    public function testForbiddenForOwnerWithoutDesignerRole(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', $this->listUrl());

        self::assertResponseStatusCodeSame(403);
    }
}
