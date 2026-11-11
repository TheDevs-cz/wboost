<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Calendar;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\Calendar\CalendarModuleInliner;

/**
 * The headless render page loads the calendar modules through an import map
 * of `data:` URLs. A module whose import is not rewritten — or points at a
 * file outside the inlined directories — would only fail inside Gotenberg, as
 * a timeout. This pins the map to the source tree instead.
 *
 * @covers \WBoost\Web\Services\Calendar\CalendarModuleInliner
 */
final class CalendarModuleInlinerTest extends TestCase
{
    public function testRelativeImportsBecomeBareSpecifiers(): void
    {
        $source = <<<'JS'
            import { a } from './svg.js';
            import {
                b,
                c,
            } from '../model/defaults.js';
            export { d } from "../generate/pages.js";
            const text = 'copied from the prototype';
            JS;

        $rewritten = CalendarModuleInliner::rewriteImports($source, 'render');

        self::assertStringContainsString("from 'wbcal/render/svg.js';", $rewritten);
        self::assertStringContainsString("} from 'wbcal/model/defaults.js';", $rewritten);
        self::assertStringContainsString('from "wbcal/generate/pages.js";', $rewritten);
        self::assertStringContainsString("'copied from the prototype'", $rewritten);
    }

    public function testEveryImportOfEveryInlinedModuleIsInTheMap(): void
    {
        $inliner = new CalendarModuleInliner(dirname(__DIR__, 3) . '/assets/calendar');

        /** @var array{imports: array<string, string>} $map */
        $map = json_decode($inliner->importMap(), true, 16, JSON_THROW_ON_ERROR);
        $imports = $map['imports'];

        self::assertArrayHasKey('wbcal/headless/render.js', $imports);
        self::assertArrayHasKey('wbcal/render/page.js', $imports);
        self::assertGreaterThan(20, count($imports));

        foreach ($imports as $specifier => $url) {
            self::assertStringStartsWith('data:text/javascript;base64,', $url);
            $source = (string) base64_decode(substr($url, strlen('data:text/javascript;base64,')), true);

            self::assertDoesNotMatchRegularExpression('~\bfrom\s+[\'"]\.{1,2}/~', $source, $specifier . ' still has a relative import');

            preg_match_all('~\bfrom\s+[\'"]([^\'"\n]+)[\'"]~', $source, $matches);

            foreach ($matches[1] as $imported) {
                if (!str_starts_with($imported, CalendarModuleInliner::PREFIX)) {
                    continue;
                }

                self::assertArrayHasKey($imported, $imports, sprintf('%s imports %s, which is not inlined — add its directory to CalendarModuleInliner::DIRECTORIES.', $specifier, $imported));
            }
        }

        // Memoised: the second call is the same string.
        self::assertSame($inliner->importMap(), $inliner->importMap());
    }
}
