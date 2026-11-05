<?php

declare(strict_types=1);

namespace WBoost\Web\Twig;

use Ramsey\Uuid\Uuid;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Attribute\AsTwigFunction;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Query\GetShellProjects;
use WBoost\Web\Query\ShellProjectRow;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Services\Security\ProjectVoter;
use WBoost\Web\Value\ProjectAvatar;

/**
 * Nocturne app-shell helpers (docs/architecture/app-shell.md).
 */
readonly final class ShellExtension
{
    /** Session key of the last project the user opened (F15). */
    public const string LAST_PROJECT_SESSION_KEY = 'shell_last_project_id';

    public function __construct(
        private GetShellProjects $getShellProjects,
        private Security $security,
        private RequestStack $requestStack,
        private ProjectRepository $projectRepository,
    ) {
    }

    /**
     * `{{ icon('share', 's16') }}` → `<span class="i i-share s16" aria-hidden="true"></span>`,
     * a Nocturne mask icon (assets/styles/nocturne/icons.css). Colour follows
     * `currentColor`; sizes: s12 s14 s16 s18 s24 s32 (default 20 px).
     */
    #[AsTwigFunction('icon', isSafe: ['html'])]
    public function icon(string $name, string $classes = ''): string
    {
        $class = trim('i i-' . $name . ' ' . $classes);

        return sprintf('<span class="%s" aria-hidden="true"></span>', htmlspecialchars($class, ENT_QUOTES));
    }

    /**
     * Monogram for avatars / project symbols: first letters of the first two
     * words ("Lukáš Rejda" → "LR").
     */
    #[AsTwigFunction('initials')]
    public function initials(string $name): string
    {
        return ProjectAvatar::initials($name);
    }

    /**
     * Projects the signed-in user can open — the sidebar count next to
     * "Všechny projekty" and the "Rychlá navigace" list.
     *
     * @return list<ShellProjectRow>
     */
    #[AsTwigFunction('shell_projects')]
    public function shellProjects(): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return [];
        }

        return $this->getShellProjects->forUser($user->id, $this->security->isGranted(User::ROLE_ADMIN));
    }

    /**
     * The project the sidebar shows (F15, 2026-11-05). On a project page that
     * is the page's own `project` — remembered in the session. On a page
     * without one (admin, Propojené aplikace, profile, /projects…) it is the
     * remembered project, so the quick-nav trigger and the PROJEKT section
     * stay; the voter is re-checked on every render and a deleted or no longer
     * viewable project is forgotten. Logout invalidates the session.
     *
     * Cost on project-less pages: one primary-key lookup (+ the voter).
     */
    #[AsTwigFunction('shell_project')]
    public function shellProject(mixed $pageProject = null): null|Project
    {
        $request = $this->requestStack->getMainRequest();
        $session = $request !== null && $request->hasSession() ? $request->getSession() : null;

        if ($pageProject instanceof Project) {
            $session?->set(self::LAST_PROJECT_SESSION_KEY, $pageProject->id->toString());

            return $pageProject;
        }

        $rememberedId = $session?->get(self::LAST_PROJECT_SESSION_KEY);

        if ($session === null || !is_string($rememberedId)) {
            return null;
        }

        try {
            $project = Uuid::isValid($rememberedId)
                ? $this->projectRepository->get(Uuid::fromString($rememberedId))
                : null;
        } catch (ProjectNotFound) {
            $project = null;
        }

        if ($project === null || !$this->security->isGranted(ProjectVoter::VIEW, $project)) {
            $session->remove(self::LAST_PROJECT_SESSION_KEY);

            return null;
        }

        return $project;
    }
}
