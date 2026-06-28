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
use WBoost\Web\Entity\FileDirectory;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Entity\CustomTemplate;
use WBoost\Web\Entity\CustomTemplateVariant;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Entity\OAuth2ClientUser;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\RegistrationRequest;
use WBoost\Web\Entity\SocialNetworkTemplate;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Entity\User;
use WBoost\Web\Entity\WeeklyMenu;
use WBoost\Web\Entity\WeeklyMenuDay;
use WBoost\Web\Value\DimensionUnit;
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\EditorTextInput;
use WBoost\Web\Value\FileSource;
use WBoost\Web\Value\CustomTemplateDimension;
use WBoost\Web\Value\ManualType;
use WBoost\Web\Value\SharingLevel;
use WBoost\Web\Value\TemplateDimension;
use WBoost\Web\Value\WeeklyMenuApprovalStatus;

final class TestDataFixture extends Fixture
{
    public const string USER_1_ID = '00000000-0000-0000-0000-000000000001';
    public const string USER_1_EMAIL = 'user1@test.cz';

    public const string USER_2_ID = '00000000-0000-0000-0000-000000000002';
    public const string USER_2_EMAIL = 'user2@test.cz';

    // Admin account (confirmed, ROLE_ADMIN) — drives the /admin/* and all-projects tests.
    public const string ADMIN_USER_ID = '00000000-0000-0000-0000-0000000000a1';
    public const string ADMIN_USER_EMAIL = 'admin@test.cz';

    // Pending invitee: confirmed=false, password '' — drives UserChecker, set-password
    // (invitation copy) and re-invite tests.
    public const string INVITED_USER_ID = '00000000-0000-0000-0000-0000000000a2';
    public const string INVITED_USER_EMAIL = 'invited@test.cz';

    // Pending public signup request — drives the admin requests list + dismiss/convert.
    public const string REGISTRATION_REQUEST_PENDING_ID = '00000000-0000-0000-0000-0000000000b1';
    public const string REGISTRATION_REQUEST_PENDING_EMAIL = 'wantsaccess@test.cz';

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

    // Image placeholder fixtures (variant 1): a fully-adjustable + hidable "photo"
    // slot and a fully-locked "logo" slot, both drawing from the ALLOWED folder.
    public const string SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID = '00000000-0000-0000-0000-000000000045';
    public const string SOCIAL_NETWORK_VARIANT_1_IMAGE_LOCKED_ID = '00000000-0000-0000-0000-000000000046';

    // Gallery folders + files (PROJECT_1, ProjectImage source). The photo
    // slot may pull from ALLOWED only; OTHER is off-limits to the slot. The
    // ROOT file sits in no folder — reachable only by UNRESTRICTED slots.
    public const string FILE_DIRECTORY_ALLOWED_ID = '00000000-0000-0000-0000-000000000061';
    public const string FILE_DIRECTORY_OTHER_ID = '00000000-0000-0000-0000-000000000062';
    public const string FILE_IN_ALLOWED_ID = '00000000-0000-0000-0000-000000000071';
    public const string FILE_IN_OTHER_ID = '00000000-0000-0000-0000-000000000072';
    public const string FILE_IN_ROOT_ID = '00000000-0000-0000-0000-000000000073';

    // Custom template fixtures — mirror the social-network ones (same input mix,
    // same gallery folders) but with a free-form A4 mm dimension.
    public const string CUSTOM_TEMPLATE_1_ID = '00000000-0000-0000-0000-000000000080';
    public const string CUSTOM_TEMPLATE_VARIANT_1_ID = '00000000-0000-0000-0000-000000000081';
    public const string CUSTOM_TEMPLATE_2_ID = '00000000-0000-0000-0000-000000000082';
    public const string CUSTOM_TEMPLATE_VARIANT_2_ID = '00000000-0000-0000-0000-000000000083';

    public const string CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID = '00000000-0000-0000-0000-000000000091';
    public const string CUSTOM_TEMPLATE_VARIANT_1_INPUT_TAGLINE_ID = '00000000-0000-0000-0000-000000000092';
    public const string CUSTOM_TEMPLATE_VARIANT_1_INPUT_LOCKED_ID = '00000000-0000-0000-0000-000000000093';
    public const string CUSTOM_TEMPLATE_VARIANT_1_INPUT_BADGE_ID = '00000000-0000-0000-0000-000000000094';
    public const string CUSTOM_TEMPLATE_VARIANT_1_IMAGE_PHOTO_ID = '00000000-0000-0000-0000-000000000095';
    public const string CUSTOM_TEMPLATE_VARIANT_1_IMAGE_LOCKED_ID = '00000000-0000-0000-0000-000000000096';
    public const string CUSTOM_TEMPLATE_VARIANT_2_INPUT_HEADLINE_ID = '00000000-0000-0000-0000-000000000097';

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

        $admin = new User(
            Uuid::fromString(self::ADMIN_USER_ID),
            self::ADMIN_USER_EMAIL,
            $date,
            true,
        );
        $admin->changeRoles([User::ROLE_ADMIN]);
        $manager->persist($admin);

        // Pending invitee — never activated: confirmed=false, password stays ''.
        $invited = new User(
            Uuid::fromString(self::INVITED_USER_ID),
            self::INVITED_USER_EMAIL,
            $date,
            false,
        );
        $manager->persist($invited);

        // Pre-share PROJECT_1 (owned by user1) with the invited user — mirrors the
        // invite pre-share flow and drives the "shared with me" project list + the
        // admin shared-count overview. Recipient is the (otherwise unused) invitee so
        // existing user1<->user2 cross-access isolation tests stay valid.
        // Cascade-persisted via $project1.
        $project1->share($invited, SharingLevel::Read, $date, $admin);

        // A pending public registration request.
        $manager->persist(new RegistrationRequest(
            Uuid::fromString(self::REGISTRATION_REQUEST_PENDING_ID),
            self::REGISTRATION_REQUEST_PENDING_EMAIL,
            $date,
        ));

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

        // Gallery folders + files for image-placeholder tests (PROJECT_1).
        $dirAllowed = new FileDirectory(
            Uuid::fromString(self::FILE_DIRECTORY_ALLOWED_ID),
            $project1,
            FileSource::ProjectImage,
            'Photos',
            null,
            $date,
        );
        $manager->persist($dirAllowed);

        $dirOther = new FileDirectory(
            Uuid::fromString(self::FILE_DIRECTORY_OTHER_ID),
            $project1,
            FileSource::ProjectImage,
            'Other',
            null,
            $date,
        );
        $manager->persist($dirOther);

        $manager->persist(new FileUpload(
            Uuid::fromString(self::FILE_IN_ALLOWED_ID),
            $project1,
            $date,
            FileSource::ProjectImage,
            'fixtures/in-allowed.png',
            $dirAllowed,
        ));

        $manager->persist(new FileUpload(
            Uuid::fromString(self::FILE_IN_OTHER_ID),
            $project1,
            $date,
            FileSource::ProjectImage,
            'fixtures/in-other.png',
            $dirOther,
        ));

        $manager->persist(new FileUpload(
            Uuid::fromString(self::FILE_IN_ROOT_ID),
            $project1,
            $date,
            FileSource::ProjectImage,
            'fixtures/in-root.png',
            null,
        ));

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
        $variant1Canvas = json_encode([
            'version' => '5.2.4',
            'objects' => [
                [
                    'type' => 'Image',
                    'inputId' => self::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID,
                    'imagePlaceholder' => true,
                    'left' => 100, 'top' => 120, 'width' => 400, 'height' => 300,
                    'scaleX' => 1, 'scaleY' => 1, 'originX' => 'left', 'originY' => 'top',
                    'assetPath' => 'fixtures/standin-photo.png',
                ],
                [
                    'type' => 'Image',
                    'inputId' => self::SOCIAL_NETWORK_VARIANT_1_IMAGE_LOCKED_ID,
                    'imagePlaceholder' => true,
                    'left' => 0, 'top' => 0, 'width' => 200, 'height' => 200,
                    'scaleX' => 1, 'scaleY' => 1, 'originX' => 'left', 'originY' => 'top',
                ],
                // Textboxes in the inputs[] positional order (headline, tagline,
                // locked, badge): the i-th Textbox binds to inputs[i], so these
                // back the per-text-input `frame` geometry.
                [
                    'type' => 'Textbox',
                    'left' => 80, 'top' => 60, 'width' => 520, 'height' => 90,
                    'scaleX' => 1, 'scaleY' => 1, 'originX' => 'left', 'originY' => 'top',
                ],
                [
                    'type' => 'Textbox',
                    'left' => 80, 'top' => 200, 'width' => 520, 'height' => 60,
                    'scaleX' => 1, 'scaleY' => 1, 'originX' => 'left', 'originY' => 'top',
                ],
                [
                    'type' => 'Textbox',
                    'left' => 80, 'top' => 300, 'width' => 300, 'height' => 50,
                    'scaleX' => 1, 'scaleY' => 1, 'originX' => 'left', 'originY' => 'top',
                ],
                [
                    'type' => 'Textbox',
                    'left' => 700, 'top' => 60, 'width' => 200, 'height' => 60,
                    'scaleX' => 1, 'scaleY' => 1, 'originX' => 'left', 'originY' => 'top',
                ],
            ],
            'backgroundImage' => null,
        ], JSON_THROW_ON_ERROR);

        $socialVariant1->editCanvas(
            $variant1Canvas,
            [
                new EditorTextInput(self::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID, 'headline', 30, false, false, null, false),
                new EditorTextInput(self::SOCIAL_NETWORK_VARIANT_1_INPUT_TAGLINE_ID, 'tagline', null, false, true, null, false),
                new EditorTextInput(self::SOCIAL_NETWORK_VARIANT_1_INPUT_LOCKED_ID, null, null, true, false, null, false),
                new EditorTextInput(self::SOCIAL_NETWORK_VARIANT_1_INPUT_BADGE_ID, 'badge', null, false, false, null, true),
            ],
            null,
            [
                new EditorImageInput(self::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID, 'photo', 'Your photo', true, true, true, true, [self::FILE_DIRECTORY_ALLOWED_ID]),
                new EditorImageInput(self::SOCIAL_NETWORK_VARIANT_1_IMAGE_LOCKED_ID, 'logo', null, false, false, false, false, [self::FILE_DIRECTORY_ALLOWED_ID]),
            ],
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

        // Custom template (USER_1 / PROJECT_1) — same input mix as the social
        // variant, with a free-form A4 (210×297 mm @ 300 DPI) dimension.
        $customTemplate1 = new CustomTemplate(
            Uuid::fromString(self::CUSTOM_TEMPLATE_1_ID),
            $project1,
            null,
            $date,
            'Custom Template 1',
            null,
            0,
        );
        $manager->persist($customTemplate1);

        $customTemplateVariant1 = new CustomTemplateVariant(
            Uuid::fromString(self::CUSTOM_TEMPLATE_VARIANT_1_ID),
            $customTemplate1,
            new CustomTemplateDimension(DimensionUnit::Mm, 210, 297),
            'fixtures/custom-template-bg-1.png',
            $date,
        );
        $customTemplateVariant1Canvas = json_encode([
            'version' => '5.2.4',
            'objects' => [
                [
                    'type' => 'Image',
                    'inputId' => self::CUSTOM_TEMPLATE_VARIANT_1_IMAGE_PHOTO_ID,
                    'imagePlaceholder' => true,
                    'left' => 100, 'top' => 120, 'width' => 400, 'height' => 300,
                    'scaleX' => 1, 'scaleY' => 1, 'originX' => 'left', 'originY' => 'top',
                    'assetPath' => 'fixtures/standin-photo.png',
                ],
                [
                    'type' => 'Image',
                    'inputId' => self::CUSTOM_TEMPLATE_VARIANT_1_IMAGE_LOCKED_ID,
                    'imagePlaceholder' => true,
                    'left' => 0, 'top' => 0, 'width' => 200, 'height' => 200,
                    'scaleX' => 1, 'scaleY' => 1, 'originX' => 'left', 'originY' => 'top',
                ],
                // Textboxes in the inputs[] positional order (headline, tagline,
                // locked, badge): the i-th Textbox binds to inputs[i].
                [
                    'type' => 'Textbox',
                    'left' => 80, 'top' => 60, 'width' => 520, 'height' => 90,
                    'scaleX' => 1, 'scaleY' => 1, 'originX' => 'left', 'originY' => 'top',
                ],
                [
                    'type' => 'Textbox',
                    'left' => 80, 'top' => 200, 'width' => 520, 'height' => 60,
                    'scaleX' => 1, 'scaleY' => 1, 'originX' => 'left', 'originY' => 'top',
                ],
                [
                    'type' => 'Textbox',
                    'left' => 80, 'top' => 300, 'width' => 300, 'height' => 50,
                    'scaleX' => 1, 'scaleY' => 1, 'originX' => 'left', 'originY' => 'top',
                ],
                [
                    'type' => 'Textbox',
                    'left' => 700, 'top' => 60, 'width' => 200, 'height' => 60,
                    'scaleX' => 1, 'scaleY' => 1, 'originX' => 'left', 'originY' => 'top',
                ],
            ],
            'backgroundImage' => null,
        ], JSON_THROW_ON_ERROR);

        $customTemplateVariant1->editCanvas(
            $customTemplateVariant1Canvas,
            [
                new EditorTextInput(self::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID, 'headline', 30, false, false, null, false),
                new EditorTextInput(self::CUSTOM_TEMPLATE_VARIANT_1_INPUT_TAGLINE_ID, 'tagline', null, false, true, null, false),
                new EditorTextInput(self::CUSTOM_TEMPLATE_VARIANT_1_INPUT_LOCKED_ID, null, null, true, false, null, false),
                new EditorTextInput(self::CUSTOM_TEMPLATE_VARIANT_1_INPUT_BADGE_ID, 'badge', null, false, false, null, true),
            ],
            null,
            [
                new EditorImageInput(self::CUSTOM_TEMPLATE_VARIANT_1_IMAGE_PHOTO_ID, 'photo', 'Your photo', true, true, true, true, [self::FILE_DIRECTORY_ALLOWED_ID]),
                new EditorImageInput(self::CUSTOM_TEMPLATE_VARIANT_1_IMAGE_LOCKED_ID, 'logo', null, false, false, false, false, [self::FILE_DIRECTORY_ALLOWED_ID]),
            ],
        );
        $manager->persist($customTemplateVariant1);

        // Custom template owned by USER_2 — cross-user scoping isolation.
        $customTemplate2 = new CustomTemplate(
            Uuid::fromString(self::CUSTOM_TEMPLATE_2_ID),
            $project2,
            null,
            $date,
            'Custom Template 2 (other user)',
            null,
            0,
        );
        $manager->persist($customTemplate2);

        $customTemplateVariant2 = new CustomTemplateVariant(
            Uuid::fromString(self::CUSTOM_TEMPLATE_VARIANT_2_ID),
            $customTemplate2,
            new CustomTemplateDimension(DimensionUnit::Px, 800, 600),
            'fixtures/custom-template-bg-2.png',
            $date,
        );
        $customTemplateVariant2->editCanvas(
            '{"version":"5.2.4","objects":[],"backgroundImage":null}',
            [new EditorTextInput(self::CUSTOM_TEMPLATE_VARIANT_2_INPUT_HEADLINE_ID, 'headline', null, false, false, null, false)],
            null,
        );
        $manager->persist($customTemplateVariant2);

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
