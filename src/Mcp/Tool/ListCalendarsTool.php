<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use WBoost\Web\Entity\Project;
use WBoost\Web\Mcp\Fill\CalendarLoader;
use WBoost\Web\Mcp\Response\CalendarSummaryResponse;
use WBoost\Web\Mcp\Response\ListCalendarsResponse;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Skill\SkillRelease;
use WBoost\Web\Mcp\Security\McpToolScope;
use WBoost\Web\Query\GetCalendars;
use WBoost\Web\Value\Calendar\CalendarListRow;

/**
 * `list_calendars` — the desk calendars (module Kalendáře) an account can
 * reach. Details: docs/architecture/calendars.md § MCP.
 *
 * ## Why `projectId` is optional
 *
 * `calendars:read` implies no template scope, so a calendar-only token cannot
 * call `get_context` to learn its project ids. Listing across every reachable
 * project is what makes the calendar scopes self-sufficient; each row carries
 * its project so a token that DOES hold `templates:read` can still correlate.
 *
 * ## Authorisation
 *
 * {@see \WBoost\Web\Services\Security\ProjectVoter::VIEW} through
 * {@see CalendarLoader} — owner, admin, or a member the project is shared
 * with. A foreign project answers like one that does not exist. The listing
 * is NOT gated on the project's module list or on the module's preview state
 * (see {@see CalendarLoader}): a calendar that exists is listed.
 *
 * Read-only. Calendars are created and set up with `manage_calendar`;
 * deleting one stays on the web.
 */
#[McpToolScope(McpScope::CalendarsRead)]
readonly final class ListCalendarsTool
{
    public function __construct(
        private CalendarLoader $loader,
        private GetCalendars $getCalendars,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Lists desk calendars ("Kalendáře"): spiral-bound calendars a designer
     * builds for a client (typically a municipality), one page per week or per
     * two weeks, whose dates, name days and public holidays the system fills in.
     * Start here — the id of a row is what every other calendar tool takes.
     *
     * Omit projectId to list the calendars of EVERY project this account can
     * reach (ordered by project name, newest calendar first); pass a project id
     * to narrow to one project. An empty list means there are no calendars, not
     * an error.
     *
     * Each row: id, projectId + projectName, name, year, type ("week" = 7 days
     * per page, "fortnight" = 14 days per page), finished (false = the designer
     * is still setting up the look), hasLayout (false = no page layout yet —
     * content can be stored, but there is nothing to look at until
     * set_calendar_layout applies a preset), updatedAt, who
     * changed it last (lastChangeBy "designer" | "client", lastChangeAt) and
     * url — the web workspace, to hand to the user.
     *
     * This tool only reads. manage_calendar creates and configures calendars,
     * set_calendar_layout designs them, update_calendar_content fills them in,
     * render_calendar_page shows a page. Deleting a calendar is done on the web.
     *
     * skill reports whether the wboost skill you run is the current release
     * ("current", "outdated", "unknown" = no skillVersion sent); when it is
     * not current, tell the user its message once per session, after doing
     * what they asked.
     *
     * @param null|string $projectId UUID of one project to narrow the listing to (calendars[].projectId of an earlier call, or get_context projects[].id). Omit to list every reachable project's calendars.
     * @param null|string $skillVersion The version your wboost skill states (e.g. "0.3.0"). Omit when you have no wboost skill.
     */
    #[McpTool(name: 'list_calendars')]
    public function __invoke(null|string $projectId = null, null|string $skillVersion = null): ListCalendarsResponse
    {
        $narrowed = $projectId !== null && trim($projectId) !== '';
        $projects = $narrowed ? [$this->loader->project($projectId)] : $this->loader->projects();

        $calendars = [];

        foreach ($projects as $project) {
            foreach ($this->getCalendars->forProject($project->id) as $row) {
                $calendars[] = $this->summary($project, $row);
            }
        }

        return new ListCalendarsResponse(
            projectId: $narrowed ? $projects[0]->id->toString() : null,
            calendars: $calendars,
            skill: SkillRelease::check($skillVersion),
        );
    }

    private function summary(Project $project, CalendarListRow $row): CalendarSummaryResponse
    {
        $calendar = $row->calendar;

        return new CalendarSummaryResponse(
            id: $calendar->id->toString(),
            projectId: $project->id->toString(),
            projectName: $project->name,
            name: $calendar->name,
            year: $calendar->year,
            type: $calendar->type->value,
            finished: $calendar->finished,
            hasLayout: $calendar->layout !== null,
            updatedAt: $calendar->updatedAt->format(\DATE_ATOM),
            lastChangeBy: $row->lastChangeBy?->value,
            lastChangeAt: $row->lastChangeAt?->format(\DATE_ATOM),
            url: $this->urlGenerator->generate('calendar_workspace', ['calendarId' => $calendar->id->toString()], UrlGeneratorInterface::ABSOLUTE_URL),
        );
    }
}
