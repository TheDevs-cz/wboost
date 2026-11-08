<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Tool;

use FontLib\Font as FontParser;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\Font;
use WBoost\Web\Entity\Project;
use WBoost\Web\Exceptions\FontAlreadyHasFontFace;
use WBoost\Web\Exceptions\FontNotFound;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Exceptions\UnsupportedFontFile;
use WBoost\Web\Mcp\Response\UploadFontResponse;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpToolScope;
use WBoost\Web\Message\Font\AddFont;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Services\Font\AddVariableFontFaces;
use WBoost\Web\Services\Security\ProjectVoter;
use WBoost\Web\Services\SocialNetwork\PlaceholderImageUploader;

/**
 * `upload_font` — add a font file to a project's fonts (2026-11-08), the MCP
 * twin of the fonts page dropzone: the same `AddFont` dispatch, the same
 * family filing by the file's own name table, the same "already uploaded"
 * dedupe — and a VARIABLE font is expanded into one face per named instance
 * ({@see AddVariableFontFaces}), exactly like the web upload.
 *
 * `templates:design`: fonts exist to be designed with, and a designing agent
 * that finds a cut missing (the case that motivated this tool: a variable
 * Inter registered as "Inter Regular" only) can fix it without leaving the
 * conversation. Additive only — nothing is replaced or deleted. Gate =
 * {@see ProjectVoter::EDIT}, the web upload's gate. base64 only, never a URL
 * (see {@see UploadImageTool} for why).
 */
#[McpToolScope(McpScope::TemplatesDesign)]
readonly final class UploadFontTool
{
    private const int MAX_FILE_SIZE_BYTES = PlaceholderImageUploader::MAX_FILE_SIZE_BYTES;

    public function __construct(
        private Security $security,
        private ProjectRepository $projectRepository,
        private MessageBusInterface $bus,
        private GetFonts $getFonts,
        private AddVariableFontFaces $addVariableFontFaces,
    ) {
    }

    /**
     * Adds a font file (TTF, OTF or WOFF — not WOFF2) to a project's fonts
     * and returns the font strings you can now use. Call get_context first:
     * its projects[].id is the projectId this tool takes.
     *
     * The file is filed under the family its own name table declares, so
     * several cuts of one typeface join one family. A VARIABLE font (one file
     * with weight / slant / width axes, e.g. "Inter-VariableFont_slnt,wght.ttf")
     * is expanded into one static face per named instance — Inter becomes
     * 18 faces, Thin…Black, each also in Italic — exactly as if every cut had
     * been uploaded on its own.
     *
     * Reply: created lists the faces this call added, alreadyPresent the
     * ones the family already had (left untouched, so re-sending a file is
     * harmless), and fonts every face of the family — all as the exact
     * strings a design element's font and input.allowedFonts take, e.g.
     * "Inter (Inter ExtraBold)".
     *
     * Send the bytes base64-encoded in fontBase64 — URLs are never fetched.
     * Keep the file under about 3 MB (one MCP request is capped at 4 MiB);
     * wboost's own limit is 10 MB. Nothing is ever replaced or deleted.
     *
     * @param string $projectId UUID of the project, as returned by get_context in projects[].id.
     * @param string $fontBase64 The font file's bytes, base64-encoded (a data: URI is accepted too). Not a URL.
     * @param string $filename The file name, e.g. "Inter-Bold.ttf" — a label and the fallback face name when the file names nothing.
     */
    #[McpTool(name: 'upload_font')]
    public function __invoke(string $projectId, string $fontBase64, string $filename): UploadFontResponse
    {
        $project = $this->project($projectId);
        $bytes = self::decode($fontBase64);

        if (strlen($bytes) > self::MAX_FILE_SIZE_BYTES) {
            throw new ToolCallException(sprintf(
                'That font file is %d bytes, over the %d-byte limit (10 MB) wboost applies to every upload.',
                strlen($bytes),
                self::MAX_FILE_SIZE_BYTES,
            ));
        }

        $filename = trim(basename($filename)) !== '' ? trim(basename($filename)) : 'font.ttf';
        $path = tempnam(sys_get_temp_dir(), 'wboost-mcp-font-');

        if ($path === false || file_put_contents($path, $bytes) === false) {
            throw new ToolCallException('The font could not be staged for upload on the server. Nothing was uploaded; try again.');
        }

        try {
            $file = new UploadedFile($path, $filename, null, null, true);

            if ($this->addVariableFontFaces->isVariable($file)) {
                $upload = ($this->addVariableFontFaces)($project->id, $file);
                $font = $this->font($project, $upload->family);

                return new UploadFontResponse(
                    family: $upload->family,
                    variable: true,
                    created: $font !== null ? array_map(static fn (string $face): string => sprintf('%s (%s)', $font->name, $face), $upload->created) : [],
                    alreadyPresent: $font !== null ? array_map(static fn (string $face): string => sprintf('%s (%s)', $font->name, $face), $upload->existing) : [],
                    fonts: self::fontStrings($font),
                    message: sprintf('Variable font: %d face(s) created, %d already present.', count($upload->created), count($upload->existing)),
                );
            }

            return $this->uploadStatic($project, $file, $path);
        } catch (UnsupportedFontFile $exception) {
            throw new ToolCallException('Nothing was uploaded: ' . $exception->getMessage());
        } finally {
            @unlink($path);
        }
    }

    /**
     * @throws UnsupportedFontFile
     */
    private function uploadStatic(Project $project, UploadedFile $file, string $path): UploadFontResponse
    {
        $before = $this->allFontStrings($project);

        try {
            $this->bus->dispatch(new AddFont($project->id, $file));
        } catch (HandlerFailedException $exception) {
            $previous = $exception->getPrevious();

            if ($previous instanceof UnsupportedFontFile) {
                throw $previous;
            }

            if (!$previous instanceof FontAlreadyHasFontFace) {
                throw $previous ?? $exception;
            }

            $label = self::fullName($path) ?? $file->getClientOriginalName();

            return new UploadFontResponse(
                family: null,
                variable: false,
                created: [],
                alreadyPresent: array_values(array_filter($before, static fn (string $font): bool => str_ends_with($font, '(' . $label . ')'))),
                fonts: [],
                message: sprintf('"%s" is already uploaded; nothing changed.', $label),
            );
        }

        $created = array_values(array_diff($this->allFontStrings($project), $before));
        $family = null;
        foreach ($this->getFonts->allForProject($project->id) as $font) {
            foreach ($font->faces as $face) {
                if (in_array($font->faceFamily($face), $created, true)) {
                    $family = $font;
                }
            }
        }

        return new UploadFontResponse(
            family: $family?->name,
            variable: false,
            created: $created,
            alreadyPresent: [],
            fonts: self::fontStrings($family),
            message: sprintf('%d face created.', count($created)),
        );
    }

    /**
     * @return list<string>
     */
    private function allFontStrings(Project $project): array
    {
        $strings = [];
        foreach ($this->getFonts->allForProject($project->id) as $font) {
            $strings = [...$strings, ...self::fontStrings($font)];
        }

        return $strings;
    }

    /**
     * @return list<string>
     */
    private static function fontStrings(null|Font $font): array
    {
        if ($font === null) {
            return [];
        }

        return array_values(array_map(static fn ($face): string => $font->faceFamily($face), $font->faces));
    }

    private function font(Project $project, string $family): null|Font
    {
        try {
            return $this->getFonts->byName($project->id, $family);
        } catch (FontNotFound) {
            return null;
        }
    }

    private static function fullName(string $path): null|string
    {
        try {
            $parsed = FontParser::load($path);
            $parsed?->parse();
            $name = $parsed?->getFontFullName();
        } catch (\Throwable) {
            return null;
        }

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    private function project(string $projectId): Project
    {
        $notFound = new ToolCallException(sprintf(
            'Project %s was not found, or this account cannot change its fonts. Call get_context for the projects it can reach.',
            $projectId,
        ));

        if (!Uuid::isValid($projectId)) {
            throw new ToolCallException(sprintf(
                '"%s" is not a valid project id. Project ids are UUIDs; call get_context to list the ones this account can reach.',
                $projectId,
            ));
        }

        try {
            $project = $this->projectRepository->get(Uuid::fromString($projectId));
        } catch (ProjectNotFound) {
            throw $notFound;
        }

        if (!$this->security->isGranted(ProjectVoter::EDIT, $project)) {
            throw $notFound;
        }

        return $project;
    }

    private static function decode(string $value): string
    {
        $payload = trim($value);

        if (stripos($payload, 'data:') === 0) {
            $comma = strpos($payload, ',');
            $payload = $comma === false ? '' : substr($payload, $comma + 1);
        }

        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $payload) === 1) {
            throw new ToolCallException('fontBase64 takes the font file\'s bytes, base64-encoded — not a URL. wboost never fetches a link a caller supplies; download the file yourself and send the bytes.');
        }

        $payload = (string) preg_replace('~\s+~', '', $payload);
        $remainder = strlen($payload) % 4;

        if ($remainder !== 0) {
            $payload .= str_repeat('=', 4 - $remainder);
        }

        $bytes = $payload !== '' ? base64_decode($payload, true) : false;

        if ($bytes === false || $bytes === '') {
            throw new ToolCallException('fontBase64 is not valid base64. Send the font file\'s raw bytes encoded as base64.');
        }

        return $bytes;
    }
}
