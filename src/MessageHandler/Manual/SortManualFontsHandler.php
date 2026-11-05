<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\ManualFontNotFound;
use WBoost\Web\Message\Manual\SortManualFonts;
use WBoost\Web\Repository\ManualFontRepository;

#[AsMessageHandler]
readonly final class SortManualFontsHandler
{
    public function __construct(
        private ManualFontRepository $manualFontRepository
    ) {
    }

    public function __invoke(SortManualFonts $message): void
    {
        // The voter only checked the manual in the URL, so an id that is
        // malformed, unknown or belongs to ANOTHER manual is ignored (F12
        // follow-up) — same skip-what-doesn't-fit stance as the category sort.
        foreach ($message->manualFonts as $position => $manualFontId) {
            if (!Uuid::isValid($manualFontId)) {
                continue;
            }

            try {
                $manualFont = $this->manualFontRepository->get(Uuid::fromString($manualFontId));
            } catch (ManualFontNotFound) {
                continue;
            }

            if (!$manualFont->manual->id->equals($message->manualId)) {
                continue;
            }

            $manualFont->sort($position);
        }
    }
}
