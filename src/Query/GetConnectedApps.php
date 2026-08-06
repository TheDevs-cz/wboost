<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use Psr\Clock\ClockInterface;
use WBoost\Web\Entity\User;
use WBoost\Web\Repository\OAuthClientApprovalRepository;
use WBoost\Web\Services\OAuth2\DescribeConsentScopes;

/**
 * The read model behind "Propojené aplikace" — one row per OAuth2 client the
 * user has approved.
 *
 * Two deliberate choices:
 *
 * - **Scopes are re-described, not stored as prose.** The row keeps raw scope
 *   strings; the Czech wording comes from {@see DescribeConsentScopes} on every
 *   render, so improving a description improves it for approvals granted
 *   months ago — and a scope this release no longer understands is shown as an
 *   explicit "unknown", never as a stale sentence that no longer applies.
 * - **`liveTokenCount` is read from the token table, not inferred.** It is what
 *   makes revocation VISIBLE: after disconnecting, the app disappears entirely,
 *   and while it is connected the number says whether anything is actually
 *   using it. Inferring it from `lastUsedAt` would report "connected" for an
 *   app whose tokens were revoked elsewhere.
 *
 * An approval whose client row is gone is skipped: deleting a client cascades
 * its access tokens away (`oauth2_access_token.client` is `ON DELETE CASCADE`),
 * so such an approval grants nothing and has no name to show.
 */
readonly final class GetConnectedApps
{
    public function __construct(
        private OAuthClientApprovalRepository $approvals,
        private ClientManagerInterface $clientManager,
        private DescribeConsentScopes $describeConsentScopes,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<ConnectedApp>
     */
    public function forUser(User $user): array
    {
        $liveTokens = $this->liveTokenCounts($user);

        /** @var list<ConnectedApp> $apps */
        $apps = [];

        foreach ($this->approvals->listForUser($user) as $approval) {
            $client = $this->clientManager->find($approval->clientIdentifier);

            if ($client === null) {
                continue;
            }

            // `getName()` is only a `@method` annotation on ClientInterface, so
            // the guard is real; a nameless client falls back to its
            // identifier, which is never attacker-chosen.
            $name = method_exists($client, 'getName') ? trim($client->getName()) : '';

            $apps[] = new ConnectedApp(
                $approval->clientIdentifier,
                $name !== '' ? $name : $approval->clientIdentifier,
                $this->describeConsentScopes->describeGranted($approval->scopes),
                $approval->approvedAt,
                $approval->lastUsedAt,
                $this->redirectHosts($client->getRedirectUris()),
                $liveTokens[$approval->clientIdentifier] ?? 0,
            );
        }

        return $apps;
    }

    /**
     * Access tokens that would still authenticate right now, per client — the
     * same predicate league's own validator applies (not revoked, not expired).
     *
     * @return array<string, int>
     */
    private function liveTokenCounts(User $user): array
    {
        $sql = <<<'SQL'
            SELECT client, COUNT(*) AS token_count
            FROM oauth2_access_token
            WHERE user_identifier = :userIdentifier
              AND revoked = false
              AND expiry > :now
            GROUP BY client
        SQL;

        $rows = $this->entityManager->getConnection()
            ->executeQuery($sql, [
                'userIdentifier' => $user->id->toString(),
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ])
            ->fetchAllAssociative();

        /** @var array<string, int> $counts */
        $counts = [];

        foreach ($rows as $row) {
            $client = $row['client'] ?? null;
            $count = $row['token_count'] ?? null;

            if (is_string($client) === false) {
                continue;
            }

            $counts[$client] = is_numeric($count) ? (int) $count : 0;
        }

        return $counts;
    }

    /**
     * @param list<\League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri> $redirectUris
     *
     * @return list<string>
     */
    private function redirectHosts(array $redirectUris): array
    {
        /** @var list<string> $hosts */
        $hosts = [];

        foreach ($redirectUris as $redirectUri) {
            $host = parse_url((string) $redirectUri, PHP_URL_HOST);

            if (is_string($host) === false || $host === '' || in_array($host, $hosts, true)) {
                continue;
            }

            $hosts[] = $host;
        }

        return $hosts;
    }
}
