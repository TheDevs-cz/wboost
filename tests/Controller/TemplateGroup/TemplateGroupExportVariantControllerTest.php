<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\TemplateGroup;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeTemplateVariantImageRenderer;
use WBoost\Web\Tests\TestingLogin;

/**
 * @covers \WBoost\Web\Controller\TemplateGroup\TemplateGroupExportVariantController
 */
final class TemplateGroupExportVariantControllerTest extends WebTestCase
{
    private function exportUrl(string $variantId): string
    {
        return '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/export/' . $variantId;
    }

    public function testDownloadRendersJustTheOneVariantAsPng(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $client->request('POST', $this->exportUrl(TestDataFixture::GROUPED_PRESET_VARIANT_ID), [
            'textValues' => [
                TestDataFixture::GROUP_SHARED_INPUT_ID => 'Letní kampaň',
            ],
        ]);

        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertSame('image/png', $response->headers->get('Content-Type'));
        // Same naming scheme as the ZIP entry for this dimension.
        self::assertSame('attachment; filename="group-campaign-1-1.png"', $response->headers->get('Content-Disposition'));
        self::assertStringStartsWith("\x89PNG", (string) $response->getContent());

        // Exactly ONE render — the sibling dimension is not touched.
        $calls = $this->getRendererFake()->calls;
        self::assertCount(1, $calls);
        self::assertSame(TestDataFixture::GROUPED_PRESET_VARIANT_ID, $calls[0]['variantId']);
        self::assertSame([TestDataFixture::GROUP_SHARED_INPUT_ID => 'Letní kampaň'], $calls[0]['texts']);
        self::assertFalse($calls[0]['strictContainerOverflow'], 'single download renders lenient, like the ZIP');
        self::assertSame('png', $calls[0]['format'], 'the downloaded file must stay lossless PNG');
    }

    public function testDownloadRejectsVariantOutsideTheGroup(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        // Lives on the grouped template but carries no group FK — the same
        // membership rule the fill preview and the group save enforce.
        $client->request('POST', $this->exportUrl(TestDataFixture::UNGROUPED_VARIANT_ON_GROUPED_TEMPLATE_ID));

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->getRendererFake()->calls, 'nothing may be rendered for a non-member variant');
    }

    public function testGetSendsTheVisitorBackToTheFillPage(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $client->request('GET', $this->exportUrl(TestDataFixture::GROUPED_PRESET_VARIANT_ID));

        self::assertResponseRedirects('/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/fill');
        self::assertSame([], $this->getRendererFake()->calls, 'a GET must not render anything');
    }

    public function testDownloadIsForbiddenForUnrelatedUser(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_2_EMAIL);

        $client->request('POST', $this->exportUrl(TestDataFixture::GROUPED_PRESET_VARIANT_ID));

        self::assertResponseStatusCodeSame(403);
    }

    public function testUnrenderableFillShowsTheReasonInsteadOfTheGenericErrorPage(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $crawler = $client->request('POST', $this->exportUrl(TestDataFixture::GROUPED_PRESET_VARIANT_ID), [
            'images' => [
                TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID => [
                    'imageId' => TestDataFixture::FILE_IN_OTHER_ID,
                ],
            ],
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('Export se nezdařil', $crawler->filter('body')->text());
        self::assertNotSame('image/png', $client->getResponse()->headers->get('Content-Type'));
    }

    private function getRendererFake(): FakeTemplateVariantImageRenderer
    {
        $renderer = self::getContainer()->get(TemplateVariantImageRendererInterface::class);
        /** @phpstan-ignore staticMethod.impossibleType */
        self::assertInstanceOf(FakeTemplateVariantImageRenderer::class, $renderer);

        return $renderer;
    }
}
