<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\AiScan;

use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Message\Font\AddFont;
use WBoost\Web\Message\Font\DeleteFontFace;
use WBoost\Web\Message\Image\DeleteFileUpload;
use WBoost\Web\Message\Image\UploadFile;
use WBoost\Web\Services\AiScan\AiScanPurge;
use WBoost\Web\Services\AiScan\AiScanStandIn;
use WBoost\Web\Tests\AiScanTesting;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\FileSource;

/**
 * The review of a template proposal over its endpoints (ux.md S4, S4b, T4):
 * every fix, the pictures, accept (once, and not at all with stale
 * references), discard, expiry, and the read-only page after accept.
 *
 * @phpstan-type Element array{type: string, name: null|string, text: string, input: bool, font: string, size: float|int, colour: string, shape: string, fill: string}
 * @phpstan-type Picture array{fileId: string, uploaded: bool, width: null|int, height: null|int, tooSmall: bool}
 * @phpstan-type Place array{id: string, name: null|string, kind: string, fit: string, kindLocked: bool, added: bool, box: mixed, width: int, height: int, decidedEmpty: bool, picture: null|Picture}
 * @phpstan-type Decision array{key: string, type: string, decided: bool, pattern: string, face: string, sizeChangePercent: int, texts: list<string>, box: mixed}
 * @phpstan-type Proposal array{readOnly: bool, name: string, revision: int, toDecide: int, counts: array{texts: int, shapes: int, places: int, background: bool}, source: array<string, int>, preview: array{which: string, seq: int, stale: bool}, format: array<string, float|int|string>, fonts: list<string>, decisions: list<Decision>, information: list<array<string, mixed>>, placeholders: list<Place>, pictures: array<string, mixed>, elements: list<array<string, mixed>>, changes: list<array{key: string, id: string, op: null|string, shape: null|string, with: list<string>, name: null|string}>}
 * @phpstan-type Answer array{ok: bool, code: string, field: string, previewStale: bool, assigned: int, fileIds: list<string>, proposal: Proposal, created: bool, elements: int, unfilledPictures: int, templateId: string, variantId: string, editorUrl: string, scan: array{status: string}, fonts: int, pictures: int, seen: bool}
 */
final class AiScanReviewTest extends WebTestCase
{
    use AiScanTesting;

    private const string PROJECT = TestDataFixture::PROJECT_1_ID;
    private const string BOLD = 'Rubik (Rubik Bold)';

    private KernelBrowser $client;
    private string $scanId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::entitle(self::PROJECT);
        self::switchOn();
        $this->scanId = self::ready($this->client);
        TestingLogin::logInAsUser($this->client, TestDataFixture::USER_1_EMAIL);
    }

    public function testTheProposalDocumentSaysWhatThereIsToDecide(): void
    {
        $proposal = $this->proposal();

        self::assertFalse($proposal['readOnly']);
        self::assertSame('Headline', $proposal['name'], 'named after the headline text');
        self::assertSame(['texts' => 2, 'shapes' => 1, 'places' => 3, 'background' => false], $proposal['counts'], 'a background PHOTO is a place');
        self::assertSame(['width' => 900, 'height' => 1200], $proposal['source']);
        self::assertSame(['candidate', 0, false], [$proposal['preview']['which'], $proposal['preview']['seq'], $proposal['preview']['stale']]);
        self::assertContains(self::BOLD, $proposal['fonts']);

        // The font to decide, the doubtful text and the left-out thing.
        self::assertSame(3, $proposal['toDecide']);
        self::assertSame(['font:headline', 'confirm:sub', 'omitted:0'], array_column($proposal['decisions'], 'key'));
        self::assertSame(['nearest_available', self::FACE, -4, ['headline']], [
            $proposal['decisions'][0]['pattern'], $proposal['decisions'][0]['face'], $proposal['decisions'][0]['sizeChangePercent'], $proposal['decisions'][0]['texts'],
        ]);
        self::assertEquals([600, 800, 200, 100], $proposal['decisions'][2]['box']);

        // Information: measured match, the shrink, the palette, a simplification, the pictures.
        self::assertSame(['font_match', 'shrunk', 'palette', 'simplification', 'pictures_not_taken'], array_column($proposal['information'], 'code'));

        // Places in the order of the cards, with their defaults.
        self::assertSame(['bg', 'logo', 'foto'], array_column($proposal['placeholders'], 'id'));
        self::assertSame(['input', 'fixed', 'input'], array_column($proposal['placeholders'], 'kind'));
        self::assertSame(['cover', 'contain', 'cover'], array_column($proposal['placeholders'], 'fit'));
        self::assertTrue($proposal['placeholders'][0]['kindLocked'], 'a full-page background is always filled at export');
        self::assertEquals([700, 60, 140, 60], $proposal['placeholders'][1]['box']);
        self::assertSame(['total' => 3, 'filled' => 0, 'unfilled' => 1, 'greyBackground' => true], $proposal['pictures']);

        // No name of the original's typeface anywhere.
        self::assertStringNotContainsString('Futura', (string) $this->client->getResponse()->getContent());
    }

    public function testFontSwapRefitsAndCanBeUndone(): void
    {
        $swapped = $this->fix(['type' => 'choose_font', 'group' => 'headline', 'face' => self::BOLD]);
        self::assertTrue($swapped['ok']);
        self::assertFalse($swapped['previewStale']);

        $headline = self::element($swapped['proposal'], 'headline');
        self::assertSame(self::BOLD, $headline['font']);
        self::assertSame(2, $swapped['proposal']['toDecide']);
        self::assertTrue($swapped['proposal']['decisions'][0]['decided']);
        self::assertSame(['proposal', 1], [$swapped['proposal']['preview']['which'], $swapped['proposal']['preview']['seq']]);

        // The picture of the proposal is served from the private bucket.
        $this->client->request('GET', sprintf('/ai-scan/%s/image/proposal', $this->scanId));
        self::assertResponseIsSuccessful();
        self::assertSame('image/webp', $this->client->getResponse()->headers->get('Content-Type'));

        $undone = $this->fix(['type' => 'undo', 'key' => 'font:headline']);
        self::assertEquals(72, self::element($undone['proposal'], 'headline')['size']);
        self::assertSame(self::FACE, self::element($undone['proposal'], 'headline')['font']);
        self::assertSame(3, $undone['proposal']['toDecide']);

        // "Nechat" decides without changing anything.
        $kept = $this->fix(['type' => 'keep_font', 'group' => 'headline']);
        self::assertSame(2, $kept['proposal']['toDecide']);
        self::assertSame(self::FACE, self::element($kept['proposal'], 'headline')['font']);

        // A face the project does not have, an unknown group: nothing saved.
        $revision = $kept['proposal']['revision'];
        self::assertSame('invalid_fix', $this->fix(['type' => 'choose_font', 'group' => 'headline', 'face' => 'Comic Sans (Regular)'])['code']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_fix', $this->fix(['type' => 'choose_font', 'group' => 'nope', 'face' => self::BOLD])['code']);
        self::assertSame($revision, $this->proposal()['revision']);
    }

    public function testAFontUploadedMeanwhileCanBeChosenAndIsRefitted(): void
    {
        // The review's "Nahrát správné písmo" is the project's font upload, then the same fix.
        $before = $this->proposal()['fonts'];
        $path = sys_get_temp_dir() . '/ai-scan-nunito.ttf';
        copy(__DIR__ . '/../../Mcp/Design/Fixtures/fonts/Nunito-Regular.ttf', $path);
        self::bus()->dispatch(new AddFont(Uuid::fromString(self::PROJECT), new UploadedFile($path, 'Nunito-Regular.ttf', 'font/ttf', null, true)));
        @unlink($path);

        $new = array_values(array_diff($this->proposal()['fonts'], $before));
        self::assertCount(1, $new, 'the uploaded face is offered at once');

        $chosen = $this->fix(['type' => 'choose_font', 'group' => 'headline', 'face' => $new[0]]);
        self::assertTrue($chosen['ok']);
        self::assertSame($new[0], self::element($chosen['proposal'], 'headline')['font']);
        // size = source line width ÷ advance at 1 px, from the font's own metrics.
        $size = self::element($chosen['proposal'], 'headline')['size'];
        self::assertNotEquals(72, $size, 'the affected texts are re-fitted');
        self::assertGreaterThan(40, $size);
        self::assertLessThan(160, $size);
    }

    public function testTextsNamesInputsShrinkAndPalette(): void
    {
        $edited = $this->fix(['type' => 'set_text', 'id' => 'sub', 'text' => "Nový text\nna dva řádky"]);
        self::assertSame("Nový text\nna dva řádky", self::element($edited['proposal'], 'sub')['text']);
        self::assertSame(3, $edited['proposal']['toDecide'], 'editing is not confirming');

        $confirmed = $this->fix(['type' => 'confirm_text', 'id' => 'sub']);
        self::assertSame(2, $confirmed['proposal']['toDecide']);

        $fixed = $this->fix(['type' => 'set_input', 'id' => 'headline', 'input' => false]);
        self::assertFalse(self::element($fixed['proposal'], 'headline')['input']);
        self::assertTrue(self::element($fixed['proposal'], 'sub')['input'], 'a text is a field by default');

        $renamed = $this->fix(['type' => 'rename', 'id' => 'headline', 'name' => 'Nadpis']);
        self::assertSame('Nadpis', self::element($renamed['proposal'], 'headline')['name']);
        self::assertSame('invalid_fix', $this->fix(['type' => 'rename', 'id' => 'headline', 'name' => '<b>x</b>'])['code']);
        self::assertSame('invalid_fix', $this->fix(['type' => 'set_text', 'id' => 'logo', 'text' => 'x'])['code'], 'a place has no text');

        // The automatic shrink and its undo.
        self::assertEquals(36, self::element($renamed['proposal'], 'sub')['size']);
        $unshrunk = $this->fix(['type' => 'undo_shrink']);
        self::assertEquals(44, self::element($unshrunk['proposal'], 'sub')['size']);
        self::assertTrue(self::information($unshrunk['proposal'], 'shrunk')['undone']);
        $shrunkAgain = $this->fix(['type' => 'undo', 'key' => 'shrink']);
        self::assertEquals(36, self::element($shrunkAgain['proposal'], 'sub')['size']);

        // The palette: back to the colour measured in the picture.
        self::assertSame('#c8102e', self::element($shrunkAgain['proposal'], 'headline')['colour']);
        $exact = $this->fix(['type' => 'revert_palette']);
        self::assertSame('#c9112f', self::element($exact['proposal'], 'headline')['colour']);
        self::assertSame('#c9112f', self::element($exact['proposal'], 'pruh')['fill']);

        $named = $this->fix(['type' => 'set_name', 'name' => 'Výsledek zápasu U19']);
        self::assertSame('Výsledek zápasu U19', $named['proposal']['name']);
        self::assertSame('invalid_fix', $this->fix(['type' => 'set_name', 'name' => 'ab'])['code']);
    }

    public function testPlacesAddRemoveMergeToShapeKindAndFit(): void
    {
        $added = $this->fix(['type' => 'add_placeholder', 'omitted' => 0]);
        self::assertSame(['bg', 'logo', 'foto', 'misto-1'], array_column($added['proposal']['placeholders'], 'id'));
        self::assertEquals([600, 800, 200, 100], $added['proposal']['placeholders'][3]['box']);
        self::assertTrue($added['proposal']['placeholders'][3]['added']);
        self::assertSame(2, $added['proposal']['toDecide']);

        $merged = $this->fix(['type' => 'merge_placeholders', 'ids' => ['misto-1', 'foto']]);
        self::assertSame(['bg', 'logo', 'foto'], array_column($merged['proposal']['placeholders'], 'id'), 'the lower place keeps its id');
        self::assertEquals([100, 400, 700, 500], $merged['proposal']['placeholders'][2]['box'], 'one place over their common box');
        self::assertSame(
            [['key' => 'place:foto', 'id' => 'foto', 'op' => 'merge', 'shape' => null, 'with' => ['misto-1'], 'name' => 'Fotografie']],
            $merged['proposal']['changes'],
            'what the pictures step can undo after a reload',
        );
        self::assertSame('invalid_fix', $this->fix(['type' => 'merge_placeholders', 'ids' => ['foto']])['code']);
        self::assertSame('invalid_fix', $this->fix(['type' => 'merge_placeholders', 'ids' => ['foto', 'bg']])['code'], 'a background does not merge');

        $unmerged = $this->fix(['type' => 'undo', 'key' => 'place:foto']);
        self::assertCount(4, $unmerged['proposal']['placeholders']);

        $shape = $this->fix(['type' => 'placeholder_to_shape', 'id' => 'logo', 'shape' => 'circle', 'colour' => '#112233']);
        self::assertSame(['shape', 'circle', '#112233'], [self::element($shape['proposal'], 'logo')['type'], self::element($shape['proposal'], 'logo')['shape'], self::element($shape['proposal'], 'logo')['fill']]);
        self::assertSame(2, $shape['proposal']['counts']['shapes']);
        self::assertSame('invalid_fix', $this->fix(['type' => 'placeholder_to_shape', 'id' => 'foto', 'shape' => 'hexagon'])['code']);

        $removed = $this->fix(['type' => 'remove_placeholder', 'id' => 'misto-1']);
        self::assertSame(['bg', 'foto'], array_column($removed['proposal']['placeholders'], 'id'));

        self::assertSame(['place:logo' => 'shape', 'place:misto-1' => 'remove'], array_column($removed['proposal']['changes'], 'op', 'key'));
        self::assertSame('circle', $removed['proposal']['changes'][0]['shape']);

        $kind = $this->fix(['type' => 'set_kind', 'id' => 'foto', 'kind' => 'fixed']);
        self::assertSame('fixed', $kind['proposal']['placeholders'][1]['kind']);
        self::assertSame('invalid_fix', $this->fix(['type' => 'set_kind', 'id' => 'bg', 'kind' => 'fixed'])['code'], 'a background is always filled at export');

        $fit = $this->fix(['type' => 'set_fit', 'id' => 'foto', 'fit' => 'contain']);
        self::assertSame('contain', $fit['proposal']['placeholders'][1]['fit']);

        // A removed background becomes a colour: a design always has one.
        $noBackground = $this->fix(['type' => 'remove_placeholder', 'id' => 'bg', 'colour' => '#ffffff']);
        self::assertTrue($noBackground['proposal']['counts']['background']);
        self::assertSame(['foto'], array_column($noBackground['proposal']['placeholders'], 'id'));
    }

    public function testPicturesMultiDropGalleryPickMoveAndRemove(): void
    {
        // Multi-drop: two files go to the first two empty places, in card order.
        $dropped = $this->upload([self::file(1600, 900), self::file(280, 120)]);
        self::assertTrue($dropped['ok']);
        self::assertSame(2, $dropped['assigned']);
        self::assertCount(2, $dropped['fileIds']);

        $places = $dropped['proposal']['placeholders'];
        self::assertSame($dropped['fileIds'][0], self::pictureOf($dropped['proposal'], 0)['fileId']);
        self::assertSame($dropped['fileIds'][1], self::pictureOf($dropped['proposal'], 1)['fileId']);
        self::assertNull($places[2]['picture']);
        self::assertSame('Logo', $places[1]['name'], 'a filled place keeps the name the scanner gave it');
        self::assertTrue(self::pictureOf($dropped['proposal'], 1)['uploaded']);
        self::assertSame([280, 120], [self::pictureOf($dropped['proposal'], 1)['width'], self::pictureOf($dropped['proposal'], 1)['height']]);
        self::assertFalse(self::pictureOf($dropped['proposal'], 1)['tooSmall']);
        self::assertSame(['total' => 3, 'filled' => 2, 'unfilled' => 0, 'greyBackground' => false], $dropped['proposal']['pictures']);

        // The uploads are ordinary gallery files in "AI sken / {název}".
        self::assertSame('Headline', self::connection()->fetchOne(
            'SELECT d.name FROM file_upload f JOIN file_directory d ON d.id = f.directory_id WHERE f.id = :id',
            ['id' => $dropped['fileIds'][0]],
        ));
        self::assertSame('AI sken', self::connection()->fetchOne("SELECT name FROM file_directory WHERE system_key = 'ai-scan' AND project_id = :p", ['p' => self::PROJECT]));

        // "Patří jinam": the logo's picture moves to the photo.
        $moved = $this->fix(['type' => 'move_picture', 'from' => 'logo', 'to' => 'foto']);
        self::assertNull($moved['proposal']['placeholders'][1]['picture']);
        self::assertSame($dropped['fileIds'][1], self::pictureOf($moved['proposal'], 2)['fileId']);
        self::assertTrue(self::pictureOf($moved['proposal'], 2)['tooSmall'], 'a 280 px picture in a 400 px place');

        // Gallery multi-pick: one existing picture into the one empty place.
        $galleryId = $this->galleryPicture();
        $picked = $this->fix(['type' => 'assign_pictures', 'fileIds' => [$galleryId, $galleryId]]);
        self::assertSame(1, $picked['assigned']);
        self::assertSame($galleryId, self::pictureOf($picked['proposal'], 1)['fileId']);
        self::assertFalse(self::pictureOf($picked['proposal'], 1)['uploaded']);

        // One file for one place; "Odebrat obrázek"; "Bez ukázky".
        $single = $this->upload([self::file(140, 60)], 'logo');
        self::assertSame($single['fileIds'][0], self::pictureOf($single['proposal'], 1)['fileId']);
        $emptied = $this->fix(['type' => 'set_picture', 'id' => 'logo', 'fileId' => null]);
        self::assertNull($emptied['proposal']['placeholders'][1]['picture']);
        $none = $this->fix(['type' => 'no_picture', 'id' => 'foto']);
        self::assertTrue($none['proposal']['placeholders'][2]['decidedEmpty']);

        // Refusals: a foreign or trashed picture, a file over 10 MB, a text file.
        self::assertSame('invalid_fix', $this->fix(['type' => 'set_picture', 'id' => 'logo', 'fileId' => Uuid::uuid7()->toString()])['code']);
        self::bus()->dispatch(new DeleteFileUpload(Uuid::fromString($galleryId)));
        self::assertSame('invalid_fix', $this->fix(['type' => 'set_picture', 'id' => 'logo', 'fileId' => $galleryId])['code']);

        $big = tempnam(sys_get_temp_dir(), 'big');
        file_put_contents($big, str_repeat("\x89PNG", 2_500_001));
        self::assertSame(['invalid_fix', 'size'], [($answer = $this->upload([new UploadedFile($big, 'big.png', 'image/png', null, true)]))['code'], $answer['field']]);
        self::assertResponseStatusCodeSame(422);
        @unlink($big);

        $text = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($text, 'not a picture');
        self::assertSame('type', $this->upload([new UploadedFile($text, 'x.png', 'image/png', null, true)])['field']);
        @unlink($text);
    }

    public function testAcceptCreatesOneTemplateAndTwiceReturnsTheSameIds(): void
    {
        $picture = $this->upload([self::file(800, 600)], 'foto');
        $this->fix(['type' => 'choose_font', 'group' => 'headline', 'face' => self::BOLD]);
        $this->fix(['type' => 'set_name', 'name' => 'Výsledek zápasu']);
        $templates = self::rowCount('template');

        $accepted = $this->post('accept');
        self::assertResponseIsSuccessful();
        self::assertTrue($accepted['created']);
        self::assertSame('accepted', $accepted['scan']['status']);
        self::assertSame(6, $accepted['elements']);
        self::assertSame(2, $accepted['unfilledPictures'], 'the logo (part of the template) and the grey background');
        self::assertStringContainsString('/template-variant/' . $accepted['variantId'] . '/editor', $accepted['editorUrl']);
        self::assertSame($templates + 1, self::rowCount('template'));

        self::assertSame('Výsledek zápasu', self::connection()->fetchOne('SELECT name FROM template WHERE id = :id', ['id' => $accepted['templateId']]));
        $variant = self::connection()->fetchAssociative('SELECT * FROM template_variant WHERE id = :id', ['id' => $accepted['variantId']]);
        self::assertIsArray($variant);
        $canvas = self::decoded($variant['canvas']);
        $objects = self::decoded($canvas['objects'] ?? null);
        self::assertCount(6, $objects);

        // Empty places reference the project's stand-in row, the filled one its picture.
        $standIn = self::connection()->fetchAssociative('SELECT id, path FROM file_upload WHERE project_id = :p AND source = :s', ['p' => self::PROJECT, 's' => FileSource::ScanStandIn->value]);
        self::assertIsArray($standIn);
        $byName = [];

        foreach ($objects as $object) {
            self::assertIsArray($object);

            if (($object['type'] ?? null) === 'Image') {
                $assetId = is_string($object['assetId'] ?? null) ? $object['assetId'] : '';
                $byName[($object['isBackground'] ?? false) === true ? 'bg' : $assetId] = $object;
            }
        }

        self::assertSame($standIn['path'], $byName['bg']['assetPath']);
        self::assertTrue($byName['bg']['imagePlaceholder'], 'the background stays a fillable slot');
        self::assertArrayHasKey($standIn['id'], $byName, 'the empty logo is a decorative picture on the stand-in');
        self::assertFalse($byName[$standIn['id']]['imagePlaceholder']);
        self::assertTrue($byName[$picture['fileIds'][0]]['imagePlaceholder'], 'the photo is a field with its sample');
        self::assertStringNotContainsString(AiScanStandIn::ASSET_ID, self::text($this->scanId, 'status') . json_encode($variant, JSON_THROW_ON_ERROR), 'the reserved id never reaches a template');
        self::assertNotNull($variant['preview_image_path']);

        // Twice → the same ids, nothing new.
        $again = $this->post('accept');
        self::assertResponseIsSuccessful();
        self::assertFalse($again['created']);
        self::assertSame([$accepted['templateId'], $accepted['variantId']], [$again['templateId'], $again['variantId']]);
        self::assertSame($templates + 1, self::rowCount('template'));

        // After accept the scan page is read-only.
        self::assertSame('wrong_state', $this->fix(['type' => 'keep_font', 'group' => 'headline'])['code']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('wrong_state', $this->upload([self::file(100, 100)])['code']);
        self::assertSame('wrong_state', $this->post('discard')['code']);
        self::assertTrue($this->proposal()['readOnly']);

        // The uploaded picture stays in the gallery.
        self::assertNull(self::connection()->fetchOne('SELECT deleted_at FROM file_upload WHERE id = :id', ['id' => $picture['fileIds'][0]]));
    }

    /**
     * A text corrected in the review is the template's text EVERYWHERE: the
     * canvas object, its sample ("Vzorový text" — what the fill page
     * pre-fills and an export without a value renders) and the input row.
     * On production the sample kept the scanner's reading (2026-11-13).
     */
    public function testACorrectedTextIsWhatTheTemplateFillsAndExports(): void
    {
        $long = "Opravený nadpis\nna dva řádky";
        $edited = $this->fix(['type' => 'set_text', 'id' => 'headline', 'text' => $long]);
        self::assertSame($long, self::element($edited['proposal'], 'headline')['text'], 'the line break survives');
        $this->fix(['type' => 'confirm_text', 'id' => 'sub', 'text' => 'Drobné písmo']);
        $this->fix(['type' => 'set_name', 'name' => 'Opravené texty']);

        $accepted = $this->post('accept');
        self::assertResponseIsSuccessful();
        $variant = self::connection()->fetchAssociative('SELECT canvas, inputs FROM template_variant WHERE id = :id', ['id' => $accepted['variantId']]);
        self::assertIsArray($variant);

        $expected = ['Titulek' => $long, 'Text' => 'Drobné písmo'];
        $texts = [];

        foreach (self::decoded(self::decoded($variant['canvas'])['objects'] ?? null) as $object) {
            $name = is_array($object) && is_string($object['name'] ?? null) ? $object['name'] : '';

            if (is_array($object) && is_string($object['inputId'] ?? null) && is_string($object['text'] ?? null) && isset($expected[$name])) {
                $texts[$name] = [$object['text'], $object['sampleValue'] ?? null, $object['maxLength'] ?? null];
            }
        }

        self::assertSame([$long, $long], array_slice($texts['Titulek'] ?? [], 0, 2), 'object text and sample');
        self::assertGreaterThanOrEqual(mb_strlen($long), $texts['Titulek'][2] ?? 0, 'the cap grew with the correction (16 in the proposal) — a longer sample is cut at render');
        self::assertSame(['Drobné písmo', 'Drobné písmo', 20], $texts['Text'] ?? null);

        $samples = [];

        foreach (self::decoded($variant['inputs']) as $input) {
            if (is_array($input) && is_string($input['name'] ?? null)) {
                $samples[$input['name']] = $input['sampleValue'] ?? null;
            }
        }

        self::assertSame($expected, array_intersect_key($samples, $expected), 'the input rows — what the fill page pre-fills and the API reports');
        $stored = json_encode($variant, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Headline', $stored, 'the old reading is nowhere in the template');
        self::assertStringNotContainsString('Small print', $stored);
    }

    /**
     * "1 prvek jsme vynechali" over the headline and the text lines
     * (production, 2026-11-13): a left-out entry that lies on layers the scan
     * built is not stored, a stored one is not offered, and no place for a
     * picture can be put there.
     */
    public function testALeftOutThingOnBuiltLayersIsNotOffered(): void
    {
        // The headline's and the small print's boxes are 80,120 600×90 and 80,240 300×40.
        $onTexts = ['code' => 'effect', 'box' => [70, 110, 620, 190], 'diff' => 0.11, 'by' => 'model'];
        $real = ['code' => 'picture', 'box' => [600, 800, 200, 100], 'diff' => 0.2, 'by' => 'model'];
        $this->scanId = self::ready($this->client, reportOverrides: ['omitted' => [$onTexts, $real]]);

        self::assertEquals([$real], self::decoded(self::text($this->scanId, 'report'))['omitted'], 'never stored');
        $proposal = $this->proposal();
        self::assertSame(['omitted:0'], array_values(array_filter(array_column($proposal['decisions'], 'key'), static fn (string $key): bool => str_starts_with($key, 'omitted:'))));
        self::assertSame([600, 800, 200, 100], array_values(array_filter($proposal['decisions'], static fn (array $decision): bool => $decision['key'] === 'omitted:0'))[0]['box']);

        // A proposal stored before the rule: the entry is in the report.
        self::connection()->executeStatement(
            'UPDATE ai_scan SET report = jsonb_set(report, \'{omitted}\', CAST(:omitted AS JSONB)) WHERE id = :id',
            ['omitted' => json_encode([$real, $onTexts], JSON_THROW_ON_ERROR), 'id' => $this->scanId],
        );
        $stored = $this->proposal();
        self::assertSame(['font:headline', 'confirm:sub', 'omitted:0'], array_column($stored['decisions'], 'key'), 'not offered');
        self::assertSame($proposal['toDecide'], $stored['toDecide']);
        self::assertSame('invalid_fix', $this->fix(['type' => 'add_placeholder', 'omitted' => 1])['code'], 'no picture place on top of the texts');
        self::assertSame($proposal['counts']['places'] + 1, $this->fix(['type' => 'add_placeholder', 'omitted' => 0])['proposal']['counts']['places'], 'the real one still gets its place');
    }

    /**
     * A PDF's working copy is its first page rasterised large, for reading;
     * the template takes the PAGE's size (production, 2026-11-13: a
     * 1080 × 1350 page became a 3276 × 4096 px template).
     */
    public function testATemplateFromAPdfHasThePagesSizeNotTheRastersSize(): void
    {
        // The fixture's working copy is 900 × 1200 px; its page is 450 × 600 pt.
        $this->scanId = self::ready($this->client, sourceMeta: ['pagePt' => [450, 600], 'pageMm' => [158.8, 211.7], 'width' => 900, 'height' => 1200]);
        self::assertEquals(['pagePt' => [450, 600], 'pageMm' => [158.8, 211.7]], array_intersect_key(self::decoded(self::text($this->scanId, 'source_meta')), ['pagePt' => 1, 'pageMm' => 1]), 'the page size is kept from the intake');

        $proposal = $this->proposal();
        self::assertEquals(['unit' => 'px', 'width' => 450, 'height' => 600, 'pxWidth' => 450, 'pxHeight' => 600], $proposal['format']);
        $foto = array_values(array_filter($proposal['placeholders'], static fn (array $place): bool => $place['id'] === 'foto'))[0];
        self::assertSame([200, 150], [$foto['width'], $foto['height']], 'a place is that large IN THE TEMPLATE');

        $accepted = $this->post('accept');
        self::assertResponseIsSuccessful();
        $variant = self::connection()->fetchAssociative('SELECT dimension_unit, dimension_unit_width, dimension_unit_height, canvas FROM template_variant WHERE id = :id', ['id' => $accepted['variantId']]);
        self::assertIsArray($variant);
        self::assertEquals(['px', 450, 600], [$variant['dimension_unit'], $variant['dimension_unit_width'], $variant['dimension_unit_height']]);

        // … and the design came along: the headline was 72 px at 80, 120.
        foreach (self::decoded(self::decoded($variant['canvas'])['objects'] ?? null) as $object) {
            if (is_array($object) && ($object['name'] ?? null) === 'Titulek') {
                self::assertEquals([36, 40, 60], [$object['fontSize'], $object['left'], $object['top']]);

                return;
            }
        }

        self::fail('The headline is not in the template.');
    }

    public function testStaleReferencesCreateNothing(): void
    {
        $picture = $this->upload([self::file(800, 600)], 'foto');
        $this->fix(['type' => 'choose_font', 'group' => 'headline', 'face' => self::BOLD]);
        $templates = self::rowCount('template');

        self::bus()->dispatch(new DeleteFileUpload(Uuid::fromString($picture['fileIds'][0])));
        self::bus()->dispatch(new DeleteFontFace(Uuid::fromString(TestDataFixture::FONT_RUBIK_ID), 'Rubik Bold'));
        self::getContainer()->get('doctrine')->getManager()->clear();

        $refused = $this->post('accept');
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['stale_references', 1, 1], [$refused['code'], $refused['fonts'], $refused['pictures']]);
        self::assertSame($templates, self::rowCount('template'));
        self::assertSame('ready', self::text($this->scanId, 'status'));

        // The review reopens with them as items to fix.
        $types = array_column($refused['proposal']['decisions'], 'type');
        self::assertContains('missing_font', $types);
        self::assertContains('missing_picture', $types);

        // Fixed → the template is created.
        $this->fix(['type' => 'choose_font', 'group' => 'headline', 'face' => self::FACE]);
        $this->fix(['type' => 'set_picture', 'id' => 'foto', 'fileId' => null]);
        $created = $this->post('accept');
        self::assertTrue($created['created'], (string) $this->client->getResponse()->getContent());
    }

    public function testDiscardSendsUploadsToTheBin(): void
    {
        $uploaded = $this->upload([self::file(800, 600)], 'foto');
        $galleryId = $this->galleryPicture();
        $this->fix(['type' => 'set_picture', 'id' => 'logo', 'fileId' => $galleryId]);

        $discarded = $this->post('discard');
        self::assertSame('discarded', $discarded['scan']['status']);

        self::assertNotNull(self::connection()->fetchOne('SELECT deleted_at FROM file_upload WHERE id = :id', ['id' => $uploaded['fileIds'][0]]), 'uploaded for the proposal → Koš');
        self::assertNull(self::connection()->fetchOne('SELECT deleted_at FROM file_upload WHERE id = :id', ['id' => $galleryId]), 'a picture picked from the gallery stays');
        self::assertNull($this->proposalAnswer()['proposal']);
    }

    public function testExpirySendsUploadsToTheBin(): void
    {
        $uploaded = $this->upload([self::file(800, 600)], 'foto');
        self::connection()->executeStatement("UPDATE ai_scan SET expires_at = NOW() - INTERVAL '1 day' WHERE id = :id", ['id' => $this->scanId]);

        $result = self::getContainer()->get(AiScanPurge::class)->run();

        self::assertSame(1, $result['expired']);
        self::assertSame(1, $result['uploadsTrashed']);
        self::assertSame('expired', self::text($this->scanId, 'status'));
        self::assertNotNull(self::connection()->fetchOne('SELECT deleted_at FROM file_upload WHERE id = :id', ['id' => $uploaded['fileIds'][0]]));
    }

    public function testRatingAndSeen(): void
    {
        self::assertTrue($this->post('rating', ['rating' => -1, 'note' => 'Písmo nesedí'])['ok']);
        self::assertSame([-1, 'Písmo nesedí'], [self::number($this->scanId, 'rating'), self::text($this->scanId, 'rating_note')]);
        self::assertTrue($this->post('rating', ['rating' => 1, 'note' => 'ignored'])['ok']);
        self::assertSame([1, ''], [self::number($this->scanId, 'rating'), self::text($this->scanId, 'rating_note')]);
        self::assertSame('invalid_fix', $this->post('rating', ['rating' => 5])['code']);

        self::assertSame('', self::text($this->scanId, 'seen_at'));
        self::assertTrue($this->post('seen')['seen']);
        self::assertNotSame('', self::text($this->scanId, 'seen_at'));
        self::assertFalse($this->post('seen')['seen'], 'once');
    }

    // ---- helpers -----------------------------------------------------------

    /**
     * @return array{proposal: null|Proposal}
     */
    private function proposalAnswer(): array
    {
        $this->client->request('GET', sprintf('/ai-scan/%s/proposal', $this->scanId));
        self::assertResponseIsSuccessful();

        /** @var array{proposal: null|Proposal} $answer */
        $answer = self::decoded((string) $this->client->getResponse()->getContent());

        return $answer;
    }

    /**
     * @return Proposal
     */
    private function proposal(): array
    {
        $proposal = $this->proposalAnswer()['proposal'];
        self::assertNotNull($proposal);

        return $proposal;
    }

    /**
     * @return Answer
     */
    private function answer(): array
    {
        /** @var Answer $answer */
        $answer = self::decoded((string) $this->client->getResponse()->getContent());

        return $answer;
    }

    /**
     * @param array<string, mixed> $fix
     * @return Answer
     */
    private function fix(array $fix): array
    {
        $this->client->request(
            'POST',
            sprintf('/ai-scan/%s/fix', $this->scanId),
            server: ['HTTP_ORIGIN' => 'http://localhost', 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['_token' => 'csrf-token', 'fix' => $fix], JSON_THROW_ON_ERROR),
        );

        return $this->answer();
    }

    /**
     * @param array<string, mixed> $fields
     * @return Answer
     */
    private function post(string $action, array $fields = []): array
    {
        $this->client->request('POST', sprintf('/ai-scan/%s/%s', $this->scanId, $action), ['_token' => 'csrf-token'] + $fields, server: ['HTTP_ORIGIN' => 'http://localhost']);

        return $this->answer();
    }

    /**
     * @param list<UploadedFile> $files
     * @return Answer
     */
    private function upload(array $files, null|string $place = null): array
    {
        $this->client->request(
            'POST',
            sprintf('/ai-scan/%s/pictures', $this->scanId),
            ['_token' => 'csrf-token'] + ($place === null ? [] : ['place' => $place]),
            ['pictures' => $files],
            ['HTTP_ORIGIN' => 'http://localhost'],
        );

        return $this->answer();
    }

    private static function file(int $width, int $height): UploadedFile
    {
        return new UploadedFile(self::picture($width, $height), 'obrazek.png', 'image/png', null, true);
    }

    private function galleryPicture(): string
    {
        $id = Uuid::uuid7();
        self::bus()->dispatch(new UploadFile($id, Uuid::fromString(self::PROJECT), FileSource::ProjectImage, self::file(500, 500)));

        return $id->toString();
    }

    /**
     * @param Proposal $proposal
     * @return Picture
     */
    private static function pictureOf(array $proposal, int $index): array
    {
        $picture = $proposal['placeholders'][$index]['picture'] ?? null;
        self::assertNotNull($picture, 'The place has no picture.');

        return $picture;
    }

    /**
     * @param Proposal $proposal
     * @return Element
     */
    private static function element(array $proposal, string $id): array
    {
        foreach ($proposal['elements'] as $element) {
            if (($element['id'] ?? null) === $id) {
                /** @var Element $element */
                return $element;
            }
        }

        self::fail('No element ' . $id);
    }

    /**
     * @param Proposal $proposal
     * @return array{undone: bool}
     */
    private static function information(array $proposal, string $code): array
    {
        foreach ($proposal['information'] as $line) {
            if (($line['code'] ?? null) === $code) {
                /** @var array{undone: bool} $line */
                return $line;
            }
        }

        self::fail('No information line ' . $code);
    }
}
