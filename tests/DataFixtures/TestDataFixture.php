<?php
declare(strict_types=1);

namespace WBoost\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

final class TestDataFixture extends Fixture
{
    public function load(ObjectManager $manager): void
    {
    }
}
