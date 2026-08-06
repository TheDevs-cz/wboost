<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\EditorTextInput;

/**
 * What a slug already means on the variant being replaced: `slug → inputId`.
 *
 * ## Why this exists at all
 *
 * `set_design` replaces the WHOLE design (plan §0.4 — no patch operations). If
 * every compile minted fresh `inputId`s, then every edit — moving one headline
 * 20 px — would hand the variant a new set of ids, and with them: every saved
 * fill would stop resolving, every API consumer's `inputs[].id` would 404, and
 * every container's `memberInputIds` would point at nothing. Slug stability is
 * the entire reason the DSL has agent-chosen ids instead of positions.
 *
 * So: a slug this map knows keeps its UUID; anything else gets a fresh **v4**.
 *
 * ## v4, not v7
 *
 * `ProvideIdentity::next()` is UUID **v7** and is right for entity ids, where
 * time-ordering buys index locality. `inputId` is neither an entity id nor
 * sorted by anything, and every existing minting site produces v4 — the admin
 * editor's `crypto.randomUUID()`, {@see EditorTextInput::fromArray()}'s
 * defensive fallback, {@see \WBoost\Web\Services\Editor\BackgroundLayer::buildObject()}.
 * Production agrees: every `inputId` in the database is v4. Plan §4.1
 * invariant 2 says v4, and matching the data beats matching a house rule
 * written for a different kind of id.
 *
 * `#[Exclude]`: a value, never a service.
 */
#[Exclude]
readonly final class DesignIdentity
{
    /**
     * @param array<string, string> $inputIdsBySlug
     */
    private function __construct(
        private array $inputIdsBySlug,
    ) {
    }

    /**
     * Nothing is known — every slug mints. The create-from-scratch case
     * (`add_variant` then the first `set_design`).
     */
    public static function fresh(): self
    {
        return new self([]);
    }

    /**
     * An explicit correspondence, for a caller that derived it some other way —
     * notably S5-T3 holding the decompiler's own slug↔`inputId` mapping, which
     * is authoritative because it is the mapping the agent was SHOWN.
     *
     * Empty slugs and empty ids are dropped rather than trusted: this map's job
     * is to preserve identity, and an entry that cannot address anything would
     * only preserve a bug.
     *
     * @param array<string, string> $inputIdsBySlug
     */
    public static function fromMap(array $inputIdsBySlug): self
    {
        $clean = [];

        foreach ($inputIdsBySlug as $slug => $inputId) {
            if ($slug !== '' && $inputId !== '') {
                $clean[$slug] = $inputId;
            }
        }

        return new self($clean);
    }

    /**
     * The correspondence implied by a variant's persisted inputs, naming each
     * one through the shared {@see DesignSlug} rule.
     *
     * **The walk order is part of the contract**: text inputs in `inputs[]`
     * order first, then image inputs in `imageInputs[]` order, with duplicate
     * names disambiguated by {@see DesignSlug::unique()} in that walk. Two
     * inputs may legitimately share a name (that is why binding moved to
     * `inputId` in the first place), so *somebody* has to decide which of them
     * keeps the bare slug; deciding it here, once, is what stops the answer
     * from depending on who asked.
     *
     * @param array<EditorTextInput> $textInputs
     * @param array<EditorImageInput> $imageInputs
     */
    public static function fromInputs(array $textInputs, array $imageInputs): self
    {
        $map = [];

        foreach ($textInputs as $input) {
            $slug = DesignSlug::unique(DesignSlug::fromName($input->name, 'text'), $map);
            $map[$slug] = $input->inputId;
        }

        foreach ($imageInputs as $input) {
            $fallback = $input->isBackground ? 'background' : 'image';
            $slug = DesignSlug::unique(DesignSlug::fromName($input->name, $fallback), $map);
            $map[$slug] = $input->inputId;
        }

        return self::fromMap($map);
    }

    /**
     * The UUID this slug already carries, or null when it is new.
     */
    public function existing(string $slug): null|string
    {
        return $this->inputIdsBySlug[$slug] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->inputIdsBySlug;
    }
}
