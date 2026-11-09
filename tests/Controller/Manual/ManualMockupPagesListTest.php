<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Manual;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Ramsey\Uuid\Uuid;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Entity\ManualMockupPage;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\MockupPageLayout;

/**
 * F13: the list carries two views (Mřížka / Náhled stránek) over ONE list of
 * page cards plus a thumbnail rail. Both reorder through the same sort route.
 *
 * @covers \WBoost\Web\Controller\Manual\ManualMockupPagesController
 */
final class ManualMockupPagesListTest extends WebTestCase
{
    public function testBothViewsShareOneListAndTheRailMirrorsIt(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $manual = $em->find(Manual::class, TestDataFixture::MANUAL_1_ID);
        self::assertNotNull($manual);
        $layout = MockupPageLayout::cases()[0];
        foreach (['Vizitky', 'Merch', 'Polep auta'] as $i => $name) {
            $em->persist(new ManualMockupPage(Uuid::uuid7(), $manual, new \DateTimeImmutable(), $layout, $name, [], $i));
        }
        $em->flush();
        $em->clear();
        $count = 3;

        $crawler = $browser->request('GET', '/manual/' . TestDataFixture::MANUAL_1_ID . '/mockup-pages');
        $this->assertResponseIsSuccessful();

        $sortUrl = '/manual/' . TestDataFixture::MANUAL_1_ID . '/stranky/poradi';

        // Default view is Náhled; the toggle + the controller hold the sort URL.
        self::assertSelectorExists('.man-mock[data-view="pdf"][data-controller="manual-mockup-pages"][data-manual-mockup-pages-sort-url-value="' . $sortUrl . '"]');
        self::assertSelectorExists('.man-viewsw [data-view-set="grid"]');
        self::assertSelectorExists('.man-viewsw [data-view-set="pdf"].on');

        // The grid's dragula hooks stay on the (single) list of cards.
        self::assertCount(1, $crawler->filter('ol.man-pages[data-controller="dragula"][data-dragula-sort-url-value="' . $sortUrl . '"]'));
        self::assertCount($count, $crawler->filter('ol.man-pages > li.man-pg[data-entity-id]'));

        // One thumbnail per page, same order as the cards.
        $cards = $crawler->filter('ol.man-pages > li.man-pg')->each(static fn ($li): string => (string) $li->attr('data-entity-id'));
        $thumbs = $crawler->filter('ol.man-thumbs > li.man-th')->each(static fn ($li): string => (string) $li->attr('data-page-id'));
        self::assertSame($cards, $thumbs);
        self::assertCount(1, $crawler->filter('ol.man-thumbs > li.man-th.on'));
    }
}
