<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * The `skill` field of `get_context` and `list_calendars`: whether the wboost
 * skill the client runs is the current release — see
 * {@see \WBoost\Web\Mcp\Skill\SkillRelease}.
 *
 * `status`: `current` (nothing to say), `outdated` (tell the user once, with
 * `message`), `unknown` (no version sent — a client without the skill, or with
 * one older than this check). Never a reason to stop working.
 */
readonly final class SkillVersionResponse
{
    public function __construct(
        public string $latestVersion,
        public null|string $installedVersion,
        public string $status,
        public null|string $message,
        public null|string $whatsNew,
        public null|string $updateClaudeCode,
        public null|string $updateClaudeAi,
    ) {
    }
}
