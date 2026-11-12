<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Skill;

use WBoost\Web\Mcp\Response\SkillVersionResponse;

/**
 * The released version of the wboost Claude skill (`plugin/wboost/`) that this
 * server's MCP tools are documented by — and the update hint a client with an
 * older copy is given.
 *
 * ## Why the server knows the skill's version
 *
 * The tools update on every deploy; the skill is a COPY on the user's machine
 * (a Claude Code plugin, or a zip uploaded to claude.ai) and goes stale
 * silently. Claude Code only re-fetches a third-party marketplace when the user
 * asks, and claude.ai never refreshes an uploaded skill. So the server is the
 * one party that always knows what is current: the skill tells Claude to pass
 * its own version as `skillVersion` on its first call (`get_context` or
 * `list_calendars`), {@see self::check()} answers whether it is outdated, and
 * the server `instructions` (`config/packages/mcp.php`) state the version for a
 * client that has no skill at all. See `docs/mcp/skill-maintenance.md`.
 *
 * ## Kept in step by a test
 *
 * {@see self::VERSION} must equal `plugin/wboost/.claude-plugin/plugin.json`,
 * the marketplace entry, the version line in `SKILL.md`, the manifest
 * `references/tools.json` and the top `CHANGELOG.md` heading —
 * `tests/Mcp/SkillManifestTest.php` fails on any mismatch, so a bump is one
 * coordinated change and cannot half-ship.
 *
 * Not a service: plain constants and one pure function.
 */
final class SkillRelease
{
    public const string VERSION = '0.3.0';

    /** One or two lines: what the CURRENT version adds over the previous one. */
    public const string WHATS_NEW = 'Desk calendars (Kalendáře: create, layout, content, photos, page renders, PDF export, client link), multi-page documents, print PDFs with bleed and crop marks, new templates (create_template) and the AI scan (scan_design), font uploads, recolourable shapes, repeatable rows and movable elements — and this update check.';

    /** A stable download of the latest released skill folder, built by `.github/workflows/plugin-release.yml`. */
    public const string ZIP_URL = 'https://github.com/TheDevs-cz/wboost/releases/latest/download/wboost-skill.zip';

    /** Claude Code: refresh the marketplace clone, then update the installed plugin. */
    public const string UPDATE_CLAUDE_CODE = 'run `claude plugin marketplace update wboost && claude plugin update wboost@wboost` and restart Claude Code (inside a session: /plugin marketplace update wboost, then update wboost under /plugin → Installed and run /reload-plugins); /plugin → Marketplaces → wboost → Enable auto-update keeps it current from then on';

    /** Claude Code without the plugin yet. */
    public const string INSTALL_CLAUDE_CODE = 'run `claude plugin marketplace add TheDevs-cz/wboost && claude plugin install wboost@wboost` and restart Claude Code';

    /** claude.ai / Claude Desktop: an uploaded skill never updates itself — delete and re-upload. */
    public const string UPDATE_CLAUDE_AI = 'download ' . self::ZIP_URL . ', delete the old "wboost" skill in Settings → Capabilities → Skills and upload the new zip';

    public const string STATUS_CURRENT = 'current';

    public const string STATUS_OUTDATED = 'outdated';

    public const string STATUS_UNKNOWN = 'unknown';

    /**
     * The answer to "is the skill this client runs current?". `$installed` is
     * whatever the client sent; anything that is not a version string counts
     * as "not sent" — a client without the skill, or with a skill older than
     * the check (0.2.0 and before never send one).
     */
    public static function check(null|string $installed): SkillVersionResponse
    {
        $installed = $installed === null ? '' : trim($installed);

        if (preg_match('/^\d+\.\d+\.\d+$/', $installed) !== 1) {
            return new SkillVersionResponse(
                latestVersion: self::VERSION,
                installedVersion: null,
                status: self::STATUS_UNKNOWN,
                message: sprintf(
                    'No skillVersion was sent. If a skill named "wboost" is available to you, load it now and follow it — do not tell the user anything about versions. Only if no wboost skill is available at all, tell the user once in this session — after finishing what they asked, never instead of it — that the wboost skill %s teaches these tools and how to get it: Claude Code: %s; claude.ai: %s.',
                    self::VERSION,
                    self::INSTALL_CLAUDE_CODE,
                    self::UPDATE_CLAUDE_AI,
                ),
                whatsNew: self::WHATS_NEW,
                updateClaudeCode: self::UPDATE_CLAUDE_CODE,
                updateClaudeAi: self::UPDATE_CLAUDE_AI,
            );
        }

        if (version_compare($installed, self::VERSION, '>=')) {
            return new SkillVersionResponse(
                latestVersion: self::VERSION,
                installedVersion: $installed,
                status: self::STATUS_CURRENT,
                message: null,
                whatsNew: null,
                updateClaudeCode: null,
                updateClaudeAi: null,
            );
        }

        return new SkillVersionResponse(
            latestVersion: self::VERSION,
            installedVersion: $installed,
            status: self::STATUS_OUTDATED,
            message: sprintf(
                'The wboost skill in use is %s; %s is out. Keep working with the tools as they are described here, and tell the user ONCE in this session — after finishing what they asked — what is new and how to update: Claude Code: %s; claude.ai: %s.',
                $installed,
                self::VERSION,
                self::UPDATE_CLAUDE_CODE,
                self::UPDATE_CLAUDE_AI,
            ),
            whatsNew: self::WHATS_NEW,
            updateClaudeCode: self::UPDATE_CLAUDE_CODE,
            updateClaudeAi: self::UPDATE_CLAUDE_AI,
        );
    }

    /**
     * The line that opens the server `instructions`, so a client with no skill
     * (or one too old to send its version) still learns that one exists. Short
     * on purpose: Claude Code cuts the instructions at 4096 characters, and the
     * update commands travel in the tool answer instead.
     */
    public static function instructions(): string
    {
        return sprintf(
            'wboost skill: the current version is %s. If a skill named "wboost" is available to you, load it before your first wboost call and pass the version it states as skillVersion to get_context or list_calendars. When skill.status is "outdated", tell the user skill.message once per session, after doing what they asked - never instead of it. Only when no wboost skill is available at all, mention once how to get it (skill.message).',
            self::VERSION,
        );
    }
}
