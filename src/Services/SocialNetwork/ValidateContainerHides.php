<?php

declare(strict_types=1);

namespace WBoost\Web\Services\SocialNetwork;

use WBoost\Web\Exceptions\InvalidExportValue;
use WBoost\Web\Value\CanvasContainer;
use WBoost\Web\Value\EditorTextInput;

/**
 * STRICT validation of the container entries of an API / MCP `inputs` map.
 *
 * A whole container is hidden through the very map that hides inputs —
 * `inputs[<containerId>] = {"hide": true}` — so the lenient resolver
 * ({@see ResolveTextOverrides}) collects it like any other hide request and
 * the renderer expands it ({@see CanvasContainer::hiddenMemberInputIds()}).
 * What only the strict surfaces must add is the refusal: an entry keyed by a
 * container id of THIS variant must be exactly `{hide: bool}`, and
 * `hide: true` needs a hidable container — otherwise a structured 400
 * (`invalid_value` / `container_not_hidable`, context `containerId`).
 *
 * Ids that name neither an input nor a container keep the documented
 * "unknown inputs are ignored" contract — a group fill passes ONE map to every
 * member dimension, and a stale id must not start failing exports.
 */
readonly final class ValidateContainerHides
{
    /**
     * @param list<CanvasContainer> $containers the variant's containers
     * @param array<EditorTextInput> $inputs the variant's text inputs (an id
     *   that is an input is never treated as a container)
     * @param array<array-key, mixed> $providedValues the `inputs` map
     *
     * @throws InvalidExportValue
     */
    public static function strict(array $containers, array $inputs, array $providedValues): void
    {
        $inputIds = [];
        foreach ($inputs as $input) {
            $inputIds[$input->inputId] = true;
        }

        foreach ($providedValues as $id => $value) {
            $id = (string) $id;
            if (isset($inputIds[$id])) {
                continue;
            }

            $container = CanvasContainer::findById($containers, $id);
            if ($container === null) {
                continue;
            }

            $label = $container->name ?? $id;

            if (!is_array($value) || !array_key_exists('hide', $value) || array_diff(array_keys($value), ['hide']) !== []) {
                throw InvalidExportValue::invalidValue(sprintf(
                    'Container "%s" accepts only { "hide": true|false } — it has no text of its own.',
                    $label,
                ))->withContext(['containerId' => $id]);
            }

            if (!is_bool($value['hide'])) {
                throw InvalidExportValue::invalidValue(sprintf('Container "%s".hide must be a boolean.', $label))
                    ->withContext(['containerId' => $id]);
            }

            if ($value['hide'] && !$container->hidable) {
                throw InvalidExportValue::containerNotHidable($label, $id);
            }
        }
    }

    /**
     * @param array<EditorTextInput> $inputs
     * @param array<array-key, mixed> $providedValues
     *
     * @throws InvalidExportValue
     */
    public static function strictForCanvas(string $canvasJson, array $inputs, array $providedValues): void
    {
        $decoded = json_decode($canvasJson, true);

        self::strict(
            is_array($decoded) ? CanvasContainer::collectionFromCanvas($decoded) : [],
            $inputs,
            $providedValues,
        );
    }
}
