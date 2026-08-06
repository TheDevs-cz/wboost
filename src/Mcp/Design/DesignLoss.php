<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * One thing an existing canvas contains that DSL v1 cannot carry, addressed by
 * the same path vocabulary {@see CompileViolation} and
 * {@see \WBoost\Web\Mcp\Design\Dsl\DslViolation} use — except that the path
 * points at the DECOMPILED document (`elements[3].font`) or, when the thing
 * has no element at all, at where it lived on the canvas
 * (`canvas.objects[2]`).
 *
 * ## Why this exists rather than a refusal
 *
 * See {@see DesignDecompiler}: almost every real variant was authored in the
 * browser and contains something DSL v1 has no word for. Refusing them would
 * make `get_design` useless on the templates people actually have. Decompiling
 * them silently would let an agent delete a designer's work while reporting
 * success. So the decompilation always succeeds and always ships the list —
 * `get_design` renders it as a warning, and an agent that reads it knows
 * before it writes.
 *
 * `#[Exclude]`: a value, never a service.
 */
#[Exclude]
readonly final class DesignLoss
{
    /**
     * @param string $path dotted/bracketed path — into the decompiled document
     *        where there is an element for it, otherwise into the canvas
     *        document (`canvas.objects[2]`, `canvas.containers[0]`)
     * @param string $message human (English) sentence, already containing the
     *        path so it reads standalone in a joined list; ends with a full stop
     * @param bool $destructive TRUE when writing the decompiled document back
     *        through `set_design` would DESTROY the thing described (the normal
     *        case). FALSE when the DSL merely cannot address it and it survives
     *        untouched — today only the canvas-level background of a legacy
     *        {@see \WBoost\Web\Value\BackgroundMode::Canvas} variant, which
     *        lives on the variant row and is re-applied by the renderer.
     */
    public function __construct(
        public string $path,
        public DesignLossCode $code,
        public string $message,
        public bool $destructive = true,
    ) {
    }

    /**
     * @return array{path: string, code: string, message: string, destructive: bool}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'code' => $this->code->value,
            'message' => $this->message,
            'destructive' => $this->destructive,
        ];
    }
}
