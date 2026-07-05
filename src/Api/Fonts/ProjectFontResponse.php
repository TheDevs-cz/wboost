<?php

declare(strict_types=1);

namespace WBoost\Web\Api\Fonts;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;

#[ApiResource(
    shortName: 'ProjectFont',
    operations: [
        new GetCollection(
            uriTemplate: '/projects/{projectId}/fonts',
            // projectId scopes the collection, it is not an identifier of this
            // resource — same Link trick as the templates listing.
            uriVariables: [
                'projectId' => new Link(
                    fromClass: ProjectFontResponse::class,
                    identifiers: [],
                    parameterName: 'projectId',
                ),
            ],
            provider: ProjectFontsProvider::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            paginationEnabled: false,
            name: 'api_project_fonts',
            openapi: new OpenApiOperation(
                summary: 'List the project\'s font faces (for client-side text measurement and previews)',
                description: <<<MD
Every uploaded font face of the project, flattened to one row per face.

`family` is the **exact Fabric font-family string** the canvases use
(`"FontName (FaceName)"`) — the same string that appears in
`variants[].inputs[].textStyle.fontFamily` and that rich-text runs carry in
`fontFamily`. `url` serves the font file (public store URL).

**Why consumers need this:** to mirror the render's text layout client-side
(live placeholder boxes, container reflow, overflow pre-checks — see
docs/api/consumer-prompt.md), text must be measured **with the real fonts**;
a fallback face produces different wrap points. Load each face via the
browser's `FontFace` API (`new FontFace(family, 'url(...)')`) — one face per
family string, no weight/style axes needed (`weight`/`style` are best-effort
metadata for grouping UIs, bold/italic live as separate families).

Fonts are project-wide, so one call serves every template/variant. The
listing is stable and small — cache it per session.
MD,
            ),
        ),
    ],
)]
final readonly class ProjectFontResponse
{
    public function __construct(
        public string $family,
        public string $fontName,
        public string $faceName,
        public int $weight,
        public string $style,
        public string $url,
    ) {
    }
}
