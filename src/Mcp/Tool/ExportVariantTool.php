<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Result\CallToolResult;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Exceptions\ContainerOverflow;
use WBoost\Web\Exceptions\TemplateRenderUnavailable;
use WBoost\Web\Mcp\Fill\VariantFill;
use WBoost\Web\Mcp\Response\ExportVariantResponse;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpToolScope;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Services\Security\TemplateVariantVoter;
use WBoost\Web\Services\Template\RecordExportVersion;
use WBoost\Web\Services\Usage\RecordExportUsage;
use WBoost\Web\Value\ExportChannel;
use WBoost\Web\Value\ExportFillValues;
use WBoost\Web\Value\RenderImageFormat;
use WBoost\Web\Value\ResolvedImageOverrides;
use WBoost\Web\Value\ResolvedInputOverrides;

/**
 * `export_variant` — THE deliverable. The variant at its designed size, as a
 * lossless PNG, byte-for-byte what the REST export and the web download hand a
 * user.
 *
 * It is the strict sibling of `render_variant`, and every difference between
 * the two follows from that one word:
 *
 * | | `render_variant` | `export_variant` |
 * |---|---|---|
 * | scope | `templates:read` | **`templates:export`** |
 * | format | lossy WebP, ≤ 1200 px long edge | **PNG at full size** |
 * | container overflow | warned, picture returned | **refused** |
 * | `/admin/usage` | not counted | **counted** ({@see ExportChannel::Mcp}) |
 *
 * ## Why the scope is its own
 *
 * A read-only token must not be able to take files away. `templates:export`
 * implies `templates:read` ({@see McpScope::grants()}), so an export-capable
 * agent can still orient itself — but the reverse never holds, and the gate
 * refuses the call rather than merely hiding the tool
 * ({@see \WBoost\Web\Mcp\Security\McpToolGate}).
 *
 * ## Why overflow is a refusal here
 *
 * Container overflow means filled text ran past the height its designer
 * allowed. The lenient render still produces pixels — with the text spilling
 * over whatever sits below it — so "just return the picture" would hand the
 * user a broken deliverable that LOOKS finished. The REST export answers 400
 * for exactly this reason (a documented contract:
 * `{code: "container_overflow", containerId, overflowPx}`), and this tool
 * answers with the same refusal translated into a sentence naming the INPUTS to
 * shorten — see {@see VariantFill::containerOverflowMessage()} for why it names
 * inputs rather than echoing the container UUID, and why it does not invent a
 * character count.
 *
 * ## Usage is recorded AFTER the render, and only after it succeeded
 *
 * {@see RecordExportUsage} is called on the last line before the reply is
 * assembled, exactly as {@see \WBoost\Web\Api\Templates\ExportProcessor} and
 * {@see \WBoost\Web\Controller\Template\TemplateVariantDownloadController} call
 * it. A refused fill, an overloaded renderer and a broken design all leave
 * `/admin/usage` untouched: those are not exports, and counting them would
 * inflate the one number the report exists to give.
 *
 * ## Authorisation
 *
 * {@see TemplateVariantVoter::VIEW} — the same voter the REST export uses, so
 * an export scope cannot widen what its user could already reach. A variant the
 * caller may not see reports the SAME failure as an id that matches no row.
 */
#[McpToolScope(McpScope::TemplatesExport)]
readonly final class ExportVariantTool
{
    /**
     * PNG, stated explicitly even though {@see RenderImageFormat} already
     * defaults to it. The default is load-bearing elsewhere (the group ZIP and
     * the Meta publish path hand these bytes to third parties labelled
     * `image/png`), and every export path in the app asserts the format it
     * asked for rather than the one it happened to get.
     */
    private const RenderImageFormat FORMAT = RenderImageFormat::Png;

    public function __construct(
        private VariantFill $fill,
        private TemplateVariantImageRendererInterface $renderer,
        private RecordExportUsage $recordExportUsage,
        private RecordExportVersion $recordExportVersion,
    ) {
    }

    /**
     * Exports ONE variant filled with the values you provide and returns the
     * finished picture: a lossless PNG at the variant's real size (2480 × 3508
     * for an A4 page at 300 DPI). This is the DELIVERABLE — iterate with
     * render_variant, which is far cheaper, and call this once the text is
     * right. The export is counted in the project's usage report.
     *
     * inputs and images take exactly the values render_variant takes, keyed by
     * the ids describe_variant reports: a plain string, or {"value": "...",
     * "hide": true}, or — for a richText input — {"runs": [...], "lines":
     * [...]}; and for a picture slot a gallery image id, a placement object
     * {"imageId": "...", "scale": 1, "offsetX": 0, "offsetY": 0, "rotation":
     * 0}, or {"hide": true}. An id left out renders the designer's sample
     * value; an empty string renders nothing for it.
     *
     * Text that overflows its container is REFUSED here rather than drawn: the
     * error names the inputs sharing that container and by how many pixels the
     * content is too long. Shorten one of those texts (or hide a hidable one)
     * and call again — render_variant shows you the overflow while you work.
     * Values that break the input contract are refused the same way, naming
     * what is wrong: an over-long value, a font outside the variant's palette,
     * an invalid colour, a list or checkbox line on an input that does not
     * allow them, a picture from a folder the slot does not accept.
     *
     * The reply is a JSON summary followed by the PNG. Nothing about the
     * template is changed — this only draws. A variant id this account cannot
     * see reports exactly the same failure as an id that does not exist.
     *
     * @param string $variantId UUID of the template variant to export, as returned by describe_variant or by find_templates in templates[].variants[].id.
     * @param array<array-key, mixed> $inputs Map of text input id (describe_variant inputs[].id) to the value to render. Omit entirely to export the variant's own sample texts.
     * @param array<array-key, mixed> $images Map of image slot id (describe_variant imageInputs[].id) to a gallery image id, a placement object, or {"hide": true}. Omit entirely to keep every stand-in picture.
     */
    #[McpTool(name: 'export_variant')]
    public function __invoke(
        string $variantId,
        #[Schema(type: 'object', additionalProperties: true)]
        array $inputs = [],
        #[Schema(type: 'object', additionalProperties: true)]
        array $images = [],
    ): CallToolResult {
        $variant = $this->fill->variant($variantId);

        $providedInputs = VariantFill::stringKeyed($inputs);
        $providedImages = VariantFill::stringKeyed($images);

        $warnings = VariantFill::warnings($variant, $providedInputs, $providedImages);

        $overrides = $this->fill->texts($variant, $providedInputs);
        $imageOverrides = $this->fill->images($variant, $providedImages);

        $png = $this->render($variant, $overrides, $imageOverrides);

        // Last step before the reply, and only on the success path — see the
        // class docblock.
        $this->recordExportUsage->record($variant, ExportChannel::Mcp);
        $this->recordExportVersion->recordVariant(
            $variant,
            ExportChannel::Mcp,
            ExportFillValues::fromApiRequest($providedInputs, $providedImages),
        );

        $summary = new ExportVariantResponse(
            variantId: $variant->id->toString(),
            templateName: $variant->template->name,
            projectName: $variant->template->project->name,
            format: self::FORMAT->contentType(),
            width: $variant->dimension->width(),
            height: $variant->dimension->height(),
            sizeBytes: strlen($png),
            warnings: $warnings,
        );

        return new CallToolResult([
            VariantFill::summary($summary),
            ImageContent::fromString($png, self::FORMAT->contentType()),
        ]);
    }

    /**
     * The PNG, rendered STRICTLY — the export's whole contract.
     *
     * There is no lenient retry here (that is `render_variant`'s job): a strict
     * render also turns unrelated console errors into failures, and an export
     * that quietly fell back would deliver a file whose defect nobody was told
     * about. Every failure is therefore a refusal, worded by what went wrong.
     */
    private function render(
        TemplateVariant $variant,
        ResolvedInputOverrides $overrides,
        ResolvedImageOverrides $imageOverrides,
    ): string {
        try {
            return $this->renderer->renderToBytes(
                $variant,
                $overrides,
                $imageOverrides,
                strictContainerOverflow: true,
                format: self::FORMAT,
            );
        } catch (ContainerOverflow $overflow) {
            throw new ToolCallException(sprintf(
                '%s Nothing was exported; call render_variant to see the overflow while you shorten the text.',
                VariantFill::overflowFor($variant, $overflow),
            ));
        } catch (TemplateRenderUnavailable) {
            throw VariantFill::rendererBusy();
        } catch (\Throwable $failure) {
            throw new ToolCallException(sprintf(
                'Exporting this variant failed: %s. This is a problem with the design or its assets, not with the values provided — opening the variant in the wboost editor will show it.',
                $failure->getMessage(),
            ));
        }
    }
}
