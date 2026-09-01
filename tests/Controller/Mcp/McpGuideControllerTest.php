<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Mcp;

use Mcp\Capability\Attribute\McpTool;
use ReflectionAttribute;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DomCrawler\Crawler;
use WBoost\Web\DependencyInjection\McpToolScopePass;
use WBoost\Web\Services\Mcp\DescribeMcpCapabilities;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

/**
 * "AI (MCP server)" — the in-app Czech guide to connecting an AI client.
 *
 * The load-bearing test here is {@see testEveryRegisteredToolIsDescribed()}. A
 * page that explains a feature is only worth having while it describes the
 * feature that actually shipped, and the failure mode of a hand-kept list is
 * silent: nothing renders differently when a tool lands or is withdrawn, so the
 * page quietly starts lying. Asserting the described set against the REGISTERED
 * set is what turns that into a red build.
 */
final class McpGuideControllerTest extends WebTestCase
{
    private const string PAGE = '/ai';

    /**
     * Where the test-only probe tools live, relative to this file. Their names
     * are READ from there rather than copied, so adding a probe cannot break
     * {@see testEveryShippedToolHasCzechCopy()} and cannot quietly exempt a
     * real tool either.
     */
    private const string FIXTURE_TOOL_DIRECTORY = __DIR__ . '/../../Mcp/Fixtures';

    public function testAnonymousVisitorIsSentToLogin(): void
    {
        $browser = self::createClient();

        $browser->request('GET', self::PAGE);

        self::assertResponseRedirects('/login');
    }

    /**
     * The endpoint URL is GENERATED, so the page is correct on whatever host it
     * is served from — asserting the test host's absolute URL is what would
     * fail if somebody replaced it with a `https://wboost.cz` literal.
     */
    public function testThePageNamesTheMcpEndpoint(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();

        $page = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('http://localhost/_mcp', $page);
    }

    /**
     * The permissions are described in the consent screen's own words, not in a
     * second translation of the same four concepts.
     */
    public function testThePageDescribesThePermissionsInCzech(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();

        $page = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('Prohlížet vaše projekty a šablony', $page);
        self::assertStringContainsString('Stahovat hotové exporty', $page);
        self::assertStringContainsString('Vytvářet a přepisovat šablony', $page);
        self::assertStringContainsString('Nahrávat obrázky do galerie', $page);
    }

    /**
     * Managing and revoking a connection lives on "Propojené aplikace"; this
     * page must point at it rather than grow a second, half-complete copy.
     */
    public function testThePageLinksToConnectedApps(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/user-profile/connected-apps"]');
    }

    /**
     * Personal access tokens are issued by an operator today. The page must say
     * so instead of showing a button that cannot exist — and must not grow one
     * by accident, which is what the second assertion guards.
     */
    public function testThePageIsHonestAboutTokensBeingIssuedByAnOperator(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();

        $page = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('Tokeny zatím vydává správce', $page);
        self::assertSelectorNotExists('form[action*="token"]');
    }

    /**
     * The page's capability list IS the registered tool list — every tool the
     * server offers is on the page, named the way the user's client names it.
     */
    public function testEveryRegisteredToolIsOnThePage(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();

        $toolScopes = self::registeredTools($browser->getContainer());

        self::assertNotSame([], $toolScopes);

        $page = (string) $browser->getResponse()->getContent();

        foreach (array_keys($toolScopes) as $tool) {
            self::assertStringContainsString($tool, $page, sprintf(
                'The registered MCP tool "%s" is missing from the AI page.',
                $tool,
            ));
        }
    }

    /**
     * …and every one of them carries real Czech copy. This is what keeps the
     * page from going stale: a tool that lands without copy still renders (as
     * the loud `known: false` row, which is the right runtime behaviour) but
     * fails the build here, where somebody can write the two sentences.
     *
     * The probes are TEST-ONLY tools registered by `config/services_test.php`
     * from `tests/Mcp/Fixtures/` to exercise the auth, scope and transport
     * layers. They never exist in a real container, so demanding Czech copy for
     * them would be asserting against the test harness rather than against the
     * product.
     */
    public function testEveryShippedToolHasCzechCopy(): void
    {
        $container = self::getContainer();

        $describeCapabilities = $container->get(DescribeMcpCapabilities::class);

        $probes = self::fixtureTools();
        self::assertNotSame([], $probes);

        $described = [];

        foreach ($describeCapabilities->describe() as $capability) {
            if (in_array($capability->tool, $probes, true)) {
                continue;
            }

            self::assertTrue($capability->known, sprintf(
                'The MCP tool "%s" is registered but the AI page has no Czech copy for it.',
                $capability->tool,
            ));

            $described[] = $capability->tool;
        }

        $shipped = array_values(array_diff(array_keys(self::registeredTools($container)), $probes));

        sort($described);
        sort($shipped);

        self::assertSame($shipped, $described);
    }

    /**
     * A read-only user shared into somebody else's project is exactly who the
     * connector is most useful for — browsing, previews and exports without
     * touching the editor — so the page and its navigation entry are not
     * gated on a designer role.
     */
    public function testAReadOnlySharedUserSeesThePageAndItsNavigationEntry(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::SHARED_USER_EMAIL);

        $browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.leftbar-account__menu a[href="/ai"]');
        self::assertSelectorTextContains('.leftbar-account__menu', 'AI (MCP server)');
    }

    /**
     * The entry lives in the SHARED account drop-up at the sidebar's bottom
     * edge, above "Odhlásit se" — so it is reachable from anywhere in the app,
     * not only from itself. The page it is asserted on is deliberately
     * somebody else's.
     */
    public function testTheNavigationEntrySitsInTheAccountMenu(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $crawler = $browser->request('GET', '/user-profile/connected-apps');

        self::assertResponseIsSuccessful();

        $items = $crawler->filter('.leftbar-account__menu .dropdown-item')->each(
            static fn (Crawler $node): string => trim($node->text()),
        );

        $ai = array_search('AI (MCP server)', $items, true);
        $logout = array_search('Odhlásit se', $items, true);

        self::assertIsInt($ai);
        self::assertIsInt($logout);
        self::assertLessThan($logout, $ai, 'The AI entry must sit above "Odhlásit se" in the account menu.');
    }

    /**
     * The tool names declared by the probe classes in `tests/Mcp/Fixtures/`,
     * read from their `#[McpTool]` attributes exactly as
     * {@see McpToolScopePass} reads them.
     *
     * @return list<string>
     */
    private static function fixtureTools(): array
    {
        /** @var list<string> $names */
        $names = [];

        foreach ((array) glob(self::FIXTURE_TOOL_DIRECTORY . '/*.php') as $file) {
            if (!is_string($file)) {
                continue;
            }

            /** @var class-string $class */
            $class = 'WBoost\\Web\\Tests\\Mcp\\Fixtures\\' . basename($file, '.php');

            if (!class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            // The probes do not agree on a method name, so every method is
            // looked at — the attribute is what identifies a tool, not the
            // signature it happens to sit on.
            foreach ($reflection->getMethods() as $method) {
                foreach ($method->getAttributes(McpTool::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                    $names[] = $attribute->newInstance()->name ?? $method->getName();
                }
            }

            foreach ($reflection->getAttributes(McpTool::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                $names[] = $attribute->newInstance()->name ?? $reflection->getShortName();
            }
        }

        return $names;
    }

    /**
     * @return array<string, null|string> tool name => scope value
     */
    private static function registeredTools(ContainerInterface $container): array
    {
        /** @var array<string, null|string> $toolScopes */
        $toolScopes = $container->getParameter(McpToolScopePass::PARAMETER);

        return $toolScopes;
    }
}
