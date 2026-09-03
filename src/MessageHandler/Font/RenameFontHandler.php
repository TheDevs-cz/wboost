<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Font;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\FontNameTaken;
use WBoost\Web\Exceptions\FontNotFound;
use WBoost\Web\Message\Font\RenameFont;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Repository\FontRepository;
use WBoost\Web\Services\Font\RewriteFontReferences;
use WBoost\Web\Value\FontFace;

/**
 * Renames a font family and rewrites every template / export reference that
 * carried the old name, in one transaction — see {@see RewriteFontReferences}.
 */
#[AsMessageHandler]
readonly final class RenameFontHandler
{
    public function __construct(
        private FontRepository $fontRepository,
        private GetFonts $getFonts,
        private RewriteFontReferences $rewriteFontReferences,
    ) {
    }

    /**
     * @throws FontNotFound
     * @throws FontNameTaken
     */
    public function __invoke(RenameFont $message): void
    {
        $font = $this->fontRepository->get($message->fontId);
        $name = trim($message->name);

        if ($name === '' || $name === $font->name) {
            return;
        }

        try {
            $existing = $this->getFonts->byName($font->project->id, $name);
            if (!$existing->id->equals($font->id)) {
                throw new FontNameTaken();
            }
        } catch (FontNotFound) {
            // Free — the rename may proceed.
        }

        $oldName = $font->name;
        $font->rename($name);

        $this->rewriteFontReferences->rename(
            $font->project->id,
            $oldName,
            $name,
            array_values(array_map(static fn (FontFace $face): string => $face->name, $font->faces)),
        );
    }
}
