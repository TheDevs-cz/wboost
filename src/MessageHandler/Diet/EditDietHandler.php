<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Diet;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\DietNotFound;
use WBoost\Web\Message\Diet\EditDiet;
use WBoost\Web\Repository\DietRepository;

#[AsMessageHandler]
readonly final class EditDietHandler
{
    public function __construct(
        private DietRepository $dietRepository,
    ) {
    }

    /**
     * @throws DietNotFound
     */
    public function __invoke(EditDiet $message): void
    {
        $diet = $this->dietRepository->get($message->dietId);
        $diet->edit($message->name, $message->codes);
    }
}
