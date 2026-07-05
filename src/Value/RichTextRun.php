<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * One styled segment of a rich-text fill value. `null` style properties mean
 * "inherit the designed object-level style of the textbox" — a run only ever
 * carries the deltas the user applied in the WYSIWYG.
 *
 * Bold/italic are NOT separate flags on purpose: font faces are standalone
 * families in this app ("Roboto (Roboto Bold)"), so emphasis is expressed by
 * switching `fontFamily` to another allowed face.
 */
readonly final class RichTextRun
{
    public function __construct(
        public string $text,
        public null|string $fontFamily,
        public null|string $color,
        public bool $underline,
    ) {
    }

    public function isStyled(): bool
    {
        return $this->fontFamily !== null || $this->color !== null || $this->underline;
    }

    public function hasSameStyle(self $other): bool
    {
        return $this->fontFamily === $other->fontFamily
            && $this->color === $other->color
            && $this->underline === $other->underline;
    }

    /**
     * @return array{text: string, fontFamily: null|string, color: null|string, underline: bool}
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'fontFamily' => $this->fontFamily,
            'color' => $this->color,
            'underline' => $this->underline,
        ];
    }
}
