<?php

declare(strict_types=1);

namespace WBoost\Web\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\JsonType;
use WBoost\Web\Value\EditorTextInput;

final class EditorTextInputsDoctrineType extends JsonType
{
    public const string NAME = 'editor_text_input[]';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'JSONB';
    }

    /**
     * @return null|array<EditorTextInput>
     *
     * @throws ConversionException
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): null|array
    {
        if ($value === null) {
            return null;
        }

        $jsonData = parent::convertToPHPValue($value, $platform);
        assert(is_array($jsonData));

        $inputs = [];

        foreach ($jsonData as $data) {
            /** @var array{name: string, maxLength: null|int, locked: bool} $data */

            $inputs[] = EditorTextInput::fromArray($data);
        }

        return $inputs;
    }

    /**
     * @param null|array<EditorTextInput> $value
     * @throws ConversionException
     */
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        $data = [];

        foreach ($value as $input) {
            $data[] = $input->toArray();
        }

        return parent::convertToDatabaseValue($data, $platform);
    }
}
