<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Calendar;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The calendar's ES modules as ONE self-contained import map, for a page
 * rendered inside Gotenberg (no network, a flat temp directory — relative
 * `import` paths between directories would not resolve there).
 *
 * Every module of the pure directories is read from `assets/calendar/`, its
 * relative import specifiers (`'./svg.js'`, `'../model/defaults.js'`) are
 * rewritten to bare ones (`wbcal/render/svg.js`), and the import map sends
 * each bare specifier to a `data:` URL holding that module's source. The
 * render template then needs one `<script type="importmap">` and one
 * `import … from 'wbcal/headless/render.js'`.
 *
 * Nothing is bundled, transformed or cached on disk: the source files ARE
 * what the browser runs, so the headless picture and the workspace cannot
 * drift apart. The map is memoised per process, keyed by the newest mtime.
 */
final class CalendarModuleInliner
{
    public const string PREFIX = 'wbcal/';

    /** The pure directories (no DOM, no fetch) plus the headless entry. `ui/` and `state/` are never needed to draw a page. */
    public const array DIRECTORIES = ['model', 'generate', 'render', 'editor', 'headless'];

    private null|string $fingerprint = null;

    private null|string $importMap = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/assets/calendar')]
        private readonly string $root,
    ) {
    }

    /**
     * The JSON of `<script type="importmap">`.
     */
    public function importMap(): string
    {
        $files = $this->files();
        $fingerprint = '';

        foreach ($files as $specifier => $path) {
            $fingerprint .= $specifier . ':' . (string) @filemtime($path) . ':' . (string) @filesize($path) . ';';
        }

        if ($this->importMap !== null && $this->fingerprint === $fingerprint) {
            return $this->importMap;
        }

        $imports = [];

        foreach ($files as $specifier => $path) {
            $source = @file_get_contents($path);

            if ($source === false) {
                throw new \RuntimeException(sprintf('Calendar module not readable at "%s".', $path));
            }

            $imports[$specifier] = 'data:text/javascript;base64,' . base64_encode(self::rewriteImports($source, dirname(substr($specifier, strlen(self::PREFIX)))));
        }

        $this->fingerprint = $fingerprint;

        return $this->importMap = json_encode(['imports' => $imports], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * A short hash of everything the headless pages run — the module sources
     * and the templates around them. A PDF export remembers it: a file made
     * by an older renderer is not handed out again as "unchanged".
     */
    public function fingerprint(): string
    {
        $hash = hash_init('xxh128');
        hash_update($hash, $this->importMap());

        foreach (['headless_print.html.twig', 'headless_render.html.twig'] as $template) {
            hash_update($hash, (string) @file_get_contents(dirname($this->root, 2) . '/templates/calendar/' . $template));
        }

        return hash_final($hash);
    }

    /**
     * Relative import specifiers of one module → bare `wbcal/<dir>/<file>.js`.
     *
     * @param string $directory the module's directory relative to `assets/calendar` (`render`)
     */
    public static function rewriteImports(string $source, string $directory): string
    {
        return (string) preg_replace_callback(
            '~\b(from\s+|import\s+)([\'"])(\.{1,2}/[^\'"\n]+)\2~',
            static function (array $match) use ($directory): string {
                $segments = $directory === '.' || $directory === '' ? [] : explode('/', $directory);

                foreach (explode('/', $match[3]) as $segment) {
                    if ($segment === '.' || $segment === '') {
                        continue;
                    }

                    if ($segment === '..') {
                        array_pop($segments);

                        continue;
                    }

                    $segments[] = $segment;
                }

                return $match[1] . $match[2] . self::PREFIX . implode('/', $segments) . $match[2];
            },
            $source,
        );
    }

    /**
     * @return array<string, string> bare specifier → absolute path, sorted
     */
    private function files(): array
    {
        $files = [];

        foreach (self::DIRECTORIES as $directory) {
            foreach (glob($this->root . '/' . $directory . '/*.js') ?: [] as $path) {
                $files[self::PREFIX . $directory . '/' . basename($path)] = $path;
            }
        }

        ksort($files);

        return $files;
    }
}
