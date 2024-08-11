<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\Font;
use WBoost\Web\Exceptions\FontNotFound;
use Doctrine\ORM\EntityManagerInterface;

readonly final class FontRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws FontNotFound
     */
    public function get(UuidInterface $fontId): Font
    {
        $font = $this->entityManager->find(Font::class, $fontId);

        if ($font instanceof Font) {
            return $font;
        }

        throw new FontNotFound();
    }

    public function add(Font $font): void
    {
        $this->entityManager->persist($font);
    }

    public function remove(Font $font): void
    {
        $this->entityManager->remove($font);
    }
}
