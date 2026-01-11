<?php
declare(strict_types=1);

namespace WBoost\Web\Tests\DataFixtures;

use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Ramsey\Uuid\Uuid;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;
use WBoost\Web\Entity\WeeklyMenu;
use WBoost\Web\Entity\WeeklyMenuDay;
use WBoost\Web\Entity\WeeklyMenuMeal;
use WBoost\Web\Entity\WeeklyMenuMealVariant;
use WBoost\Web\Entity\WeeklyMenuMealVariantDietVersion;
use WBoost\Web\Value\ManualType;
use WBoost\Web\Value\WeeklyMenuMealType;

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

    // Weekly Menu fixtures
    public const string WEEKLY_MENU_1_ID = '00000000-0000-0000-0000-000000000010';
    public const string WEEKLY_MENU_DAY_1_ID = '00000000-0000-0000-0000-000000000011';
    public const string WEEKLY_MENU_MEAL_1_ID = '00000000-0000-0000-0000-000000000012';
    public const string WEEKLY_MENU_VARIANT_1_ID = '00000000-0000-0000-0000-000000000013';
    public const string WEEKLY_MENU_VARIANT_2_ID = '00000000-0000-0000-0000-000000000014';
    public const string WEEKLY_MENU_DIET_VERSION_1_ID = '00000000-0000-0000-0000-000000000015';
    public const string WEEKLY_MENU_DIET_VERSION_2_ID = '00000000-0000-0000-0000-000000000016';

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

        // Create Weekly Menu with full nested structure for testing
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

        // Create one day (Monday) with a meal that has 2 variants
        $day1 = new WeeklyMenuDay(
            Uuid::fromString(self::WEEKLY_MENU_DAY_1_ID),
            $weeklyMenu1,
            1, // Monday
        );
        $weeklyMenu1->addDay($day1);
        $manager->persist($day1);

        // Create a breakfast meal
        $meal1 = new WeeklyMenuMeal(
            Uuid::fromString(self::WEEKLY_MENU_MEAL_1_ID),
            $day1,
            WeeklyMenuMealType::Breakfast,
            0,
        );
        $day1->addMeal($meal1);
        $manager->persist($meal1);

        // Create first variant with one diet version
        $variant1 = new WeeklyMenuMealVariant(
            Uuid::fromString(self::WEEKLY_MENU_VARIANT_1_ID),
            $meal1,
            1,
            'Variant 1',
            0,
        );
        $meal1->addVariant($variant1);
        $manager->persist($variant1);

        $dietVersion1 = new WeeklyMenuMealVariantDietVersion(
            Uuid::fromString(self::WEEKLY_MENU_DIET_VERSION_1_ID),
            $variant1,
            'V',
            'Ovesná kaše s ovocem',
            0,
        );
        $variant1->addDietVersion($dietVersion1);
        $manager->persist($dietVersion1);

        // Create second variant with one diet version
        $variant2 = new WeeklyMenuMealVariant(
            Uuid::fromString(self::WEEKLY_MENU_VARIANT_2_ID),
            $meal1,
            2,
            'Variant 2',
            1,
        );
        $meal1->addVariant($variant2);
        $manager->persist($variant2);

        $dietVersion2 = new WeeklyMenuMealVariantDietVersion(
            Uuid::fromString(self::WEEKLY_MENU_DIET_VERSION_2_ID),
            $variant2,
            null,
            'Míchaná vejce se slaninou',
            0,
        );
        $variant2->addDietVersion($dietVersion2);
        $manager->persist($dietVersion2);

        $manager->flush();
    }
}
