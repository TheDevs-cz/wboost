<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\DishType;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\DishTypeNotFound;
use WBoost\Web\Message\DishType\DeleteDishType;
use WBoost\Web\Repository\DishTypeRepository;

#[AsMessageHandler]
readonly final class DeleteDishTypeHandler
{
    public function __construct(
        private DishTypeRepository $dishTypeRepository,
    ) {
    }

    /**
     * @throws DishTypeNotFound
     */
    public function __invoke(DeleteDishType $message): void
    {
        $dishType = $this->dishTypeRepository->get($message->dishTypeId);
        $this->dishTypeRepository->remove($dishType);
    }
}
