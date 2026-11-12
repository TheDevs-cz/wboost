<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan\Template;

use Ramsey\Uuid\UuidInterface;

/**
 * Renders the same lines of text in several of a project's font faces as ONE
 * picture — the app half of font matching: the app renders (it has the font
 * files and the same Chromium that renders templates), the scanner measures
 * and ranks.
 */
interface FontFacesRendererInterface
{
    public const int MAX_LINES = 8;
    public const int MAX_LINE_LENGTH = 200;
    public const int MAX_FACES = 60;
    public const int DEFAULT_SIZE = 64;
    public const int MIN_SIZE = 8;
    public const int MAX_SIZE = 400;

    /**
     * @param list<string> $lines
     * @param null|list<string> $faces face family strings (`"Rubik (Bold)"`); null = every face of the project
     * @return array{bytes: string, contentType: string, width: int, height: int, cells: list<array{face: string, line: int, x: int, y: int, width: int, height: int, fontSize: int}>}
     *
     * @throws \WBoost\Web\Exceptions\TemplateRenderUnavailable when the renderer is overloaded / unreachable
     */
    public function render(UuidInterface $projectId, array $lines, null|array $faces, int $fontSize): array;
}
