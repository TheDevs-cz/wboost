<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Everything project-specific {@see DesignCompiler} needs, gathered up front so
 * the compile itself is a **pure function**.
 *
 * That split is deliberate and it is what makes the accuracy core testable.
 * `DslParser` is context-free on purpose (it cannot know whether a font is
 * real); the compiler is the first stage that DOES know the project, which is
 * where plan §4.2 invariant 10 (unknown font → hard error) and invariant 9
 * (`src` + `assetPath`) live. Handing that knowledge in as data — rather than
 * letting the compiler reach for a repository — means every invariant in §4 is
 * asserted by a plain `TestCase` with no kernel, no database and no Minio.
 * {@see CompilationContextFactory} is the one place that talks to either.
 *
 * `#[Exclude]`: a value, never a service.
 */
#[Exclude]
readonly final class CompilationContext
{
    /**
     * @param list<string> $allowedFonts Exact face strings, as
     *        {@see \WBoost\Web\Entity\Font::faceFamily()} builds them and
     *        `get_context` reports them: `"Rubik (Rubik Bold)"`. A text
     *        element's `font` must be one of these BYTE FOR BYTE — the render
     *        template registers its `@font-face` under exactly this name, so a
     *        near-miss does not fall back visibly, it renders in a substitute
     *        face nobody asked for.
     * @param array<string, DesignAsset> $assets Gallery pictures the document
     *        references, keyed by their `FileUpload` UUID. Only the referenced
     *        ones: a project library of a thousand images has no business being
     *        read to compile a design that uses two. An id the factory could
     *        not resolve is simply ABSENT here, and the compiler turns that
     *        absence into the violation — one error site, one wording.
     */
    public function __construct(
        public array $allowedFonts,
        public array $assets,
    ) {
    }

    /**
     * A context for a project with no fonts and no assets. Only meaningful for
     * a document that references neither; anything else compiles straight into
     * violations, which is the honest outcome.
     */
    public static function empty(): self
    {
        return new self([], []);
    }

    public function allowsFont(string $family): bool
    {
        return in_array($family, $this->allowedFonts, true);
    }

    public function asset(string $assetId): null|DesignAsset
    {
        return $this->assets[$assetId] ?? null;
    }
}
