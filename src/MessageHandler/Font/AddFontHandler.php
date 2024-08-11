<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Font;

use FontLib\Font as FontParser;
use FontLib\Table\Type\name;
use FontLib\Table\Type\nameRecord;
use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\Font;
use WBoost\Web\Exceptions\FontAlreadyHasFontFace;
use WBoost\Web\Exceptions\FontNotFound;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Message\Font\AddFont;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Repository\FontRepository;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Value\FontFace;

#[AsMessageHandler]
readonly final class AddFontHandler
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private ClockInterface $clock,
        private Filesystem $filesystem,
        private FontRepository $fontRepository,
        private GetFonts $getFonts,
    ) {
    }

    /**
     * @throws ProjectNotFound
     * @throws FontAlreadyHasFontFace
     */
    public function __invoke(AddFont $message): void
    {
        $project = $this->projectRepository->get($message->projectId);
        $fontFile = $message->font;
        $now = $this->clock->now();

        $parsedFont = FontParser::load($fontFile->getPathname());
        assert($parsedFont !== null);
        $parsedFont->parse();

        $fontWeight = $parsedFont->getFontWeight();
        assert($fontWeight !== null);

        $fontStyle = $parsedFont->getFontSubfamily();
        assert($fontStyle !== null);

        $fontFaceName = $parsedFont->getFontFullName();
        assert($fontFaceName !== null);

        /** @var array<int, nameRecord> $fontNameRecords */
        $fontNameRecords = $parsedFont->getData('name', 'records');
        $fontName = (string) ($fontNameRecords[name::NAME_PREFERRE_FAMILY] ?? $parsedFont->getFontName());

        $fontFileName = $parsedFont->getFontFullName() . '-' . $now->getTimestamp() . '.' . $fontFile->getClientOriginalExtension();
        $path = "fonts/{$project->id}/$fontFileName";

        $fontFace = new FontFace(
            $fontFaceName,
            (int) $fontWeight,
            $fontStyle,
            $path,
        );

        try {
            $font = $this->getFonts->byName($message->projectId, $fontName);
            $font->addFontFace($fontFace);
        } catch (FontNotFound) {
            $font = new Font(
                Uuid::uuid7(),
                $project,
                $now,
                $fontName,
                $fontFace,
            );

            $this->fontRepository->add($font);
        }

        // Stream is better because it is memory safe
        $stream = fopen($fontFile->getPathname(), 'rb');
        $this->filesystem->writeStream($path, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }
    }
}
