<?php

declare(strict_types=1);

namespace WBoost\Web\Services\OAuth2;

use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\AccessToken;
use League\Bundle\OAuth2ServerBundle\Model\AuthorizationCode;
use League\Bundle\OAuth2ServerBundle\Model\RefreshToken;
use WBoost\Web\Entity\User;

/**
 * Kills every OAuth credential one user holds for ONE client — access tokens,
 * refresh tokens and any authorization code still in flight.
 *
 * ## Why not the bundle's `CredentialsRevokerInterface`
 *
 * It ships exactly two granularities and both are wrong for "disconnect this
 * app":
 *
 * - `revokeCredentialsForUser()` kills the user's tokens for EVERY client, so
 *   disconnecting one app would silently log the user's other connectors out;
 * - `revokeCredentialsForClient()` kills EVERY user's tokens for that client,
 *   which for a shared connector (claude.ai is one `client_id` for everybody)
 *   means one user's disconnect logs out the whole installation.
 *
 * So the intersection is done here — but with the bundle's own models and its
 * own `revoked` flag, in the same three statements
 * `DoctrineCredentialsRevoker` runs, plus the client predicate. Nothing is
 * deleted and no schema knowledge is duplicated: this is the very column
 * `AccessTokenRepository::isAccessTokenRevoked()` reads, which is what makes an
 * already-issued JWT stop working at `/_mcp` mid-hour.
 *
 * Revocation is what revocation CAN be here: the access token is a
 * self-contained JWT whose `exp` may be up to an hour out, so `persist_access_token`
 * (on, see `config/packages/league_oauth2_server.php`) plus this flag are the
 * ONLY things that can end a session early. Refresh tokens are reached through
 * their access token, exactly as the bundle does it — they carry no user
 * column of their own.
 *
 * The statements are DQL UPDATEs, so they take effect immediately inside the
 * caller's transaction (the `command_bus` `doctrine_transaction` middleware),
 * and commit or roll back together with the approval row being deleted.
 */
readonly final class RevokeUserClientCredentials
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * `$userIdentifier` is the App User UUID string: that is what
     * {@see AppUserConverter} puts in the token's `sub` and therefore what
     * league writes into `oauth2_access_token.user_identifier`.
     */
    public function revoke(User $user, string $clientIdentifier): void
    {
        $userIdentifier = $user->id->toString();

        $this->entityManager->createQueryBuilder()
            ->update(AccessToken::class, 'at')
            ->set('at.revoked', ':revoked')
            ->where('at.userIdentifier = :userIdentifier')
            ->andWhere('at.client = :client')
            ->setParameter('revoked', true)
            ->setParameter('userIdentifier', $userIdentifier)
            ->setParameter('client', $clientIdentifier, 'string')
            ->getQuery()
            ->execute();

        // A refresh token has no user of its own — it points at the access
        // token it was issued beside, which is where both predicates live. The
        // subquery mirrors DoctrineCredentialsRevoker::revokeCredentialsForUser().
        $queryBuilder = $this->entityManager->createQueryBuilder();
        $queryBuilder
            ->update(RefreshToken::class, 'rt')
            ->set('rt.revoked', ':revoked')
            ->where($queryBuilder->expr()->in(
                'rt.accessToken',
                $this->entityManager->createQueryBuilder()
                    ->select('at.identifier')
                    ->from(AccessToken::class, 'at')
                    ->where('at.userIdentifier = :userIdentifier')
                    ->andWhere('at.client = :client')
                    ->getDQL(),
            ))
            ->setParameter('revoked', true)
            ->setParameter('userIdentifier', $userIdentifier)
            ->setParameter('client', $clientIdentifier, 'string')
            ->getQuery()
            ->execute();

        // The window is short (an authorization code lives seconds) but it is
        // the one credential that could still be exchanged for a NEW token
        // after everything else was killed.
        $this->entityManager->createQueryBuilder()
            ->update(AuthorizationCode::class, 'ac')
            ->set('ac.revoked', ':revoked')
            ->where('ac.userIdentifier = :userIdentifier')
            ->andWhere('ac.client = :client')
            ->setParameter('revoked', true)
            ->setParameter('userIdentifier', $userIdentifier)
            ->setParameter('client', $clientIdentifier, 'string')
            ->getQuery()
            ->execute();
    }
}
