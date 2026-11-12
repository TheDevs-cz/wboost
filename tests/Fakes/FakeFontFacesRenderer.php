<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Fakes;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Exceptions\TemplateRenderUnavailable;
use WBoost\Web\Services\AiScan\Template\FontFacesRendererInterface;

/**
 * No Gotenberg in the suite: a 1 × 1 WebP and one cell per requested
 * `(face, line)`.
 */
final class FakeFontFacesRenderer implements FontFacesRendererInterface
{
    /** @var list<array{projectId: string, lines: list<string>, faces: null|list<string>, fontSize: int}> */
    public array $calls = [];

    public bool $unavailable = false;

    public function render(UuidInterface $projectId, array $lines, null|array $faces, int $fontSize): array
    {
        if ($this->unavailable) {
            throw TemplateRenderUnavailable::timedOut(new \RuntimeException('fake'));
        }

        $this->calls[] = ['projectId' => $projectId->toString(), 'lines' => $lines, 'faces' => $faces, 'fontSize' => $fontSize];

        $cells = [];
        $y = 0;

        foreach ($faces ?? ['Fake (Regular)'] as $face) {
            foreach (array_keys($lines) as $line) {
                $cells[] = ['face' => $face, 'line' => $line, 'x' => 0, 'y' => $y, 'width' => 100, 'height' => $fontSize * 2, 'fontSize' => $fontSize];
                $y += $fontSize * 2;
            }
        }

        return [
            'bytes' => (string) base64_decode('UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAwA0JaQAA3AA/vuUAAA=', true),
            'contentType' => 'image/webp',
            'width' => 100,
            'height' => $y,
            'cells' => $cells,
        ];
    }
}
