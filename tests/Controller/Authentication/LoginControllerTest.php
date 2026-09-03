<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Authentication;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Tests\TestingLogin;

final class LoginControllerTest extends WebTestCase
{
    public function testResponseIsOk(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/login');

        $this->assertResponseIsSuccessful();
    }


    public function testRedirectLoggedUsersToDashboard(): void
    {
        $browser = self::createClient();
        
        TestingLogin::logInAsUser($browser, 'user1@test.cz');

        $browser->request('GET', '/login');

        $this->assertResponseRedirects('/dashboard');
    }


    /**
     * A login POST whose credential fields are missing, or arrive as arrays —
     * a scanner, a stale bookmark — used to escape the firewall as a
     * BadRequestHttpException ("The key "_username" must be a string, "NULL"
     * given.", Sentry 77905823) and answer 400 through the error page. It is a
     * failed login: back to the form, no exception report.
     *
     * @param array<string, mixed> $body
     */
    #[DataProvider('malformedCredentials')]
    public function testMalformedCredentialsAreAFailedLogin(string $uri, array $body): void
    {
        $browser = self::createClient();

        $browser->request('POST', $uri, $body);

        $this->assertResponseRedirects('/login');
    }


    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function malformedCredentials(): iterable
    {
        yield 'no username at all' => ['/login', ['_password' => 'x']];
        yield 'no fields at all' => ['/login', []];
        yield 'username as array' => ['/login', ['_username' => ['a'], '_password' => 'x']];
        yield 'password as array' => ['/login', ['_username' => 'user1@test.cz', '_password' => ['x']]];
        yield 'csrf token as array' => ['/login', ['_username' => 'user1@test.cz', '_password' => 'x', '_csrf_token' => ['c']]];
        // The CSRF token is looked up in the query string too.
        yield 'csrf token as array in query' => ['/login?_csrf_token[]=x', ['_username' => 'user1@test.cz', '_password' => 'x']];
    }


    /**
     * ...and the user is told what is wrong in the ordinary way, rather than
     * being shown an error page.
     */
    public function testMissingUsernameShowsTheInvalidCredentialsMessage(): void
    {
        $browser = self::createClient();

        $browser->request('POST', '/login', ['_password' => 'x']);
        $browser->followRedirect();

        $this->assertSelectorTextContains('.alert-danger', 'Neplatné přihlašovací údaje.');
    }


    /**
     * The repair must never SHADOW a good token: the CSRF parameter is read
     * from the query string BEFORE the body, so writing an empty one into the
     * query bag of every login POST would break all of them, not just the
     * malformed ones.
     */
    public function testAWellFormedLoginStillValidatesItsCsrfToken(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/login');
        $form = $crawler->filter('form[action="/login"]')->form([
            '_username' => 'user1@test.cz',
            '_password' => 'wrong-password',
        ]);

        $browser->submit($form);

        $this->assertResponseRedirects('/login');

        $browser->followRedirect();

        // Had the submitted token been shadowed, this would read
        // "Neplatný CSRF token." instead.
        $this->assertSelectorTextContains('.alert-danger', 'Neplatné přihlašovací údaje.');
    }
}
