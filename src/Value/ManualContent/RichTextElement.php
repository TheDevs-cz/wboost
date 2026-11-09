<?php

declare(strict_types=1);

namespace WBoost\Web\Value\ManualContent;

use WBoost\Web\Exceptions\InvalidManualContent;

readonly final class RichTextElement implements ContentElement
{
    public const string TYPE = 'richText';

    public function __construct(
        public string $id,
        public RichTextDoc $doc,
    ) {
    }

    /**
     * @param array<mixed> $data
     * @throws InvalidManualContent
     */
    public static function fromArray(array $data): self
    {
        return new self(ContentElements::id($data), RichTextDoc::fromArray($data['doc'] ?? null));
    }

    public function id(): string
    {
        return $this->id;
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function withDoc(RichTextDoc $doc): self
    {
        return new self($this->id, $doc);
    }

    /**
     * @return array{id: string, type: string, doc: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => self::TYPE,
            'doc' => $this->doc->toArray(),
        ];
    }
}
