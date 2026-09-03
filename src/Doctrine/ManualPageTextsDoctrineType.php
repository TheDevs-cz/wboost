<?php

declare(strict_types=1);

namespace WBoost\Web\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\JsonType;
use WBoost\Web\Value\ManualPage;
use WBoost\Web\Value\ManualPageText;

/**
 * Per-page text overrides of a manual, keyed by `ManualPage` value. Keys that
 * no longer name a page are dropped on read — a renamed page must not resurrect
 * as a phantom entry.
 */
final class ManualPageTextsDoctrineType extends JsonType
{
    public const string NAME = 'manual_page_text[]';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'JSONB';
    }

    /**
     * @return null|array<string, ManualPageText>
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

        $texts = [];

        foreach ($jsonData as $key => $entry) {
            if (!is_string($key) || !is_array($entry) || ManualPage::tryFrom($key) === null) {
                continue;
            }

            /** @var array<string, mixed> $entry */
            $text = ManualPageText::fromArray($entry);

            if ($text->isEmpty()) {
                continue;
            }

            $texts[$key] = $text;
        }

        return $texts;
    }

    /**
     * @param null|array<string, ManualPageText> $value
     * @throws ConversionException
     */
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        $data = [];

        foreach ($value as $key => $text) {
            if ($text->isEmpty()) {
                continue;
            }

            $data[$key] = $text->toArray();
        }

        return parent::convertToDatabaseValue($data, $platform);
    }
}
