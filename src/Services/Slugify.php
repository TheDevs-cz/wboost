<?php

declare(strict_types=1);

namespace WBoost\Web\Services;

use Symfony\Component\String\Slugger\AsciiSlugger;

final class Slugify
{
    private static null|AsciiSlugger $slugger = null;

    public static function string(string $string): string
    {
        if (self::$slugger === null) {
            self::$slugger = new AsciiSlugger();
        }

        $slug = self::$slugger->slug($string);

        return strtolower((string) $slug);
    }
}
