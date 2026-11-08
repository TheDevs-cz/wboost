<?php

declare(strict_types=1);

namespace WBoost\Web\Services;

use League\Flysystem\Filesystem;
use Symfony\Contracts\Service\ResetInterface;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Value\RgbaColor;

final class SvgColorsMapper implements ResetInterface
{
    private const string HEX_TARGET = '/^#?[0-9a-f]{3,8}$/i';

    /**
     * @var array<string, string>
     */
    private array $images = [];

    public function __construct(
        readonly private Filesystem $filesystem,
    ) {
    }

    /**
     * Recolours the logo. A target is either
     * - a HEX (`FFFFFF`) — the legacy replacement: the FROM literal is swapped
     *   wherever it appears, the SVG's own `#` stays in front of it;
     * - an `rgba()` (a translucent target, {@see \WBoost\Web\Entity\Manual::logoColorMapping()})
     *   — replaces the WHOLE colour literal, `#` included, so a 6-digit key
     *   never eats the start of a longer literal.
     * Targets that are neither are ignored: the download route takes the map
     * from the query string, and whatever passes here lands inside the SVG.
     *
     * Two passes through placeholders, so `A → B, B → C` swaps instead of
     * chaining A into C.
     *
     * @param array<string, string> $replacementMap
     */
    public function map(string $image, array $replacementMap): string
    {
        $svgContent = $this->getSvgContent($image);

        $placeholders = [];
        $index = 0;

        foreach ($replacementMap as $from => $to) {
            $from = (string) $from;
            $placeholder = '__TMPCOLOR_' . $index++ . '__';

            if ($from === '') {
                continue;
            }

            if (preg_match(self::HEX_TARGET, $to) === 1) {
                $svgContent = str_ireplace($from, $placeholder, $svgContent);
            } elseif (RgbaColor::isCss($to)) {
                $literal = preg_match('/^#?[0-9a-f]{3,8}$/i', $from) === 1
                    ? '#' . preg_quote(ltrim($from, '#'), '/') . '(?![0-9a-f])'
                    : preg_quote($from, '/');

                $svgContent = preg_replace('/' . $literal . '/i', $placeholder, $svgContent) ?? $svgContent;
            } else {
                continue;
            }

            $placeholders[$placeholder] = $to;
        }

        return strtr($svgContent, $placeholders);
    }

    /**
     * @param array<string, string> $replacementMap
     */
    public function mapToDataUri(string $image, array $replacementMap): string
    {
        $svgContent = $this->map($image, $replacementMap);

        return 'data:image/svg+xml;base64,' . base64_encode($svgContent);
    }

    private function getSvgContent(string $filePath): string
    {
        if (!isset($this->images[$filePath])) {
            $svgContent = $this->filesystem->read($filePath);

            $this->images[$filePath] = $svgContent;
        }

        return $this->images[$filePath];
    }

    public function reset(): void
    {
        $this->images = [];
    }
}
