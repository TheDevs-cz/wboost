<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;
use WBoost\Web\Mcp\Response\ContextDimensionResponse;
use WBoost\Web\Mcp\Response\ContextProjectResponse;
use WBoost\Web\Mcp\Response\ContextUserResponse;
use WBoost\Web\Mcp\Response\GetContextResponse;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpScopeChecker;
use WBoost\Web\Mcp\Security\McpToolScope;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Query\GetManuals;
use WBoost\Web\Query\GetProjects;
use WBoost\Web\Query\GetProjectTemplateStats;
use WBoost\Web\Query\ProjectTemplateStats;
use WBoost\Web\Query\TemplateDimensionUsage;
use WBoost\Web\Services\Security\ProjectVoter;
use WBoost\Web\Services\SocialNetwork\ResolveRichTextOptions;

/**
 * `get_context` — the orientation tool, and the first one every client is told
 * to call (see the server `instructions` in `config/packages/mcp.php`).
 *
 * ## Why it returns what it returns
 *
 * The reference image a user drops into their chat never reaches this server:
 * the host model sees it natively. What the model CANNOT see is the account —
 * which projects exist, which typefaces were uploaded and under exactly which
 * family strings, which colours the brand manual defines, which canvas sizes
 * are in use. This tool supplies that vocabulary so an agent designs with real
 * project assets instead of inventing `"Helvetica"` and `#FF0000`.
 *
 * The font strings are the load-bearing part: a design document's `font` must
 * match one of them byte for byte, and an unknown family is a hard compile
 * error. They come from {@see \WBoost\Web\Entity\Font::faceFamily()}, the same
 * single source the renderer registers `@font-face` under.
 *
 * ## Authorisation
 *
 * There is no voter gate on the tool itself — it returns the caller's OWN
 * context. The per-project scoping is the ordinary web rule and nothing else:
 * {@see GetProjects} lists owned + shared (or everything, for an admin) and
 * every candidate is then re-checked through {@see ProjectVoter::VIEW}, so this
 * surface cannot drift into a parallel permission model. The token's scopes
 * narrow on the second axis and are enforced by
 * {@see \WBoost\Web\Mcp\Security\McpToolGate} before this class is reached.
 */
#[McpToolScope(McpScope::TemplatesRead)]
readonly final class GetContextTool
{
    /**
     * How long a user's project vocabulary is reused. Short on purpose: the
     * data is cheap to rebuild and the window only has to cover the burst of
     * calls a single agent turn makes.
     */
    private const int CACHE_TTL_SECONDS = 60;

    /**
     * ⚠️ BUMP THIS whenever the cached shape changes (a property added to or
     * removed from any `Context*Response`).
     *
     * The pool stores serialized DTOs, and a deploy that changes their shape
     * leaves up to {@see CACHE_TTL_SECONDS} of entries that unserialize into
     * objects with uninitialized typed properties — a fatal on first read.
     * Versioning the key makes the old entries unreachable instead.
     */
    private const string CACHE_SHAPE_VERSION = 'v1';

    public function __construct(
        private Security $security,
        private GetProjects $getProjects,
        private GetProjectTemplateStats $getProjectTemplateStats,
        private GetFonts $getFonts,
        private GetManuals $getManuals,
        private McpScopeChecker $scopeChecker,
        #[Autowire(service: 'cache.app')]
        private CacheInterface $cache,
    ) {
    }

    /**
     * Start here. Returns who you are talking to wboost as, what your token is
     * allowed to do, and the design vocabulary of every project you can access.
     *
     * Each project reports its brand fonts, its brand colours, the canvas
     * dimensions it already designs at, and how many templates and variants it
     * holds. Use those values verbatim in later calls: a font MUST be one of
     * the returned face strings, exactly as written (for example
     * "Rubik (Rubik Bold)") — an unknown family is rejected, it is never
     * substituted. Colours are the brand palette in lowercase #rrggbb; prefer
     * them over inventing hex values. Dimensions report both the authored size
     * (unit, unitWidth, unitHeight) and the canvas pixels (width, height) that
     * every coordinate elsewhere is expressed in.
     *
     * Takes no arguments and changes nothing. Call it once at the start of a
     * session, and again only if the user says they have just added a font,
     * colour or project.
     */
    #[McpTool(name: 'get_context')]
    public function __invoke(): GetContextResponse
    {
        $user = $this->security->getUser();

        // Unreachable in practice: `^/_mcp` is IS_AUTHENTICATED_FULLY on a
        // firewall whose only authenticator resolves a real User entity.
        if (!$user instanceof User) {
            throw new AuthenticationException();
        }

        // Only the PROJECT vocabulary is cached, and it is keyed by the user
        // whose visibility produced it. Identity and scopes are deliberately
        // left out: scopes belong to the TOKEN, not the user, and two tokens of
        // the same user can carry different ones — caching them under a
        // per-user key would hand one token the other's answer.
        $projects = $this->cache->get(
            self::cacheKey($user->id),
            function (ItemInterface $item) use ($user): array {
                $item->expiresAfter(self::CACHE_TTL_SECONDS);

                return $this->buildProjects($user);
            },
        );

        return new GetContextResponse(
            user: new ContextUserResponse(
                id: $user->id->toString(),
                email: $user->email,
                name: $user->name,
                role: self::primaryRole($user),
            ),
            scopes: array_map(
                static fn (McpScope $scope): string => $scope->value,
                $this->scopeChecker->grantedScopes(),
            ),
            projects: $projects,
        );
    }

    /**
     * The cache key for one user's project vocabulary.
     *
     * Public because it is the single most dangerous line in this class and is
     * asserted directly: the payload describes what THIS user may see, so a key
     * that is not per-user hands one account another's project list. Exposing
     * it makes that testable without a working cache backend (the suite has no
     * Redis, so a warm/cold assertion would pass vacuously).
     */
    public static function cacheKey(UuidInterface $userId): string
    {
        return sprintf('mcp_context.%s.%s', self::CACHE_SHAPE_VERSION, $userId->toString());
    }

    /**
     * @return list<ContextProjectResponse>
     */
    private function buildProjects(User $user): array
    {
        // Same listing rule as the web /projects page, then the voter as the
        // authority — belt and braces, and the belt is what keeps a future
        // change to GetProjects from widening this surface silently.
        $candidates = $this->security->isGranted(User::ROLE_ADMIN)
            ? $this->getProjects->all()
            : $this->getProjects->allForUser($user->id);

        /** @var list<Project> $projects */
        $projects = [];

        foreach ($candidates as $project) {
            if (!$this->security->isGranted(ProjectVoter::VIEW, $project)) {
                continue;
            }

            $projects[] = $project;
        }

        // `all()` and `allForUser()` disagree on ordering (and the latter has
        // none), so impose one here: a cached payload must be reproducible.
        usort($projects, static fn (Project $a, Project $b): int =>
            [$a->name, $a->id->toString()] <=> [$b->name, $b->id->toString()]);

        $stats = $this->getProjectTemplateStats->forProjects(
            array_map(static fn (Project $project): UuidInterface => $project->id, $projects),
        );

        /** @var list<ContextProjectResponse> $responses */
        $responses = [];

        foreach ($projects as $project) {
            // forProjects() answers for every id it was given; the fallback is
            // belt-and-braces, not a real branch.
            $projectStats = $stats[$project->id->toString()] ?? ProjectTemplateStats::none();

            /** @var list<string> $fonts */
            $fonts = [];

            foreach ($this->getFonts->allForProject($project->id) as $font) {
                foreach ($font->faces as $face) {
                    $fonts[] = $font->faceFamily($face);
                }
            }

            $responses[] = new ContextProjectResponse(
                id: $project->id->toString(),
                name: $project->name,
                templateCount: $projectStats->templateCount,
                variantCount: $projectStats->variantCount,
                fonts: $fonts,
                // The manual-colour palette has exactly one implementation —
                // the same pure function the rich-text toolbar, the API
                // listing and export-time validation resolve their swatches
                // through. A second copy here would let the palette an agent
                // is offered drift from the one the app considers "the brand".
                colors: ResolveRichTextOptions::computeColors($this->getManuals->allForProject($project->id)),
                dimensions: array_map(
                    static fn (TemplateDimensionUsage $usage): ContextDimensionResponse => new ContextDimensionResponse(
                        label: $usage->dimension->label(),
                        preset: $usage->dimension->preset?->value,
                        unit: $usage->dimension->unit->value,
                        unitWidth: $usage->dimension->unitWidth,
                        unitHeight: $usage->dimension->unitHeight,
                        width: $usage->dimension->width(),
                        height: $usage->dimension->height(),
                        variantCount: $usage->variantCount,
                    ),
                    $projectStats->dimensions,
                ),
            );
        }

        return $responses;
    }

    /**
     * The highest role the user holds. `getRoles()` guarantees ROLE_USER, so
     * the fallback is never a guess.
     */
    private static function primaryRole(User $user): string
    {
        $roles = $user->getRoles();

        foreach ([User::ROLE_ADMIN, User::ROLE_DESIGNER] as $role) {
            if (in_array($role, $roles, true)) {
                return $role;
            }
        }

        return 'ROLE_USER';
    }
}
