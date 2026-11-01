<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * What kind of text value a shared text field carries — the richest editor
 * EVERY bound input accepts, so one value renders on every page:
 *
 * - `checklist`: every binding is a checklist component (a checklist only
 *   ever binds to checklists — {@see self::isCompatible()});
 * - `list`: every binding is rich text with lists;
 * - `rich`: every binding is rich text (some without lists → no lists);
 * - `plain`: anything else (one plain binding makes the field plain).
 */
enum DocumentTextMode: string
{
    case Plain = 'plain';
    case Rich = 'rich';
    case List = 'list';
    case Checklist = 'checklist';

    public static function ofInput(EditorTextInput $input): self
    {
        return match (true) {
            $input->checklist => self::Checklist,
            $input->richText && $input->lists => self::List,
            $input->richText => self::Rich,
            default => self::Plain,
        };
    }

    /**
     * @param list<EditorTextInput> $inputs every bound input
     */
    public static function common(array $inputs): self
    {
        if ($inputs === []) {
            return self::Plain;
        }

        $modes = array_map(self::ofInput(...), $inputs);

        if (in_array(self::Checklist, $modes, true)) {
            return count(array_unique(array_map(static fn (self $mode): string => $mode->value, $modes))) === 1 ? self::Checklist : self::Plain;
        }

        if (in_array(self::Plain, $modes, true)) {
            return self::Plain;
        }

        return in_array(self::Rich, $modes, true) ? self::Rich : self::List;
    }

    /**
     * A checklist holds items, not text: it shares only with checklists.
     *
     * @param list<EditorTextInput> $inputs
     */
    public static function isCompatible(array $inputs): bool
    {
        $checklists = count(array_filter($inputs, static fn (EditorTextInput $input): bool => $input->checklist));

        return $checklists === 0 || $checklists === count($inputs);
    }

    public function label(): string
    {
        return match ($this) {
            self::Plain => 'Prostý text',
            self::Rich => 'Formátovaný text',
            self::List => 'Formátovaný text se seznamy',
            self::Checklist => 'Zaškrtávací seznam',
        };
    }
}
