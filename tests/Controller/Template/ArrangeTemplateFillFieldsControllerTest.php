<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Template;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Entity\Template;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

final class ArrangeTemplateFillFieldsControllerTest extends WebTestCase
{
    private function url(): string
    {
        return '/template/' . TestDataFixture::GROUPED_TEMPLATE_ID . '/fill-field-order';
    }

    /**
     * @param array<mixed> $body
     */
    private function post(KernelBrowser $client, array $body): void
    {
        $client->request('POST', $this->url(), [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($body));
    }

    /**
     * @return list<string>
     */
    private function storedOrder(): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $template = $entityManager->find(Template::class, TestDataFixture::GROUPED_TEMPLATE_ID);
        self::assertInstanceOf(Template::class, $template);

        return $template->fillFieldOrder;
    }

    public function testAdminArrangementIsStoredAndAppliedToTheGroupFillPanel(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        // Natural order is text first, image second — store the reverse.
        $order = [TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID, TestDataFixture::GROUP_SHARED_INPUT_ID];
        $this->post($client, ['order' => [...$order, TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID]]);

        self::assertResponseIsSuccessful();
        // Duplicates collapse to their first position.
        self::assertSame($order, $this->storedOrder());

        $crawler = $client->request('GET', '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/fill');
        self::assertResponseIsSuccessful();

        $panel = $crawler->filter('[data-variant-fill-overlay-target="panel"]');
        self::assertSame('fill-field-order', $panel->attr('data-controller'));
        self::assertSame(
            $order,
            $panel->filter('.fill-popover[data-inputid]')->each(static fn ($node): string => (string) $node->attr('data-inputid')),
        );
        self::assertCount(2, $panel->filter('[data-fill-field-order-handle]'));
    }

    public function testNonAdminCannotArrangeAndGetsNoHandles(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::SHARED_USER_EMAIL);

        $this->post($client, ['order' => [TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID]]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->storedOrder());

        $crawler = $client->request('GET', '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/fill');
        self::assertResponseIsSuccessful();
        self::assertNull($crawler->filter('[data-variant-fill-overlay-target="panel"]')->attr('data-controller'));
        self::assertCount(0, $crawler->filter('[data-fill-field-order-handle]'));
    }

    public function testRejectsMalformedBody(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $this->post($client, ['order' => 'nope']);
        self::assertResponseStatusCodeSame(400);

        $this->post($client, ['order' => [42]]);
        self::assertResponseStatusCodeSame(400);
    }
}
