<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Print\Cmyk;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

use WBoost\Web\Exceptions\CmykConversionFailed;
use WBoost\Web\Exceptions\PdfStructureNotSupported;

/**
 * One run of {@see PdfCmykConverter}: walks a PDF from its pages down
 * through everything that carries colour — by TYPE, never by pattern
 * matching — and writes each object converted. What the walk does not reach
 * is not written, and what it has no rule for throws: a construct is either
 * understood or the export fails.
 *
 * Objects keep their numbers. An object that is needed in two forms (the
 * same resource used both on a page and inside a luminosity soft mask) gets
 * a second, new number for the second form.
 *
 * @internal
 */
#[Exclude]
final class CmykChunkConversion
{
    private const int MAX_CONTENT_BYTES = 256_000_000;

    /** Input fed to the inflater at once: deflate expands at most ~1030 : 1, so ~8 MB come out. */
    private const int INFLATE_BYTES = 8192;

    private const array BLEND_MODES = [
        'Normal', 'Compatible', 'Multiply', 'Screen', 'Overlay', 'Darken', 'Lighten', 'ColorDodge', 'ColorBurn',
        'HardLight', 'SoftLight', 'Difference', 'Exclusion', 'Hue', 'Saturation', 'Color', 'Luminosity',
    ];

    private const string REGISTRATION = 'WbRegistration';

    /** @var array<int, array<string, int>> source number → form → output number */
    private array $slots = [];

    private int $next;

    private null|int $registration = null;

    public function __construct(
        private readonly PdfReader $reader,
        private readonly PdfWriter $writer,
        private readonly CmykPass $pass,
        private readonly IccColorTransform $icc,
        private readonly null|CropMarkLines $marks,
    ) {
        $this->next = $this->reader->size;
    }

    public function run(): void
    {
        $catalog = $this->reader->value($this->reader->root);
        $pages = $catalog instanceof PdfDict ? $catalog->get('Pages') : null;

        if (!$pages instanceof PdfRef) {
            throw new PdfStructureNotSupported('The PDF catalog has no /Pages.');
        }

        $this->pageTree($pages->number, 0);
        $this->slots[$this->reader->root]['x'] = $this->reader->root;
        $this->writer->object($this->reader->root, new PdfDict(['Type' => new PdfName('Catalog'), 'Pages' => $pages]));
        $this->writer->finish($this->reader->root, $this->next);
    }

    private function pageTree(int $number, int $depth): void
    {
        $node = $this->reader->value($number);

        if ($depth > 32 || isset($this->slots[$number]) || !$node instanceof PdfDict) {
            throw new PdfStructureNotSupported('The PDF page tree is too deep, loops or is not made of dictionaries.');
        }

        $this->slots[$number]['x'] = $number;

        if ($node->name('Type') === 'Page') {
            $this->page($number, $node);

            return;
        }

        if ($node->name('Type') !== 'Pages') {
            throw new PdfStructureNotSupported(sprintf('The PDF object %d is neither a page nor a page-tree node.', $number));
        }

        $out = new PdfDict();

        foreach ($node->entries as $key => $value) {
            match ($key) {
                'Type', 'Parent' => $out->set($key, $value),
                'Kids', 'Count', 'MediaBox', 'CropBox', 'Rotate' => $out->set($key, $this->reader->resolve($value)),
                default => $this->fail(sprintf('A page-tree node has /%s, which the colour pass does not handle.', $key)),
            };
        }

        $kids = $out->get('Kids');

        if (!is_array($kids)) {
            throw new PdfStructureNotSupported('A PDF page-tree node has no /Kids.');
        }

        foreach ($kids as $kid) {
            if (!$kid instanceof PdfRef) {
                throw new PdfStructureNotSupported('A PDF page-tree node lists something that is not a page.');
            }

            $this->pageTree($kid->number, $depth + 1);
        }

        $this->writer->object($number, $out);
    }

    private function page(int $number, PdfDict $page): void
    {
        $out = new PdfDict();
        $resources = new PdfDict();
        $spaces = [];
        $contents = [];

        foreach ($page->entries as $key => $value) {
            switch ($key) {
                case 'Type':
                case 'Parent':
                    $out->set($key, $value);

                    break;

                case 'MediaBox':
                case 'CropBox':
                case 'BleedBox':
                case 'TrimBox':
                case 'ArtBox':
                case 'Rotate':
                case 'UserUnit':
                    $out->set($key, $this->reader->resolve($value));

                    break;

                case 'Resources':
                    [$resources, $spaces] = $this->resources($value, false);

                    break;

                case 'Contents':
                    $value = $this->reader->resolve($value);
                    $contents = is_array($value) ? $value : [$value];

                    break;

                case 'Group':
                    $out->set($key, $this->group($value, false, false));

                    break;

                    // not part of what is printed: the structure tree and its tab order, links, editing data
                case 'StructParents':
                case 'Tabs':
                case 'Annots':
                case 'Metadata':
                case 'PieceInfo':
                case 'LastModified':
                    break;

                default:
                    $this->fail(sprintf('A page has /%s, which the colour pass does not handle.', $key));
            }
        }

        $content = '';

        foreach ($contents as $reference) {
            if (!$reference instanceof PdfRef || !$this->reader->isStream($reference->number)) {
                throw new PdfStructureNotSupported('A page content is not a stream.');
            }

            $content .= $this->decoded($reference->number) . "\n";
        }

        $content = CmykContentRewriter::rewrite($content, $spaces, $this->pass->rules, false);
        $contentNumber = $this->next++;
        $this->writer->stream($contentNumber, new PdfDict(['Filter' => new PdfName('FlateDecode')]), self::deflate($content));
        $list = [new PdfRef($contentNumber)];

        if ($this->marks !== null && $this->marks->lines !== []) {
            $box = $out->get('MediaBox');

            if (!is_array($box) || count($box) !== 4 || !PdfSyntax::isNumber($box[0]) || !PdfSyntax::isNumber($box[3])) {
                throw new PdfStructureNotSupported('A PDF page has no MediaBox of its own.');
            }

            $colorSpaces = $resources->get('ColorSpace');
            $colorSpaces = $colorSpaces instanceof PdfDict ? $colorSpaces : new PdfDict();
            $colorSpaces->set(self::REGISTRATION, new PdfRef($this->registration()));
            $resources->set('ColorSpace', $colorSpaces);

            // Chromium's page content changes the coordinate system outside any q … Q: fence it in
            $open = $this->next++;
            $this->writer->stream($open, new PdfDict(), "q\n");
            $close = $this->next++;
            $this->writer->stream($close, new PdfDict(), $this->markOperators(self::float($box[0]), self::float($box[3])));
            $list = [new PdfRef($open), new PdfRef($contentNumber), new PdfRef($close)];
        }

        $out->set('Resources', $resources);
        $out->set('Contents', count($list) === 1 ? $list[0] : $list);
        $this->writer->object($number, $out);
    }

    private function registration(): int
    {
        if ($this->registration === null) {
            $this->registration = $this->next++;
            $this->writer->object($this->registration, [
                new PdfName('Separation'),
                new PdfName('All'),
                new PdfName('DeviceCMYK'),
                new PdfDict(['FunctionType' => '2', 'Domain' => ['0', '1'], 'C0' => ['0', '0', '0', '0'], 'C1' => ['1', '1', '1', '1'], 'N' => '1']),
            ]);
        }

        return $this->registration;
    }

    private function markOperators(float $left, float $top): string
    {
        $marks = $this->marks;
        assert($marks !== null);
        $out = sprintf("Q\nq\n/%s CS 1 SCN\n%s w 0 J [] 0 d\n", self::REGISTRATION, PdfSyntax::number($marks->weight));

        foreach ($marks->lines as [$x1, $y1, $x2, $y2]) {
            $out .= sprintf(
                "%s %s m %s %s l S\n",
                PdfSyntax::number($left + $x1),
                PdfSyntax::number($top - $y1),
                PdfSyntax::number($left + $x2),
                PdfSyntax::number($top - $y2),
            );
        }

        return $out . "Q\n";
    }

    /**
     * @return array{PdfDict, array<string, string>} the converted resources and the kinds of their colour spaces
     */
    private function resources(mixed $value, bool $mask): array
    {
        $resources = $this->reader->resolve($value);

        if (!$resources instanceof PdfDict) {
            throw new PdfStructureNotSupported('A /Resources entry is not a dictionary.');
        }

        $out = new PdfDict();
        $spaces = [];

        foreach ($resources->entries as $category => $entries) {
            $entries = $this->reader->resolve($entries);

            if ($category === 'ProcSet') {
                $out->set($category, $entries);

                continue;
            }

            if (!$entries instanceof PdfDict) {
                throw new PdfStructureNotSupported(sprintf('The /%s resources are not a dictionary.', $category));
            }

            $converted = new PdfDict();

            foreach ($entries->entries as $name => $entry) {
                switch ($category) {
                    case 'ExtGState':
                        $converted->set($name, $this->graphicsState($entry, $mask));

                        break;

                    case 'ColorSpace':
                        [$space, $kind] = $this->colorSpace($entry, $mask);
                        $converted->set($name, $space);
                        $spaces[$name] = $kind;

                        break;

                    case 'Pattern':
                        $converted->set($name, $this->pattern($entry, $mask));

                        break;

                    case 'Shading':
                        $converted->set($name, $this->shading($entry, $mask));

                        break;

                    case 'XObject':
                        $converted->set($name, $this->xObject($entry, $mask));

                        break;

                    case 'Font':
                        $converted->set($name, $this->font($entry, $mask));

                        break;

                    case 'Properties':
                        $converted->set($name, $this->copy($entry));

                        break;

                    default:
                        $this->fail(sprintf('Resources of the kind /%s are not handled by the colour pass.', $category));
                }
            }

            $out->set($category, $converted);
        }

        return [$out, $spaces];
    }

    private function graphicsState(mixed $value, bool $mask): mixed
    {
        $state = $this->reader->resolve($value);

        if (!$state instanceof PdfDict) {
            throw new PdfStructureNotSupported('A graphics state is not a dictionary.');
        }

        $softMask = $this->reader->resolve($state->get('SMask'));

        if (!$value instanceof PdfRef) {
            return $this->graphicsStateDict($state, $mask);
        }

        // only a state with a soft mask differs between a page and a mask
        [$number, $fresh] = $this->slot($value->number, $softMask instanceof PdfDict ? ($mask ? 'm' : 'n') : 'x');

        if ($fresh) {
            $this->writer->object($number, $this->graphicsStateDict($state, $mask));
        }

        return new PdfRef($number);
    }

    private function graphicsStateDict(PdfDict $state, bool $mask): PdfDict
    {
        $out = new PdfDict();

        foreach ($state->entries as $key => $value) {
            switch ($key) {
                case 'Type':
                case 'LW':
                case 'LC':
                case 'LJ':
                case 'ML':
                case 'D':
                case 'RI':
                case 'OP':
                case 'op':
                case 'OPM':
                case 'FL':
                case 'SM':
                case 'SA':
                case 'CA':
                case 'ca':
                case 'AIS':
                case 'TK':
                    $out->set($key, $this->reader->resolve($value));

                    break;

                case 'BM':
                    $modes = $this->reader->resolve($value);

                    foreach (is_array($modes) ? $modes : [$modes] as $mode) {
                        if (!$mode instanceof PdfName || !in_array($mode->value, self::BLEND_MODES, true)) {
                            $this->fail('A graphics state has an unknown blend mode.');
                        }
                    }

                    $out->set($key, $modes);

                    break;

                case 'SMask':
                    $softMask = $this->reader->resolve($value);
                    $out->set($key, $softMask instanceof PdfDict ? $this->softMask($softMask) : $softMask);

                    if (!$softMask instanceof PdfDict && !($softMask instanceof PdfName && $softMask->value === 'None')) {
                        $this->fail('A graphics state has an unreadable soft mask.');
                    }

                    break;

                default:
                    // transfer / black-generation / undercolour-removal functions, halftones: a print
                    // file must not carry them, and Chromium does not write them
                    $this->fail(sprintf('A graphics state has /%s, which the colour pass does not handle.', $key));
            }
        }

        return $out;
    }

    private function softMask(PdfDict $softMask): PdfDict
    {
        $out = new PdfDict();
        $luminosity = $softMask->name('S') === 'Luminosity';

        if (!$luminosity && $softMask->name('S') !== 'Alpha') {
            $this->fail('A soft mask is neither a luminosity nor an alpha mask.');
        }

        foreach ($softMask->entries as $key => $value) {
            switch ($key) {
                case 'Type':
                case 'S':
                    $out->set($key, $value);

                    break;

                case 'G':
                    $out->set($key, $this->form($value, $luminosity, $luminosity));

                    break;

                case 'BC':
                    // the backdrop, in the group's colour space — which is DeviceGray from now on
                    $backdrop = $this->reader->resolve($value);

                    if ($luminosity) {
                        $out->set($key, is_array($backdrop) && count($backdrop) === 1 ? $backdrop : $this->color($backdrop, true));
                    }

                    break;

                case 'TR':
                    $transfer = $this->reader->resolve($value);

                    if (!$transfer instanceof PdfName || $transfer->value !== 'Identity') {
                        $this->fail('A soft mask has a transfer function.');
                    }

                    $out->set($key, $transfer);

                    break;

                default:
                    $this->fail(sprintf('A soft mask has /%s, which the colour pass does not handle.', $key));
            }
        }

        return $out;
    }

    /**
     * A transparency group dictionary. Its blending space follows the file:
     * DeviceCMYK on a page, DeviceGray inside a luminosity mask.
     */
    private function group(mixed $value, bool $mask, bool $maskRoot): PdfDict
    {
        $group = $this->reader->resolve($value);

        if (!$group instanceof PdfDict || ($group->has('S') && $group->name('S') !== 'Transparency')) {
            $this->fail('A group is not a transparency group.');
        }

        $out = new PdfDict();

        foreach ($group->entries as $key => $entry) {
            switch ($key) {
                case 'Type':
                case 'S':
                case 'I':
                case 'K':
                    $out->set($key, $this->reader->resolve($entry));

                    break;

                case 'CS':
                    [$space, $kind] = $this->colorSpace($entry, $mask);

                    if ($kind !== 'rgb' && $kind !== 'gray' && $kind !== 'cmyk' || ($mask && $kind === 'cmyk')) {
                        $this->fail('A transparency group blends in a colour space the colour pass does not handle.');
                    }

                    $out->set($key, $space);

                    break;

                default:
                    $this->fail(sprintf('A transparency group has /%s, which the colour pass does not handle.', $key));
            }
        }

        if ($maskRoot && !$out->has('CS')) {
            $out->set('CS', new PdfName('DeviceGray'));
        }

        return $out;
    }

    private function xObject(mixed $value, bool $mask): PdfRef
    {
        if (!$value instanceof PdfRef || !$this->reader->has($value->number) || !$this->reader->isStream($value->number)) {
            throw new PdfStructureNotSupported('An XObject is not a stream.');
        }

        $dictionary = $this->reader->value($value->number);
        assert($dictionary instanceof PdfDict);

        return match ($dictionary->name('Subtype')) {
            'Image' => $this->image($value, $mask),
            'Form' => $this->form($value, $mask, false),
            default => $this->fail('An XObject is neither an image nor a form.'),
        };
    }

    private function form(mixed $value, bool $mask, bool $maskRoot): PdfRef
    {
        $dictionary = $value instanceof PdfRef && $this->reader->isStream($value->number) ? $this->reader->value($value->number) : null;

        if (!$value instanceof PdfRef || !$dictionary instanceof PdfDict || $dictionary->name('Subtype') !== 'Form') {
            throw new PdfStructureNotSupported('A form XObject is not a form stream.');
        }

        [$number, $fresh] = $this->slot($value->number, $maskRoot ? 'M' : ($mask ? 'm' : 'n'));

        if (!$fresh) {
            return new PdfRef($number);
        }

        $out = new PdfDict();
        $spaces = null;

        foreach ($dictionary->entries as $key => $entry) {
            switch ($key) {
                case 'Type':
                case 'Subtype':
                case 'FormType':
                case 'BBox':
                case 'Matrix':
                    $out->set($key, $this->reader->resolve($entry));

                    break;

                case 'Resources':
                    [$resources, $spaces] = $this->resources($entry, $mask);
                    $out->set($key, $resources);

                    break;

                case 'Group':
                    $out->set($key, $this->group($entry, $mask, $maskRoot));

                    break;

                case 'Filter':
                case 'DecodeParms':
                case 'Length':
                case 'StructParents':
                case 'StructParent':
                case 'PieceInfo':
                case 'LastModified':
                case 'Metadata':
                case 'Name':
                    break;

                default:
                    $this->fail(sprintf('A form XObject has /%s, which the colour pass does not handle.', $key));
            }
        }

        if ($spaces === null) {
            // it would paint with its parent's resources, which this walk does not follow
            $this->fail('A form XObject has no resources of its own.');
        }

        if ($maskRoot && !$out->has('Group')) {
            $this->fail('A luminosity soft mask is not a transparency group.');
        }

        $out->set('Filter', new PdfName('FlateDecode'));
        $content = CmykContentRewriter::rewrite($this->decoded($value->number), $spaces, $this->pass->rules, $mask);
        $this->writer->stream($number, $out, self::deflate($content));

        return new PdfRef($number);
    }

    private function pattern(mixed $value, bool $mask): PdfRef
    {
        $pattern = $value instanceof PdfRef ? $this->reader->value($value->number) : null;

        if (!$value instanceof PdfRef || !$pattern instanceof PdfDict) {
            throw new PdfStructureNotSupported('A pattern is not an object of its own.');
        }

        [$number, $fresh] = $this->slot($value->number, $mask ? 'm' : 'n');

        if (!$fresh) {
            return new PdfRef($number);
        }

        $type = $this->reader->resolve($pattern->get('PatternType'));
        $out = new PdfDict();

        if ($type === '2') {
            foreach ($pattern->entries as $key => $entry) {
                match ($key) {
                    'Type', 'PatternType', 'Matrix' => $out->set($key, $this->reader->resolve($entry)),
                    'Shading' => $out->set($key, $this->shading($entry, $mask)),
                    'ExtGState' => $out->set($key, $this->graphicsState($entry, $mask)),
                    default => $this->fail(sprintf('A shading pattern has /%s, which the colour pass does not handle.', $key)),
                };
            }

            $this->writer->object($number, $out);

            return new PdfRef($number);
        }

        if ($type !== '1' || !$this->reader->isStream($value->number)) {
            $this->fail('A pattern is neither a tiling nor a shading pattern.');
        }

        if ($this->reader->resolve($pattern->get('PaintType')) !== '1') {
            $this->fail('An uncoloured tiling pattern has no rule in the colour pass.');
        }

        $spaces = null;

        foreach ($pattern->entries as $key => $entry) {
            switch ($key) {
                case 'Type':
                case 'PatternType':
                case 'PaintType':
                case 'TilingType':
                case 'BBox':
                case 'XStep':
                case 'YStep':
                case 'Matrix':
                    $out->set($key, $this->reader->resolve($entry));

                    break;

                case 'Resources':
                    [$resources, $spaces] = $this->resources($entry, $mask);
                    $out->set($key, $resources);

                    break;

                case 'Filter':
                case 'DecodeParms':
                case 'Length':
                    break;

                default:
                    $this->fail(sprintf('A tiling pattern has /%s, which the colour pass does not handle.', $key));
            }
        }

        if ($spaces === null) {
            $this->fail('A tiling pattern has no resources.');
        }

        $out->set('Filter', new PdfName('FlateDecode'));
        $content = CmykContentRewriter::rewrite($this->decoded($value->number), $spaces, $this->pass->rules, $mask);
        $this->writer->stream($number, $out, self::deflate($content));

        return new PdfRef($number);
    }

    private function shading(mixed $value, bool $mask): mixed
    {
        if ($value instanceof PdfRef && $this->reader->has($value->number) && $this->reader->isStream($value->number)) {
            $this->fail('A mesh shading (types 4–7) has no rule in the colour pass.');
        }

        $shading = $this->reader->resolve($value);

        if (!$shading instanceof PdfDict) {
            throw new PdfStructureNotSupported('A shading is not a dictionary.');
        }

        if (!$value instanceof PdfRef) {
            return $this->shadingDict($shading, $mask);
        }

        [$number, $fresh] = $this->slot($value->number, $mask ? 'm' : 'n');

        if ($fresh) {
            $this->writer->object($number, $this->shadingDict($shading, $mask));
        }

        return new PdfRef($number);
    }

    private function shadingDict(PdfDict $shading, bool $mask): PdfDict
    {
        if (!in_array($this->reader->resolve($shading->get('ShadingType')), ['1', '2', '3'], true)) {
            $this->fail('A shading is not function-based, axial or radial.');
        }

        [$space, $kind] = $this->colorSpace($shading->get('ColorSpace'), $mask);

        if ($kind !== 'rgb' && $kind !== 'gray' && $kind !== 'cmyk') {
            $this->fail('A shading uses a colour space the colour pass does not handle.');
        }

        $out = new PdfDict();

        foreach ($shading->entries as $key => $entry) {
            switch ($key) {
                case 'ShadingType':
                case 'BBox':
                case 'AntiAlias':
                case 'Domain':
                case 'Matrix':
                case 'Coords':
                case 'Extend':
                    $out->set($key, $this->reader->resolve($entry));

                    break;

                case 'ColorSpace':
                    $out->set($key, $space);

                    break;

                case 'Background':
                    $out->set($key, $kind === 'rgb' ? $this->color($this->reader->resolve($entry), $mask) : $this->reader->resolve($entry));

                    break;

                case 'Function':
                    $out->set($key, $kind === 'rgb' ? $this->colorFunction($entry, $mask) : $this->copy($entry));

                    break;

                default:
                    $this->fail(sprintf('A shading has /%s, which the colour pass does not handle.', $key));
            }
        }

        return $out;
    }

    /**
     * A function whose output is an RGB colour → the same function with
     * CMYK (mask: grey) output. The stops are converted like any other
     * colour; between two stops the inks are interpolated linearly, as every
     * CMYK gradient is.
     */
    private function colorFunction(mixed $value, bool $mask): mixed
    {
        $function = $value instanceof PdfRef && $this->reader->has($value->number) ? $this->reader->value($value->number) : $value;

        if (!$function instanceof PdfDict) {
            // an array of one-output functions, one per colour component
            $this->fail('A shading computes its colour components separately; there is no rule for it.');
        }

        if (!$value instanceof PdfRef) {
            return $this->colorFunctionDict($function, $mask, null, null);
        }

        [$number, $fresh] = $this->slot($value->number, $mask ? 'fm' : 'fn');

        if ($fresh) {
            $converted = $this->colorFunctionDict($function, $mask, $value->number, $number);

            if ($converted !== null) {
                $this->writer->object($number, $converted);
            }
        }

        return new PdfRef($number);
    }

    /**
     * @return ($target is null ? PdfDict : null|PdfDict) null: the function was a stream and has been written under `$target`
     */
    private function colorFunctionDict(PdfDict $function, bool $mask, null|int $source, null|int $target): null|PdfDict
    {
        $type = $this->reader->resolve($function->get('FunctionType'));
        $outputs = $mask ? 1 : 4;
        $range = [];

        for ($i = 0; $i < $outputs; ++$i) {
            array_push($range, '0', '1');
        }

        $out = new PdfDict();

        if ($type === '2') {
            foreach ($function->entries as $key => $entry) {
                match ($key) {
                    'FunctionType', 'Domain', 'N' => $out->set($key, $this->reader->resolve($entry)),
                    'C0', 'C1' => $out->set($key, $this->color($this->reader->resolve($entry), $mask)),
                    'Range' => null,
                    default => $this->fail(sprintf('An exponential function has /%s, which the colour pass does not handle.', $key)),
                };
            }

            if (!$out->has('C0') || !$out->has('C1')) {
                $this->fail('A colour function has no end colours.');
            }

            return $out;
        }

        if ($type === '3') {
            foreach ($function->entries as $key => $entry) {
                match ($key) {
                    'FunctionType', 'Domain', 'Bounds', 'Encode' => $out->set($key, $this->reader->resolve($entry)),
                    'Functions' => $out->set($key, array_map(fn (mixed $part): mixed => $this->colorFunction($part, $mask), self::list($this->reader->resolve($entry)))),
                    'Range' => null,
                    default => $this->fail(sprintf('A stitching function has /%s, which the colour pass does not handle.', $key)),
                };
            }

            return $out;
        }

        if ($type !== '0' || $source === null || $target === null || !$this->reader->isStream($source)) {
            // type 4 (PostScript calculator) would have to be rewritten as a program
            $this->fail('A shading function is neither exponential, stitching nor sampled.');
        }

        $decode = $this->reader->resolve($function->get('Decode'));

        if ($this->reader->resolve($function->get('BitsPerSample')) !== '8'
            || ($decode !== null && self::floats($decode) !== [0.0, 1.0, 0.0, 1.0, 0.0, 1.0])
            || self::floats($this->reader->resolve($function->get('Range'))) !== [0.0, 1.0, 0.0, 1.0, 0.0, 1.0]
        ) {
            $this->fail('A sampled colour function is not 8-bit RGB in the range 0…1.');
        }

        foreach ($function->entries as $key => $entry) {
            match ($key) {
                'FunctionType', 'Domain', 'Size', 'BitsPerSample', 'Order', 'Encode' => $out->set($key, $this->reader->resolve($entry)),
                'Range', 'Decode', 'Filter', 'DecodeParms', 'Length' => null,
                default => $this->fail(sprintf('A sampled function has /%s, which the colour pass does not handle.', $key)),
            };
        }

        $samples = $this->decoded($source);

        if (strlen($samples) % 3 !== 0) {
            $this->fail('A sampled colour function does not hold whole RGB samples.');
        }

        $converted = '';

        for ($i = 0, $length = strlen($samples); $i < $length; $i += 3) {
            [$r, $g, $b] = [ord($samples[$i]), ord($samples[$i + 1]), ord($samples[$i + 2])];

            if ($mask) {
                $converted .= self::byte(CmykColorRules::luminosity($r / 255, $g / 255, $b / 255));

                continue;
            }

            foreach ($this->pass->rules->cmyk8($r, $g, $b) as $ink) {
                $converted .= self::byte($ink);
            }
        }

        $out->set('Range', $range);
        $out->set('Filter', new PdfName('FlateDecode'));
        $this->writer->stream($target, $out, self::deflate($converted));

        return null;
    }

    /**
     * @return list<string> the colour as operands: C M Y K, or one grey value inside a mask
     */
    private function color(mixed $value, bool $mask): array
    {
        if (!is_array($value) || count($value) !== 3) {
            $this->fail('An RGB colour does not have three components.');
        }

        [$r, $g, $b] = self::floats($value);

        if ($mask) {
            return [PdfSyntax::number(CmykColorRules::luminosity($r, $g, $b))];
        }

        return array_map(PdfSyntax::number(...), $this->pass->rules->cmyk($r, $g, $b));
    }

    /**
     * @return array{mixed, string} the colour space to write and its kind: `rgb` (was RGB, is written as
     *   DeviceCMYK / DeviceGray), `gray`, `cmyk`, `pattern` or `other` (a space whose colour operands need no change)
     */
    private function colorSpace(mixed $value, bool $mask): array
    {
        $space = $this->reader->resolve($value);
        $target = new PdfName($mask ? 'DeviceGray' : 'DeviceCMYK');

        if ($space instanceof PdfName) {
            return match ($space->value) {
                'DeviceRGB' => [$target, 'rgb'],
                'DeviceGray' => [$space, 'gray'],
                'DeviceCMYK' => [$space, 'cmyk'],
                'Pattern' => [$space, 'pattern'],
                default => $this->fail(sprintf('The colour space /%s has no rule in the colour pass.', substr($space->value, 0, 40))),
            };
        }

        if (!is_array($space) || !($space[0] ?? null) instanceof PdfName) {
            $this->fail('A colour space is neither a name nor an array.');
        }

        switch ($space[0]->value) {
            case 'ICCBased':
                $profile = $space[1] ?? null;
                $dictionary = $profile instanceof PdfRef && $this->reader->has($profile->number) ? $this->reader->value($profile->number) : null;
                $components = $dictionary instanceof PdfDict ? $this->reader->resolve($dictionary->get('N')) : null;

                // Chromium tags its colours and pictures with sRGB; the picture's own profile, if it
                // has one, is read from the picture's data when it is converted
                return match ($components) {
                    '3' => [$target, 'rgb'],
                    '1' => [new PdfName('DeviceGray'), 'gray'],
                    default => $this->fail('An ICC-based colour space is neither RGB nor grey.'),
                };

            case 'CalRGB':
                return [$target, 'rgb'];

            case 'CalGray':
                return [new PdfName('DeviceGray'), 'gray'];

            case 'Pattern':
                if (count($space) !== 1) {
                    $this->fail('An uncoloured pattern colour space has no rule in the colour pass.');
                }

                return [new PdfName('Pattern'), 'pattern'];

            case 'Indexed':
                [$base, $kind] = $this->colorSpace($space[1] ?? null, $mask);
                $lookup = $this->reader->resolve($space[3] ?? null);

                if ($kind === 'gray' || $kind === 'cmyk') {
                    return [[$space[0], $base, $this->reader->resolve($space[2] ?? null), $this->copy($space[3] ?? null)], 'other'];
                }

                if ($kind !== 'rgb') {
                    $this->fail('An indexed colour space is built on a space the colour pass does not handle.');
                }

                $table = match (true) {
                    $lookup instanceof PdfString => $lookup->bytes(),
                    $lookup instanceof PdfRef => $this->decoded($lookup->number),
                    default => $this->fail('An indexed colour space has no colour table.'),
                };

                // a colour table is flat artwork (a logo, a QR code): its entries follow the vector rules
                $converted = '';

                for ($i = 0, $length = strlen($table) - strlen($table) % 3; $i < $length; $i += 3) {
                    [$r, $g, $b] = [ord($table[$i]), ord($table[$i + 1]), ord($table[$i + 2])];

                    if ($mask) {
                        $converted .= self::byte(CmykColorRules::luminosity($r / 255, $g / 255, $b / 255));

                        continue;
                    }

                    foreach ($this->pass->rules->cmyk8($r, $g, $b) as $ink) {
                        $converted .= self::byte($ink);
                    }
                }

                return [[$space[0], $base, $this->reader->resolve($space[2] ?? null), new PdfString('<' . bin2hex($converted) . '>')], 'other'];

            case 'Separation':
            case 'DeviceN':
                [, $kind] = $this->colorSpace($space[2] ?? null, $mask);

                if ($kind !== 'gray' && $kind !== 'cmyk') {
                    $this->fail('A spot colour falls back to RGB; there is no rule for it.');
                }

                return [$this->copy($space), 'other'];

            default:
                return $this->fail(sprintf('The colour space /%s has no rule in the colour pass.', substr($space[0]->value, 0, 40)));
        }
    }

    private function image(PdfRef $reference, bool $mask): PdfRef
    {
        $image = $this->reader->value($reference->number);
        assert($image instanceof PdfDict);

        foreach (['Alternates', 'OPI', 'OC', 'Metadata'] as $key) {
            if ($image->has($key)) {
                $this->fail(sprintf('A picture has /%s, which the colour pass does not handle.', $key));
            }
        }

        if ($this->reader->resolve($image->get('ImageMask')) === 'true') {
            // a stencil takes the colour of the fill operator
            [$number, $fresh] = $this->slot($reference->number, 'x');

            if ($fresh) {
                $stencil = new PdfDict();

                foreach ($image->entries as $key => $entry) {
                    if ($key !== 'Length') {
                        $stencil->set($key, $this->copy($entry));
                    }
                }

                $this->writer->streamFromReader($number, $stencil, $this->reader, $reference->number);
            }

            return new PdfRef($number);
        }

        [$space, $kind] = $this->colorSpace($image->get('ColorSpace'), $mask);
        // a grey or CMYK picture is the same picture wherever it is used
        [$number, $fresh] = $this->slot($reference->number, $kind === 'gray' || $kind === 'cmyk' ? 'i' : ($mask ? 'im' : 'in'));

        if (!$fresh) {
            return new PdfRef($number);
        }

        $out = new PdfDict();

        foreach ($image->entries as $key => $entry) {
            switch ($key) {
                case 'Type':
                case 'Subtype':
                case 'Width':
                case 'Height':
                case 'BitsPerComponent':
                case 'Interpolate':
                case 'Intent':
                case 'Filter':
                case 'DecodeParms':
                case 'Decode':
                case 'ColorTransform':
                    $out->set($key, $this->reader->resolve($entry));

                    break;

                case 'ColorSpace':
                    $out->set($key, $space);

                    break;

                case 'SMask':
                    $softMask = $entry instanceof PdfRef && $this->reader->isStream($entry->number) ? $this->reader->value($entry->number) : null;

                    if (!$entry instanceof PdfRef || !$softMask instanceof PdfDict || ($softMask->has('Matte') && $kind === 'rgb')) {
                        // /Matte: the picture's colours are pre-multiplied with a colour in ITS colour space
                        $this->fail('A picture has a soft mask the colour pass does not handle.');
                    }

                    $out->set($key, $this->image($entry, true));

                    break;

                case 'Mask':
                    if (!$entry instanceof PdfRef) {
                        $this->fail('A picture is masked by a colour range; there is no rule for it.');
                    }

                    $out->set($key, $this->image($entry, $mask));

                    break;

                case 'Length':
                case 'StructParent':
                case 'Name':
                case 'Matte':
                    if ($key === 'Matte') {
                        $out->set($key, $this->reader->resolve($entry));
                    }

                    break;

                default:
                    $this->fail(sprintf('A picture has /%s, which the colour pass does not handle.', $key));
            }
        }

        if ($kind !== 'rgb') {
            $this->writer->streamFromReader($number, $out, $this->reader, $reference->number);

            return new PdfRef($number);
        }

        if ($mask) {
            $this->fail('A colour picture paints a luminosity soft mask; there is no rule for it.');
        }

        $this->picture($reference->number, $number, $out);

        return new PdfRef($number);
    }

    /**
     * An RGB picture → the same pixels in CMYK (or, when every pixel of a
     * losslessly stored picture is neutral, in DeviceGray — black ink only).
     */
    private function picture(int $source, int $number, PdfDict $out): void
    {
        $filter = $out->get('Filter');
        $filter = is_array($filter) && count($filter) === 1 ? $filter[0] : $filter;
        $filter = $filter instanceof PdfName ? $filter->value : ($filter === null ? null : '?');
        $width = $out->get('Width');
        $height = $out->get('Height');
        $parms = $out->get('DecodeParms');

        if ($out->get('BitsPerComponent') !== '8' || $out->has('Decode') || !is_string($width) || !ctype_digit($width) || !is_string($height) || !ctype_digit($height)
            || ($parms !== null && $parms !== 'null') || !in_array($filter, ['DCTDecode', 'FlateDecode', null], true)
        ) {
            $this->fail(sprintf('An RGB picture is stored in a way the colour pass does not handle (filter %s).', $filter ?? 'none'));
        }

        $hash = hash_init('xxh128');
        hash_update($hash, sprintf('%s:%s:%s:', $filter ?? '', $width, $height));
        $this->reader->copyStream($source, static function (string $chunk) use ($hash): void {
            hash_update($hash, $chunk);
        });
        $digest = hash_final($hash);
        $picture = $this->pass->picture($digest);

        if ($picture === null) {
            if ($filter === 'DCTDecode') {
                $this->photo($source, $digest);
            } else {
                $this->pixels($source, $digest, (int) $width, (int) $height, $filter === 'FlateDecode');
            }

            $picture = $this->pass->picture($digest);
            assert($picture !== null);
        }

        $out->remove('ColorTransform');
        $out->remove('DecodeParms');
        $out->set('ColorSpace', new PdfName($picture['kind'] === 'gray' ? 'DeviceGray' : 'DeviceCMYK'));

        if ($picture['inverted']) {
            $out->set('Decode', ['1', '0', '1', '0', '1', '0', '1', '0']);
        }

        if ($picture['path'] === null) {
            $this->writer->streamFromReader($number, $out, $this->reader, $source);

            return;
        }

        if ($picture['filter'] === null) {
            $out->remove('Filter');
        } else {
            $out->set('Filter', new PdfName($picture['filter']));
        }

        $this->writer->streamFromFile($number, $out, $picture['path']);
    }

    private function photo(int $source, string $digest): void
    {
        $jpeg = $this->pass->tempFile('rgb.jpg');
        $target = $this->pass->tempFile('jpg');

        try {
            $this->toFile($source, $jpeg);
            $result = $this->icc->jpeg($jpeg, $target);
        } finally {
            @unlink($jpeg);
        }

        // a one-channel JPEG that was only labelled RGB stays as it is
        $this->pass->keepPicture($digest, $result['kind'] === 'gray' ? null : $target, $result['kind'], $result['inverted'], 'DCTDecode');
    }

    private function pixels(int $source, string $digest, int $width, int $height, bool $deflated): void
    {
        $raw = $this->pass->tempFile('rgb');
        $gray = $this->pass->tempFile('gray.z');
        $rawFile = fopen($raw, 'wb');
        $grayFile = fopen($gray, 'wb');

        if ($rawFile === false || $grayFile === false) {
            throw new CmykConversionFailed('A work file for a picture cannot be written.');
        }

        $inflate = $deflated ? inflate_init(ZLIB_ENCODING_DEFLATE) : null;
        $deflate = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 6]);

        if ($inflate === false || $deflate === false) {
            throw new CmykConversionFailed('zlib is not available.');
        }

        $neutral = true;
        $carry = '';
        $bytes = 0;

        $take = static function (string $pixels) use (&$neutral, &$carry, &$bytes, $rawFile, $grayFile, $deflate): void {
            if ($pixels === '') {
                return;
            }

            $bytes += strlen($pixels);
            fwrite($rawFile, $pixels);

            if (!$neutral) {
                return;
            }

            $pixels = $carry . $pixels;
            $whole = strlen($pixels) - strlen($pixels) % 3;
            $carry = substr($pixels, $whole);
            $pixels = substr($pixels, 0, $whole);

            if ($pixels === '') {
                return;
            }

            // R = G and G = B for every pixel: XOR with the string shifted by one byte must be 0 at offsets 0 and 1 of each triple
            $differences = ($pixels ^ (substr($pixels, 1) . "\x00")) & str_repeat("\xFF\xFF\x00", intdiv($whole, 3));

            if (strspn($differences, "\x00") !== $whole) {
                $neutral = false;

                return;
            }

            fwrite($grayFile, (string) deflate_add($deflate, (string) preg_replace('/(.)../s', '$1', $pixels), ZLIB_NO_FLUSH));
        };

        try {
            $this->reader->copyStream($source, static function (string $chunk) use ($inflate, $take): void {
                if ($inflate === null) {
                    $take($chunk);

                    return;
                }

                foreach (str_split($chunk, self::INFLATE_BYTES) as $piece) {
                    $pixels = inflate_add($inflate, $piece, ZLIB_SYNC_FLUSH);

                    if ($pixels === false) {
                        throw new CmykConversionFailed('A picture does not decompress.');
                    }

                    $take($pixels);
                }
            });

            if ($neutral) {
                fwrite($grayFile, (string) deflate_add($deflate, '', ZLIB_FINISH));
            }
        } finally {
            fclose($rawFile);
            fclose($grayFile);
        }

        try {
            if ($bytes !== $width * $height * 3) {
                $this->fail('An RGB picture does not hold width × height pixels.');
            }

            if ($neutral && $carry === '') {
                $this->pass->keepPicture($digest, $gray, 'gray', false, 'FlateDecode');
                $gray = null;

                return;
            }

            $cmyk = $this->pass->tempFile('cmyk');
            $target = $this->pass->tempFile('cmyk.z');

            try {
                $this->icc->raw($raw, $width, $height, $cmyk);
                self::deflateFile($cmyk, $target);
            } finally {
                @unlink($cmyk);
            }

            $this->pass->keepPicture($digest, $target, 'cmyk', false, 'FlateDecode');
        } finally {
            @unlink($raw);

            if ($gray !== null) {
                @unlink($gray);
            }
        }
    }

    private function font(mixed $value, bool $mask): mixed
    {
        $font = $this->reader->resolve($value);

        if (!$value instanceof PdfRef || !$font instanceof PdfDict || $font->name('Subtype') !== 'Type3') {
            // the glyphs of every other font take the colour of the text operators
            return $this->copy($value);
        }

        [$number, $fresh] = $this->slot($value->number, $mask ? 'm' : 'n');

        if (!$fresh) {
            return new PdfRef($number);
        }

        // a Type 3 glyph is a little content stream: it may set colours and draw pictures
        $out = new PdfDict();
        $spaces = [];

        if ($font->has('Resources')) {
            [$resources, $spaces] = $this->resources($font->get('Resources'), $mask);
            $out->set('Resources', $resources);
        }

        foreach ($font->entries as $key => $entry) {
            if ($key === 'Resources') {
                continue;
            }

            if ($key !== 'CharProcs') {
                $out->set($key, $this->copy($entry));

                continue;
            }

            $procedures = $this->reader->resolve($entry);

            if (!$procedures instanceof PdfDict) {
                throw new PdfStructureNotSupported('A Type 3 font has no glyph procedures.');
            }

            $glyphs = new PdfDict();

            foreach ($procedures->entries as $glyph => $procedure) {
                if (!$procedure instanceof PdfRef || !$this->reader->isStream($procedure->number)) {
                    throw new PdfStructureNotSupported('A Type 3 glyph is not a stream.');
                }

                [$glyphNumber, $glyphFresh] = $this->slot($procedure->number, $mask ? 'gm' : 'gn');

                if ($glyphFresh) {
                    $content = CmykContentRewriter::rewrite($this->decoded($procedure->number), $spaces, $this->pass->rules, $mask);
                    $this->writer->stream($glyphNumber, new PdfDict(['Filter' => new PdfName('FlateDecode')]), self::deflate($content));
                }

                $glyphs->set($glyph, new PdfRef($glyphNumber));
            }

            $out->set($key, $glyphs);
        }

        $this->writer->object($number, $out);

        return new PdfRef($number);
    }

    /**
     * A value copied as it is, with every object it refers to — for what
     * carries no colour of its own (fonts and their programs, marked-content
     * property lists, a grey picture).
     *
     */
    private function copy(mixed $value): mixed
    {
        if ($value instanceof PdfRef) {
            if (!$this->reader->has($value->number)) {
                return $value;
            }

            [$number, $fresh] = $this->slot($value->number, 'x');

            if ($fresh) {
                $object = $this->reader->value($value->number);

                if ($this->reader->isStream($value->number) && $object instanceof PdfDict) {
                    $dictionary = new PdfDict();

                    foreach ($object->entries as $key => $entry) {
                        if ($key !== 'Length') {
                            $dictionary->set($key, $this->copy($entry));
                        }
                    }

                    $this->writer->streamFromReader($number, $dictionary, $this->reader, $value->number);
                } else {
                    $this->writer->object($number, $this->copy($object));
                }
            }

            return new PdfRef($number);
        }

        if ($value instanceof PdfDict) {
            return new PdfDict(array_map($this->copy(...), $value->entries));
        }

        if (is_array($value)) {
            return array_map($this->copy(...), $value);
        }

        return $value;
    }

    /**
     * @return array{int, bool} the number this form of the object is written under, and whether it still has to be written
     */
    private function slot(int $source, string $form): array
    {
        if (isset($this->slots[$source][$form])) {
            return [$this->slots[$source][$form], false];
        }

        $number = isset($this->slots[$source]) ? $this->next++ : $source;
        $this->slots[$source][$form] = $number;

        return [$number, true];
    }

    /** The decoded data of a content stream, a function or a colour table. */
    private function decoded(int $number): string
    {
        $dictionary = $this->reader->value($number);

        if (!$dictionary instanceof PdfDict || !$this->reader->isStream($number)) {
            throw new PdfStructureNotSupported(sprintf('The PDF object %d is not a stream.', $number));
        }

        $filter = $this->reader->resolve($dictionary->get('Filter'));
        $filter = is_array($filter) && count($filter) === 1 ? $filter[0] : $filter;
        $parms = $this->reader->resolve($dictionary->get('DecodeParms'));
        $bytes = $this->reader->streamBytes($number);

        if ($filter === null || $filter === 'null' || $filter === []) {
            return $bytes;
        }

        if (!$filter instanceof PdfName || $filter->value !== 'FlateDecode' || ($parms !== null && $parms !== 'null')) {
            $this->fail('A stream is compressed in a way the colour pass does not read.');
        }

        $data = @zlib_decode($bytes, self::MAX_CONTENT_BYTES);

        if ($data === false) {
            $this->fail('A stream does not decompress.');
        }

        return $data;
    }

    private function toFile(int $number, string $path): void
    {
        $file = fopen($path, 'wb');

        if ($file === false) {
            throw new CmykConversionFailed('A work file for a picture cannot be written.');
        }

        try {
            $this->reader->copyStream($number, static function (string $chunk) use ($file): void {
                if (fwrite($file, $chunk) !== strlen($chunk)) {
                    throw new CmykConversionFailed('A work file for a picture cannot be written (disk full?).');
                }
            });
        } finally {
            fclose($file);
        }
    }

    private static function deflateFile(string $source, string $target): void
    {
        $in = fopen($source, 'rb');
        $out = fopen($target, 'wb');
        $deflate = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 6]);

        if ($in === false || $out === false || $deflate === false) {
            throw new CmykConversionFailed('A work file for a picture cannot be written.');
        }

        try {
            while (!feof($in)) {
                fwrite($out, (string) deflate_add($deflate, (string) fread($in, 1_048_576), ZLIB_NO_FLUSH));
            }

            fwrite($out, (string) deflate_add($deflate, '', ZLIB_FINISH));
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    private static function deflate(string $data): string
    {
        $deflated = gzcompress($data, 6);

        if ($deflated === false) {
            throw new CmykConversionFailed('A stream could not be compressed.');
        }

        return $deflated;
    }

    private static function float(mixed $value): float
    {
        if (!is_string($value) || !PdfSyntax::isNumber($value)) {
            throw new CmykConversionFailed('A number was expected in a colour-carrying object.');
        }

        return (float) $value;
    }

    /**
     * @return list<float>
     */
    private static function floats(mixed $value): array
    {
        return array_map(self::float(...), self::list($value));
    }

    /** A 0…1 value as one sample byte. */
    private static function byte(float $unit): string
    {
        return chr(max(0, min(255, (int) round($unit * 255))));
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    private function fail(string $reason): never
    {
        throw new CmykConversionFailed($reason);
    }
}
