<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Font;

use FontLib\Font as FontParser;
use FontLib\Table\Type\name;
use FontLib\Table\Type\nameRecord;
use FontLib\TrueType\File as TrueTypeFile;
use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\Font;
use WBoost\Web\Exceptions\FontAlreadyHasFontFace;
use WBoost\Web\Exceptions\FontNotFound;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Exceptions\UnsupportedFontFile;
use WBoost\Web\Message\Font\AddFont;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Repository\FontRepository;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Value\FontFace;

/**
 * One uploaded font FILE → one face, filed under the family the file's own
 * name table declares (preferred family, else the plain family name), so a
 * batch of "Montserrat-Bold.ttf", "Montserrat-Italic.ttf" lands in one
 * "Montserrat" card without the designer naming anything.
 *
 * Only what php-font-lib can parse is accepted (TTF / OTF / WOFF): the face
 * weight and subfamily it reads are what the fill-page WYSIWYG's bold/italic
 * buttons and the MCP text measurement run on, so a file it cannot open
 * (WOFF2, a corrupt table) is refused with a message the upload UI shows
 * verbatim — never stored half-known.
 */
#[AsMessageHandler]
readonly final class AddFontHandler
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private ClockInterface $clock,
        private Filesystem $filesystem,
        private FontRepository $fontRepository,
        private GetFonts $getFonts,
        private ProvideIdentity $provideIdentity,
    ) {
    }

    /**
     * @throws ProjectNotFound
     * @throws FontAlreadyHasFontFace
     * @throws UnsupportedFontFile
     */
    public function __invoke(AddFont $message): void
    {
        $project = $this->projectRepository->get($message->projectId);
        $fontFile = $message->font;
        $now = $this->clock->now();

        $parsedFont = self::parse($fontFile);
        $stem = pathinfo($fontFile->getClientOriginalName(), PATHINFO_FILENAME);

        // Name-table reads are best-effort: a sparse table falls back to the
        // file name rather than refusing a font Chromium renders fine.
        $fontFaceName = self::nonEmpty($parsedFont->getFontFullName()) ?? self::nonEmpty($parsedFont->getFontName()) ?? $stem;

        /** @var array<int, nameRecord> $fontNameRecords */
        $fontNameRecords = $parsedFont->getData('name', 'records') ?? [];
        $fontName = self::nonEmpty(isset($fontNameRecords[name::NAME_PREFERRE_FAMILY]) ? (string) $fontNameRecords[name::NAME_PREFERRE_FAMILY] : null)
            ?? self::nonEmpty($parsedFont->getFontName())
            ?? $stem;

        $extension = strtolower($fontFile->getClientOriginalExtension());
        $fontFileName = $fontFaceName . '-' . $now->getTimestamp() . '.' . ($extension !== '' ? $extension : 'ttf');
        $path = "fonts/{$project->id}/$fontFileName";

        $fontFace = new FontFace(
            $fontFaceName,
            (int) ($parsedFont->getFontWeight() ?? 400),
            self::nonEmpty($parsedFont->getFontSubfamily()) ?? 'Regular',
            $path,
            $fontFile->getSize() !== false ? (int) $fontFile->getSize() : null,
        );

        try {
            $font = $this->getFonts->byName($message->projectId, $fontName);
            $font->addFontFace($fontFace);
        } catch (FontNotFound) {
            $font = new Font(
                $this->provideIdentity->next(),
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

    /**
     * @throws UnsupportedFontFile
     */
    private static function parse(UploadedFile $fontFile): TrueTypeFile
    {
        $fileName = $fontFile->getClientOriginalName();

        // php-font-lib dispatches on the magic number and knows nothing about
        // WOFF2 ("wOF2") — say so instead of a generic "unreadable".
        $header = (string) file_get_contents($fontFile->getPathname(), false, null, 0, 4);
        if ($header === 'wOF2') {
            throw UnsupportedFontFile::woff2($fileName);
        }

        try {
            $parsedFont = FontParser::load($fontFile->getPathname());
        } catch (\Throwable) {
            throw UnsupportedFontFile::unreadable($fileName);
        }

        if ($parsedFont === null) {
            throw UnsupportedFontFile::unreadable($fileName);
        }

        try {
            $parsedFont->parse();
        } catch (\Throwable) {
            throw UnsupportedFontFile::unreadable($fileName);
        }

        return $parsedFont;
    }

    private static function nonEmpty(mixed $value): null|string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
