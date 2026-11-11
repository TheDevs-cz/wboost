<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Fill;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Mcp\Response\CalendarChangeResponse;
use WBoost\Web\Mcp\Response\CalendarDesignResponse;
use WBoost\Web\Mcp\Response\CalendarExportResponse;
use WBoost\Web\Value\Calendar\CalendarModelResult;

/**
 * Builds the answers of the calendar design tools from the stored calendar
 * and the model's materialised design — the {@see DocumentPresenter} of the
 * Kalendáře module.
 */
readonly final class CalendarPresenter
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param CalendarModelResult $model the model run that produced what is stored now
     * @param list<string> $changed
     * @param list<string> $notes
     * @param list<CalendarChangeResponse> $recorded
     */
    public function design(Calendar $calendar, CalendarModelResult $model, array $changed, array $notes, array $recorded, null|string $clientUrl = null, null|CalendarExportResponse $export = null): CalendarDesignResponse
    {
        $design = $model->design;

        return new CalendarDesignResponse(
            calendarId: $calendar->id->toString(),
            projectId: $calendar->project->id->toString(),
            name: $calendar->name,
            year: $calendar->year,
            type: $calendar->type->value,
            finished: $calendar->finished,
            step: $calendar->step,
            revision: $calendar->revision,
            updatedAt: $calendar->updatedAt->format(\DATE_ATOM),
            preset: $calendar->layoutPreset,
            features: self::map($design->features ?? null),
            print: self::map($design->print ?? null),
            page: self::map($design->page ?? null),
            layout: ($design->layout ?? null) instanceof \stdClass ? $design->layout : null,
            changed: $changed,
            notes: $notes,
            recorded: $recorded,
            url: $this->workspaceUrl($calendar),
            clientUrl: $clientUrl,
            export: $export,
        );
    }

    public function workspaceUrl(Calendar $calendar): string
    {
        return $this->urlGenerator->generate('calendar_workspace', ['calendarId' => $calendar->id->toString()], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    public static function map(mixed $value): \stdClass
    {
        return $value instanceof \stdClass ? $value : new \stdClass();
    }
}
