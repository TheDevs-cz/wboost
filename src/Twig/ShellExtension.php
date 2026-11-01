<?php

declare(strict_types=1);

namespace WBoost\Web\Twig;

use Symfony\Bundle\SecurityBundle\Security;
use Twig\Attribute\AsTwigFunction;
use WBoost\Web\Entity\User;
use WBoost\Web\Query\GetShellProjects;
use WBoost\Web\Query\ShellProjectRow;
use WBoost\Web\Value\ProjectAvatar;

/**
 * Nocturne app-shell helpers (docs/architecture/app-shell.md).
 */
readonly final class ShellExtension
{
    public function __construct(
        private GetShellProjects $getShellProjects,
        private Security $security,
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
}
