<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * One line of the consent screen: a scope the user is about to grant, in words
 * a brand manager can act on.
 *
 * A pure view value — built by
 * {@see \WBoost\Web\Services\OAuth2\DescribeConsentScopes}, rendered by
 * `oauth_consent.html.twig` and `connected_apps.html.twig`, never persisted
 * (what is persisted is the raw {@see $value}, so re-describing an old approval
 * always uses TODAY's wording).
 */
readonly final class ConsentScope
{
    /**
     * @param string      $value       the raw wire scope (`templates:read`)
     * @param string      $title       short Czech label — what the app will be able to do
     * @param string      $description one sentence of CONSEQUENCE, not a restatement of the title
     * @param bool        $known       false = this release cannot describe the scope; the UI must
     *                                 say so loudly rather than print a slug and look reassuring
     * @param null|string $impliedBy   Czech title of the scope that pulled this one in, null when
     *                                 the client asked for it literally
     */
    public function __construct(
        public string $value,
        public string $title,
        public string $description,
        public bool $known,
        public null|string $impliedBy = null,
    ) {
    }
}
