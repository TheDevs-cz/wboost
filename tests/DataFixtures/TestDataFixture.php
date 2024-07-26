<?php
declare(strict_types=1);

namespace WBoost\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use WBoost\Web\Entity\Product;
use WBoost\Web\Entity\ProductVariant;
use WBoost\Web\Value\Currency;
use WBoost\Web\Value\Price;

final class TestDataFixture extends Fixture
{
    public function load(ObjectManager $manager): void
    {
    }
}
