<?php

declare(strict_types=1);

namespace WBoost\Web\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\JsonType;
use WBoost\Web\Value\MockupPageDownload;

/**
 * The per-slot files of a mockup page — a list positionally aligned with
 * ManualMockupPage::$images, so a slot without a file stores a null hole.
 */
final class MockupPageDownloadsDoctrineType extends JsonType
{
    public const string NAME = 'mockup_page_download[]';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'JSONB';
    }

    /**
     * @return null|array<int, null|MockupPageDownload>
     *
     * @throws ConversionException
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): null|array
    {
        if ($value === null) {
            return null;
        }

        $jsonData = parent::convertToPHPValue($value, $platform);

        if (!is_array($jsonData)) {
            return [];
        }

        $downloads = [];

        foreach (array_values($jsonData) as $entry) {
            if (!is_array($entry)) {
                $downloads[] = null;
                continue;
            }

            /** @var array<string, mixed> $entry */

            $downloads[] = MockupPageDownload::fromArray($entry);
        }

        return $downloads;
    }

    /**
     * @param null|array<int, null|MockupPageDownload> $value
     * @throws ConversionException
     */
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        $data = [];

        foreach ($value as $download) {
            $data[] = $download?->toArray();
        }

        return parent::convertToDatabaseValue($data, $platform);
    }
}
