<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\DataFixtures;

use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use League\Bundle\OAuth2ServerBundle\Model\Client as OAuth2Client;
use League\Bundle\OAuth2ServerBundle\OAuth2Grants;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use Ramsey\Uuid\Uuid;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Entity\OAuth2ClientUser;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\SocialNetworkTemplate;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Entity\User;
use WBoost\Web\Entity\WeeklyMenu;
use WBoost\Web\Entity\WeeklyMenuDay;
use WBoost\Web\Value\EditorTextInput;
use WBoost\Web\Value\ManualType;
use WBoost\Web\Value\TemplateDimension;
use WBoost\Web\Value\WeeklyMenuApprovalStatus;

final class TestDataFixture extends Fixture
{
    public const string USER_1_ID = '00000000-0000-0000-0000-000000000001';
    public const string USER_1_EMAIL = 'user1@test.cz';

    public const string USER_2_ID = '00000000-0000-0000-0000-000000000002';
    public const string USER_2_EMAIL = 'user2@test.cz';

    public const string PROJECT_1_ID = '00000000-0000-0000-0000-000000000001';
    public const string PROJECT_2_ID = '00000000-0000-0000-0000-000000000002';

    public const string MANUAL_1_ID = '00000000-0000-0000-0000-000000000001';
    public const string MANUAL_2_ID = '00000000-0000-0000-0000-000000000002';

    // OAuth2 fixtures (active client linked to USER_1, plus an inactive one)
    public const string OAUTH2_CLIENT_ID = 'testclientidaaaaaaaaaaaaaaaaaaaa';
    public const string OAUTH2_CLIENT_SECRET = 'testclientsecretbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    public const string OAUTH2_INACTIVE_CLIENT_ID = 'testinactiveclientcccccccccccccc';
    public const string OAUTH2_INACTIVE_CLIENT_SECRET = 'testinactivesecretdddddddddddddddddddddddddddddddddddddddddddddd';

    // Weekly Menu fixtures
    public const string WEEKLY_MENU_1_ID = '00000000-0000-0000-0000-000000000010';
    public const string WEEKLY_MENU_DAY_1_ID = '00000000-0000-0000-0000-000000000011';

    // Weekly Menu with approval (pending)
    public const string WEEKLY_MENU_2_ID = '00000000-0000-0000-0000-000000000020';
    public const string WEEKLY_MENU_2_APPROVAL_HASH = 'abcdef1234567890abcdef1234567890abcdef1234567890abcdef1234567890';

    // Social network template fixtures
    public const string SOCIAL_NETWORK_TEMPLATE_1_ID = '00000000-0000-0000-0000-000000000030';
    public const string SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID = '00000000-0000-0000-0000-000000000031';
    public const string SOCIAL_NETWORK_TEMPLATE_2_ID = '00000000-0000-0000-0000-000000000032';
    public const string SOCIAL_NETWORK_TEMPLATE_VARIANT_2_ID = '00000000-0000-0000-0000-000000000033';

    // Stable inputIds for the variant 1 inputs (headline, tagline, locked, badge).
    // Tests reference these to construct id-keyed export payloads.
    public const string SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID = '00000000-0000-0000-0000-000000000041';
    public const string SOCIAL_NETWORK_VARIANT_1_INPUT_TAGLINE_ID = '00000000-0000-0000-0000-000000000042';
    public const string SOCIAL_NETWORK_VARIANT_1_INPUT_LOCKED_ID = '00000000-0000-0000-0000-000000000043';
    public const string SOCIAL_NETWORK_VARIANT_1_INPUT_BADGE_ID = '00000000-0000-0000-0000-000000000044';

    public const string SOCIAL_NETWORK_VARIANT_2_INPUT_HEADLINE_ID = '00000000-0000-0000-0000-000000000051';

    public function load(ObjectManager $manager): void
    {
        $date = new DateTimeImmutable('00:00:00 2024/01/01');

        $user1 = new User(
            Uuid::fromString(self::USER_1_ID),
            self::USER_1_EMAIL,
            $date,
            true,
        );
        $manager->persist($user1);

        $project1 = new Project(
            Uuid::fromString(self::PROJECT_1_ID),
            $user1,
            $date,
            'Project 1',
        );
        $manager->persist($project1);

        $manual1 = new Manual(
            Uuid::fromString(self::MANUAL_1_ID),
            $project1,
            $date,
            ManualType::Logo,
            'Manual 1',
            null,
        );
        $manager->persist($manual1);

        $user2 = new User(
            Uuid::fromString(self::USER_2_ID),
            self::USER_2_EMAIL,
            $date,
            true,
        );
        $manager->persist($user2);

        $project2 = new Project(
            Uuid::fromString(self::PROJECT_2_ID),
            $user2,
            $date,
            'Project 2',
        );
        $manager->persist($project2);

        $manual2 = new Manual(
            Uuid::fromString(self::MANUAL_2_ID),
            $project2,
            $date,
            ManualType::Logo,
            'Manual 2',
            null,
        );
        $manager->persist($manual2);

        // Create Weekly Menu with day for testing
        $weeklyMenu1 = new WeeklyMenu(
            Uuid::fromString(self::WEEKLY_MENU_1_ID),
            $project1,
            $date,
            'Test Weekly Menu',
            new DateTimeImmutable('2024-01-01'),
            new DateTimeImmutable('2024-01-07'),
            null,
            'Jan Novak',
            'Marie Svobodova',
        );
        $manager->persist($weeklyMenu1);

        // Create one day (Monday)
        $day1 = new WeeklyMenuDay(
            Uuid::fromString(self::WEEKLY_MENU_DAY_1_ID),
            $weeklyMenu1,
            1, // Monday
        );
        $weeklyMenu1->addDay($day1);
        $manager->persist($day1);

        // Weekly Menu with approval (pending state)
        $weeklyMenu2 = new WeeklyMenu(
            Uuid::fromString(self::WEEKLY_MENU_2_ID),
            $project1,
            $date,
            'Approval Test Menu',
            new DateTimeImmutable('2024-02-01'),
            new DateTimeImmutable('2024-02-07'),
            null,
            'Jan Novak',
            null,
            'approver@test.cz',
            WeeklyMenuApprovalStatus::Pending,
            self::WEEKLY_MENU_2_APPROVAL_HASH,
            null,
            null,
            'user1@test.cz',
        );
        $manager->persist($weeklyMenu2);

        // Social network template (USER_1 / PROJECT_1) — exercises non-locked named, uppercase, and locked-unnamed inputs.
        $socialTemplate1 = new SocialNetworkTemplate(
            Uuid::fromString(self::SOCIAL_NETWORK_TEMPLATE_1_ID),
            $project1,
            null,
            $date,
            'Insta Template 1',
            null,
            0,
        );
        $manager->persist($socialTemplate1);

        $socialVariant1 = new SocialNetworkTemplateVariant(
            Uuid::fromString(self::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID),
            $socialTemplate1,
            TemplateDimension::InstagramPost,
            'fixtures/bg-1.png',
            $date,
        );
        $socialVariant1->editCanvas(
            '{"version":"5.2.4","objects":[],"backgroundImage":null}',
            [
                new EditorTextInput(self::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID, 'headline', 30, false, false, null, false),
                new EditorTextInput(self::SOCIAL_NETWORK_VARIANT_1_INPUT_TAGLINE_ID, 'tagline', null, false, true, null, false),
                new EditorTextInput(self::SOCIAL_NETWORK_VARIANT_1_INPUT_LOCKED_ID, null, null, true, false, null, false),
                new EditorTextInput(self::SOCIAL_NETWORK_VARIANT_1_INPUT_BADGE_ID, 'badge', null, false, false, null, true),
            ],
            null,
        );
        $manager->persist($socialVariant1);

        // Social network template owned by USER_2 — used to verify cross-user scoping isolation.
        $socialTemplate2 = new SocialNetworkTemplate(
            Uuid::fromString(self::SOCIAL_NETWORK_TEMPLATE_2_ID),
            $project2,
            null,
            $date,
            'Insta Template 2 (other user)',
            null,
            0,
        );
        $manager->persist($socialTemplate2);

        $socialVariant2 = new SocialNetworkTemplateVariant(
            Uuid::fromString(self::SOCIAL_NETWORK_TEMPLATE_VARIANT_2_ID),
            $socialTemplate2,
            TemplateDimension::InstagramPost,
            'fixtures/bg-2.png',
            $date,
        );
        $socialVariant2->editCanvas(
            '{"version":"5.2.4","objects":[],"backgroundImage":null}',
            [new EditorTextInput(self::SOCIAL_NETWORK_VARIANT_2_INPUT_HEADLINE_ID, 'headline', null, false, false, null, false)],
            null,
        );
        $manager->persist($socialVariant2);

        // OAuth2 client (active, linked to user1) — used by /api/projects auth flow tests
        $activeClient = new OAuth2Client('test-client', self::OAUTH2_CLIENT_ID, self::OAUTH2_CLIENT_SECRET);
        $activeClient->setActive(true);
        $activeClient->setGrants(new Grant(OAuth2Grants::CLIENT_CREDENTIALS));
        $manager->persist($activeClient);

        $clientUserMapping = new OAuth2ClientUser(self::OAUTH2_CLIENT_ID, $user1);
        $manager->persist($clientUserMapping);

        // OAuth2 client (inactive, no user mapping) — used to verify revocation rejects token requests
        $inactiveClient = new OAuth2Client('test-inactive-client', self::OAUTH2_INACTIVE_CLIENT_ID, self::OAUTH2_INACTIVE_CLIENT_SECRET);
        $inactiveClient->setActive(false);
        $inactiveClient->setGrants(new Grant(OAuth2Grants::CLIENT_CREDENTIALS));
        $manager->persist($inactiveClient);

        $manager->flush();
    }
}
