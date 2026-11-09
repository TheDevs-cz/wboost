<?php

declare(strict_types=1);

namespace WBoost\Web\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\JsonType;
use WBoost\Web\Exceptions\InvalidManualContent;
use WBoost\Web\Value\ManualContent\ContentSegment;

/**
 * `manual_content_page.segments`: the page's layout segments with their
 * elements, decoded through the strict value objects. Every write goes
 * through the same decoding (the save handler), so a stored document always
 * decodes; one that does not is a hand edit and fails loudly rather than
 * silently losing content on the next save.
 */
final class ManualContentSegmentsDoctrineType extends JsonType
{
    public const string NAME = 'manual_content_segment[]';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'JSONB';
    }

    /**
     * @return null|list<ContentSegment>
     *
     * @throws ConversionException
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): null|array
    {
        if ($value === null) {
            return null;
        }

        $jsonData = parent::convertToPHPValue($value, $platform);

        try {
            return ContentSegment::listFromArray($jsonData);
        } catch (InvalidManualContent $exception) {
            throw new ConversionException('Invalid manual content segments: ' . $exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @param null|list<ContentSegment> $value
     * @throws ConversionException
     */
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        return parent::convertToDatabaseValue(ContentSegment::listToArray($value), $platform);
    }
}
