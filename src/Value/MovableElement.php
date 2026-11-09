<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * One element the END USER may move ("Uživatel může posunout") on the variant
 * being filled: a free text (locked ones too), a free shape, a free decorative
 * image, or a TOP-LEVEL container moved as one block — with the designer's
 * {@see ElementMoveAllowance} and the DESIGNED frame the allowance's
 * "unlimited = stays on the artwork" bounds are measured from.
 *
 * Built only by {@see \WBoost\Web\Services\SocialNetwork\ResolveElementOffsets::movableElements()},
 * the single eligibility source.
 */
readonly final class MovableElement
{
    public const string KIND_TEXT = 'text';
    public const string KIND_SHAPE = 'shape';
    public const string KIND_IMAGE = 'image';
    public const string KIND_CONTAINER = 'container';

    /**
     * @param string $id the object's `inputId`, or the container definition's `id`
     * @param array{left: float, top: float, width: float, height: float} $frame
     *   designed axis-aligned bounding box in canvas px: Fabric's
     *   `getBoundingRect()` of the object (stroke included, rotation-aware),
     *   for a container the union of its design-visible deep members' boxes
     */
    public function __construct(
        public string $id,
        public string $kind,
        public ElementMoveAllowance $allowance,
        public array $frame,
    ) {
    }

    /**
     * @return array{minX: float, maxX: float, minY: float, maxY: float}
     */
    public function bounds(float $canvasWidth, float $canvasHeight): array
    {
        return $this->allowance->bounds($this->frame, $canvasWidth, $canvasHeight);
    }

    /**
     * @return array{x: float, y: float}
     */
    public function clamp(float $x, float $y, float $canvasWidth, float $canvasHeight): array
    {
        return $this->allowance->clamp($x, $y, $this->frame, $canvasWidth, $canvasHeight);
    }
}
