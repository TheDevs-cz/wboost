<?php

declare(strict_types=1);

namespace WBoost\Web\Value\ManualContent;

use WBoost\Web\Exceptions\InvalidManualContent;

readonly final class HeadingElement implements ContentElement
{
    public const string TYPE = 'heading';
    public const int MAX_LENGTH = 300;

    public function __construct(
        public string $id,
        /** 1–3 */
        public int $level,
        public string $text,
    ) {
    }

    /**
     * @param array<mixed> $data
     * @throws InvalidManualContent
     */
    public static function fromArray(array $data): self
    {
        $id = ContentElements::id($data);
        $level = $data['level'] ?? null;
        $text = $data['text'] ?? null;

        if (!is_int($level) || $level < 1 || $level > 3) {
            throw new InvalidManualContent('Heading level must be 1–3.');
        }

        if (!is_string($text)) {
            throw new InvalidManualContent('Heading text must be a string.');
        }

        $text = ContentElements::plainLine($text);

        if (mb_strlen($text) > self::MAX_LENGTH) {
            throw new InvalidManualContent('Heading text is too long.');
        }

        return new self($id, $level, $text);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function type(): string
    {
        return self::TYPE;
    }

    /**
     * @return array{id: string, type: string, level: int, text: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => self::TYPE,
            'level' => $this->level,
            'text' => $this->text,
        ];
    }
}
