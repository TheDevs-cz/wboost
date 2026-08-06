<?php

declare(strict_types=1);

namespace WBoost\Web\Services\OAuth2;

use League\Bundle\OAuth2ServerBundle\Converter\UserConverterInterface;
use League\Bundle\OAuth2ServerBundle\Entity\User as LeagueUser;
use League\OAuth2\Server\Entities\UserEntityInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use WBoost\Web\Entity\User;

/**
 * Turns the logged-in Symfony user into the OAuth2 resource owner, identified by
 * the App User **UUID**.
 *
 * The bundle's own converter uses `getUserIdentifier()`, which on
 * {@see User} is the EMAIL — and the identifier chosen here is what ends up in
 * the issued token's `sub` claim. Two things then read that claim back, and both
 * key on the UUID:
 *
 * - the `api` firewall's `api_user_provider`, an entity provider on the `id`
 *   column. Handing it an e-mail does not merely 401 — Postgres rejects
 *   `WHERE id = 'user@example.com'` on a `uuid` column outright, so an
 *   auth-code token would blow up every API request with a driver error.
 * - {@see \WBoost\Web\Services\OAuth2\IssueAccessTokenWithUserListener}, which
 *   already stamps `sub` with `$user->id->toString()` for the
 *   client_credentials grant. Without this converter the two grants would
 *   disagree about what a `sub` means, and S8-T6 (one authenticator serving
 *   both PATs and OAuth bearers) would have to special-case the difference.
 *
 * Registered as the `UserConverterInterface` alias in `config/services.php`,
 * overriding the bundle's. The only other consumer is the bundle's password
 * grant {@see \League\Bundle\OAuth2ServerBundle\Repository\UserRepository},
 * which is not enabled here.
 */
final readonly class AppUserConverter implements UserConverterInterface
{
    public function toLeague(UserInterface $user): UserEntityInterface
    {
        $entity = new LeagueUser();

        // A non-App user cannot reach the authorization endpoint (the `main`
        // firewall's provider only ever produces WBoost users), but the
        // interface is typed on the Symfony contract — so fall back to the
        // framework's identifier rather than crash on a hypothetical one.
        $entity->setIdentifier($user instanceof User ? $user->id->toString() : $user->getUserIdentifier());

        return $entity;
    }
}
