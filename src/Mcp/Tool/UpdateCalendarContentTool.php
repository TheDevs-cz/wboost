<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Exceptions\CalendarChangeForbidden;
use WBoost\Web\Exceptions\InvalidCalendarChange;
use WBoost\Web\Mcp\Fill\CalendarLoader;
use WBoost\Web\Mcp\Response\CalendarChangeResponse;
use WBoost\Web\Mcp\Response\CalendarOperationResultResponse;
use WBoost\Web\Mcp\Response\UpdateCalendarContentResponse;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpToolScope;
use WBoost\Web\Message\Calendar\SaveCalendar;
use WBoost\Web\MessageHandler\Calendar\SaveCalendarHandler;
use WBoost\Web\Repository\CalendarHistoryRepository;
use WBoost\Web\Services\Calendar\CalendarAccess;
use WBoost\Web\Services\Calendar\CalendarContentRules;
use WBoost\Web\Value\Calendar\CalendarSavePayload;

/**
 * `update_calendar_content` — the content half of the web save, for an agent.
 * Details: docs/architecture/calendars.md § MCP.
 *
 * ## One write path
 *
 * The operations are re-encoded as the web payload's `content` list and go
 * through {@see CalendarSavePayload::fromJson()} and the {@see SaveCalendar}
 * message — the same envelope checks, the same
 * {@see SaveCalendarHandler} (row lock, item-wise merge, validation by
 * `CalendarContentRules`, history, revision). Nothing is validated or written
 * here a second time; this class only translates names and refusals.
 *
 * ## No design, by construction
 *
 * The payload built here has no `design` key and no `finish`, and an
 * operation is refused for any key but `collection` / `id` / `value` /
 * `label`, so there is no spelling of a request that reaches the design half
 * of the handler.
 *
 * ## The actor is the web's
 *
 * {@see CalendarAccess::forUser()} — whoever may EDIT the project acts as the
 * designer, a member the project is only shared with acts as a client and is
 * held to the client whitelist by the handler (scope ∩ role, never wider than
 * the web). The history line is recorded under that role with the token's user.
 *
 * ## Refusals
 *
 * `message (reason)`: `invalid_calendar_id`, `calendar_not_found` (also a
 * calendar of a project the caller cannot see), `no_operations`,
 * `invalid_operation` (the envelope), `invalid_content` (the item rules —
 * the Czech sentence is the one the web shows), `designer_only` (a client
 * touching a collection outside its whitelist). A refused call stored nothing.
 */
#[McpToolScope(McpScope::CalendarsWrite)]
readonly final class UpdateCalendarContentTool
{
    private const array OPERATION_KEYS = ['collection', 'id', 'value', 'label'];

    /** History lines read around the save: one request adds at most MAX_HISTORY_ENTRIES + 1 content lines. */
    private const int HISTORY_WINDOW = SaveCalendarHandler::MAX_HISTORY_ENTRIES + 10;

    public function __construct(
        private CalendarLoader $loader,
        private CalendarAccess $access,
        private MessageBusInterface $bus,
        private CalendarHistoryRepository $historyRepository,
    ) {
    }

    /**
     * Adds, replaces or deletes CONTENT items of a desk calendar: events,
     * extra names, waste collection, photo captions, comments and so on. It
     * cannot change the calendar's design (name, year, type, features, layout,
     * styles), cannot upload files and cannot create or delete calendars —
     * those stay on the web. Call get_calendar first.
     *
     * operations: a list of {"collection", "id", "value", "label"?}.
     * - value = the WHOLE item (every key listed below, no others) to create or
     *   replace it; value = null to delete it. There is no partial update: to
     *   change one field, copy the item from get_calendar content and send it
     *   back whole.
     * - id: for a new item invent one — 8 to 40 characters of A–Z a–z 0–9 _ -
     *   (16 random letters and digits is what the web uses). For page-keyed
     *   collections the id IS the page key ("w05", "pre", "post", "cover",
     *   "back", "sp:<id>" — see get_calendar).
     * - label: optional short text for the history line only — the date as
     *   "1. 6." for dated items, the page title ("5. týden") for page items.
     *
     * The call is atomic: if any operation is refused nothing is saved. Items
     * are merged one by one, so other people's changes to OTHER items are never
     * overwritten; for the same item the later save wins. An operation that
     * equals what is stored is reported as "unchanged". Every real change adds a
     * line to the calendar's history ("Historie") under this account's role.
     *
     * Dates are "YYYY-MM-DD" and must lie between 1 December of the previous
     * year and 31 January of the next year. Weekdays are 0 = Monday … 6 =
     * Sunday. An asset is the id of a file from get_calendar assets, or null
     * where allowed. `pos` is the order within a collection (0, 1, 2 …).
     *
     * Collections and their items (all keys required):
     * - events {date, name ≤80, club: clubId|null, icon: assetId|null} — an
     *   event printed on its day ("Upravitelné akce"; feature events).
     * - clubs {code ≤6, name ≤60, pos} — organisers; an event of a club prints
     *   as "CODE · name".
     * - names {date, name ≤40} — an extra name beside the day's name day.
     * - wasteKinds {builtin: "kom"|"bio"|"plast"|"papir"|"sklo"|null, name ≤30,
     *   on: bool, place ≤40, mode: "rule"|"dates", weekdays: [0–6, at least
     *   one], interval: "w" weekly | "2e" even ISO weeks | "2o" odd ISO weeks |
     *   "4w" | "4w2" every fourth week | "m" first of the month, from: date|null,
     *   to: date|null (null = whole year), dates: [date…] (used when mode is
     *   "dates"), pos} — a kind of waste and when it is collected. A new
     *   calendar already holds kom, bio, plast, papir switched off: turn one on
     *   by re-sending it with on: true.
     * - wasteExtras {kind: wasteKindId, date} — one extra collection day.
     * - comments {date, text ≤500} — a note for the other side on a day. Insert
     *   only: a comment cannot be edited or deleted, and the server sets its
     *   author and time itself.
     * - pageCaptions {text ≤80} — the photo caption of a week page (id = page key).
     * - pagePhotos {asset, zoom 1–3, px 0–1, py 0–1} — the photo of a week page
     *   and its crop (zoom 1, px 0.5, py 0.5 = centred).
     * Designer role only (a "client" role is refused with designer_only):
     * - moonEdits {op: "add"|"remove", date, phase 0–3} — corrections of the
     *   computed moon phases (0 new moon, 1 first quarter, 2 full, 3 last quarter).
     * - shiftOps {name ≤30, shifts: [{letter ≤2, from "HH:MM", to "HH:MM"}] (1–4),
     *   crews: [four strings ≤2], pos} — a shift table column ("Šichtovnice").
     * - specialPages {kind: "intro"|"contacts"|"year"|"waste"|"image"|"text",
     *   on, layout 0–20, position: "front"|"back", nextYear, name ≤60,
     *   asset|null, text ≤6000|null, fileName ≤120|null, pos} — the generated
     *   pages use the fixed ids intro, contacts, year, waste.
     * - pageImages {head: asset|null, bg: asset|null}, pageQr {asset} — a page's
     *   own header / background picture and QR code (id = page key).
     *
     * Content of a feature that is switched off (get_calendar features) is
     * stored but not printed — only the designer can switch a feature on, on
     * the web.
     *
     * Returns results[] (one per operation: created | updated | deleted |
     * unchanged), the touched collections as stored now, the history lines this
     * call recorded, and the new revision. Errors end with a reason code in
     * parentheses: invalid_calendar_id, calendar_not_found, no_operations,
     * invalid_operation, invalid_content, designer_only.
     *
     * @param string $calendarId UUID of the calendar, from list_calendars calendars[].id.
     * @param list<mixed> $operations 1–2000 operations {"collection": string, "id": string, "value": object|null, "label"?: string}, applied in order.
     */
    #[McpTool(name: 'update_calendar_content')]
    public function __invoke(
        string $calendarId,
        #[Schema(type: 'array', items: ['type' => 'object', 'additionalProperties' => true])]
        array $operations,
    ): UpdateCalendarContentResponse {
        $calendar = $this->loader->viewable($calendarId);
        $actor = $this->access->forUser($calendar);
        $payload = self::payload($operations);

        $before = $calendar->content;
        $knownHistory = $this->historyIds($calendar);

        try {
            $this->bus->dispatch(new SaveCalendar($calendar->id, $actor, null, $payload->ops));
        } catch (InvalidCalendarChange $invalid) {
            throw self::invalidContent($invalid);
        } catch (CalendarChangeForbidden) {
            throw self::designerOnly();
        } catch (HandlerFailedException $failed) {
            $real = $failed->getPrevious();

            if ($real instanceof InvalidCalendarChange) {
                throw self::invalidContent($real);
            }

            if ($real instanceof CalendarChangeForbidden) {
                throw self::designerOnly();
            }

            throw $failed;
        }

        // The handler locked and refreshed this very instance.
        $after = $calendar->content;
        $results = [];
        $touched = [];

        foreach ($payload->ops as $op) {
            $was = self::item($before, $op->collection, $op->id);
            $is = self::item($after, $op->collection, $op->id);

            $results[] = new CalendarOperationResultResponse(
                collection: $op->collection,
                id: $op->id,
                status: match (true) {
                    Calendar::same($was, $is) => 'unchanged',
                    $was === null => 'created',
                    $is === null => 'deleted',
                    default => 'updated',
                },
            );

            $collection = $after[$op->collection] ?? [];
            $touched[$op->collection] = (object) (is_array($collection) ? $collection : []);
        }

        $recorded = [];

        foreach ($this->historyRepository->latest($calendar->id, self::HISTORY_WINDOW) as $entry) {
            if (!isset($knownHistory[$entry->id->toString()])) {
                $recorded[] = new CalendarChangeResponse(
                    who: $entry->authorKind->value,
                    what: $entry->description,
                    at: $entry->createdAt->format(\DATE_ATOM),
                );
            }
        }

        return new UpdateCalendarContentResponse(
            calendarId: $calendar->id->toString(),
            revision: $calendar->revision,
            updatedAt: $calendar->updatedAt->format(\DATE_ATOM),
            role: $actor->kind->value,
            results: $results,
            content: (object) $touched,
            recorded: $recorded,
        );
    }

    /**
     * The operations as the web save payload — `{ "content": [{c, id, v, label}] }`
     * and nothing else — parsed by the web's own envelope parser.
     *
     * @param array<array-key, mixed> $operations
     */
    private static function payload(array $operations): CalendarSavePayload
    {
        if ($operations === []) {
            throw new ToolCallException('"operations" is empty — pass at least one {"collection", "id", "value"} operation. (no_operations)');
        }

        $content = [];

        foreach (array_values($operations) as $index => $operation) {
            if (!is_array($operation) || array_is_list($operation)) {
                throw self::invalidOperation($index, 'it must be an object {"collection", "id", "value", "label"?}');
            }

            $unknown = array_diff(array_map(strval(...), array_keys($operation)), self::OPERATION_KEYS);

            if ($unknown !== []) {
                throw self::invalidOperation($index, sprintf(
                    'unknown key "%s" — an operation has only collection, id, value and label; a calendar\'s design cannot be changed through this tool',
                    mb_substr((string) reset($unknown), 0, 40),
                ));
            }

            $collection = $operation['collection'] ?? null;
            $id = $operation['id'] ?? null;
            $label = $operation['label'] ?? '';

            if (!is_string($collection) || $collection === '' || !is_string($id) || $id === '') {
                throw self::invalidOperation($index, '"collection" and "id" are required strings');
            }

            if (!is_string($label)) {
                throw self::invalidOperation($index, '"label" must be a string');
            }

            // Deleting is spelled out — a forgotten "value" must not delete an item.
            if (!array_key_exists('value', $operation)) {
                throw self::invalidOperation($index, '"value" is required: the whole item to store, or null to delete it');
            }

            $value = $operation['value'];

            if ($value !== null && (!is_array($value) || ($value !== [] && array_is_list($value)))) {
                throw self::invalidOperation($index, '"value" must be an object (the whole item) or null');
            }

            $content[] = [
                'c' => $collection,
                'id' => $id,
                // An item is a map: `{}` even when the caller sent it empty.
                'v' => $value === null ? null : (object) $value,
                'label' => $label,
            ];
        }

        try {
            return CalendarSavePayload::fromJson(json_encode(['content' => $content], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION));
        } catch (InvalidCalendarChange $invalid) {
            throw new ToolCallException(sprintf('%s (invalid_operation)', $invalid->getMessage()));
        } catch (\JsonException) {
            throw new ToolCallException('The operations could not be encoded as JSON — check the values for invalid text. (invalid_operation)');
        }
    }

    /**
     * @param array<array-key, mixed> $content
     * @return null|array<array-key, mixed>
     */
    private static function item(array $content, string $collection, string $id): null|array
    {
        $items = $content[$collection] ?? null;
        $item = is_array($items) ? ($items[$id] ?? null) : null;

        return is_array($item) ? $item : null;
    }

    /**
     * @return array<string, true>
     */
    private function historyIds(Calendar $calendar): array
    {
        $ids = [];

        foreach ($this->historyRepository->latest($calendar->id, self::HISTORY_WINDOW) as $entry) {
            $ids[$entry->id->toString()] = true;
        }

        return $ids;
    }

    private static function invalidOperation(int $index, string $problem): ToolCallException
    {
        return new ToolCallException(sprintf('operations[%d]: %s. Nothing was saved. (invalid_operation)', $index, $problem));
    }

    private static function invalidContent(InvalidCalendarChange $invalid): ToolCallException
    {
        return new ToolCallException(sprintf(
            'The calendar refused the change: "%s" Nothing was saved — the item shapes are in this tool\'s description. (invalid_content)',
            $invalid->getMessage(),
        ));
    }

    private static function designerOnly(): ToolCallException
    {
        return new ToolCallException(sprintf(
            'This account is a client of the calendar (the project is only shared with it), and a client may change only: %s. Nothing was saved. (designer_only)',
            implode(', ', CalendarContentRules::CLIENT_WRITABLE),
        ));
    }
}
