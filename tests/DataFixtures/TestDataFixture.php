<?php
declare(strict_types=1);

namespace WBoost\Web\Tests\DataFixtures;

use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Ramsey\Uuid\Uuid;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Entity\User;

final class TestDataFixture extends Fixture
{
    public const string USER_1_ID = '00000000-0000-0000-0000-000000000001';
    public const string USER_1_EMAIL = 'user1@test.cz';

    public const string USER_2_ID = '00000000-0000-0000-0000-000000000002';
    public const string USER_2_EMAIL = 'user2@test.cz';

    public const string PROJECT_1_ID = '00000000-0000-0000-0000-000000000001';
    public const string PROJECT_2_ID = '00000000-0000-0000-0000-000000000002';

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

        $project1 = new Manual(
            Uuid::fromString(self::PROJECT_1_ID),
            $user1,
            'Project 1',
            $date,
        );
        $manager->persist($project1);

        $user2 = new User(
            Uuid::fromString(self::USER_2_ID),
            self::USER_2_EMAIL,
            $date,
            true,
        );
        $manager->persist($user2);

        $project2 = new Manual(
            Uuid::fromString(self::PROJECT_2_ID),
            $user2,
            'Project 2',
            $date,
        );
        $manager->persist($project2);

        $manager->flush();
    }
}
