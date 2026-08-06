<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use WBoost\Web\Mcp\Design\Dsl\DesignDocument;

/**
 * What {@see DesignDecompiler} produces: the design as a DSL document, the
 * slug ↔ `inputId` correspondence that document implies, and the honest list
 * of everything the DSL could not carry.
 *
 * ## The three parts are one unit and must travel together
 *
 * - {@see $document} is what `get_design` shows and what the agent edits.
 * - {@see $inputIdsBySlug} is what makes editing SAFE. `set_design` replaces
 *   the whole design; the compiler maps each slug back onto the UUID it
 *   already means, and this is that map — authoritative because it is the
 *   mapping the agent was SHOWN. Hand it to {@see DesignIdentity::fromMap()};
 *   {@see identity()} does exactly that.
 * - {@see $losses} is what stops the agent from destroying a designer's work
 *   without knowing. A caller that drops this list turns a truthful tool into
 *   a silent shredder — see {@see DesignLoss}.
 *
 * `#[Exclude]`: a value, never a service.
 */
#[Exclude]
readonly final class DecompiledDesign
{
    /**
     * @param array<string, string> $inputIdsBySlug every element slug that
     *        addresses an existing input, mapped to its `inputId` UUID. Slugs
     *        for elements the canvas gave no id (a decorative image that never
     *        had one) are absent — the compiler mints for those.
     * @param list<DesignLoss> $losses in canvas order: document-level first,
     *        then per object, then per container
     */
    public function __construct(
        public DesignDocument $document,
        public array $inputIdsBySlug,
        public array $losses,
    ) {
    }

    /**
     * The correspondence as {@see DesignCompiler::compile()} wants it.
     */
    public function identity(): DesignIdentity
    {
        return DesignIdentity::fromMap($this->inputIdsBySlug);
    }

    /**
     * Did the DSL carry everything? True means a `set_design` of
     * {@see $document} loses nothing.
     */
    public function isLossless(): bool
    {
        return $this->losses === [];
    }

    /**
     * The losses that a `set_design` would actually DESTROY, as opposed to the
     * ones the DSL merely cannot address (see {@see DesignLoss::$destructive}).
     * This is the list a warning should lead with.
     *
     * @return list<DesignLoss>
     */
    public function destructiveLosses(): array
    {
        return array_values(array_filter(
            $this->losses,
            static fn (DesignLoss $loss): bool => $loss->destructive,
        ));
    }

    /**
     * @return list<array{path: string, code: string, message: string, destructive: bool}>
     */
    public function lossesToArray(): array
    {
        return array_map(static fn (DesignLoss $loss): array => $loss->toArray(), $this->losses);
    }
}
