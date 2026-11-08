<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Font;

use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Exceptions\FontAlreadyHasFontFace;
use WBoost\Web\Exceptions\FontNotFound;
use WBoost\Web\Exceptions\UnsupportedFontFile;
use WBoost\Web\Message\Font\AddFont;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Value\VariableFontUpload;

/**
 * Uploading a VARIABLE font = uploading each of its named instances as a
 * static cut, one by one: the file is expanded ({@see VariableFontExpander})
 * and every cut goes through the SAME `AddFont` dispatch a single static
 * upload does — so family filing, face naming, weight ordering, storage,
 * "already uploaded" dedupe and enabling the new cut in manuals that present
 * the font all behave exactly as for N separate uploads. Each cut is its own
 * dispatch (own transaction): a face the family already has is reported, the
 * rest still land. The variable file itself is NOT stored.
 *
 * Shared by the fonts page dropzone, the legacy add-face form and the MCP
 * `upload_font` tool.
 */
final readonly class AddVariableFontFaces
{
    public function __construct(
        private VariableFontExpander $expander,
        private MessageBusInterface $bus,
        private GetFonts $getFonts,
    ) {
    }

    public function isVariable(UploadedFile $file): bool
    {
        return $this->expander->isVariable($file->getPathname());
    }

    /**
     * @throws UnsupportedFontFile
     */
    public function __invoke(UuidInterface $projectId, UploadedFile $file): VariableFontUpload
    {
        $expanded = $this->expander->expand($file->getPathname(), $file->getClientOriginalName());

        $created = [];
        $existing = [];

        try {
            foreach ($expanded->faces as $face) {
                try {
                    $this->bus->dispatch(new AddFont(
                        $projectId,
                        new UploadedFile($face->path, $face->clientName, null, null, true),
                    ));
                    $created[] = $face->fullName;
                } catch (HandlerFailedException $exception) {
                    $previous = $exception->getPrevious();

                    if (!$previous instanceof FontAlreadyHasFontFace) {
                        throw $previous ?? $exception;
                    }

                    $existing[] = $face->fullName;
                }
            }
        } finally {
            $this->expander->discard($expanded);
        }

        return new VariableFontUpload(
            $expanded->family,
            $created,
            $existing,
            $this->familyFaceCount($projectId, $expanded->family),
        );
    }

    private function familyFaceCount(UuidInterface $projectId, string $family): int
    {
        try {
            return count($this->getFonts->byName($projectId, $family)->faces);
        } catch (FontNotFound) {
            return 0;
        }
    }
}
