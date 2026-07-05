<?php

declare(strict_types=1);

namespace WBoost\Web\Api\SocialNetworkTemplates;

/**
 * A designer-authored container ("smart text area"): its member text inputs
 * reflow vertically at render time — a filled text that wraps to more lines
 * pushes the members below it down, hidden members collapse, and the flow is
 * bounded by maxHeight measured from `y` downward (both in canvas px, the same
 * coordinate space as `inputs[].frame`). An export whose container content
 * exceeds the bound is rejected with 400 `container_overflow`.
 *
 * `memberInputIds` lists the member inputs in flow order (top to bottom);
 * each listed input also carries this container's id as its `containerId`.
 * The reflow algorithm is documented in docs/api/consumer-prompt.md.
 */
final readonly class SocialNetworkTemplateVariantContainerResponse
{
    /**
     * @param list<string> $memberInputIds
     */
    public function __construct(
        public string $id,
        public float $maxHeight,
        public float $y,
        public array $memberInputIds,
    ) {
    }
}
