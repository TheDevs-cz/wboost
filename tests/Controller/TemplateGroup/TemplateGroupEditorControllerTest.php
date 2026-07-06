<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\TemplateGroup;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Repository\CustomTemplateVariantRepository;
use WBoost\Web\Repository\SocialNetworkTemplateVariantRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

/**
 * @covers \WBoost\Web\Controller\TemplateGroup\TemplateGroupEditorController
 */
final class TemplateGroupEditorControllerTest extends WebTestCase
{
    private function editorUrl(): string
    {
        return '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/editor';
    }

    public function testEditorRendersTabsForBothModulesAsAdmin(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $client->request('GET', $this->editorUrl());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller~="group-editor"]');
        self::assertSelectorExists('[data-controller~="canvas-editor"]');
        // One rail card per GROUP member — the manually-added ungrouped
        // variant on the grouped template gets NO tab.
        self::assertSelectorExists('[data-group-editor-target="card"][data-variant-id="' . TestDataFixture::GROUPED_SOCIAL_VARIANT_ID . '"]');
        self::assertSelectorExists('[data-group-editor-target="card"][data-variant-id="' . TestDataFixture::GROUPED_CUSTOM_VARIANT_ID . '"]');
        self::assertSelectorNotExists('[data-group-editor-target="card"][data-variant-id="' . TestDataFixture::UNGROUPED_VARIANT_ON_GROUPED_TEMPLATE_ID . '"]');
    }

    public function testSavePersistsCanvasOfBothModules(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $socialCanvas = '{"version":"5.2.4","objects":[],"note":"social-updated"}';
        $customCanvas = '{"version":"5.2.4","objects":[],"note":"custom-updated"}';

        $client->request('POST', $this->editorUrl(), [
            '_token' => $this->csrfToken($client),
            'variants' => [
                TestDataFixture::GROUPED_SOCIAL_VARIANT_ID => [
                    'canvas' => $socialCanvas,
                    'textInputs' => '[]',
                    'imageInputs' => '[]',
                    'imagePreview' => '',
                ],
                TestDataFixture::GROUPED_CUSTOM_VARIANT_ID => [
                    'canvas' => $customCanvas,
                    'textInputs' => '[]',
                    'imageInputs' => '[]',
                    'imagePreview' => '',
                ],
            ],
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame('success', $payload['status']);

        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $socialVariant = self::getContainer()->get(SocialNetworkTemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::GROUPED_SOCIAL_VARIANT_ID));
        self::assertStringContainsString('social-updated', $socialVariant->canvas);

        $customVariant = self::getContainer()->get(CustomTemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::GROUPED_CUSTOM_VARIANT_ID));
        self::assertStringContainsString('custom-updated', $customVariant->canvas);
    }

    public function testSaveRejectsVariantNotCreatedViaGroup(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $client->request('POST', $this->editorUrl(), [
            '_token' => $this->csrfToken($client),
            'variants' => [
                // Belongs to the grouped TEMPLATE but was added manually —
                // carries no group FK, so it is NOT group-editable.
                TestDataFixture::UNGROUPED_VARIANT_ON_GROUPED_TEMPLATE_ID => [
                    'canvas' => '{"version":"5.2.4","objects":[],"note":"must-not-persist"}',
                    'textInputs' => '[]',
                ],
            ],
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(400);

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $variant = self::getContainer()->get(SocialNetworkTemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::UNGROUPED_VARIANT_ON_GROUPED_TEMPLATE_ID));
        self::assertStringNotContainsString('must-not-persist', $variant->canvas);
    }

    public function testSaveRejectsForeignVariantAndPersistsNothing(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $client->request('POST', $this->editorUrl(), [
            '_token' => $this->csrfToken($client),
            'variants' => [
                TestDataFixture::GROUPED_SOCIAL_VARIANT_ID => [
                    'canvas' => '{"version":"5.2.4","objects":[],"note":"rolled-back"}',
                    'textInputs' => '[]',
                ],
                TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID => [
                    'canvas' => '{}',
                    'textInputs' => '[]',
                ],
            ],
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(400);

        // Validation happens BEFORE any dispatch — the valid entry must not
        // have been persisted either.
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $variant = self::getContainer()->get(SocialNetworkTemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::GROUPED_SOCIAL_VARIANT_ID));
        self::assertStringNotContainsString('rolled-back', $variant->canvas);
    }

    public function testOwnerWithoutDesignerRoleIsForbidden(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', $this->editorUrl());

        self::assertResponseStatusCodeSame(403);
    }

    public function testOtherUserIsForbidden(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_2_EMAIL);

        $client->request('GET', $this->editorUrl());

        self::assertResponseStatusCodeSame(403);
    }

    private function csrfToken(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', $this->editorUrl());
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('[data-group-editor-csrf-value]')->attr('data-group-editor-csrf-value');
        self::assertIsString($token);

        return $token;
    }
}
