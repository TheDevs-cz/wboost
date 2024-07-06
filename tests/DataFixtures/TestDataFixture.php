<?php
declare(strict_types=1);

namespace BrandManuals\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use BrandManuals\Web\Entity\Product;
use BrandManuals\Web\Entity\ProductVariant;
use BrandManuals\Web\Value\Currency;
use BrandManuals\Web\Value\Price;

final class TestDataFixture extends Fixture
{
    public function load(ObjectManager $manager): void
    {
    }
}
