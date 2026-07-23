<?php

declare(strict_types=1);

namespace WBoost\Web\Api\SocialNetworkTemplates;

final class ExportRequest
{
    /**
     * Map of inputId UUID → value: a plain string, `{ value, hide }`, or — for
     * inputs with `richText: true` — `{ runs: [...], hide }` where each run is
     * `{ "text": "...", "fontFamily": null|string, "color": null|"#rrggbb",
     * "underline": bool }` (null style = inherit the designed style; fonts
     * must come from the variant's `richTextOptions.fonts[].family`).
     * Inputs whose ids are missing keep the variant's default canvas text.
     * Locked inputs cannot be addressed and are always served from the canvas
     * defaults. Discover ids via `GET /api/social-network-templates`
     * (`variants[].inputs[].id`). Unknown ids are silently ignored.
     *
     * @var array<string, mixed>
     */
    public array $inputs = [];

    /**
     * Map of imageInputId UUID → chosen gallery image + optional placement.
     * Each value is either a plain **string** (the gallery image id, placed
     * centered + object-contain in the designer's frame) or an object
     * `{ "imageId": "...", "scale": 1, "offsetX": 0, "offsetY": 0, "rotation": 0 }`
     * (`scale` multiplies the contain-fit, `offsetX/Y` pan in canvas px from the
     * frame centre, `rotation` is degrees), or `{ "hide": true }` to blank a
     * hidable slot.
     *
     * The pan may instead be given as `offsetXRatio` / `offsetYRatio` — the same
     * pan as a FRACTION of the frame's width / height (0.25 = a quarter of the
     * frame to the right / down). That form is portable: the same value keeps
     * one crop intent when it is reused on another variant of the same template
     * whose frame differs. Send one form or the other per axis; both for the
     * same axis is a 400.
     *
     * Discover the ids, frames and allowed folders via
     * `GET /api/projects/{projectId}/social-network-templates`
     * (`variants[].imageInputs[]`); list a slot's pickable images via
     * `GET /api/social-network-template-variants/{variantId}/placeholders/{inputId}/images`.
     * Unfilled slots keep the designer's stand-in image. An adjustment a slot
     * does not permit (move / resize / rotate) is rejected with 400.
     *
     * @var array<string, mixed>
     */
    public array $images = [];
}
