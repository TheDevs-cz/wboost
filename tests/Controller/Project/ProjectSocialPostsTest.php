<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Project;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\SocialPost;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Entity\User;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\SocialPlatform;

/**
 * "Publikované příspěvky" on the project's „Facebook a Instagram“ page and
 * the matching pill in the variant's export history.
 */
final class ProjectSocialPostsTest extends WebTestCase
{
    public function testEmptyStateWithoutPosts(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', '/project/' . TestDataFixture::PROJECT_1_ID . '/social');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#prispevky', 'Publikované příspěvky');
        self::assertSelectorExists('#prispevky .sp-empty');
    }

    public function testPostsAreListedNewestFirstForProjectMembers(): void
    {
        $client = self::createClient();
        $this->post('ig-1', SocialPlatform::Instagram, 'feed', '-2 days', 'Starší', 'https://www.instagram.com/p/old/');
        $this->post('fb-1', SocialPlatform::Facebook, 'feed', '-1 hour', 'Novější', 'https://www.facebook.com/1_2');
        $this->post('ig-2', SocialPlatform::Instagram, 'story', '-1 day', '', null);

        // A shared member (not the owner) sees the history too.
        TestingLogin::logInAsUser($client, TestDataFixture::SHARED_USER_EMAIL);
        $crawler = $client->request('GET', '/project/' . TestDataFixture::PROJECT_1_ID . '/social');

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('#prispevky .sp-row');
        self::assertCount(3, $rows);
        self::assertSame(['fb-1', 'ig-2', 'ig-1'], $rows->each(static fn ($row): string => (string) $row->attr('data-social-post')));
        self::assertStringContainsString('Novější', $rows->eq(0)->text());
        self::assertSame('https://www.facebook.com/1_2', $rows->eq(0)->filter('a.sp-open')->attr('href'));
        self::assertStringContainsString('Story', $rows->eq(1)->text());
        self::assertCount(0, $rows->eq(1)->filter('a.sp-open'), 'no permalink → no link');
    }

    public function testOutsiderCannotSeeThePage(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_2_EMAIL);

        $client->request('GET', '/project/' . TestDataFixture::PROJECT_1_ID . '/social');

        self::assertResponseStatusCodeSame(403);
    }

    private function post(string $externalId, SocialPlatform $platform, string $kind, string $when, string $caption, null|string $permalink, null|string $hash = null): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $project = $entityManager->find(Project::class, Uuid::fromString(TestDataFixture::PROJECT_1_ID));
        self::assertInstanceOf(Project::class, $project);
        $variant = $entityManager->find(TemplateVariant::class, Uuid::fromString(TestDataFixture::GROUPED_PRESET_VARIANT_ID));
        self::assertInstanceOf(TemplateVariant::class, $variant);
        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => TestDataFixture::USER_1_EMAIL]);

        $entityManager->persist(new SocialPost(
            Uuid::uuid7(),
            $project,
            $variant,
            $variant->template->id,
            $variant->template->name,
            $platform,
            $kind,
            'acc-1',
            $platform === SocialPlatform::Instagram ? '@kavarna' : 'Kavárna',
            $externalId,
            $permalink,
            $caption,
            $user,
            new \DateTimeImmutable($when),
            $hash,
            ['postId' => $externalId],
        ));
        $entityManager->flush();
    }
}
