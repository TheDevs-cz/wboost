<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design\Dsl;

/**
 * Anything that can appear in `elements[]`.
 *
 * Every element carries a document-unique, agent-chosen **slug id** (plan
 * §3.4). That id is the whole editing story: `set_design` maps a slug to the
 * `inputId` UUID an existing variant already uses, so replacing the full
 * document keeps input identity — and with it the fills, the API contract and
 * the container membership — stable.
 */
interface DesignElement
{
    /** Document-unique slug: `[a-z0-9][a-z0-9_-]*`, ≤ 64 chars. */
    public string $id { get; }

    public function kind(): ElementKind;

    /**
     * The element as wire-format DSL. Canonical: every optional key is emitted
     * with its resolved value, so `parse(toArray(parse($x)))` is the identity.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
