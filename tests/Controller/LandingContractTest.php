<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

/**
 * The contract between the app and the standalone landing site.
 *
 * They are independent code — see landing/README.md — but they share a domain,
 * and that creates exactly two ways for them to drift apart: the URLs the
 * landing links INTO the app, and the paths the app must never claim back.
 *
 * This is the ONLY file in tests/ that knows landing/ exists, and it reads
 * nothing but URLs and page filenames. Everything is derived from the
 * filesystem — landing/src/*.html IS the page set — so adding a page is picked
 * up automatically and fails here with the exact change to make.
 */
final class LandingContractTest extends KernelTestCase
{
    private const LANDING_SRC = __DIR__ . '/../../landing/src';

    /** Paths the landing owns; every other root-relative href points at the app. */
    private const LANDING_OWNED = ['/landing/', '/robots.txt', '/sitemap.xml', '/favicon'];

    protected function setUp(): void
    {
        if (!is_dir(self::LANDING_SRC)) {
            self::markTestSkipped('landing/src is absent — the static site is not built in this checkout.');
        }
    }

    /**
     * 1. Every app URL the landing links to still resolves against the real
     *    router. A renamed route fails the build with the offending href.
     */
    public function testEveryAppLinkOnTheLandingResolves(): void
    {
        self::bootKernel();
        $matcher = self::getContainer()->get('router');

        $hrefs = [];
        $ownPages = $this->pages();

        foreach ($this->pageFiles() as $file) {
            $html = (string) file_get_contents($file);
            preg_match_all('~href="(/[^"#]*)"~', $html, $matches);

            foreach ($matches[1] as $href) {
                if (isset($ownPages[$href]) || $this->isLandingOwned($href)) {
                    continue;
                }

                $hrefs[$href] = $href;
            }
        }

        self::assertNotEmpty($hrefs, 'The landing links to no app URL at all — that cannot be right.');

        foreach ($hrefs as $href) {
            try {
                $matcher->match($href);
            } catch (ResourceNotFoundException) {
                self::fail(sprintf('The landing links to %s, which no app route matches.', $href));
            }
        }
    }

    /**
     * 2. The app never shadows a landing path. The day someone adds a Symfony
     *    route under /landing/, or re-adds a `/` route, this fails loudly
     *    instead of silently shadowing the static site.
     */
    public function testTheAppClaimsNoLandingPath(): void
    {
        self::bootKernel();
        $matcher = self::getContainer()->get('router');

        $paths = ['/', '/landing/style.css', '/landing/img/logo.svg'];

        foreach (array_keys($this->pages()) as $url) {
            $paths[] = $url;
        }

        $shadowed = [];

        foreach (array_unique($paths) as $path) {
            try {
                $matcher->match($path);
                $shadowed[] = $path;
            } catch (ResourceNotFoundException) {
                // Good: the app leaves this path to nginx.
            }
        }

        self::assertSame([], $shadowed, sprintf(
            'The app matches %s, which belong to the static landing site.',
            implode(', ', $shadowed),
        ));
    }

    /**
     * 3. The committed Traefik rule matches the page set. On mismatch this
     *    prints the new rule verbatim, so the one unavoidable cross-repo
     *    duplication is a build failure with the fix in the message rather than
     *    a convention nobody remembers.
     */
    public function testTheTraefikRuleIsInSyncWithThePageSet(): void
    {
        $slugs = [];

        foreach ($this->pages() as $url => $file) {
            if ($url !== '/') {
                $slugs[] = ltrim($url, '/');
            }
        }

        sort($slugs);

        $rule = sprintf(
            'Host(`wboost.cz`) && (Path(`/`) || PathPrefix(`/landing/`) || Path(`/robots.txt`) || Path(`/sitemap.xml`) || PathRegexp(`^/(%s)/?$`))',
            implode('|', $slugs),
        );

        $committed = trim((string) file_get_contents(dirname(self::LANDING_SRC) . '/traefik-rule.txt'));

        self::assertSame($rule, $committed, sprintf(
            "landing/traefik-rule.txt is stale. Write this into it AND paste it into\n"
            . "apps/wboost-landing/compose.yaml in the infra repo (infra first — see\n"
            . "landing/README.md \"Adding a page\"):\n\n%s\n",
            $rule,
        ));
    }

    /** 4. Every page is in the sitemap. Extra entries (like /ai, served by the app) are allowed. */
    public function testEveryPageIsInTheSitemap(): void
    {
        $sitemap = (string) file_get_contents(self::LANDING_SRC . '/sitemap.xml');

        foreach (array_keys($this->pages()) as $url) {
            self::assertStringContainsString(
                sprintf('<loc>https://wboost.cz%s</loc>', $url),
                $sitemap,
                sprintf('landing/src/sitemap.xml has no <loc> for %s.', $url),
            );
        }
    }

    /**
     * The page set, derived from the filesystem: index.html serves `/`, every
     * other <slug>.html serves /<slug> extensionless.
     *
     * @return array<string, string> url => absolute file path
     */
    private function pages(): array
    {
        $pages = [];

        foreach ($this->pageFiles() as $file) {
            $slug = basename($file, '.html');
            $pages[$slug === 'index' ? '/' : '/' . $slug] = $file;
        }

        ksort($pages);

        return $pages;
    }

    /** @return list<string> */
    private function pageFiles(): array
    {
        $files = glob(self::LANDING_SRC . '/*.html');
        self::assertNotFalse($files);
        self::assertNotEmpty($files, 'landing/src holds no HTML pages.');

        sort($files);

        return $files;
    }

    private function isLandingOwned(string $href): bool
    {
        foreach (self::LANDING_OWNED as $prefix) {
            if (str_starts_with($href, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
