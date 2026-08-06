<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Exceptions\ContainerOverflow;
use WBoost\Web\Exceptions\InvalidRichTextValue;
use WBoost\Web\Exceptions\TemplateRenderUnavailable;
use WBoost\Web\Exceptions\TemplateVariantNotFound;
use WBoost\Web\Mcp\Response\RenderVariantResponse;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpToolScope;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Services\Image\DownscaleImage;
use WBoost\Web\Services\Security\TemplateVariantVoter;
use WBoost\Web\Services\SocialNetwork\ResolveImageOverrides;
use WBoost\Web\Services\SocialNetwork\ResolveRichTextOptions;
use WBoost\Web\Services\SocialNetwork\ResolveTextOverrides;
use WBoost\Web\Value\RenderImageFormat;
use WBoost\Web\Value\ResolvedImageOverrides;
use WBoost\Web\Value\ResolvedInputOverrides;

/**
 * `render_variant` — the cheap look at a filled variant.
 *
 * An agent iterating on wording wants to SEE the result after every edit. That
 * loop is what this tool is shaped around, and it is the reason it exists
 * separately from `export_variant`: the deliverable is a full-size lossless PNG
 * (2480 × 3508 for an A4 page), and paying for one of those per phrasing
 * attempt is wasteful in latency and — because the picture rides base64-encoded
 * inside the JSON-RPC reply and then stays in the client's context window —
 * ruinous in tokens. So this one is deliberately worse in every dimension that
 * does not affect judging a layout: lossy WebP, bounded at
 * {@see MAX_LONG_EDGE} px on its long edge ({@see DownscaleImage}), and NOT
 * recorded as an export — a preview render is not a delivered file, and
 * `/admin/usage` must keep counting what users actually took away.
 *
 * ## Same fill vocabulary as the REST export, deliberately
 *
 * `inputs` and `images` accept the exact shapes
 * {@see \WBoost\Web\Api\Templates\ExportRequest} documents, resolved through
 * the very same {@see ResolveTextOverrides} / {@see ResolveImageOverrides}
 * services, and keyed by the ids `describe_variant` publishes. An agent can
 * therefore move a fill between this tool, `export_variant` and the REST API
 * without translating anything — and a fill that previews correctly cannot then
 * be rejected by the export for a reason this call did not surface. Which is
 * also why maxLength, the rich-text whitelist and the per-slot image limits are
 * enforced STRICTLY here: being forgiving would only postpone the failure to
 * the one call where it costs the user their deliverable.
 *
 * ## Container overflow: lenient here, and how it is detected at all
 *
 * Overflow is the ONE contract this tool relaxes. `export_variant` refuses an
 * overflowing fill; here the picture comes back showing the overflow, with a
 * warning naming the container and the pixels, so the agent can shorten the
 * text and try again instead of being left with nothing to look at.
 *
 * That costs a subtlety worth stating plainly, because the call sequence looks
 * backwards: the render below is issued STRICT first. Overflow can only be
 * measured by Fabric inside headless Chromium, and the only channel a
 * screenshot response has for it is the strict path's uncaught console
 * exception, which Gotenberg turns into an error body and the renderer parses
 * into {@see ContainerOverflow} (there is no lenient equivalent — a lenient
 * render returns pixels and nothing else). So: strict attempt, and on overflow
 * a second, lenient render supplies the picture. The happy path is exactly one
 * render; only an already-broken fill pays for two. A strict render also fails
 * on unrelated console errors that the lenient path tolerates on purpose (a
 * corrupt font face, say — see the renderer's `failOnConsoleExceptions`
 * comment), so ANY strict failure falls back to the lenient render rather than
 * letting this tool be pickier than the fill page.
 *
 * ## Why the reply is two content blocks
 *
 * The other read tools return a DTO and the SDK encodes it into a single
 * {@see TextContent}. An image cannot travel that way — a real MCP client
 * renders an {@see ImageContent} block and would show a base64 blob smuggled
 * into text as gibberish — so this one returns a {@see CallToolResult} itself,
 * carrying the JSON summary AND the picture. The summary comes first: it says
 * what the image is, at what size, and what is wrong with it, which is the
 * context the model wants before looking.
 *
 * ## Authorisation
 *
 * {@see TemplateVariantVoter::VIEW}, and the same anti-enumeration rule as
 * `describe_variant`: a variant the caller may not see reports the SAME failure
 * as an id that matches no row (one {@see notFound()} factory, one wording).
 */
#[McpToolScope(McpScope::TemplatesRead)]
readonly final class RenderVariantTool
{
    /**
     * Bound on the returned picture's longer side.
     *
     * Chosen against the real variant sizes: every Instagram preset (1080 px on
     * its long edge for 1:1 and 4:5) passes through untouched, 9:16 and every
     * print dimension are scaled. Large enough to read a headline and judge a
     * composition, small enough that the base64 payload stays in the tens of
     * KB — the transport's own request cap is 4 MiB, which is a fair hint at
     * the scale this protocol expects a message to be.
     */
    private const int MAX_LONG_EDGE = 1200;

    /**
     * The preview format. Not defaulted anywhere: {@see RenderImageFormat}
     * defaults to PNG on purpose (the export, ZIP and publish paths depend on
     * it), so WebP is stated explicitly at every call below.
     */
    private const RenderImageFormat FORMAT = RenderImageFormat::Webp;

    public function __construct(
        private Security $security,
        private TemplateVariantRepository $templateVariantRepository,
        private TemplateVariantImageRendererInterface $renderer,
        private ResolveTextOverrides $resolveTextOverrides,
        private ResolveRichTextOptions $resolveRichTextOptions,
        private ResolveImageOverrides $resolveImageOverrides,
        private DownscaleImage $downscaleImage,
    ) {
    }

    /**
     * Renders ONE variant filled with the values you provide and returns the
     * result as an image. This is the FAST LOOK: a lossy WebP scaled down to at
     * most 1200 px on its long edge, cheap enough to call after every wording
     * change. It is not the deliverable — when the text is right, call
     * export_variant for the full-size lossless PNG.
     *
     * Call describe_variant first. inputs and images are keyed by the ids it
     * reports, and every constraint enforced here is published there.
     *
     * inputs maps a text input id to its value: a plain string, or
     * {"value": "...", "hide": true} to blank a hidable input, or — for an
     * input describe_variant marks richText — {"runs": [{"text": "...",
     * "fontFamily": null, "color": null, "underline": false}], "lines":
     * ["p", "ul"]}. Leave an id out and the designer's sampleValue renders;
     * send an empty string and nothing renders for it. Locked inputs cannot be
     * written. maxLength, the allowed fonts and the list permissions are
     * enforced exactly as export_variant enforces them, so a value that is
     * refused here would have been refused there.
     *
     * images maps an image slot id to a gallery image id from list_gallery, or
     * to {"imageId": "...", "scale": 1, "offsetX": 0, "offsetY": 0,
     * "rotation": 0} to place it, or to {"hide": true} to blank a hidable slot.
     * A slot that forbids moving, resizing or rotating rejects that adjustment.
     * Slots you do not fill keep the designer's stand-in picture.
     *
     * Text that overflows its container is REPORTED here, not refused: the
     * picture comes back showing the overflow and warnings says which container
     * and by how many pixels. export_variant refuses that same fill, so treat
     * the warning as work to do first — shorten the texts of that container, or
     * hide one of its inputs.
     *
     * The reply is a JSON summary followed by the image. width and height
     * describe the picture returned; canvasWidth and canvasHeight are the
     * variant's real size, which is what export_variant produces. Nothing is
     * saved and nothing is counted as an export: this only draws. A variant id
     * this account cannot see reports exactly the same failure as an id that
     * does not exist.
     *
     * @param string $variantId UUID of the template variant to render, as returned by describe_variant or by find_templates in templates[].variants[].id.
     * @param array<array-key, mixed> $inputs Map of text input id (describe_variant inputs[].id) to the value to render. Omit entirely to render the variant's own sample texts.
     * @param array<array-key, mixed> $images Map of image slot id (describe_variant imageInputs[].id) to a gallery image id, a placement object, or {"hide": true}. Omit entirely to keep every stand-in picture.
     */
    #[McpTool(name: 'render_variant')]
    public function __invoke(
        string $variantId,
        #[Schema(type: 'object', additionalProperties: true)]
        array $inputs = [],
        #[Schema(type: 'object', additionalProperties: true)]
        array $images = [],
    ): CallToolResult {
        $variant = $this->variant($variantId);

        $providedInputs = self::stringKeyed($inputs);
        $providedImages = self::stringKeyed($images);

        $warnings = self::unaddressedKeys($variant, $providedInputs, $providedImages);

        $overrides = $this->resolveTexts($variant, $providedInputs);
        $imageOverrides = $this->resolveImages($variant, $providedImages);

        $render = $this->renderPreview($variant, $overrides, $imageOverrides);

        if ($render['warning'] !== null) {
            $warnings[] = $render['warning'];
        }

        $preview = $this->downscaleImage->toLongEdge($render['bytes'], self::MAX_LONG_EDGE, self::FORMAT);

        $summary = new RenderVariantResponse(
            variantId: $variant->id->toString(),
            templateName: $variant->template->name,
            projectName: $variant->template->project->name,
            format: self::FORMAT->contentType(),
            width: $preview['width'],
            height: $preview['height'],
            canvasWidth: $variant->dimension->width(),
            canvasHeight: $variant->dimension->height(),
            downscaled: $preview['downscaled'],
            warnings: $warnings,
        );

        return new CallToolResult([
            new TextContent(self::encode($summary)),
            ImageContent::fromString($preview['contents'], self::FORMAT->contentType()),
        ]);
    }

    /**
     * The variant, or the one refusal this tool ever gives for a variant id.
     */
    private function variant(string $variantId): TemplateVariant
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
     * The refusal, worded once. Both callers — "no such row" and "not yours" —
     * must produce a byte-identical message; see the class docblock.
     */
    private static function notFound(string $variantId): ToolCallException
    {
        return new ToolCallException(sprintf(
            'Template variant %s was not found, or this account cannot access it. Call find_templates to list the variants of a project this account can reach.',
            $variantId,
        ));
    }

    /**
     * Text overrides, resolved STRICTLY (`truncateOverflow: false`) — the API
     * export's setting, not the web fill page's. A preview that silently cut an
     * over-long value would hand the agent a picture of a fill the export then
     * refuses, which is the one failure this tool is supposed to prevent.
     *
     * The structured violations become tool errors carrying the same wording
     * the REST 400 body does, plus its machine code and its context (the
     * allowed font list, above all — that is what makes the message actionable
     * rather than merely accurate).
     *
     * @param array<string, mixed> $providedInputs
     */
    private function resolveTexts(TemplateVariant $variant, array $providedInputs): ResolvedInputOverrides
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
    private function resolveImages(TemplateVariant $variant, array $providedImages): ResolvedImageOverrides
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
     * The picture. Strict first so overflow can be MEASURED, lenient after so
     * it can still be SEEN — see the class docblock for why that order is the
     * only one available.
     *
     * @return array{bytes: string, warning: null|string}
     */
    private function renderPreview(
        TemplateVariant $variant,
        ResolvedInputOverrides $overrides,
        ResolvedImageOverrides $imageOverrides,
    ): array {
        $warning = null;

        try {
            return [
                'bytes' => $this->renderer->renderToBytes(
                    $variant,
                    $overrides,
                    $imageOverrides,
                    strictContainerOverflow: true,
                    format: self::FORMAT,
                ),
                'warning' => null,
            ];
        } catch (ContainerOverflow $overflow) {
            $warning = sprintf(
                'Container %s overflows its max height by %.1f px. The preview shows the overflow, but export_variant will refuse this fill — shorten the texts of that container (describe_variant reports which inputs belong to it) or hide one of them.',
                $overflow->containerId ?? '(unknown)',
                $overflow->overflowPx,
            );
        } catch (TemplateRenderUnavailable) {
            throw self::rendererBusy();
        } catch (\Throwable) {
            // Strict mode also fails the render on console errors the lenient
            // path deliberately tolerates. Falling through to the lenient
            // render keeps this tool from being pickier than the fill page; if
            // the picture is genuinely unrenderable, the retry below says so.
        }

        try {
            return [
                'bytes' => $this->renderer->renderToBytes(
                    $variant,
                    $overrides,
                    $imageOverrides,
                    strictContainerOverflow: false,
                    format: self::FORMAT,
                ),
                'warning' => $warning,
            ];
        } catch (TemplateRenderUnavailable) {
            throw self::rendererBusy();
        } catch (\Throwable $failure) {
            throw new ToolCallException(sprintf(
                'Rendering this variant failed: %s. This is a problem with the design or its assets, not with the values provided — opening the variant in the wboost editor will show it.',
                $failure->getMessage(),
            ));
        }
    }

    /**
     * Gotenberg is a synchronous dependency shared with every fill page and
     * export in the app; "busy" is a retry, not a defect in the request.
     */
    private static function rendererBusy(): ToolCallException
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
    private static function unaddressedKeys(TemplateVariant $variant, array $providedInputs, array $providedImages): array
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
     * it once here also gives the warning builder above a shape it can compare.
     *
     * @param array<array-key, mixed> $provided
     *
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $provided): array
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
     * The summary block, encoded with the same flags the SDK's own formatter
     * uses for a DTO — so this tool's text block reads exactly like every other
     * tool's, even though it builds the result by hand.
     */
    private static function encode(RenderVariantResponse $summary): string
    {
        return json_encode(
            $summary,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }
}
