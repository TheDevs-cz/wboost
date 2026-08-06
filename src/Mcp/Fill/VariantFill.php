<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Fill;

use Mcp\Exception\ToolCallException;
use Mcp\Schema\Content\TextContent;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Exceptions\ContainerOverflow;
use WBoost\Web\Exceptions\InvalidRichTextValue;
use WBoost\Web\Exceptions\TemplateVariantNotFound;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\Security\TemplateVariantVoter;
use WBoost\Web\Services\SocialNetwork\ResolveImageOverrides;
use WBoost\Web\Services\SocialNetwork\ResolveRichTextOptions;
use WBoost\Web\Services\SocialNetwork\ResolveTextOverrides;
use WBoost\Web\Value\CanvasContainer;
use WBoost\Web\Value\EditorTextInput;
use WBoost\Web\Value\ResolvedImageOverrides;
use WBoost\Web\Value\ResolvedInputOverrides;

/**
 * Everything `render_variant` and `export_variant` do IDENTICALLY: find the
 * variant, resolve `{inputs, images}` into renderer overrides, and phrase every
 * refusal in words an agent can act on.
 *
 * The two tools differ only in what they do with the picture afterwards — one
 * returns a cheap downscaled WebP and tolerates container overflow, the other
 * returns the full-size lossless PNG and refuses it. Everything BEFORE that fork
 * is a contract, not an implementation detail: the fill vocabulary is
 * byte-identical to the REST {@see \WBoost\Web\Api\Templates\ExportRequest}, so
 * a value that previews must also export, and a value the export rejects must
 * have been rejected by the preview. Two copies of those rules would drift
 * apart exactly once and then quietly lie to every agent using the pair.
 *
 * ## The refusal wordings are the reason this is one class
 *
 * - "not found or not yours" is worded ONCE ({@see notFound()}): a
 *   distinguishable "exists, but not yours" would turn any token into an
 *   id-probing oracle, and two tools disagreeing about that would leak it from
 *   whichever one was written second.
 * - the structured rich-text violations keep their REST wording plus their
 *   machine code and their context (the allowed font list above all — that is
 *   what makes the message actionable rather than merely accurate).
 * - {@see containerOverflowMessage()} turns a container id and a pixel count
 *   into a sentence naming the INPUTS an agent can shorten.
 *
 * Not a value object: it holds the repository, the voter and the three
 * resolvers, and is an ordinary autowired service.
 */
readonly final class VariantFill
{
    public function __construct(
        private Security $security,
        private TemplateVariantRepository $templateVariantRepository,
        private ResolveTextOverrides $resolveTextOverrides,
        private ResolveRichTextOptions $resolveRichTextOptions,
        private ResolveImageOverrides $resolveImageOverrides,
    ) {
    }

    /**
     * The variant, or the one refusal an MCP tool ever gives for a variant id.
     */
    public function variant(string $variantId): TemplateVariant
    {
        if (!Uuid::isValid($variantId)) {
            // NOT folded into notFound(): a string that cannot be a variant id
            // reveals nothing about which variants exist, and telling the agent
            // it sent a template id (or a name) where a variant id belongs is
            // the difference between a fixable mistake and a silent dead end.
            throw new ToolCallException(sprintf(
                '"%s" is not a valid template variant id. Variant ids are UUIDs; call find_templates to list the ones this account can reach.',
                $variantId,
            ));
        }

        try {
            $variant = $this->templateVariantRepository->get(Uuid::fromString($variantId));
        } catch (TemplateVariantNotFound) {
            throw self::notFound($variantId);
        }

        if (!$this->security->isGranted(TemplateVariantVoter::VIEW, $variant)) {
            throw self::notFound($variantId);
        }

        return $variant;
    }

    /**
     * Text overrides, resolved STRICTLY (`truncateOverflow: false`) — the API
     * export's setting, not the web fill page's. A preview that silently cut an
     * over-long value would hand the agent a picture of a fill the export then
     * refuses; an export that cut it would hand the user a deliverable they did
     * not ask for.
     *
     * @param array<string, mixed> $providedInputs
     */
    public function texts(TemplateVariant $variant, array $providedInputs): ResolvedInputOverrides
    {
        try {
            return $this->resolveTextOverrides->resolve(
                $variant->inputs,
                $providedInputs,
                richTextOptions: $this->resolveRichTextOptions->forVariant($variant),
            );
        } catch (InvalidRichTextValue $invalidRichText) {
            throw new ToolCallException(self::withContext(
                sprintf('%s (%s)', $invalidRichText->getMessage(), $invalidRichText->errorCode),
                $invalidRichText->context,
            ));
        } catch (BadRequestHttpException $badRequest) {
            // maxLength — already phrased for a human ("Input "Nadpis" exceeds
            // max length of 24 characters."), so it is passed through as-is.
            throw new ToolCallException($badRequest->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $providedImages
     */
    public function images(TemplateVariant $variant, array $providedImages): ResolvedImageOverrides
    {
        try {
            return $this->resolveImageOverrides->resolve(
                $variant->imageInputs,
                $variant->template->project->id,
                $providedImages,
            );
        } catch (BadRequestHttpException $badRequest) {
            // "cannot be rotated", "is not available for this placeholder", …
            throw new ToolCallException($badRequest->getMessage());
        }
    }

    /**
     * The refusal, worded once. Both callers — "no such row" and "not yours" —
     * must produce a byte-identical message; see the class docblock.
     */
    public static function notFound(string $variantId): ToolCallException
    {
        return new ToolCallException(sprintf(
            'Template variant %s was not found, or this account cannot access it. Call find_templates to list the variants of a project this account can reach.',
            $variantId,
        ));
    }

    /**
     * Gotenberg is a synchronous dependency shared with every fill page and
     * export in the app; "busy" is a retry, not a defect in the request.
     */
    public static function rendererBusy(): ToolCallException
    {
        return new ToolCallException(
            'The image renderer is busy and did not answer in time. Nothing was changed — try the same call again in a few seconds.',
        );
    }

    /**
     * Ids the caller addressed that no input on this variant answers to.
     *
     * The resolvers ignore unknown ids in silence (the REST contract, so that a
     * consumer's stale id cannot break an otherwise valid export). For an agent
     * silence is the worst answer available: a fill keyed by NAMES instead of
     * ids renders the untouched design and looks like the tool ignored the
     * request. Locked inputs are called out for the same reason — they are
     * addressable-looking and unwritable.
     *
     * @param array<string, mixed> $providedInputs
     * @param array<string, mixed> $providedImages
     *
     * @return list<string>
     */
    public static function warnings(TemplateVariant $variant, array $providedInputs, array $providedImages): array
    {
        $warnings = [];

        $known = [];
        $locked = [];

        foreach ($variant->inputs as $input) {
            $known[$input->inputId] = true;

            if ($input->locked) {
                $locked[$input->inputId] = $input->name ?? $input->inputId;
            }
        }

        $unknown = array_values(array_diff(array_keys($providedInputs), array_keys($known)));

        if ($unknown !== []) {
            $warnings[] = sprintf(
                'These inputs ids match no text input on this variant and were ignored: %s. Text inputs are addressed by describe_variant inputs[].id, never by name.',
                implode(', ', $unknown),
            );
        }

        $addressedLocked = array_values(array_intersect_key($locked, $providedInputs));

        if ($addressedLocked !== []) {
            $warnings[] = sprintf(
                'These inputs are locked by the designer and keep their designed text: %s.',
                implode(', ', $addressedLocked),
            );
        }

        $knownImages = [];

        foreach ($variant->imageInputs as $imageInput) {
            $knownImages[$imageInput->inputId] = true;
        }

        $unknownImages = array_values(array_diff(array_keys($providedImages), array_keys($knownImages)));

        if ($unknownImages !== []) {
            $warnings[] = sprintf(
                'These image slot ids match no image placeholder on this variant and were ignored: %s. Image slots are addressed by describe_variant imageInputs[].id.',
                implode(', ', $unknownImages),
            );
        }

        return $warnings;
    }

    /**
     * Re-keys a decoded JSON object to string keys.
     *
     * `json_decode(..., true)` turns a numeric-looking property name into an
     * INT key, and both resolvers look their ids up with
     * `array_key_exists('<uuid>', …)` — a mismatch that cannot happen with real
     * UUID keys but would silently drop a caller's value if one ever did. Doing
     * it once here also gives {@see warnings()} a shape it can compare.
     *
     * @param array<array-key, mixed> $provided
     *
     * @return array<string, mixed>
     */
    public static function stringKeyed(array $provided): array
    {
        $result = [];

        foreach ($provided as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }

    /**
     * A structured-error message with its context appended as `key: value`
     * pairs, so an agent reading only the sentence still learns the allowed
     * fonts rather than being told to guess.
     *
     * @param array<string, mixed> $context
     */
    private static function withContext(string $message, array $context): string
    {
        $parts = [];

        foreach ($context as $key => $value) {
            $parts[] = sprintf('%s: %s', $key, is_array($value)
                ? implode(', ', array_map(static fn (mixed $item): string => self::scalarToString($item), $value))
                : self::scalarToString($value));
        }

        return $parts === [] ? $message : sprintf('%s Allowed values — %s.', $message, implode('; ', $parts));
    }

    private static function scalarToString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR);
    }

    /**
     * The JSON summary block that travels in front of an image tool's picture,
     * encoded with the same flags the SDK's own formatter uses for a returned
     * DTO — so a hand-built {@see \Mcp\Schema\Result\CallToolResult} reads
     * exactly like every other tool's reply.
     */
    public static function summary(object $summary): TextContent
    {
        return new TextContent(json_encode(
            $summary,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
        ));
    }

    /**
     * {@see containerOverflowMessage()} for one variant: its canvas holds the
     * container definitions, its `inputs` column the names.
     *
     * A canvas that will not decode degrades to "no containers", which the
     * message builder answers with its id-only wording — an overflow must still
     * be REPORTED when the design is unreadable, never swallowed.
     */
    public static function overflowFor(TemplateVariant $variant, ContainerOverflow $overflow): string
    {
        $decoded = json_decode($variant->canvas, true);

        return self::containerOverflowMessage(
            is_array($decoded) ? CanvasContainer::collectionFromCanvas($decoded) : [],
            $variant->inputs,
            $overflow,
        );
    }

    /**
     * A container overflow, said in terms of the thing an agent can change.
     *
     * {@see ContainerOverflow} carries a container id and a pixel count, and
     * neither is actionable on its own: the id is a UUID the agent has probably
     * never seen, and "12 px" says nothing about WHICH text to shorten. What it
     * can change is the fill — so the message names the container's fillable
     * TEXT INPUTS, walking the nesting tree (overflow is always reported on the
     * ROOT container, whose members may sit several levels down).
     *
     * **No character estimate.** The plan sketched "shorten it to ~90
     * characters"; that number cannot be derived here. Overflow is measured in
     * pixels of WRAPPED text, and only Fabric inside headless Chromium knows how
     * many characters fit a line — the server has no measurement of the current
     * line count to divide by. A made-up number would be worse than none: an
     * agent would trust it, cut to it, and be refused again. So the message
     * states the pixels exactly and names what to shorten.
     *
     * Three shapes, by how much can honestly be said:
     * - exactly one fillable member → name it, singular;
     * - several → list them and refuse to guess which one is at fault;
     * - none locatable (unknown id, or a container whose members are all
     *   decorative / design-hidden) → say so, and point at describe_variant or
     *   the designer.
     *
     * Pure and static so it can be unit-tested without a kernel — the
     * {@see \WBoost\Web\Services\SocialNetwork\PlaceholderAllowedDirectories::effectiveIds()}
     * pattern.
     *
     * @param list<CanvasContainer> $containers container definitions of the variant's canvas
     * @param array<EditorTextInput> $inputs the variant's listed (fillable) text inputs
     */
    public static function containerOverflowMessage(
        array $containers,
        array $inputs,
        ContainerOverflow $overflow,
    ): string {
        $overflowPx = self::px($overflow->overflowPx);
        $containerId = $overflow->containerId;
        $definition = null;

        foreach ($containers as $candidate) {
            if ($candidate->id === $containerId) {
                $definition = $candidate;
                break;
            }
        }

        if ($definition === null) {
            return sprintf(
                'The content of container %s overflows its max height by %s px. Call describe_variant to see which inputs belong to that container, then shorten their texts, hide a hidable one, or have the designer raise the container maxHeight in the wboost editor.',
                $containerId ?? '(unknown)',
                $overflowPx,
            );
        }

        $members = self::fillableMembers($definition, $containers, $inputs);
        $maxHeight = self::px($definition->maxHeight);

        if ($members === []) {
            return sprintf(
                'The content of container %s overflows its max height of %s px by %s px, but none of its members is a fillable text input — the overflow comes from the design itself. Have the designer raise the container maxHeight in the wboost editor.',
                $definition->id,
                $maxHeight,
                $overflowPx,
            );
        }

        if (count($members) === 1) {
            return sprintf(
                'Input %s overflows its container by %s px: container %s allows %s px of content and the filled text needs more. Shorten that text, hide the input if describe_variant reports it hidable, or have the designer raise the container maxHeight in the wboost editor.',
                self::label($members[0]),
                $overflowPx,
                $definition->id,
                $maxHeight,
            );
        }

        return sprintf(
            'The texts of container %s overflow its max height of %s px by %s px. Its fillable text inputs are %s — shorten one of them, hide a hidable one, or have the designer raise the container maxHeight in the wboost editor. Which one is too long cannot be told apart here: they share one vertical flow.',
            $definition->id,
            $maxHeight,
            $overflowPx,
            implode(', ', array_map(static fn (EditorTextInput $input): string => self::label($input), $members)),
        );
    }

    /**
     * Every FILLABLE text input in a container's tree — its own members plus,
     * recursively, its children's.
     *
     * Narrowed to the variant's listed inputs on purpose, exactly as
     * {@see \WBoost\Web\Value\ResolvedCanvasContainer::collection()} narrows
     * them: `memberInputIds` also carries decorative images and design-hidden
     * texts, and neither is something an agent can shorten.
     *
     * @param list<CanvasContainer> $containers
     * @param array<EditorTextInput> $inputs
     *
     * @return list<EditorTextInput>
     */
    private static function fillableMembers(
        CanvasContainer $container,
        array $containers,
        array $inputs,
    ): array {
        $byId = [];

        foreach ($inputs as $input) {
            $byId[$input->inputId] = $input;
        }

        $definitions = [];

        foreach ($containers as $candidate) {
            $definitions[$candidate->id] = $candidate;
        }

        /** @var list<EditorTextInput> $members */
        $members = [];
        /** @var array<string, true> $seenContainers */
        $seenContainers = [];
        /** @var array<string, true> $seenInputs */
        $seenInputs = [];

        $queue = [$container];

        while ($queue !== []) {
            $current = array_shift($queue);

            // A cycle cannot be authored (the editor forbids it and the layout
            // engine guards defensively), but a hand-edited canvas is still a
            // canvas — and hanging on a render REFUSAL would be a bad way to
            // find out.
            if (isset($seenContainers[$current->id])) {
                continue;
            }

            $seenContainers[$current->id] = true;

            foreach ($current->memberInputIds as $memberInputId) {
                if (isset($seenInputs[$memberInputId]) || !isset($byId[$memberInputId])) {
                    continue;
                }

                $seenInputs[$memberInputId] = true;
                $members[] = $byId[$memberInputId];
            }

            foreach ($current->memberContainerIds as $childId) {
                $child = $definitions[$childId] ?? null;

                if ($child !== null) {
                    $queue[] = $child;
                }
            }
        }

        return $members;
    }

    /**
     * An input as an agent can address it: its name for the human reading the
     * message, its id for the call that fixes it. An unnamed input has only the
     * id, and saying `"" (id …)` would read as a bug.
     */
    private static function label(EditorTextInput $input): string
    {
        return $input->name !== null && $input->name !== ''
            ? sprintf('"%s" (id %s)', $input->name, $input->inputId)
            : sprintf('(unnamed, id %s)', $input->inputId);
    }

    /**
     * One decimal, trailing zeros dropped: `42.5`, `700`. Pixel counts here are
     * measurements, not identifiers — `700.0 px` reads like a rounding artefact
     * and `42 px` would hide the half-pixel that actually failed the export.
     */
    private static function px(float $value): string
    {
        $formatted = sprintf('%.1f', $value);

        return str_ends_with($formatted, '.0') ? substr($formatted, 0, -2) : $formatted;
    }
}
