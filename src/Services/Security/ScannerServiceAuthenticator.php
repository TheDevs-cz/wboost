<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Guards `/internal/scanner/*` (the `scanner` firewall). A request is the
 * scanner's only when BOTH hold:
 *
 * 1. **It came from the scan network.** The TCP peer address — `REMOTE_ADDR`
 *    as the server saw it, never `getClientIp()`, which honours forwarding
 *    headers from trusted proxies — lies in `SCAN_NETWORK_CIDR`. A request
 *    through the public router arrives from the router's network and carries
 *    `X-Forwarded-For`; either fact refuses it.
 * 2. **It carries the token**: `Authorization: Bearer <SCANNER_TOKEN>`.
 *    `SCANNER_TOKEN` may hold two values (comma-separated) while the token is
 *    rotated: the current and the previous one.
 *
 * Everything else gets **404 with an empty body** — the same answer whether
 * the network, the token or both were wrong, so the path does not even admit
 * to existing. With `SCAN_NETWORK_CIDR` or `SCANNER_TOKEN` unset (the default,
 * and production until the scanner is deployed) nothing passes.
 */
final class ScannerServiceAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public const string ROLE = 'ROLE_SCANNER';

    /** A shorter token is treated as not configured. */
    private const int MIN_TOKEN_LENGTH = 24;

    /** @var list<string> */
    private readonly array $networks;

    /** @var list<string> */
    private readonly array $tokens;

    public function __construct(
        #[Autowire('%env(SCAN_NETWORK_CIDR)%')]
        string $scanNetworkCidr,
        #[Autowire('%env(SCANNER_TOKEN)%')]
        string $scannerToken,
    ) {
        $this->networks = self::list($scanNetworkCidr);
        $this->tokens = array_values(array_filter(
            self::list($scannerToken),
            static fn (string $token): bool => strlen($token) >= self::MIN_TOKEN_LENGTH,
        ));
    }

    public function supports(Request $request): bool
    {
        // Every request of the firewall: a missing header must be refused
        // here (404), not handed to the access rules.
        return true;
    }

    public function authenticate(Request $request): Passport
    {
        if (!$this->fromScanNetwork($request) || !$this->hasToken($request)) {
            throw new CustomUserMessageAuthenticationException('Not found.');
        }

        return new SelfValidatingPassport(new UserBadge(
            'scanner',
            static fn (): InMemoryUser => new InMemoryUser('scanner', null, [self::ROLE]),
        ));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): null|Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return self::notFound();
    }

    public function start(Request $request, null|AuthenticationException $authException = null): Response
    {
        return self::notFound();
    }

    private function fromScanNetwork(Request $request): bool
    {
        if ($this->networks === [] || $request->headers->has('X-Forwarded-For') || $request->headers->has('Forwarded')) {
            return false;
        }

        $peer = $request->server->get('REMOTE_ADDR');

        return is_string($peer) && $peer !== '' && IpUtils::checkIp($peer, $this->networks);
    }

    private function hasToken(Request $request): bool
    {
        $header = $request->headers->get('Authorization') ?? '';

        if (!str_starts_with($header, 'Bearer ')) {
            return false;
        }

        $presented = substr($header, 7);
        $match = false;

        // No early exit: compare against every configured value.
        foreach ($this->tokens as $token) {
            $match = hash_equals($token, $presented) || $match;
        }

        return $match;
    }

    private static function notFound(): Response
    {
        return new Response('', Response::HTTP_NOT_FOUND);
    }

    /**
     * @return list<string>
     */
    private static function list(string $csv): array
    {
        return array_values(array_filter(array_map(trim(...), explode(',', $csv)), static fn (string $item): bool => $item !== ''));
    }
}
