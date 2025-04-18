<?php

declare(strict_types=1);

namespace WBoost\Web\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\JsonType;
use WBoost\Web\Value\EmailTextInput;

/**
 * @phpstan-import-type EmailTextInputArray from EmailTextInput
 */
final class EmailTextInputsDoctrineType extends JsonType
{
    public const string NAME = 'email_text_input[]';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'JSONB';
    }

    /**
     * @return null|array<EmailTextInput>
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
            /** @var EmailTextInputArray $data */
            $inputs[] = EmailTextInput::fromArray($data);
        }

        return $inputs;
    }

    /**
     * @param null|array<EmailTextInput> $value
     * @throws ConversionException
     */
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        $data = [];

        foreach ($value as $input) {
            if (!is_a($input, EmailTextInput::class)) {
                throw InvalidType::new($value, self::NAME, [EmailTextInput::class]);
            }

            $data[] = $input->toArray();
        }

        return parent::convertToDatabaseValue($data, $platform);
    }
}
