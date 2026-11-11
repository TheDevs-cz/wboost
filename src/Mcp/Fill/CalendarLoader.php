<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Fill;

use Mcp\Exception\ToolCallException;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\SecurityBundle\Security;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;
use WBoost\Web\Exceptions\CalendarNotFound;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Query\GetProjects;
use WBoost\Web\Repository\CalendarRepository;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Services\Security\CalendarVoter;
use WBoost\Web\Services\Security\ProjectVoter;

/**
 * Loads calendars and their projects for the calendar MCP tools — the
 * {@see DocumentLoader} of the Kalendáře module.
 *
 * A calendar (or project) the caller may not see answers EXACTLY like an id
 * that matches nothing, so the tools cannot be used to enumerate what exists.
 * Every refusal ends with its reason code in parentheses — the same
 * `message (reason)` shape `manage_document` uses.
 *
 * Deliberately NOT gated on the project's module list or on the module being
 * in preview ({@see \WBoost\Web\Value\ProjectModule::isInPreview()}): like
 * every item page, the REST API and the other MCP tools, access follows the
 * voters alone. The module switch only decides what the web navigation shows.
 */
readonly final class CalendarLoader
{
    public function __construct(
        private Security $security,
        private CalendarRepository $calendarRepository,
        private ProjectRepository $projectRepository,
        private GetProjects $getProjects,
    ) {
    }

    /**
     * The calendar, for a caller who may at least view its project.
     */
    public function viewable(string $calendarId): Calendar
    {
        $calendarId = trim($calendarId);

        if (!Uuid::isValid($calendarId)) {
            throw new ToolCallException(sprintf(
                '"%s" is not a valid calendar id. Calendar ids are UUIDs; list_calendars returns them as calendars[].id. (invalid_calendar_id)',
                $calendarId,
            ));
        }

        try {
            $calendar = $this->calendarRepository->get(Uuid::fromString($calendarId));
        } catch (CalendarNotFound) {
            $calendar = null;
        }

        if ($calendar === null || !$this->security->isGranted(CalendarVoter::VIEW, $calendar)) {
            throw new ToolCallException(sprintf(
                'No calendar with id %s exists, or this account cannot see it. list_calendars returns the calendars it can reach. (calendar_not_found)',
                $calendarId,
            ));
        }

        return $calendar;
    }

    /**
     * One project the caller may view.
     */
    public function project(string $projectId): Project
    {
        $projectId = trim($projectId);

        if (!Uuid::isValid($projectId)) {
            throw new ToolCallException(sprintf(
                '"%s" is not a valid project id. Project ids are UUIDs; call list_calendars without projectId to see every calendar this account can reach. (invalid_project_id)',
                $projectId,
            ));
        }

        try {
            $project = $this->projectRepository->get(Uuid::fromString($projectId));
        } catch (ProjectNotFound) {
            $project = null;
        }

        if ($project === null || !$this->security->isGranted(ProjectVoter::VIEW, $project)) {
            throw new ToolCallException(sprintf(
                'No project with id %s exists, or this account cannot see it. (project_not_found)',
                $projectId,
            ));
        }

        return $project;
    }

    /**
     * Every project the caller may view — the listing rule of `get_context`
     * (owned + shared, everything for an admin), then the voter as the
     * authority. Sorted by name, then id, so two calls answer in one order.
     *
     * @return list<Project>
     */
    public function projects(): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return [];
        }

        $candidates = $this->security->isGranted(User::ROLE_ADMIN)
            ? $this->getProjects->all()
            : $this->getProjects->allForUser($user->id);

        $projects = [];

        foreach ($candidates as $project) {
            if ($this->security->isGranted(ProjectVoter::VIEW, $project)) {
                $projects[] = $project;
            }
        }

        usort($projects, static fn (Project $a, Project $b): int =>
            [$a->name, $a->id->toString()] <=> [$b->name, $b->id->toString()]);

        return $projects;
    }
}
