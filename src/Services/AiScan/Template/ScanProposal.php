<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan\Template;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\AiScan;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Exceptions\TemplateRenderUnavailable;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Repository\AiScanRepository;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Services\AiScan\AiScanStorage;
use WBoost\Web\Value\AiScan\AiScanLimits;
use WBoost\Web\Value\AiScan\AiScanRefusal;
use WBoost\Web\Value\AiScan\AiScanRefused;
use WBoost\Web\Value\AiScan\AiScanStatus;
use WBoost\Web\Value\DimensionUnit;
use WBoost\Web\Value\FileSource;
use WBoost\Web\Value\TemplateDimension;

/**
 * The review of a template proposal: its stored state (`ai_scan.fixes`,
 * `ai_scan.pictures`), one fix at a time, and the picture that shows it.
 *
 * - {@see apply()} — validates one fix against the proposal as it is now,
 *   replays everything ({@see ApplyFixes}), runs the design preflight on the
 *   result and saves the state. A fix the design cannot take is refused with
 *   `invalid_fix` and nothing is saved. Call it with the scan row locked (the
 *   handlers do: {@see lock()}).
 * - {@see render()} — one bounded render of the review document through the
 *   transient path (no template is created), stored as `proposal.webp` in
 *   the scan's private directory. When the renderer is busy or away the fix
 *   stays saved and the preview is marked stale — the review says so and
 *   offers to try again.
 *
 * The state is written by conditional updates (`… AND status = 'ready'`), like
 * every change to a scan: after accept the scan page is read-only.
 */
final readonly class ScanProposal
{
    public const int TEXT_MAX = 2000;
    public const int NAME_MAX = 40;
    /** The limits of the template form (`TemplateFormData`). */
    public const int TEMPLATE_NAME_MIN = 3;
    public const int TEMPLATE_NAME_MAX = 30;

    public function __construct(
        private Connection $connection,
        private ApplyFixes $applyFixes,
        private TemplateProposal $templateProposal,
        private ScanRenderLimiter $renderLimiter,
        private AiScanStorage $storage,
        private AiScanRepository $repository,
        private FileUploadRepository $files,
        private GetFonts $getFonts,
    ) {
    }

    /**
     * Locks the scan row for the rest of the transaction and returns the
     * scan as it is now — `ready`, or the refusal `wrong_state`.
     *
     * @throws AiScanRefused
     */
    public function lock(UuidInterface $scanId): AiScan
    {
        $status = $this->connection->fetchOne('SELECT status FROM ai_scan WHERE id = :id FOR UPDATE', ['id' => $scanId->toString()]);

        if ($status !== AiScanStatus::Ready->value) {
            throw new AiScanRefused(AiScanRefusal::WrongState);
        }

        return $this->repository->get($scanId);
    }

    /**
     * The stored review state, in its full shape whatever is on the row.
     *
     * @return array{
     *     fixes: array{v: int, rev: int, name: null|string, items: array<string, array<string, mixed>>, preview: array{rev: int, seq: int, stale: bool}},
     *     pictures: array{places: array<string, array<string, mixed>>, uploads: list<string>},
     * }
     */
    public static function state(AiScan $scan): array
    {
        $fixes = $scan->fixes ?? [];
        $pictures = $scan->pictures ?? [];
        $preview = is_array($fixes['preview'] ?? null) ? $fixes['preview'] : [];
        $items = [];

        foreach (is_array($fixes['items'] ?? null) ? $fixes['items'] : [] as $key => $item) {
            if (is_string($key) && is_array($item)) {
                /** @var array<string, mixed> $item */
                $items[$key] = $item;
            }
        }

        $places = [];

        foreach (is_array($pictures['places'] ?? null) ? $pictures['places'] : [] as $id => $place) {
            if (is_string($id) && is_array($place)) {
                /** @var array<string, mixed> $place */
                $places[$id] = $place;
            }
        }

        return [
            'fixes' => [
                'v' => 1,
                'rev' => is_int($fixes['rev'] ?? null) ? $fixes['rev'] : 0,
                'name' => is_string($fixes['name'] ?? null) ? $fixes['name'] : null,
                'items' => $items,
                'preview' => [
                    'rev' => is_int($preview['rev'] ?? null) ? $preview['rev'] : 0,
                    'seq' => is_int($preview['seq'] ?? null) ? $preview['seq'] : 0,
                    'stale' => ($preview['stale'] ?? false) === true,
                ],
            ],
            'pictures' => [
                'places' => $places,
                'uploads' => array_values(array_filter(
                    is_array($pictures['uploads'] ?? null) ? $pictures['uploads'] : [],
                    static fn (mixed $id): bool => is_string($id) && Uuid::isValid($id),
                )),
            ],
        ];
    }

    /**
     * The face strings of the project's fonts — the only faces a proposal may use.
     *
     * @return list<string>
     */
    public function faces(UuidInterface $projectId): array
    {
        $faces = [];

        foreach ($this->getFonts->allForProject($projectId) as $font) {
            foreach ($font->faces as $face) {
                $faces[] = $font->faceFamily($face);
            }
        }

        return $faces;
    }

    /**
     * Where each face's font file is stored — for the review's font picker,
     * which draws every face in its own typeface.
     *
     * @return array<string, string> face string → storage path
     */
    public function faceFiles(UuidInterface $projectId): array
    {
        $files = [];

        foreach ($this->getFonts->allForProject($projectId) as $font) {
            foreach ($font->faces as $face) {
                $files[$font->faceFamily($face)] = $face->filePath;
            }
        }

        return $files;
    }

    /**
     * The design document of the proposal with every fix applied.
     *
     * @param null|array{fixes: array{items: array<string, array<string, mixed>>, ...}, pictures: array{places: array<string, array<string, mixed>>, ...}} $state
     * @param null|string $standInAssetId null = as the review shows it; the project's stand-in row = as the template is written
     * @return array{canvas: array<string, mixed>, elements: list<array<string, mixed>>}
     */
    public function document(AiScan $scan, null|array $state = null, null|string $standInAssetId = null): array
    {
        $replay = $this->replay($scan, $state, $standInAssetId);

        return ['canvas' => $replay['canvas'], 'elements' => $replay['elements']];
    }

    /**
     * The document plus `places`: the ids of its places for pictures, in
     * stack order.
     *
     * @param null|array{fixes: array{items: array<string, array<string, mixed>>, ...}, pictures: array{places: array<string, array<string, mixed>>, ...}} $state
     * @return array{canvas: array<string, mixed>, elements: list<array<string, mixed>>, places: list<string>}
     */
    public function replay(AiScan $scan, null|array $state = null, null|string $standInAssetId = null): array
    {
        $state ??= self::state($scan);

        return $this->applyFixes->replay(
            $scan->project->id,
            $scan->proposal ?? [],
            $scan->report ?? [],
            $state['fixes']['items'],
            $state['pictures']['places'],
            $this->pictureSizes($state['pictures']['places']),
            $standInAssetId,
        );
    }

    /**
     * The template's name: what the user typed, else the picture's headline
     * text, else the file name.
     *
     * @param null|array{fixes: array{name: null|string, items: array<string, array<string, mixed>>, ...}, pictures: array{places: array<string, array<string, mixed>>, ...}} $state
     */
    public function name(AiScan $scan, null|array $state = null): string
    {
        $state ??= self::state($scan);

        if ($state['fixes']['name'] !== null && $state['fixes']['name'] !== '') {
            return $state['fixes']['name'];
        }

        $headline = ($scan->report ?? [])['headline'] ?? null;

        foreach ($this->document($scan, $state)['elements'] as $element) {
            if (is_string($headline) && ($element['id'] ?? null) === $headline && is_string($element['text'] ?? null)) {
                $text = trim(preg_replace('/\s+/u', ' ', $element['text']) ?? '');

                if (mb_strlen($text) >= self::TEMPLATE_NAME_MIN) {
                    return trim(mb_substr($text, 0, self::TEMPLATE_NAME_MAX));
                }
            }
        }

        $file = is_string($scan->sourceMeta['name'] ?? null) ? pathinfo($scan->sourceMeta['name'], PATHINFO_FILENAME) : '';
        $file = trim(preg_replace('/[\x00-\x1F\x7F<>]/u', '', $file) ?? '');

        return mb_strlen($file) >= self::TEMPLATE_NAME_MIN ? trim(mb_substr($file, 0, self::TEMPLATE_NAME_MAX)) : 'Šablona z obrázku';
    }

    /**
     * The size the template is created at: the format chosen before the scan
     * (`options.targetUnit` px | mm | cm with `targetWidth` / `targetHeight`
     * — "2×", a custom width, a print size) when it has the picture's ratio
     * and fits the limit; otherwise the proposal's own canvas, in pixels. A
     * print size comes without bleed: a picture cannot tell it.
     *
     * @param array{canvas: array<string, mixed>, ...} $document
     */
    public static function dimension(AiScan $scan, array $document): TemplateDimension
    {
        $width = (int) round(ApplyFixes::number($document['canvas']['width'] ?? null) ?? 0.0);
        $height = (int) round(ApplyFixes::number($document['canvas']['height'] ?? null) ?? 0.0);
        $own = new TemplateDimension(DimensionUnit::Px, (float) $width, (float) $height);

        $unit = DimensionUnit::tryFrom(is_string($scan->options['targetUnit'] ?? null) ? $scan->options['targetUnit'] : '');
        $targetWidth = is_numeric($scan->options['targetWidth'] ?? null) ? (float) $scan->options['targetWidth'] : 0.0;
        $targetHeight = is_numeric($scan->options['targetHeight'] ?? null) ? (float) $scan->options['targetHeight'] : 0.0;

        if ($unit === null || $targetWidth <= 0.0 || $targetHeight <= 0.0 || $width < 1 || $height < 1) {
            return $own;
        }

        $target = new TemplateDimension($unit, $targetWidth, $targetHeight);
        $ratio = ($target->width() / max(1, $target->height())) / ($width / $height);

        if (abs($ratio - 1.0) > 0.02 || max($target->width(), $target->height()) > AiScanLimits::TEMPLATE_MAX_LONG_EDGE || min($target->width(), $target->height()) < 1) {
            return $own;
        }

        return $target;
    }

    /**
     * One fix. Returns facts about what it did (`assigned` for a bulk
     * assignment).
     *
     * @param array<string, mixed> $fix `{type, …}` — the types and their fields: docs/architecture/ai-scan.md § "Fixes"
     * @param list<string> $uploadedFileIds gallery files uploaded FOR this scan by the request (they go to the bin when the proposal is thrown away)
     * @return array{assigned: null|int}
     *
     * @throws AiScanRefused `wrong_state`, `invalid_fix`
     */
    public function apply(UuidInterface $scanId, array $fix, array $uploadedFileIds = []): array
    {
        $scan = $this->lock($scanId);
        $state = self::state($scan);
        $facts = ['assigned' => null];

        foreach ($uploadedFileIds as $fileId) {
            if (!in_array($fileId, $state['pictures']['uploads'], true)) {
                $state['pictures']['uploads'][] = $fileId;
            }
        }

        $before = $state;
        $state = $this->changed($scan, $state, $fix, $facts);

        // A fix must not BREAK the design. One that leaves an already broken
        // design broken is let through: with a font and a picture both gone
        // (F15), each of the two repairs is such a fix.
        if ($this->templateProposal->check($scan->project, $this->document($scan, $state))['blocked']
            && !$this->templateProposal->check($scan->project, $this->document($scan, $before))['blocked']) {
            throw new AiScanRefused(AiScanRefusal::InvalidFix, ['reason' => 'design']);
        }

        $state['fixes']['rev']++;
        $state['fixes']['preview']['stale'] = true;
        $this->save($scanId, $state);

        return $facts;
    }

    /**
     * Renders the proposal as it is now and stores the picture. One render,
     * no retry: `false` = the renderer was busy or away and the preview stays
     * stale.
     *
     * @throws AiScanRefused `wrong_state`
     */
    public function render(UuidInterface $scanId): bool
    {
        $scan = $this->lock($scanId);
        $state = self::state($scan);
        $slot = $this->renderLimiter->acquire();

        if ($slot === null) {
            return false;
        }

        try {
            $result = $this->templateProposal->candidate($scan->project, $this->document($scan, $state));
        } catch (TemplateRenderUnavailable) {
            return false;
        } finally {
            $this->renderLimiter->release($slot);
        }

        if ($result['bytes'] === null) {
            return false;
        }

        try {
            $this->storage->write(
                AiScanStorage::directory($scan->project->id->toString(), $scan->id->toString(), $scan->storageSecret),
                AiScanStorage::PROPOSAL,
                $result['bytes'],
            );
        } catch (\League\Flysystem\FilesystemException) {
            return false;
        }

        $state['fixes']['preview'] = ['rev' => $state['fixes']['rev'], 'seq' => $state['fixes']['preview']['seq'] + 1, 'stale' => false];
        $this->save($scanId, $state);

        return true;
    }

    /**
     * What the proposal references that the project no longer has — a font
     * removed or a picture trashed since it was chosen (F15). Empty = the
     * template can be created.
     *
     * @return array{fonts: list<string>, pictures: list<string>} faces; place ids
     */
    public function staleReferences(AiScan $scan): array
    {
        $state = self::state($scan);
        $faces = $this->faces($scan->project->id);
        $stale = ['fonts' => [], 'pictures' => []];

        foreach ($this->document($scan, $state)['elements'] as $element) {
            $font = $element['font'] ?? null;

            if (($element['kind'] ?? null) === 'text' && is_string($font) && !in_array($font, $faces, true) && !in_array($font, $stale['fonts'], true)) {
                $stale['fonts'][] = $font;
            }
        }

        foreach ($state['pictures']['places'] as $placeId => $place) {
            $fileId = $place['fileId'] ?? null;

            if (is_string($fileId) && $fileId !== '' && $this->galleryFile($scan, $fileId) === null) {
                $stale['pictures'][] = $placeId;
            }
        }

        return $stale;
    }

    /**
     * A live gallery picture of the scan's project, or null.
     */
    public function galleryFile(AiScan $scan, string $fileId): null|FileUpload
    {
        if (!Uuid::isValid($fileId)) {
            return null;
        }

        $file = $this->files->find(Uuid::fromString($fileId));

        if ($file === null || !$file->project->id->equals($scan->project->id) || $file->source !== FileSource::ProjectImage || $file->isTrashed()) {
            return null;
        }

        return $file;
    }

    /**
     * @param array{fixes: array<string, mixed>, pictures: array<string, mixed>} $state
     */
    private function save(UuidInterface $scanId, array $state): void
    {
        $fixes = $state['fixes'];
        $pictures = $state['pictures'];

        // Empty maps must stay JSON objects.
        if (($fixes['items'] ?? []) === []) {
            $fixes['items'] = new \stdClass();
        }

        if (($pictures['places'] ?? []) === []) {
            $pictures['places'] = new \stdClass();
        }

        $changed = $this->connection->executeStatement(
            "UPDATE ai_scan SET fixes = CAST(:fixes AS JSONB), pictures = CAST(:pictures AS JSONB) WHERE id = :id AND status = 'ready'",
            [
                'id' => $scanId->toString(),
                'fixes' => json_encode($fixes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'pictures' => json_encode($pictures, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        );

        if ($changed !== 1) {
            throw new AiScanRefused(AiScanRefusal::WrongState);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $places
     * @return array<string, array{int, int}>
     */
    private function pictureSizes(array $places): array
    {
        $sizes = [];

        foreach ($places as $place) {
            $fileId = $place['fileId'] ?? null;

            if (!is_string($fileId) || !Uuid::isValid($fileId) || isset($sizes[$fileId])) {
                continue;
            }

            $file = $this->files->find(Uuid::fromString($fileId));

            if ($file !== null && $file->width !== null && $file->height !== null && $file->width > 0 && $file->height > 0) {
                $sizes[$fileId] = [$file->width, $file->height];
            }
        }

        return $sizes;
    }

    /**
     * The state after the fix — or the refusal.
     *
     * @param array{fixes: array{v: int, rev: int, name: null|string, items: array<string, array<string, mixed>>, preview: array{rev: int, seq: int, stale: bool}}, pictures: array{places: array<string, array<string, mixed>>, uploads: list<string>}} $state
     * @param array<string, mixed> $fix
     * @param array{assigned: null|int} $facts
     * @return array{fixes: array{v: int, rev: int, name: null|string, items: array<string, array<string, mixed>>, preview: array{rev: int, seq: int, stale: bool}}, pictures: array{places: array<string, array<string, mixed>>, uploads: list<string>}}
     *
     * @throws AiScanRefused
     */
    private function changed(AiScan $scan, array $state, array $fix, array &$facts): array
    {
        $type = is_string($fix['type'] ?? null) ? $fix['type'] : '';
        $report = $scan->report ?? [];
        $elements = [];
        $replay = $this->replay($scan, $state);
        $placeIds = $replay['places'];

        foreach ($replay['elements'] as $element) {
            if (is_string($element['id'] ?? null)) {
                $elements[$element['id']] = $element;
            }
        }

        $id = is_string($fix['id'] ?? null) ? $fix['id'] : '';
        $items = &$state['fixes']['items'];
        $places = &$state['pictures']['places'];

        $text = static function (string $id) use ($elements): void {
            if (($elements[$id]['kind'] ?? null) !== 'text') {
                throw self::invalid('id');
            }
        };
        $place = static function (string $id, bool $backgroundToo = true) use ($elements, $placeIds): void {
            if (!in_array($id, $placeIds, true) || (!$backgroundToo && ($elements[$id]['kind'] ?? null) === 'background')) {
                throw self::invalid('id');
            }
        };

        switch ($type) {
            case 'choose_font':
            case 'keep_font':
                $group = is_string($fix['group'] ?? null) ? $fix['group'] : '';

                if (!in_array($group, array_column(ApplyFixes::rows($report['fonts'] ?? null), 'group'), true)) {
                    throw self::invalid('group');
                }

                if ($type === 'keep_font') {
                    $items['font:' . $group] = ['type' => 'keep_font'];

                    break;
                }

                $face = is_string($fix['face'] ?? null) ? $fix['face'] : '';

                if (!in_array($face, $this->faces($scan->project->id), true)) {
                    throw self::invalid('face');
                }

                $items['font:' . $group] = ['type' => 'choose_font', 'face' => $face];

                break;

            case 'set_text':
            case 'confirm_text':
                $text($id);
                $value = $fix['text'] ?? null;

                if ($type === 'set_text' || $value !== null) {
                    $items['text:' . $id] = ['type' => 'set_text', 'text' => self::plainText($value, self::TEXT_MAX, true)];
                }

                if ($type === 'confirm_text') {
                    $items['confirm:' . $id] = ['type' => 'confirm_text'];
                }

                break;

            case 'set_input':
                $text($id);

                if (!is_bool($fix['input'] ?? null)) {
                    throw self::invalid('input');
                }

                $items['input:' . $id] = ['type' => 'set_input', 'input' => $fix['input']];

                break;

            case 'rename':
                if (!isset($elements[$id]) || ($elements[$id]['kind'] ?? null) === 'background') {
                    throw self::invalid('id');
                }

                $items['name:' . $id] = ['type' => 'rename', 'name' => self::plainText($fix['name'] ?? null, self::NAME_MAX, false)];

                break;

            case 'undo_shrink':
                $items['shrink'] = ['type' => 'undo_shrink'];

                break;

            case 'revert_palette':
                $items['palette'] = ['type' => 'revert_palette'];

                break;

            case 'add_placeholder':
            case 'keep_omitted':
                $index = $fix['omitted'] ?? null;

                if (!is_int($index) || !isset(ApplyFixes::rows($report['omitted'] ?? null)[$index])) {
                    throw self::invalid('omitted');
                }

                $items['omitted:' . $index] = ['type' => $type, 'decision' => $type === 'add_placeholder' ? 'place' : 'keep'];

                break;

            case 'remove_placeholder':
                $place($id);
                $items['place:' . $id] = array_filter(['type' => $type, 'op' => 'remove', 'colour' => self::optionalColour($fix['colour'] ?? null)]);
                unset($places[$id]);

                break;

            case 'placeholder_to_shape':
                $place($id, false);

                if (!in_array($fix['shape'] ?? null, ApplyFixes::SHAPES, true)) {
                    throw self::invalid('shape');
                }

                $items['place:' . $id] = array_filter(['type' => $type, 'op' => 'shape', 'shape' => $fix['shape'], 'colour' => self::optionalColour($fix['colour'] ?? null)]);
                unset($places[$id]);

                break;

            case 'merge_placeholders':
                $ids = array_values(array_unique(array_filter(is_array($fix['ids'] ?? null) ? $fix['ids'] : [], is_string(...))));

                if (count($ids) < 2) {
                    throw self::invalid('ids');
                }

                foreach ($ids as $member) {
                    $place($member, false);
                }

                // The place lowest in the stack keeps its id, name and picture.
                $first = null;

                foreach (array_keys($elements) as $elementId) {
                    if (in_array($elementId, $ids, true)) {
                        $first = (string) $elementId;

                        break;
                    }
                }

                assert($first !== null);
                $others = array_values(array_diff($ids, [$first]));
                $items['place:' . $first] = ['type' => $type, 'op' => 'merge', 'with' => $others];

                foreach ($others as $other) {
                    unset($places[$other], $items['place:' . $other]);
                }

                break;

            case 'set_kind':
                $place($id, false);

                if (!in_array($fix['kind'] ?? null, [ApplyFixes::KIND_FIXED, ApplyFixes::KIND_INPUT], true)) {
                    throw self::invalid('kind');
                }

                $places[$id] = ['kind' => $fix['kind']] + ($places[$id] ?? []);

                break;

            case 'set_fit':
                $place($id);

                if (!in_array($fix['fit'] ?? null, [ApplyFixes::FIT_CONTAIN, ApplyFixes::FIT_COVER], true)) {
                    throw self::invalid('fit');
                }

                $places[$id] = ['fit' => $fix['fit']] + ($places[$id] ?? []);

                break;

            case 'set_picture':
                $place($id);
                $fileId = $fix['fileId'] ?? null;

                if ($fileId === null) {
                    unset($places[$id]['fileId'], $places[$id]['none']);

                    break;
                }

                if (!is_string($fileId) || $this->galleryFile($scan, $fileId) === null) {
                    throw self::invalid('fileId');
                }

                $places[$id] = ['fileId' => $fileId] + ($places[$id] ?? []);
                unset($places[$id]['none']);

                break;

            case 'no_picture':
                // "Bez ukázky" / "Nechat šedé pozadí": decided, stays empty.
                $place($id);
                $places[$id] = ['none' => true] + ($places[$id] ?? []);
                unset($places[$id]['fileId']);

                break;

            case 'assign_pictures':
                $fileIds = array_values(array_filter(is_array($fix['fileIds'] ?? null) ? $fix['fileIds'] : [], is_string(...)));

                if ($fileIds === [] || count($fileIds) > 50) {
                    throw self::invalid('fileIds');
                }

                foreach ($fileIds as $fileId) {
                    if ($this->galleryFile($scan, $fileId) === null) {
                        throw self::invalid('fileIds');
                    }
                }

                $assigned = 0;

                // Into the empty places, in the order the cards are listed.
                foreach (self::placeOrder($report, $placeIds) as $placeId) {
                    if ($fileIds === []) {
                        break;
                    }

                    if (is_string($places[$placeId]['fileId'] ?? null)) {
                        continue;
                    }

                    $places[$placeId] = ['fileId' => array_shift($fileIds)] + ($places[$placeId] ?? []);
                    unset($places[$placeId]['none']);
                    $assigned++;
                }

                $facts['assigned'] = $assigned;

                break;

            case 'move_picture':
                $from = is_string($fix['from'] ?? null) ? $fix['from'] : '';
                $to = is_string($fix['to'] ?? null) ? $fix['to'] : '';
                $place($from);
                $place($to);
                $moving = $places[$from]['fileId'] ?? null;
                $there = $places[$to]['fileId'] ?? null;

                if (!is_string($moving) || $from === $to) {
                    throw self::invalid('from');
                }

                // "Patří jinam": the two places swap what they hold.
                $places[$to] = ['fileId' => $moving] + ($places[$to] ?? []);
                unset($places[$to]['none'], $places[$from]['fileId']);

                if (is_string($there)) {
                    $places[$from]['fileId'] = $there;
                }

                break;

            case 'set_name':
                $state['fixes']['name'] = self::plainText($fix['name'] ?? null, self::TEMPLATE_NAME_MAX, false);

                if (mb_strlen($state['fixes']['name']) < self::TEMPLATE_NAME_MIN) {
                    throw self::invalid('name');
                }

                break;

            case 'undo':
                $key = is_string($fix['key'] ?? null) ? $fix['key'] : '';

                if (!isset($items[$key])) {
                    throw self::invalid('key');
                }

                unset($items[$key]);

                break;

            default:
                throw self::invalid('type');
        }

        return $state;
    }

    /**
     * Place ids in the order the picture cards are listed: the report's
     * places first, then places the user added — only those still there.
     *
     * @param array<string, mixed> $report
     * @param list<string> $placeIds the places of the document ({@see replay()})
     * @return list<string>
     */
    public static function placeOrder(array $report, array $placeIds): array
    {
        $order = [];

        foreach (ApplyFixes::rows($report['placeholders'] ?? null) as $placeholder) {
            $id = $placeholder['id'] ?? null;

            if (is_string($id) && in_array($id, $placeIds, true) && !in_array($id, $order, true)) {
                $order[] = $id;
            }
        }

        foreach ($placeIds as $id) {
            if (!in_array($id, $order, true)) {
                $order[] = $id;
            }
        }

        return $order;
    }

    private static function plainText(mixed $value, int $max, bool $multiline): string
    {
        if (!is_string($value)) {
            throw self::invalid('text');
        }

        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = $multiline ? $value : trim($value);
        $pattern = $multiline ? '/[\x00-\x09\x0B-\x1F\x7F]/u' : '/[\x00-\x1F\x7F<>]/u';

        if ($value === '' || mb_strlen($value) > $max || preg_match($pattern, $value) !== 0) {
            throw self::invalid('text');
        }

        return $value;
    }

    private static function optionalColour(mixed $value): null|string
    {
        if ($value === null) {
            return null;
        }

        return ApplyFixes::colour($value) ?? throw self::invalid('colour');
    }

    private static function invalid(string $field): AiScanRefused
    {
        return new AiScanRefused(AiScanRefusal::InvalidFix, ['field' => $field]);
    }
}
