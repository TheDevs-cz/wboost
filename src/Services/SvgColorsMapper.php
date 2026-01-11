<?php

declare(strict_types=1);

namespace WBoost\Web\Services;

use League\Flysystem\Filesystem;
use Symfony\Contracts\Service\ResetInterface;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Exceptions\InvalidColorMapping;

final class SvgColorsMapper implements ResetInterface
{
    /**
     * @var array<string, string>
     */
    private array $images = [];

    public function __construct(
        readonly private Filesystem $filesystem,
    ) {
    }

    /**
     * @param array<string, string> $replacementMap
     */
    public function map(string $image, array $replacementMap): string
    {
        $svgContent = $this->getSvgContent($image);

        if ($replacementMap !== []) {
            $mapFrom = array_keys($replacementMap);
            $mapTo = array_values($replacementMap);
            $placeholders = [];

            foreach ($mapTo as $key => $_) {
                $placeholders[$key] = "__TMPCOLOR_{$key}__";
            }

            $svgContent = str_ireplace($mapFrom, $placeholders, $svgContent);
            $svgContent = str_ireplace($placeholders, $mapTo, $svgContent);
        }

        return $svgContent;
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
