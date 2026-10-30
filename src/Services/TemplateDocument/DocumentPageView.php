<?php

declare(strict_types=1);

namespace WBoost\Web\Services\TemplateDocument;

use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Value\DocumentFillKeys;
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\EditorTextInput;

/**
 * A document page as the fill page's EXISTING placeholder builders must see
 * it: the page variant with every inputId replaced by its FILL KEY
 * ({@see DocumentFillKeys}), so the overlay boxes, popovers, mirrors, echo
 * payload and image frames of N pages can share one form without colliding.
 *
 * Hard rules (plan §3) — each one is a bug that was designed out:
 *
 * - The result is a CLONE, re-keyed through `editCanvas()` on the clone. The
 *   managed original is never touched: the fill page flushes mid-request
 *   (`FileUploadPixelSizeBackfill`, reached from the image-slot builder), and
 *   a re-keyed managed variant would persist fill keys into the stored canvas.
 *   The clone is not in the identity map and nothing with cascade-persist
 *   references it (`Template::$variants` never gets it), so it stays out of
 *   the UnitOfWork.
 * - EVERY inputId location is re-keyed: `inputs[]` (order kept — text binding
 *   is POSITIONAL), `imageInputs[]`, the canvas objects' `inputId` (texts,
 *   image placeholders, shapes — nested group objects included) and the
 *   canvas `containers[].memberInputIds` (a stale member id makes the
 *   container drop out and the overlay reflow diverge from the render).
 * - The clone is for VIEW DATA ONLY: never hand it to a renderer, a resolver
 *   or a message — rendering always uses the real variant +
 *   {@see DocumentFillKeys::valuesForPage()}.
 */
final readonly class DocumentPageView
{
    public static function rekeyed(TemplateVariant $variant, DocumentFillKeys $keys, string $templateId): TemplateVariant
    {
        // Read everything off the original first: on a lazy proxy this also
        // initialises it, so the clone carries real state.
        $canvas = $variant->canvas;
        $inputs = $variant->inputs;
        $imageInputs = $variant->imageInputs;
        $previewImagePath = $variant->previewImagePath;

        $key = static fn (string $inputId): string => $keys->keyFor($templateId, $inputId);

        $clone = clone $variant;
        $clone->editCanvas(
            self::rekeyCanvas($canvas, $key),
            array_map(
                static fn (EditorTextInput $input): EditorTextInput => EditorTextInput::fromArray(['inputId' => $key($input->inputId)] + $input->toArray()),
                array_values($inputs),
            ),
            $previewImagePath,
            array_map(
                static fn (EditorImageInput $input): EditorImageInput => EditorImageInput::fromArray(['inputId' => $key($input->inputId)] + $input->toArray()),
                array_values($imageInputs),
            ),
        );

        return $clone;
    }

    /**
     * @param \Closure(string): string $key
     */
    private static function rekeyCanvas(string $canvas, \Closure $key): string
    {
        $decoded = json_decode($canvas, true);

        if (!is_array($decoded)) {
            return $canvas;
        }

        if (is_array($decoded['objects'] ?? null)) {
            $decoded['objects'] = self::rekeyObjects($decoded['objects'], $key);
        }

        if (is_array($decoded['containers'] ?? null)) {
            $containers = $decoded['containers'];
            foreach ($containers as $index => $container) {
                if (!is_array($container) || !is_array($container['memberInputIds'] ?? null)) {
                    continue;
                }

                $container['memberInputIds'] = array_map(
                    static fn (mixed $id): mixed => is_string($id) && $id !== '' ? $key($id) : $id,
                    $container['memberInputIds'],
                );
                $containers[$index] = $container;
            }
            $decoded['containers'] = $containers;
        }

        $encoded = json_encode($decoded, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? $canvas : $encoded;
    }

    /**
     * @param array<array-key, mixed> $objects
     * @param \Closure(string): string $key
     *
     * @return array<array-key, mixed>
     */
    private static function rekeyObjects(array $objects, \Closure $key): array
    {
        foreach ($objects as $index => $object) {
            if (!is_array($object)) {
                continue;
            }

            if (is_string($object['inputId'] ?? null) && $object['inputId'] !== '') {
                $object['inputId'] = $key($object['inputId']);
            }

            if (is_array($object['objects'] ?? null)) {
                $object['objects'] = self::rekeyObjects($object['objects'], $key);
            }

            $objects[$index] = $object;
        }

        return $objects;
    }
}
