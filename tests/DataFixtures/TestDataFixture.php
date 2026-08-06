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
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\TemplateCategory;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Entity\Font;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Entity\McpAccessToken;
use WBoost\Web\Entity\OAuth2ClientUser;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\RegistrationRequest;
use WBoost\Web\Entity\SocialAccount;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpTokenGenerator;
use WBoost\Web\Services\Security\TokenCrypto;
use WBoost\Web\Entity\TemplateGroup;
use WBoost\Web\Entity\User;
use WBoost\Web\Entity\WeeklyMenu;
use WBoost\Web\Entity\WeeklyMenuDay;
use WBoost\Web\Value\BackgroundMode;
use WBoost\Web\Value\Color;
use WBoost\Web\Value\DimensionUnit;
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\EditorTextInput;
use WBoost\Web\Value\FileSource;
use WBoost\Web\Value\FontFace;
use WBoost\Web\Value\TemplateDimension;
use WBoost\Web\Value\ManualColor;
use WBoost\Web\Value\ManualColorType;
use WBoost\Web\Value\ManualType;
use WBoost\Web\Value\SharingLevel;
use WBoost\Web\Value\SocialProvider;
use WBoost\Web\Value\DimensionPreset;
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

    // Confirmed, role-less account that OWNS nothing and has PROJECT_1 shared
    // with it — the "a project was shared with me" case, end to end.
    //
    // None of the accounts above can stand in for it: USER_1 owns PROJECT_1,
    // the admin sees every project by god-mode (so a shared project proves
    // nothing about sharing), and the invitee is unconfirmed, which the
    // firewall's UserChecker blocks before any tool runs.
    public const string SHARED_USER_ID = '00000000-0000-0000-0000-0000000000a3';
    public const string SHARED_USER_EMAIL = 'shared@test.cz';

    // Pending public signup request — drives the admin requests list + dismiss/convert.
    public const string REGISTRATION_REQUEST_PENDING_ID = '00000000-0000-0000-0000-0000000000b1';
    public const string REGISTRATION_REQUEST_PENDING_EMAIL = 'wantsaccess@test.cz';

    // Facebook social account (USER_1) — really-encrypted long-lived token;
    // USER_2 deliberately has none (exercises the not-connected paths).
    public const string SOCIAL_ACCOUNT_1_ID = '00000000-0000-0000-0000-0000000000d0';
    public const string SOCIAL_ACCOUNT_1_PROVIDER_USER_ID = 'fb-user-1';
    public const string SOCIAL_ACCOUNT_1_TOKEN = 'plaintext-long-lived-token-1';

    public const string PROJECT_1_ID = '00000000-0000-0000-0000-000000000001';
    public const string PROJECT_2_ID = '00000000-0000-0000-0000-000000000002';

    // Project 1 font ("Rubik", faces Regular + Bold) — the rich-text whitelist.
    public const string FONT_RUBIK_ID = '00000000-0000-0000-0000-0000000000f1';

    public const string MANUAL_1_ID = '00000000-0000-0000-0000-000000000001';
    public const string MANUAL_2_ID = '00000000-0000-0000-0000-000000000002';

    // OAuth2 fixtures (active client linked to USER_1, plus an inactive one)
    public const string OAUTH2_CLIENT_ID = 'testclientidaaaaaaaaaaaaaaaaaaaa';
    public const string OAUTH2_CLIENT_SECRET = 'testclientsecretbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    public const string OAUTH2_INACTIVE_CLIENT_ID = 'testinactiveclientcccccccccccccc';
    public const string OAUTH2_INACTIVE_CLIENT_SECRET = 'testinactivesecretdddddddddddddddddddddddddddddddddddddddddddddd';

    // MCP personal access tokens. The PLAINTEXT is a constant only because a
    // test has to present it in an `Authorization` header — the fixture stores
    // nothing but `McpTokenGenerator::hash()` of it, exactly like production.
    // Every state the authenticator must distinguish gets its own row.
    public const string MCP_TOKEN_ACTIVE_ID = '00000000-0000-0000-0000-0000000000e1';
    public const string MCP_TOKEN_ACTIVE = 'wb_mcp_test-active-token-user1';

    public const string MCP_TOKEN_REVOKED_ID = '00000000-0000-0000-0000-0000000000e2';
    public const string MCP_TOKEN_REVOKED = 'wb_mcp_test-revoked-token-user1';

    public const string MCP_TOKEN_EXPIRED_ID = '00000000-0000-0000-0000-0000000000e3';
    public const string MCP_TOKEN_EXPIRED = 'wb_mcp_test-expired-token-user1';

    // Belongs to the never-activated invitee — proves the firewall's UserChecker
    // blocks a structurally valid token whose user may not log in.
    public const string MCP_TOKEN_UNCONFIRMED_ID = '00000000-0000-0000-0000-0000000000e4';
    public const string MCP_TOKEN_UNCONFIRMED = 'wb_mcp_test-token-unconfirmed-user';

    // Narrow tokens for the tool gate (S1-T6). Both belong to the SAME user as
    // MCP_TOKEN_ACTIVE on purpose: whatever they cannot do is the SCOPE talking,
    // not the roles — effective permission = role ∩ scope, and these two isolate
    // the second half of that.
    public const string MCP_TOKEN_READ_ONLY_ID = '00000000-0000-0000-0000-0000000000e5';
    public const string MCP_TOKEN_READ_ONLY = 'wb_mcp_test-read-only-token-user1';

    // `templates:design` ONLY — no `templates:read` of its own, so every read
    // tool it reaches, it reaches through the implication closure.
    public const string MCP_TOKEN_DESIGN_ONLY_ID = '00000000-0000-0000-0000-0000000000e6';
    public const string MCP_TOKEN_DESIGN_ONLY = 'wb_mcp_test-design-only-token-user1';

    // Belongs to SHARED_USER — a read token whose whole project list is one
    // project somebody else owns.
    public const string MCP_TOKEN_SHARED_USER_ID = '00000000-0000-0000-0000-0000000000e7';
    public const string MCP_TOKEN_SHARED_USER = 'wb_mcp_test-token-shared-user';

    // Belongs to the ADMIN. Roles are not scopes, and the `mcp` firewall is
    // stateless (there is no session to log an admin into alongside a token), so
    // the god-mode read path needs a token of its own.
    public const string MCP_TOKEN_ADMIN_ID = '00000000-0000-0000-0000-0000000000e8';
    public const string MCP_TOKEN_ADMIN = 'wb_mcp_test-token-admin';

    // Weekly Menu fixtures
    public const string WEEKLY_MENU_1_ID = '00000000-0000-0000-0000-000000000010';
    public const string WEEKLY_MENU_DAY_1_ID = '00000000-0000-0000-0000-000000000011';

    // Weekly Menu with approval (pending)
    public const string WEEKLY_MENU_2_ID = '00000000-0000-0000-0000-000000000020';
    public const string WEEKLY_MENU_2_APPROVAL_HASH = 'abcdef1234567890abcdef1234567890abcdef1234567890abcdef1234567890';

    // Former social-network template fixtures — now UNIFIED Template rows
    // with a preset (1:1) dimension. The constant names survive to minimize
    // churn in the legacy-alias API contract tests.
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
    public const string SOCIAL_NETWORK_VARIANT_1_CONTAINER_ID = '00000000-0000-0000-0000-000000000047';

    // Gallery folders + files (PROJECT_1, ProjectImage source). The photo
    // slot may pull from ALLOWED only; OTHER is off-limits to the slot. The
    // ROOT file sits in no folder — reachable only by UNRESTRICTED slots.
    public const string FILE_DIRECTORY_ALLOWED_ID = '00000000-0000-0000-0000-000000000061';
    public const string FILE_DIRECTORY_OTHER_ID = '00000000-0000-0000-0000-000000000062';
    public const string FILE_IN_ALLOWED_ID = '00000000-0000-0000-0000-000000000071';
    public const string FILE_IN_OTHER_ID = '00000000-0000-0000-0000-000000000072';
    public const string FILE_IN_ROOT_ID = '00000000-0000-0000-0000-000000000073';

    // A NESTED folder (inside ALLOWED) — the gallery tree is only one level
    // deep without it, so nothing would tell a per-level listing apart from a
    // whole-tree one. Deliberately EMPTY: a folder holding no pictures is a
    // real state a browsing agent must handle.
    public const string FILE_DIRECTORY_NESTED_ID = '00000000-0000-0000-0000-000000000063';

    // A TRASHED image, in the shape the soft delete leaves behind: `deletedAt`
    // stamped, `directory` detached to NULL, the original folder remembered in
    // `restoreDirectory`. The detachment is why it must be asserted absent from
    // the gallery ROOT above all — a listing that filtered by folder alone
    // would show the entire bin there.
    public const string FILE_TRASHED_ID = '00000000-0000-0000-0000-000000000074';

    // PROJECT_2's only gallery folder. Exists so a folder id can be FOREIGN
    // while still being real: passing it alongside a project the caller CAN see
    // must fail exactly like an id that matches nothing.
    public const string FILE_DIRECTORY_PROJECT_2_ID = '00000000-0000-0000-0000-000000000064';

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

    // The ONLY filed category in the fixtures (PROJECT_1), holding
    // CUSTOM_TEMPLATE_1. Its name shares no word with any template name on
    // purpose: it is what proves a name-or-category search really consults the
    // category, and not just the template it happens to contain.
    public const string TEMPLATE_CATEGORY_1_ID = '00000000-0000-0000-0000-000000000098';
    public const string TEMPLATE_CATEGORY_1_NAME = 'Print materials';

    // Template group fixtures — one group (PROJECT_1) holding exactly ONE
    // template whose variants span a preset (1:1) and a free-form (A4 mm)
    // dimension. The two member variants share the same logical input id (the
    // join key group edits propagate by). The grouped template ALSO carries a
    // manually-added variant WITHOUT the group FK — it must never be
    // group-editable.
    public const string TEMPLATE_GROUP_1_ID = '00000000-0000-0000-0000-0000000000c0';
    public const string GROUPED_TEMPLATE_ID = '00000000-0000-0000-0000-0000000000c1';
    public const string GROUPED_PRESET_VARIANT_ID = '00000000-0000-0000-0000-0000000000c2';
    public const string GROUPED_FREEFORM_VARIANT_ID = '00000000-0000-0000-0000-0000000000c4';
    public const string UNGROUPED_VARIANT_ON_GROUPED_TEMPLATE_ID = '00000000-0000-0000-0000-0000000000c5';
    public const string GROUP_SHARED_INPUT_ID = '00000000-0000-0000-0000-0000000000c6';
    /** Image placeholder shared by both member variants — unrestricted slot (whole gallery + root). */
    public const string GROUP_SHARED_IMAGE_INPUT_ID = '00000000-0000-0000-0000-0000000000c7';

    // "Orientation Template" (PROJECT_1) — ONE variant carrying every input
    // FEATURE the older fixtures leave untouched, so a describing surface can
    // be asserted against real data instead of defaults: a rich input with
    // lists + checkbox lines, a dedicated checklist component with a non-default
    // capability, a per-input sampleValue, a design-HIDDEN textbox (present on
    // the canvas, absent from inputs[] — it must not consume a positional
    // binding slot), a fillable BACKGROUND layer and an UNRESTRICTED image slot
    // next to a restricted one. Layer background mode, 1:1 so canvas pixels and
    // designed coordinates are the same numbers.
    public const string ORIENTATION_TEMPLATE_ID = '00000000-0000-0000-0000-000000000101';
    public const string ORIENTATION_VARIANT_ID = '00000000-0000-0000-0000-000000000102';
    public const string ORIENTATION_INPUT_INTRO_ID = '00000000-0000-0000-0000-000000000103';
    public const string ORIENTATION_INPUT_BULLETS_ID = '00000000-0000-0000-0000-000000000104';
    public const string ORIENTATION_INPUT_CHECKLIST_ID = '00000000-0000-0000-0000-000000000105';
    /** Fillable background layer — restricted to the "Photos" folder. */
    public const string ORIENTATION_IMAGE_BACKGROUND_ID = '00000000-0000-0000-0000-000000000106';
    /** Ordinary image slot with an EMPTY allow-list = the whole gallery, root included. */
    public const string ORIENTATION_IMAGE_FREE_ID = '00000000-0000-0000-0000-000000000107';
    public const string ORIENTATION_ROOT_CONTAINER_ID = '00000000-0000-0000-0000-000000000108';
    public const string ORIENTATION_NESTED_CONTAINER_ID = '00000000-0000-0000-0000-000000000109';

    public function __construct(
        private readonly TokenCrypto $tokenCrypto,
    ) {
    }

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

        // The token is REALLY encrypted (test env key) — destination/publish
        // flows decrypt it before hitting the (faked) Graph API.
        $manager->persist(new SocialAccount(
            Uuid::fromString(self::SOCIAL_ACCOUNT_1_ID),
            $user1,
            SocialProvider::Facebook,
            self::SOCIAL_ACCOUNT_1_PROVIDER_USER_ID,
            $this->tokenCrypto->encrypt(self::SOCIAL_ACCOUNT_1_TOKEN),
            $date->modify('+60 days'),
            ['public_profile', 'email', 'pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'instagram_basic', 'instagram_content_publish'],
            'Test FB User',
            $date,
        ));

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
        // Brand colors — the swatch source for rich-text (WYSIWYG) inputs.
        $manual1->editColors(
            detectedColors: [],
            customColors: [
                new ManualColor(new Color('#C8102E'), ManualColorType::Primary, null, null),
                new ManualColor(new Color('#004E7C'), ManualColorType::Secondary, null, null),
            ],
        );
        $manager->persist($manual1);

        // Project font matching the family used by variant 1's headline textbox
        // ("Rubik (Rubik Bold)") — the rich-text whitelist expands it to BOTH
        // faces (canvas families → all their faces).
        $rubik = new Font(
            Uuid::fromString(self::FONT_RUBIK_ID),
            $project1,
            $date,
            'Rubik',
            new FontFace('Rubik Regular', 400, 'normal', 'fixtures/fonts/rubik-regular.ttf'),
        );
        $rubik->addFontFace(new FontFace('Rubik Bold', 700, 'normal', 'fixtures/fonts/rubik-bold.ttf'));
        $manager->persist($rubik);

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

        // Also share PROJECT_1 with the admin so the admin /projects list has a
        // "shared with me" project (admin owns nothing): PROJECT_1 ranks in the
        // shared tier while the un-shared PROJECT_2 falls to "others". PROJECT_1's
        // share-count is asserted nowhere; PROJECT_2 stays share-free for the
        // exact-count handler tests.
        $project1->share($admin, SharingLevel::Read, $date, $admin);

        // A confirmed, role-less recipient of the same share — the account that
        // proves "shared with me" reaches a normal user, without the admin's
        // god-mode or the invitee's blocked login muddying it.
        $sharedUser = new User(
            Uuid::fromString(self::SHARED_USER_ID),
            self::SHARED_USER_EMAIL,
            $date,
            true,
        );
        $manager->persist($sharedUser);

        $project1->share($sharedUser, SharingLevel::Read, $date, $admin);

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

        // Second level of the tree, inside "Photos" — without it every folder
        // in the fixtures is top-level and a one-level listing is
        // indistinguishable from a whole-tree one.
        $manager->persist(new FileDirectory(
            Uuid::fromString(self::FILE_DIRECTORY_NESTED_ID),
            $project1,
            FileSource::ProjectImage,
            'Logos',
            $dirAllowed,
            $date,
        ));

        $manager->persist(new FileUpload(
            Uuid::fromString(self::FILE_IN_ROOT_ID),
            $project1,
            $date,
            FileSource::ProjectImage,
            'fixtures/in-root.png',
            null,
        ));

        // In the Koš. Built through the real transition so the row carries the
        // exact shape production leaves behind (detached from "Photos",
        // `restoreDirectory` remembering it) rather than a hand-set flag.
        $trashed = new FileUpload(
            Uuid::fromString(self::FILE_TRASHED_ID),
            $project1,
            $date,
            FileSource::ProjectImage,
            'fixtures/in-trash.png',
            $dirAllowed,
        );
        $trashed->moveToTrash($date);
        $manager->persist($trashed);

        // PROJECT_2's gallery folder — a REAL folder that is nonetheless
        // foreign to PROJECT_1.
        $manager->persist(new FileDirectory(
            Uuid::fromString(self::FILE_DIRECTORY_PROJECT_2_ID),
            $project2,
            FileSource::ProjectImage,
            'Project 2 photos',
            null,
            $date,
        ));

        // Former social template (USER_1 / PROJECT_1), now a unified Template —
        // exercises non-locked named, uppercase, and locked-unnamed inputs plus
        // containers + rich text over a PRESET (1:1) dimension. Constructed
        // WITHOUT an explicit background mode → legacy canvas mode, mirroring
        // every pre-rework/merged row.
        $socialTemplate1 = new Template(
            Uuid::fromString(self::SOCIAL_NETWORK_TEMPLATE_1_ID),
            $project1,
            null,
            $date,
            'Insta Template 1',
            null,
            0,
        );
        $manager->persist($socialTemplate1);

        $socialVariant1 = new TemplateVariant(
            Uuid::fromString(self::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID),
            $socialTemplate1,
            TemplateDimension::fromPreset(DimensionPreset::InstagramPost),
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
                    'fontFamily' => 'Rubik (Rubik Bold)', 'fontSize' => 24, 'lineHeight' => 1.4, 'charSpacing' => 0,
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
            // Container ("smart text area") over headline + tagline: the two
            // reflow vertically at render time, bounded by 200 px from the
            // headline's designed top (y=60 → content must end by y=260).
            'containers' => [
                [
                    'id' => self::SOCIAL_NETWORK_VARIANT_1_CONTAINER_ID,
                    'maxHeight' => 200,
                    'memberInputIds' => [
                        self::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID,
                        self::SOCIAL_NETWORK_VARIANT_1_INPUT_TAGLINE_ID,
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $socialVariant1->editCanvas(
            $variant1Canvas,
            [
                new EditorTextInput(self::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID, 'headline', 30, false, false, null, false, richText: true),
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

        // Former social template owned by USER_2 (now unified) — used to verify
        // cross-user scoping isolation.
        $socialTemplate2 = new Template(
            Uuid::fromString(self::SOCIAL_NETWORK_TEMPLATE_2_ID),
            $project2,
            null,
            $date,
            'Insta Template 2 (other user)',
            null,
            0,
        );
        $manager->persist($socialTemplate2);

        $socialVariant2 = new TemplateVariant(
            Uuid::fromString(self::SOCIAL_NETWORK_TEMPLATE_VARIANT_2_ID),
            $socialTemplate2,
            TemplateDimension::fromPreset(DimensionPreset::InstagramPost),
            'fixtures/bg-2.png',
            $date,
        );
        $socialVariant2->editCanvas(
            '{"version":"5.2.4","objects":[],"backgroundImage":null}',
            [new EditorTextInput(self::SOCIAL_NETWORK_VARIANT_2_INPUT_HEADLINE_ID, 'headline', null, false, false, null, false)],
            null,
        );
        $manager->persist($socialVariant2);

        // The one filed category — every other fixture template sits
        // uncategorized, so both branches of "has a category" are covered.
        $templateCategory1 = new TemplateCategory(
            Uuid::fromString(self::TEMPLATE_CATEGORY_1_ID),
            $project1,
            $date,
            self::TEMPLATE_CATEGORY_1_NAME,
            0,
        );
        $manager->persist($templateCategory1);

        // Free-form template (USER_1 / PROJECT_1) — same input mix as the
        // preset variant above, with a free-form A4 (210×297 mm @ 300 DPI)
        // dimension.
        $template1 = new Template(
            Uuid::fromString(self::CUSTOM_TEMPLATE_1_ID),
            $project1,
            $templateCategory1,
            $date,
            'Custom Template 1',
            null,
            0,
        );
        $manager->persist($template1);

        $templateVariant1 = new TemplateVariant(
            Uuid::fromString(self::CUSTOM_TEMPLATE_VARIANT_1_ID),
            $template1,
            new TemplateDimension(DimensionUnit::Mm, 210, 297),
            'fixtures/custom-template-bg-1.png',
            $date,
        );
        $templateVariant1Canvas = json_encode([
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

        $templateVariant1->editCanvas(
            $templateVariant1Canvas,
            [
                new EditorTextInput(self::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID, 'headline', 30, false, false, null, false, richText: true),
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
        $manager->persist($templateVariant1);

        // Custom template owned by USER_2 — cross-user scoping isolation.
        $template2 = new Template(
            Uuid::fromString(self::CUSTOM_TEMPLATE_2_ID),
            $project2,
            null,
            $date,
            'Custom Template 2 (other user)',
            null,
            0,
        );
        $manager->persist($template2);

        $templateVariant2 = new TemplateVariant(
            Uuid::fromString(self::CUSTOM_TEMPLATE_VARIANT_2_ID),
            $template2,
            new TemplateDimension(DimensionUnit::Px, 800, 600),
            'fixtures/custom-template-bg-2.png',
            $date,
        );
        $templateVariant2->editCanvas(
            '{"version":"5.2.4","objects":[],"backgroundImage":null}',
            [new EditorTextInput(self::CUSTOM_TEMPLATE_VARIANT_2_INPUT_HEADLINE_ID, 'headline', null, false, false, null, false)],
            null,
        );
        $manager->persist($templateVariant2);

        // Template group (PROJECT_1): ONE template, variants spanning a preset
        // and a free-form dimension. Both member variants carry the SAME
        // textbox inputId — group edits join on it.
        $templateGroup1 = new TemplateGroup(
            Uuid::fromString(self::TEMPLATE_GROUP_1_ID),
            $project1,
            $date,
            'Group Campaign',
        );
        $manager->persist($templateGroup1);

        $groupSharedCanvas = json_encode([
            'version' => '5.2.4',
            'objects' => [
                [
                    'type' => 'Textbox',
                    'inputId' => self::GROUP_SHARED_INPUT_ID,
                    'left' => 80, 'top' => 60, 'width' => 520, 'height' => 90,
                    'scaleX' => 1, 'scaleY' => 1, 'originX' => 'left', 'originY' => 'top',
                ],
                [
                    'type' => 'Image',
                    'inputId' => self::GROUP_SHARED_IMAGE_INPUT_ID,
                    'imagePlaceholder' => true,
                    'left' => 80, 'top' => 200, 'width' => 400, 'height' => 300,
                    'scaleX' => 1, 'scaleY' => 1, 'originX' => 'left', 'originY' => 'top',
                ],
            ],
            'backgroundImage' => null,
        ], JSON_THROW_ON_ERROR);

        // Unrestricted slot (empty allow-list = whole gallery + root), so the
        // fill page must offer the "upload your own" field.
        $groupSharedImageInput = new EditorImageInput(
            self::GROUP_SHARED_IMAGE_INPUT_ID,
            'photo',
            null,
            allowMove: true,
            allowResize: true,
            allowRotate: false,
            hidable: true,
            allowedDirectoryIds: [],
        );

        $groupedTemplate = new Template(
            Uuid::fromString(self::GROUPED_TEMPLATE_ID),
            $project1,
            null,
            $date,
            'Group Campaign',
            null,
            1,
        );
        $groupedTemplate->assignToGroup($templateGroup1);
        $manager->persist($groupedTemplate);

        $groupedPresetVariant = new TemplateVariant(
            Uuid::fromString(self::GROUPED_PRESET_VARIANT_ID),
            $groupedTemplate,
            TemplateDimension::fromPreset(DimensionPreset::InstagramPost),
            'fixtures/bg-1.png',
            $date,
        );
        $groupedPresetVariant->editCanvas(
            $groupSharedCanvas,
            [new EditorTextInput(self::GROUP_SHARED_INPUT_ID, 'headline', null, false, false, null, false)],
            null,
            [$groupSharedImageInput],
        );
        $groupedPresetVariant->assignToGroup($templateGroup1);
        $manager->persist($groupedPresetVariant);

        // Manually-added variant on the grouped template — NO group FK.
        $ungroupedVariantOnGroupedTemplate = new TemplateVariant(
            Uuid::fromString(self::UNGROUPED_VARIANT_ON_GROUPED_TEMPLATE_ID),
            $groupedTemplate,
            TemplateDimension::fromPreset(DimensionPreset::InstagramStory),
            'fixtures/bg-1.png',
            $date,
        );
        $manager->persist($ungroupedVariantOnGroupedTemplate);

        $groupedFreeformVariant = new TemplateVariant(
            Uuid::fromString(self::GROUPED_FREEFORM_VARIANT_ID),
            $groupedTemplate,
            new TemplateDimension(DimensionUnit::Mm, 210, 297),
            'fixtures/custom-template-bg-1.png',
            $date,
        );
        $groupedFreeformVariant->editCanvas(
            $groupSharedCanvas,
            [new EditorTextInput(self::GROUP_SHARED_INPUT_ID, 'headline', null, false, false, null, false)],
            null,
            [$groupSharedImageInput],
        );
        $groupedFreeformVariant->assignToGroup($templateGroup1);
        $manager->persist($groupedFreeformVariant);

        // "Orientation Template" (PROJECT_1) — the feature-complete variant the
        // describing surfaces are asserted against. Everything here exists
        // because it is a DIFFERENT branch of some publishing rule; see the
        // constants block for the inventory.
        $orientationTemplate = new Template(
            Uuid::fromString(self::ORIENTATION_TEMPLATE_ID),
            $project1,
            null,
            $date,
            'Orientation Template',
            null,
            0,
        );
        $manager->persist($orientationTemplate);

        $orientationVariant = new TemplateVariant(
            Uuid::fromString(self::ORIENTATION_VARIANT_ID),
            $orientationTemplate,
            TemplateDimension::fromPreset(DimensionPreset::InstagramPost),
            // Layer mode keeps this column as a denormalized pointer to the
            // background LAYER's asset, not to a canvas-level background.
            'fixtures/orientation-bg.png',
            $date,
            BackgroundMode::Layer,
        );

        $orientationCanvas = json_encode([
            'version' => '5.2.4',
            'objects' => [
                // 0 — the background layer, marked fillable by the designer.
                [
                    'type' => 'Image',
                    'inputId' => self::ORIENTATION_IMAGE_BACKGROUND_ID,
                    'imagePlaceholder' => true,
                    'isBackground' => true,
                    // Cover-fit anchored top-left; the designed box deliberately
                    // OVERFLOWS the 1080×1080 canvas, which is why a background
                    // slot must publish the canvas rect instead of this one.
                    'left' => 0, 'top' => 0, 'width' => 1200, 'height' => 1100,
                    'scaleX' => 1, 'scaleY' => 1, 'originX' => 'left', 'originY' => 'top',
                    'assetPath' => 'fixtures/orientation-bg.png',
                ],
                // 1 — inputs[0]: plain text with a designer-authored default.
                [
                    'type' => 'Textbox',
                    'left' => 80, 'top' => 100, 'width' => 520, 'height' => 80,
                    'scaleX' => 1, 'scaleY' => 1, 'originX' => 'left', 'originY' => 'top',
                    'fontFamily' => 'Rubik (Rubik Regular)', 'fontSize' => 32, 'lineHeight' => 1.2,
                ],
                // 2 — DESIGN-HIDDEN (the editor's per-layer eye): it is NOT in
                // inputs[], so it must not consume a positional binding slot.
                // Every frame after this one is wrong if it ever does.
                [
                    'type' => 'Textbox',
                    'visible' => false,
                    'left' => 80, 'top' => 900, 'width' => 400, 'height' => 40,
                    'scaleX' => 1, 'scaleY' => 1, 'originX' => 'left', 'originY' => 'top',
                ],
                // 3 — inputs[1]: WYSIWYG with lists + checkbox lines.
                [
                    'type' => 'Textbox',
                    'left' => 80, 'top' => 220, 'width' => 520, 'height' => 160,
                    'scaleX' => 1, 'scaleY' => 1, 'originX' => 'left', 'originY' => 'top',
                    'fontFamily' => 'Rubik (Rubik Regular)', 'fontSize' => 24,
                ],
                // 4 — inputs[2]: the checklist component.
                [
                    'type' => 'Textbox',
                    'left' => 80, 'top' => 420, 'width' => 520, 'height' => 200,
                    'scaleX' => 1, 'scaleY' => 1, 'originX' => 'left', 'originY' => 'top',
                    'fontFamily' => 'Rubik (Rubik Regular)', 'fontSize' => 24,
                ],
                // 5 — the unrestricted image slot.
                [
                    'type' => 'Image',
                    'inputId' => self::ORIENTATION_IMAGE_FREE_ID,
                    'imagePlaceholder' => true,
                    'left' => 620, 'top' => 220, 'width' => 360, 'height' => 360,
                    'scaleX' => 1, 'scaleY' => 1, 'originX' => 'left', 'originY' => 'top',
                ],
            ],
            'backgroundImage' => null,
            // A NESTED pair: the root flows the intro and, as one item, the
            // child holding the two list inputs. Only the root bounds anything;
            // the child grows with its content.
            'containers' => [
                [
                    'id' => self::ORIENTATION_ROOT_CONTAINER_ID,
                    'maxHeight' => 700,
                    'memberInputIds' => [self::ORIENTATION_INPUT_INTRO_ID],
                    'memberContainerIds' => [self::ORIENTATION_NESTED_CONTAINER_ID],
                    'spaceAfter' => 40,
                ],
                [
                    'id' => self::ORIENTATION_NESTED_CONTAINER_ID,
                    'maxHeight' => 400,
                    'memberInputIds' => [
                        self::ORIENTATION_INPUT_BULLETS_ID,
                        self::ORIENTATION_INPUT_CHECKLIST_ID,
                    ],
                    'gap' => 24,
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $orientationVariant->editCanvas(
            $orientationCanvas,
            [
                new EditorTextInput(
                    self::ORIENTATION_INPUT_INTRO_ID,
                    'intro',
                    120,
                    false,
                    false,
                    'Lead paragraph, one or two sentences',
                    false,
                    sampleValue: 'Welcome to the show',
                ),
                new EditorTextInput(
                    self::ORIENTATION_INPUT_BULLETS_ID,
                    'bullets',
                    null,
                    false,
                    false,
                    null,
                    true,
                    richText: true,
                    lists: true,
                    listCheckboxes: true,
                ),
                // The checklist component: richText + lists + listCheckboxes are
                // forced on by the editor, and one capability is off so a
                // surface reporting the four flags cannot pass by defaulting.
                new EditorTextInput(
                    self::ORIENTATION_INPUT_CHECKLIST_ID,
                    'tasks',
                    null,
                    false,
                    false,
                    null,
                    false,
                    richText: true,
                    lists: true,
                    listCheckboxes: true,
                    checklist: true,
                    checklistAdd: false,
                    sampleValue: '{"runs":[{"text":"First task\nSecond task"}],"lines":["cb","cbx"]}',
                ),
            ],
            null,
            [
                new EditorImageInput(
                    self::ORIENTATION_IMAGE_BACKGROUND_ID,
                    'background',
                    'Full-bleed photo',
                    allowMove: false,
                    allowResize: false,
                    allowRotate: false,
                    hidable: true,
                    allowedDirectoryIds: [self::FILE_DIRECTORY_ALLOWED_ID],
                    isBackground: true,
                ),
                new EditorImageInput(
                    self::ORIENTATION_IMAGE_FREE_ID,
                    'photo',
                    null,
                    allowMove: true,
                    allowResize: true,
                    allowRotate: true,
                    hidable: false,
                    // Empty = UNRESTRICTED: every project folder plus the root.
                    allowedDirectoryIds: [],
                ),
            ],
        );
        $manager->persist($orientationVariant);

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

        // MCP access tokens — hashed through the production generator so the
        // fixture can never drift from the format the authenticator expects.
        $mcpTokens = new McpTokenGenerator();

        $manager->persist(new McpAccessToken(
            Uuid::fromString(self::MCP_TOKEN_ACTIVE_ID),
            $user1,
            'Test agent',
            array_map(static fn (McpScope $scope): string => $scope->value, McpScope::cases()),
            $mcpTokens->hash(self::MCP_TOKEN_ACTIVE),
            $date,
        ));

        $revoked = new McpAccessToken(
            Uuid::fromString(self::MCP_TOKEN_REVOKED_ID),
            $user1,
            'Revoked agent',
            [McpScope::TemplatesRead->value],
            $mcpTokens->hash(self::MCP_TOKEN_REVOKED),
            $date,
        );
        $revoked->revoke($date->modify('+1 hour'));
        $manager->persist($revoked);

        // expiresAt is in 2024 — always in the past by the time a test runs.
        $manager->persist(new McpAccessToken(
            Uuid::fromString(self::MCP_TOKEN_EXPIRED_ID),
            $user1,
            'Expired agent',
            [McpScope::TemplatesRead->value],
            $mcpTokens->hash(self::MCP_TOKEN_EXPIRED),
            $date,
            $date->modify('+1 day'),
        ));

        $manager->persist(new McpAccessToken(
            Uuid::fromString(self::MCP_TOKEN_UNCONFIRMED_ID),
            $invited,
            'Agent of a never-activated account',
            [McpScope::TemplatesRead->value],
            $mcpTokens->hash(self::MCP_TOKEN_UNCONFIRMED),
            $date,
        ));

        $manager->persist(new McpAccessToken(
            Uuid::fromString(self::MCP_TOKEN_READ_ONLY_ID),
            $user1,
            'Read-only agent',
            [McpScope::TemplatesRead->value],
            $mcpTokens->hash(self::MCP_TOKEN_READ_ONLY),
            $date,
        ));

        $manager->persist(new McpAccessToken(
            Uuid::fromString(self::MCP_TOKEN_DESIGN_ONLY_ID),
            $user1,
            'Design-only agent',
            [McpScope::TemplatesDesign->value],
            $mcpTokens->hash(self::MCP_TOKEN_DESIGN_ONLY),
            $date,
        ));

        $manager->persist(new McpAccessToken(
            Uuid::fromString(self::MCP_TOKEN_SHARED_USER_ID),
            $sharedUser,
            'Agent of a share recipient',
            [McpScope::TemplatesRead->value],
            $mcpTokens->hash(self::MCP_TOKEN_SHARED_USER),
            $date,
        ));

        $manager->persist(new McpAccessToken(
            Uuid::fromString(self::MCP_TOKEN_ADMIN_ID),
            $admin,
            'Admin agent',
            [McpScope::TemplatesRead->value],
            $mcpTokens->hash(self::MCP_TOKEN_ADMIN),
            $date,
        ));

        $manager->flush();
    }
}
