<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Calendar;

use WBoost\Web\Value\Calendar\CalendarExportVariant;

/**
 * Turns an applied content operation into the Czech history sentence
 * (docs/calendar/architecture.md §C.6; style: past tense, masculine, no full
 * stop). The verb comes from what really changed, the names from the stored
 * items; only the trailing label (page title, date) is the client's text.
 */
readonly final class DescribeCalendarChange
{
    public const string DESIGN_CHANGED = 'Upravil vzhled a nastavení';
    public const string CREATED = 'Vytvořil kalendář';
    public const string FINISHED_FIRST = 'Dokončil kalendář';
    public const string FINISHED_AGAIN = 'Uložil vzhled kalendáře';
    public const string LINK_COPIED = 'Zkopíroval odkaz pro klienta';
    public const string LINK_REGENERATED = 'Vygeneroval nový odkaz pro klienta';
    public const string LAYOUT_FROM_FILE = 'Načetl layout ze souboru';
    public const string MOON_CHANGED = 'Upravil fáze měsíce';
    public const string SPECIAL_PAGES_CHANGED = 'Upravil speciální stránky';
    public const string EXPORTED_PRINT = 'Stáhl PDF pro tisk';
    public const string EXPORTED_APPROVAL = 'Stáhl PDF k odsouhlasení';

    private const int COMMENT_TEXT_LENGTH = 160;

    /** Sentences written at most once per save, however many items they cover. */
    public static function isCoalesced(string $sentence): bool
    {
        return $sentence === self::MOON_CHANGED || $sentence === self::SPECIAL_PAGES_CHANGED;
    }

    public static function exported(CalendarExportVariant $variant): string
    {
        return match ($variant) {
            CalendarExportVariant::Print => self::EXPORTED_PRINT,
            CalendarExportVariant::Approval => self::EXPORTED_APPROVAL,
        };
    }

    public static function layoutFromCalendar(string $name): string
    {
        return sprintf('Načetl layout z „%s“', $name);
    }

    public static function andMore(int $count): string
    {
        return sprintf('… a dalších %d změn', $count);
    }

    /**
     * @param null|array<mixed> $old the item before (null = it did not exist)
     * @param null|array<mixed> $new the item after (null = removed)
     * @param array<mixed> $content the calendar content (for names of referenced items)
     * @return null|string null = this change is not worth a history line
     */
    public function describe(string $collection, null|array $old, null|array $new, string $label, array $content): null|string
    {
        $isNew = $old === null;
        $removed = $new === null;
        $name = self::quoted($removed ? $old : $new);

        return match ($collection) {
            'pagePhotos' => self::labelled(match (true) {
                $removed => 'Odebral fotku',
                $isNew => 'Nahrál fotku',
                ($old['asset'] ?? null) !== ($new['asset'] ?? null) => 'Vyměnil fotku',
                default => 'Posunul fotku',
            }, $label),
            'pageCaptions' => self::labelled($removed ? 'Odebral popisek' : 'Upravil popisek', $label),
            'pageImages' => self::labelled($removed ? 'Odebral obrázky strany' : 'Upravil obrázky strany', $label),
            'pageQr' => self::labelled($removed ? 'Odebral QR kód' : 'Nahrál QR kód', $label),
            'events' => match (true) {
                $removed => 'Odebral akci ' . $name,
                $isNew => self::labelled('Přidal akci ' . $name, $label),
                default => self::labelled('Upravil akci ' . $name, $label),
            },
            'clubs' => ($removed ? 'Odebral spolek ' : ($isNew ? 'Přidal spolek ' : 'Upravil spolek ')) . $name,
            'names' => match (true) {
                $removed => 'Odebral jméno ' . $name,
                $isNew => self::labelled('Přidal jméno ' . $name, $label),
                default => self::labelled('Upravil jméno ' . $name, $label),
            },
            'wasteKinds' => match (true) {
                $removed => 'Odebral druh odpadu ' . $name,
                $isNew => 'Přidal druh odpadu ' . $name,
                default => self::labelled('Upravil svoz', self::plainName($new)),
            },
            'wasteExtras' => self::labelled(
                trim(($removed ? 'Odebral svoz ' : ($isNew ? 'Přidal svoz ' : 'Upravil svoz ')) . self::wasteKindName($removed ? $old : $new, $content)),
                $label,
            ),
            'comments' => $isNew && !$removed
                ? sprintf('%s: %s', $label !== '' ? 'Komentář k ' . $label : 'Komentář', self::cut(self::string($new, 'text'), self::COMMENT_TEXT_LENGTH))
                : null,
            'moonEdits' => self::MOON_CHANGED,
            'shiftOps' => match (true) {
                $removed => 'Odebral provoz ' . $name,
                $isNew => 'Přidal provoz ' . $name,
                default => self::labelled('Upravil šichtovnici', self::plainName($new)),
            },
            'specialPages' => self::specialPage($old, $new),
            default => null,
        };
    }

    /**
     * @param null|array<mixed> $old
     * @param null|array<mixed> $new
     */
    private static function specialPage(null|array $old, null|array $new): string
    {
        if ($new === null) {
            return 'Smazal stránku ' . self::quoted($old);
        }

        $uploaded = in_array($new['kind'] ?? null, ['image', 'text'], true);

        if (!$uploaded) {
            return self::SPECIAL_PAGES_CHANGED;
        }

        if ($old === null) {
            return 'Přidal vlastní stránku ' . self::quoted($new);
        }

        if (($old['asset'] ?? null) !== ($new['asset'] ?? null) || ($old['text'] ?? null) !== ($new['text'] ?? null)) {
            return 'Vyměnil vlastní stránku ' . self::quoted($new);
        }

        return self::SPECIAL_PAGES_CHANGED;
    }

    private static function labelled(string $sentence, string $label): string
    {
        return $label === '' ? $sentence : $sentence . ' — ' . $label;
    }

    /**
     * @param null|array<mixed> $item
     */
    private static function quoted(null|array $item): string
    {
        return sprintf('„%s“', self::plainName($item));
    }

    /**
     * @param null|array<mixed> $item
     */
    private static function plainName(null|array $item): string
    {
        return self::cut(self::string($item, 'name'), 80);
    }

    /**
     * @param null|array<mixed> $extra
     * @param array<mixed> $content
     */
    private static function wasteKindName(null|array $extra, array $content): string
    {
        $kindId = self::string($extra, 'kind');
        $kinds = $content['wasteKinds'] ?? null;
        $kind = is_array($kinds) ? ($kinds[$kindId] ?? null) : null;

        return is_array($kind) ? self::plainName($kind) : '';
    }

    /**
     * @param null|array<mixed> $item
     */
    private static function string(null|array $item, string $key): string
    {
        $value = $item[$key] ?? '';

        return is_string($value) ? trim((string) preg_replace('/\s+/u', ' ', $value)) : '';
    }

    private static function cut(string $text, int $length): string
    {
        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1) . '…' : $text;
    }
}
