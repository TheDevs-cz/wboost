<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * What uploading one variable font did: the faces it created and the ones
 * the family already had (kept untouched), plus the family's face count
 * afterwards. `message()` is the Czech line every upload surface shows.
 */
readonly final class VariableFontUpload
{
    /**
     * @param list<string> $created face names, in the font's instance order
     * @param list<string> $existing face names the family already had
     */
    public function __construct(
        public string $family,
        public array $created,
        public array $existing,
        public int $familyFaceCount,
    ) {
    }

    public function message(): string
    {
        $created = count($this->created);
        $existing = count($this->existing);

        if ($created === 0) {
            return sprintf('Variabilní písmo — %s už %s.', self::all($existing), $existing === 1 ? 'byl nahraný' : ($existing <= 4 ? 'jsou nahrané' : 'je nahraných'));
        }

        $message = 'Variabilní písmo — ' . self::createdPhrase($created);

        if ($existing > 0) {
            $message .= ', ' . match (true) {
                $existing === 1 => '1 už byl nahraný',
                $existing <= 4 => $existing . ' už byly nahrané',
                default => $existing . ' už bylo nahraných',
            };
        }

        return $message . '.';
    }

    private static function createdPhrase(int $count): string
    {
        return match (true) {
            $count === 1 => 'vytvořen 1 řez',
            $count <= 4 => sprintf('vytvořeny %d řezy', $count),
            default => sprintf('vytvořeno %d řezů', $count),
        };
    }

    private static function all(int $count): string
    {
        return match (true) {
            $count === 1 => 'jediný řez',
            $count <= 4 => sprintf('všechny %d řezy', $count),
            default => sprintf('všech %d řezů', $count),
        };
    }
}
