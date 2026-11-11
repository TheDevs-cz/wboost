<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Fill;

use Mcp\Exception\ToolCallException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Exceptions\CalendarChangeForbidden;
use WBoost\Web\Exceptions\CalendarModelRefused;
use WBoost\Web\Exceptions\CalendarModelUnavailable;
use WBoost\Web\Exceptions\InvalidCalendarChange;
use WBoost\Web\Mcp\Response\CalendarChangeResponse;
use WBoost\Web\Message\Calendar\SaveCalendar;
use WBoost\Web\MessageHandler\Calendar\SaveCalendarHandler;
use WBoost\Web\Repository\CalendarHistoryRepository;
use WBoost\Web\Services\Calendar\CalendarAccess;
use WBoost\Web\Services\Calendar\CalendarModelBridge;
use WBoost\Web\Value\Calendar\CalendarActor;
use WBoost\Web\Value\Calendar\CalendarModelResult;
use WBoost\Web\Value\Calendar\CalendarSavePayload;

/**
 * The DESIGN half of a calendar save for the MCP tools — the counterpart of
 * what `update_calendar_content` does for content.
 *
 * ## One write path
 *
 * A tool phrases what it wants as model commands. {@see CalendarModelBridge}
 * runs the calendar's own JavaScript store on them (defaults, cascades,
 * clamping) and answers the save payload the web workspace would have
 * POSTed; this class parses it with the web's envelope parser
 * ({@see CalendarSavePayload::fromJson()}) and dispatches the web's
 * {@see SaveCalendar}. The handler's rules (`CalendarDesignRules`, asset
 * ownership, roles, history, revision, row lock) are the only ones that
 * write. No tool touches a `Calendar` entity.
 *
 * ## Designer only
 *
 * Every design change needs the role the web needs: whoever may EDIT the
 * project ({@see CalendarAccess::forUser()}). A member the project is only
 * shared with is a calendar CLIENT and is refused here with `designer_only`
 * before anything runs — and the handler would refuse the same request again.
 *
 * ## Refusals
 *
 * `message (reason)`. From the model: `unknown_preset`,
 * `preset_type_mismatch`, `no_layout`, `unknown_box`, `invalid_box`,
 * `not_hideable`, `invalid_order`, `unknown_style_key`,
 * `invalid_style_value`, `unknown_font`, `invalid_color`, `unknown_feature`,
 * `invalid_print`, `invalid_year`, `invalid_type`, `invalid_name`. From the
 * server's rules: `invalid_design` (quotes the Czech sentence the web shows;
 * it names the offending key). `model_unavailable` = the model could not be
 * run; retry.
 */
readonly final class CalendarWriter
{
    /** History lines read around a save. */
    private const int HISTORY_WINDOW = SaveCalendarHandler::MAX_HISTORY_ENTRIES + 10;

    public function __construct(
        private CalendarAccess $access,
        private CalendarModelBridge $model,
        private MessageBusInterface $bus,
        private CalendarHistoryRepository $historyRepository,
    ) {
    }

    /**
     * The caller as the calendar's designer, or the refusal.
     */
    public function designer(Calendar $calendar): CalendarActor
    {
        $actor = $this->access->forUser($calendar);

        if (!$actor->isDesigner()) {
            throw self::designerOnly();
        }

        return $actor;
    }

    /**
     * Runs the commands on the model and saves what they changed.
     *
     * @param list<array<string, mixed>> $commands
     * @param null|array<string, true> $known history ids read BEFORE the tool recorded a line of its own ({@see historyIds()}); null = read them now
     * @return array{model: CalendarModelResult, changed: list<string>, recorded: list<CalendarChangeResponse>}
     */
    public function apply(Calendar $calendar, CalendarActor $actor, array $commands, bool $finish = false, null|array $known = null): array
    {
        $known ??= $this->historyIds($calendar);

        try {
            $model = $this->model->run($calendar, $commands);
        } catch (CalendarModelRefused | CalendarModelUnavailable $failure) {
            throw self::refusal($failure);
        }

        $changed = [];

        if ($model->payload !== null || $finish) {
            try {
                $payload = CalendarSavePayload::fromJson($model->payload ?? '{}');
                $changed = array_map(strval(...), array_keys(get_object_vars($payload->design ?? new \stdClass())));

                if ($payload->ops !== []) {
                    $changed[] = 'content';
                }

                $this->bus->dispatch(new SaveCalendar($calendar->id, $actor, $payload->design, $payload->ops, $finish));
            } catch (\Throwable $failure) {
                throw self::refusal($failure);
            }
        }

        return [
            'model' => $model,
            'changed' => $changed,
            'recorded' => $this->recordedSince($calendar, $known),
        ];
    }

    /**
     * @return array<string, true>
     */
    public function historyIds(Calendar $calendar): array
    {
        $ids = [];

        foreach ($this->historyRepository->latest($calendar->id, self::HISTORY_WINDOW) as $entry) {
            $ids[$entry->id->toString()] = true;
        }

        return $ids;
    }

    /**
     * The history lines added since {@see historyIds()} was read, newest first.
     *
     * @param array<string, true> $known
     * @return list<CalendarChangeResponse>
     */
    public function recordedSince(Calendar $calendar, array $known): array
    {
        $recorded = [];

        foreach ($this->historyRepository->latest($calendar->id, self::HISTORY_WINDOW) as $entry) {
            if (!isset($known[$entry->id->toString()])) {
                $recorded[] = new CalendarChangeResponse(
                    who: $entry->authorKind->value,
                    what: $entry->description,
                    at: $entry->createdAt->format(\DATE_ATOM),
                );
            }
        }

        return $recorded;
    }

    /**
     * Any failure of a design save as the tool refusal it is.
     */
    public static function refusal(\Throwable $failure): \Throwable
    {
        if ($failure instanceof HandlerFailedException && $failure->getPrevious() !== null) {
            $failure = $failure->getPrevious();
        }

        return match (true) {
            $failure instanceof ToolCallException => $failure,
            $failure instanceof CalendarModelRefused => new ToolCallException(sprintf('%s Nothing was saved. (%s)', $failure->getMessage(), $failure->reason)),
            $failure instanceof CalendarModelUnavailable => new ToolCallException(sprintf('%s Nothing was saved — this is a problem on the wboost side; try again. (model_unavailable)', $failure->getMessage())),
            $failure instanceof InvalidCalendarChange => new ToolCallException(sprintf('The calendar refused the change: "%s" Nothing was saved. (invalid_design)', $failure->getMessage())),
            $failure instanceof CalendarChangeForbidden => self::designerOnly(),
            default => $failure,
        };
    }

    public static function designerOnly(): ToolCallException
    {
        return new ToolCallException('This account is a client of the calendar (the project is only shared with it). Its settings, layout and styles belong to the designer — a client may only fill in content with update_calendar_content and upload photos. Nothing was saved. (designer_only)');
    }
}
