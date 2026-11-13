<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan\Template;

use WBoost\Web\Entity\AiScan;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\AiScan\AiScanKind;
use WBoost\Web\Value\AiScan\AiScanStatus;

/**
 * The proposal as the review reads it (ux.md S4, S4b) — one JSON document
 * for the web screens and for MCP `scan_design result`.
 *
 * Nothing here is prose: codes, numbers, ids, boxes, face strings of the
 * project, and the texts of the design itself (which the review edits). The
 * Czech sentences are the screens' business. Boxes named `box` are in the
 * pixels of the working copy (`source`) — the review cuts its crops from that
 * picture; `canvasBox` is the same thing in the proposal's canvas pixels.
 *
 * Shape: docs/architecture/ai-scan.md § "The proposal document".
 */
final readonly class ScanProposalView
{
    /** Font patterns the user decides about; `measured_match` is information. */
    private const array FONT_DECISIONS = ['nearest_available', 'undetermined'];

    public function __construct(
        private ScanProposal $proposal,
        private UploaderHelper $uploaderHelper,
    ) {
    }

    /**
     * @return null|array<string, mixed> null when the scan has no template proposal (never had, or it is gone)
     */
    public function view(AiScan $scan): null|array
    {
        if ($scan->kind !== AiScanKind::Template || $scan->proposal === null || !in_array($scan->status, [AiScanStatus::Ready, AiScanStatus::Accepted], true)) {
            return null;
        }

        $state = ScanProposal::state($scan);
        $items = $state['fixes']['items'];
        $places = $state['pictures']['places'];
        $report = $scan->report ?? [];
        $measured = ApplyFixes::measured($scan->proposal);
        $scale = ApplyFixes::sourceScale($scan->proposal);
        $document = $this->proposal->replay($scan, $state);
        $placeIds = $document['places'];
        $byId = [];

        foreach ($document['elements'] as $element) {
            if (is_string($element['id'] ?? null)) {
                $byId[$element['id']] = $element;
            }
        }

        // Names as the scanner gave them: a place that got a picture as
        // "Součást šablony" is a plain picture in the replayed document and
        // carries no input any more — its card must not lose its name.
        $original = [];
        $originalDocument = is_array($scan->proposal['document'] ?? null) ? $scan->proposal['document'] : [];
        foreach (ApplyFixes::rows($originalDocument['elements'] ?? null) as $element) {
            if (is_string($element['id'] ?? null)) {
                $original[$element['id']] = $element;
            }
        }
        $nameOf = static function (array $element) use ($original, $items): null|string {
            /** @var array<string, mixed> $element */
            $id = is_string($element['id'] ?? null) ? $element['id'] : '';
            $input = is_array($element['input'] ?? null) ? $element['input'] : [];
            $renamed = is_array($items['name:' . $id] ?? null) && is_string($items['name:' . $id]['name'] ?? null) ? $items['name:' . $id]['name'] : null;
            $first = is_array($original[$id]['input'] ?? null) && is_string($original[$id]['input']['name'] ?? null) ? $original[$id]['input']['name'] : null;

            return (is_string($input['name'] ?? null) ? $input['name'] : null)
                ?? (is_string($element['name'] ?? null) ? $element['name'] : null)
                ?? $renamed
                ?? $first;
        };

        $doubtful = [];

        foreach (ApplyFixes::rows($report['lowConfidenceTexts'] ?? null) as $row) {
            if (is_string($row['id'] ?? null)) {
                $doubtful[$row['id']] = $row;
            }
        }

        $sourceBox = static function (array $element) use ($measured, $scale): null|array {
            /** @var array<string, mixed> $element */
            $box = ApplyFixes::elementBox($element, $measured, $scale);

            return $box === null ? null : self::rounded([$box[0] / $scale[0], $box[1] / $scale[1], $box[2] / $scale[0], $box[3] / $scale[1]]);
        };

        // ---- layers ("Vrstvy"), in stack order
        $elements = [];
        $counts = ['texts' => 0, 'shapes' => 0, 'places' => 0, 'background' => false];

        foreach ($document['elements'] as $element) {
            $id = is_string($element['id'] ?? null) ? $element['id'] : '';
            $kind = is_string($element['kind'] ?? null) ? $element['kind'] : '';
            $input = is_array($element['input'] ?? null) ? $element['input'] : [];
            $place = in_array($id, $placeIds, true);
            $row = [
                'id' => $id,
                'type' => $place ? 'place' : $kind,
                'name' => $nameOf($element),
                'box' => $kind === 'background' ? null : $sourceBox($element),
            ];

            if ($place) {
                $counts['places']++;
            } elseif ($kind === 'text') {
                $counts['texts']++;
                $row += [
                    'text' => is_string($element['text'] ?? null) ? $element['text'] : '',
                    'input' => ($input['locked'] ?? false) !== true,
                    'font' => $element['font'] ?? null,
                    'size' => $element['size'] ?? null,
                    'colour' => $element['color'] ?? '#000000',
                    'doubtful' => isset($doubtful[$id]),
                ];
            } elseif ($kind === 'shape') {
                $counts['shapes']++;
                $row += ['shape' => $element['shape'] ?? null, 'fill' => is_string($element['fill'] ?? null) ? $element['fill'] : 'gradient'];
            } elseif ($kind === 'background') {
                // A colour or gradient background; a background PHOTO is a place.
                $counts['background'] = true;
                $row += ['fill' => is_string($element['fill'] ?? null) ? $element['fill'] : (is_array($element['fill'] ?? null) ? 'gradient' : null)];
            }

            $elements[] = $row;
        }

        // ---- places for pictures ("Fotky a loga")
        $roles = [];

        foreach (ApplyFixes::rows($report['placeholders'] ?? null) as $placeholder) {
            if (is_string($placeholder['id'] ?? null)) {
                $roles[$placeholder['id']] = is_string($placeholder['role'] ?? null) ? $placeholder['role'] : 'other';
            }
        }

        $placeholders = [];
        $unfilled = 0;
        $filled = 0;
        $greyBackground = false;

        foreach (ScanProposal::placeOrder($report, $placeIds) as $number => $placeId) {
            $element = $byId[$placeId];
            $background = ($element['kind'] ?? null) === 'background';
            $role = $roles[$placeId] ?? ($background ? 'background' : 'other');
            $chosen = $places[$placeId] ?? [];
            $kind = !$background && in_array($chosen['kind'] ?? null, [ApplyFixes::KIND_FIXED, ApplyFixes::KIND_INPUT], true) ? $chosen['kind'] : ApplyFixes::defaultKind($role, $background);
            $fit = in_array($chosen['fit'] ?? null, [ApplyFixes::FIT_CONTAIN, ApplyFixes::FIT_COVER], true) ? $chosen['fit'] : ApplyFixes::defaultFit($role);
            $box = $background ? null : ApplyFixes::elementBox($element, $measured, $scale);
            $canvasWidth = $box[2] ?? (ApplyFixes::number($document['canvas']['width'] ?? null) ?? 0.0);
            $canvasHeight = $box[3] ?? (ApplyFixes::number($document['canvas']['height'] ?? null) ?? 0.0);
            $fileId = is_string($chosen['fileId'] ?? null) ? $chosen['fileId'] : null;
            $file = $fileId === null ? null : $this->proposal->galleryFile($scan, $fileId);
            $picture = null;

            if ($fileId !== null) {
                $filled++;
                $picture = [
                    'fileId' => $fileId,
                    'missing' => $file === null,
                    'url' => $file === null ? null : $this->uploaderHelper->getPublicPath($file->path),
                    'name' => $file?->originalName,
                    'width' => $file?->width,
                    'height' => $file?->height,
                    'uploaded' => in_array($fileId, $state['pictures']['uploads'], true),
                    // "Obrázek je menší než místo v šabloně".
                    'tooSmall' => $file !== null && $file->width !== null && $file->height !== null
                        && ($file->width < $canvasWidth * 0.98 && $file->height < $canvasHeight * 0.98),
                    // "Vyplnit" is the browser's crop to the place's ratio; a picture of another ratio is fitted whole.
                    'fitApplied' => $fit === ApplyFixes::FIT_COVER && $file !== null && $file->width !== null && $file->height !== null && $canvasHeight > 0.0 && $file->height > 0
                        && abs(($file->width / $file->height) / ($canvasWidth / $canvasHeight) - 1.0) > 0.02
                            ? ApplyFixes::FIT_CONTAIN
                            : $fit,
                ];
            } elseif ($background) {
                // A background always carries a picture: without a sample it stays on the grey stand-in.
                $greyBackground = true;
            } elseif ($kind === ApplyFixes::KIND_FIXED) {
                $unfilled++;
            }

            $input = is_array($element['input'] ?? null) ? $element['input'] : [];

            $placeholders[] = [
                'id' => $placeId,
                'number' => $number + 1,
                'name' => $nameOf($element),
                'role' => $role,
                'background' => $background,
                'added' => !isset($roles[$placeId]),
                'kind' => $kind,
                'kindLocked' => $background,
                'fit' => $fit,
                'box' => $background ? null : $sourceBox($element),
                'canvasBox' => $box === null ? null : self::rounded($box),
                'width' => (int) round($canvasWidth),
                'height' => (int) round($canvasHeight),
                'picture' => $picture,
                // "Bez ukázky" / "Nechat šedé pozadí" was chosen.
                'decidedEmpty' => $fileId === null && ($chosen['none'] ?? false) === true,
            ];
        }

        // ---- decisions ("K rozhodnutí")
        $decisions = [];
        $toDecide = 0;
        $information = [];

        foreach (ApplyFixes::rows($report['fonts'] ?? null) as $group) {
            $key = is_string($group['group'] ?? null) ? $group['group'] : '';
            $pattern = is_string($group['pattern'] ?? null) ? $group['pattern'] : 'undetermined';
            $texts = array_values(array_filter(is_array($group['texts'] ?? null) ? $group['texts'] : [], static fn (mixed $id): bool => is_string($id) && isset($byId[$id])));
            $face = null;

            foreach ($texts as $textId) {
                $face ??= is_string($byId[$textId]['font'] ?? null) ? $byId[$textId]['font'] : null;
            }

            if ($texts === []) {
                continue;
            }

            $entry = [
                'group' => $key,
                'role' => is_string($group['role'] ?? null) ? $group['role'] : 'other',
                'pattern' => $pattern,
                'face' => $face ?? ($group['face'] ?? null),
                'sizeChangePercent' => is_int($group['sizeChangePercent'] ?? null) ? $group['sizeChangePercent'] : 0,
                'texts' => $texts,
                'boxes' => array_values(array_filter(array_map(static fn (string $id): null|array => $sourceBox($byId[$id]), $texts))),
            ];

            if (!in_array($pattern, self::FONT_DECISIONS, true)) {
                $information[] = ['code' => 'font_match'] + $entry;

                continue;
            }

            $item = $items['font:' . $key] ?? null;
            $toDecide += $item === null ? 1 : 0;
            $decisions[] = [
                'key' => 'font:' . $key,
                'type' => 'font',
                'decided' => $item !== null,
                'decision' => $item['type'] ?? null,
                'fixes' => ['choose_font', 'keep_font'],
            ] + $entry;
        }

        foreach ($doubtful as $textId => $row) {
            if (!isset($byId[$textId])) {
                continue;
            }

            $decided = isset($items['confirm:' . $textId]);
            $toDecide += $decided ? 0 : 1;
            $decisions[] = [
                'key' => 'confirm:' . $textId,
                'type' => 'doubtful_text',
                'decided' => $decided,
                'id' => $textId,
                'reason' => is_string($row['reason'] ?? null) ? $row['reason'] : null,
                'text' => $byId[$textId]['text'] ?? '',
                'box' => self::rounded(ApplyFixes::box($row['box'] ?? null)) ?? $sourceBox($byId[$textId]),
                'fixes' => ['confirm_text', 'set_text'],
            ];
        }

        $omittedOpen = false;

        foreach (ApplyFixes::rows($report['omitted'] ?? null) as $index => $omitted) {
            $item = $items['omitted:' . $index] ?? null;
            $omittedOpen = $omittedOpen || $item === null;
            $decisions[] = [
                'key' => 'omitted:' . $index,
                'type' => 'omitted',
                'decided' => $item !== null,
                'decision' => $item['decision'] ?? null,
                'omitted' => $index,
                'code' => is_string($omitted['code'] ?? null) ? $omitted['code'] : 'other',
                'box' => self::rounded(ApplyFixes::box($omitted['box'] ?? null)),
                'placeId' => ApplyFixes::addedPlaceId($index),
                'fixes' => ['add_placeholder', 'keep_omitted'],
            ];
        }

        // The left-out things are ONE item with a row per thing.
        $toDecide += $omittedOpen ? 1 : 0;

        // F15: what creating would trip over — shown as items to fix.
        $stale = $scan->status === AiScanStatus::Ready ? $this->proposal->staleReferences($scan) : ['fonts' => [], 'pictures' => []];

        foreach ($stale['fonts'] as $face) {
            $decisions[] = ['key' => 'missing_font:' . $face, 'type' => 'missing_font', 'decided' => false, 'face' => $face, 'fixes' => ['choose_font']];
            $toDecide++;
        }

        foreach ($stale['pictures'] as $placeId) {
            $decisions[] = ['key' => 'missing_picture:' . $placeId, 'type' => 'missing_picture', 'decided' => false, 'placeId' => $placeId, 'fixes' => ['set_picture']];
            $toDecide++;
        }

        // ---- information ("Pro informaci")
        $shrunk = array_values(array_filter(array_column(ApplyFixes::rows($report['shrunk'] ?? null), 'id'), static fn (mixed $id): bool => is_string($id) && isset($byId[$id])));

        if ($shrunk !== []) {
            $information[] = ['code' => 'shrunk', 'n' => count($shrunk), 'texts' => $shrunk, 'undo' => 'undo_shrink', 'undoKey' => 'shrink', 'undone' => isset($items['shrink'])];
        }

        $snaps = [];

        foreach (ApplyFixes::rows($report['paletteSnaps'] ?? null) as $snap) {
            $from = ApplyFixes::colour($snap['from'] ?? null);
            $to = ApplyFixes::colour($snap['to'] ?? null);

            if ($from !== null && $to !== null) {
                $snaps[] = ['from' => $from, 'to' => $to];
            }
        }

        if ($snaps !== []) {
            $information[] = ['code' => 'palette', 'n' => count($snaps), 'pairs' => $snaps, 'undo' => 'revert_palette', 'undoKey' => 'palette', 'undone' => isset($items['palette'])];
        }

        foreach (ApplyFixes::rows($report['simplifications'] ?? null) as $simplification) {
            $information[] = [
                'code' => 'simplification',
                'simplification' => is_string($simplification['code'] ?? null) ? $simplification['code'] : null,
                'n' => is_int($simplification['n'] ?? null) ? $simplification['n'] : 1,
                'element' => $simplification['element'] ?? null,
                'box' => self::rounded(ApplyFixes::box($simplification['box'] ?? null)),
            ];
        }

        if ($placeholders !== []) {
            $information[] = ['code' => 'pictures_not_taken', 'n' => count($placeholders)];
        }

        foreach (ApplyFixes::rows($report['notes'] ?? null) as $note) {
            if (is_string($note['code'] ?? null)) {
                $information[] = ['code' => 'note', 'note' => $note['code']];
            }
        }

        // Places the user removed, merged or turned into a shape: gone from
        // `placeholders`, so the pictures step could not offer "Vrátit"
        // after a reload without them.
        $changes = [];
        foreach ($items as $key => $item) {
            if (!str_starts_with($key, 'place:')) {
                continue;
            }

            $id = substr($key, 6);
            $input = is_array($original[$id]['input'] ?? null) ? $original[$id]['input'] : [];
            $changes[] = [
                'key' => $key,
                'id' => $id,
                'op' => is_string($item['op'] ?? null) ? $item['op'] : null,
                'shape' => is_string($item['shape'] ?? null) ? $item['shape'] : null,
                'with' => is_array($item['with'] ?? null) ? array_values(array_filter($item['with'], is_string(...))) : [],
                'name' => is_string($input['name'] ?? null) ? $input['name'] : (is_string($original[$id]['name'] ?? null) ? $original[$id]['name'] : null),
            ];
        }

        $source = ApplyFixes::sourceSize($scan->proposal);

        return [
            'readOnly' => $scan->status !== AiScanStatus::Ready,
            'name' => $this->proposal->name($scan, $state),
            'nameChosen' => $state['fixes']['name'] !== null,
            'unfinished' => $scan->unfinished,
            'expiresAt' => $scan->expiresAt?->format(DATE_ATOM),
            'revision' => $state['fixes']['rev'],
            'preview' => [
                // The render with the fixes applied; before the first fix the kept candidate IS the proposal.
                'which' => $state['fixes']['preview']['seq'] > 0 ? 'proposal' : 'candidate',
                'seq' => $state['fixes']['preview']['seq'],
                'stale' => $state['fixes']['preview']['stale'] && $state['fixes']['preview']['rev'] !== $state['fixes']['rev'],
            ],
            'canvas' => ['width' => $document['canvas']['width'] ?? null, 'height' => $document['canvas']['height'] ?? null],
            'source' => $source === null ? null : ['width' => (int) round($source[0]), 'height' => (int) round($source[1])],
            'counts' => $counts,
            'toDecide' => $toDecide,
            'elements' => $elements,
            'placeholders' => $placeholders,
            // `unfilled` — empty "Součást šablony" places: the tail of the create button and the pill `K doplnění`.
            'pictures' => ['total' => count($placeholders), 'filled' => $filled, 'unfilled' => $unfilled, 'greyBackground' => $greyBackground],
            'decisions' => $decisions,
            'information' => $information,
            'changes' => $changes,
            'fonts' => $this->proposal->faces($scan->project->id),
            // face string → the font file's public address (the picker's @font-face).
            'fontFiles' => (object) array_map(
                fn (string $path): string => $this->uploaderHelper->getPublicPath($path),
                $this->proposal->faceFiles($scan->project->id),
            ),
            'rating' => $scan->rating,
        ];
    }

    /**
     * @param null|array{float, float, float, float} $box
     * @return null|list<float>
     */
    private static function rounded(null|array $box): null|array
    {
        return $box === null ? null : array_map(static fn (float $value): float => round($value, 1), $box);
    }
}
