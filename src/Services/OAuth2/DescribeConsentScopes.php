<?php

declare(strict_types=1);

namespace WBoost\Web\Services\OAuth2;

use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Value\ConsentScope;

/**
 * Turns the raw scope strings of an authorization request into the Czech lines
 * the consent screen shows — and into the EFFECTIVE scope list that is stored
 * as the user's approval.
 *
 * ## Why implications are EXPANDED rather than listed literally
 *
 * `templates:design` and `templates:export` each grant `templates:read`
 * ({@see McpScope::grants()}), and that expansion is applied at CHECK time by
 * {@see \WBoost\Web\Mcp\Security\McpScopeChecker} — so a token issued for
 * `templates:design` really can read everything. A consent screen that printed
 * only the two scopes the client named would therefore under-state the grant,
 * which is the one thing a consent screen must never do.
 *
 * So the effective set is what is shown, with the implied lines marked as such
 * ("je součástí oprávnění …") rather than silently mixed in: the user sees both
 * the truth (what the app will be able to do) and the reason (why an
 * unrequested line is there).
 *
 * The same expansion is what gets STORED, so
 * {@see \WBoost\Web\Entity\OAuthClientApproval::covers()} compares like with
 * like. It is safe to compare a literal request against a stored expansion,
 * because the expansion is closed: if every requested scope is in the stored
 * set, so is everything those scopes imply.
 *
 * ## The failure mode is loud on purpose
 *
 * {@see describeOne()} is an exhaustive `match` over {@see McpScope} with no
 * `default`, so adding a case fails PHPStan HERE until someone writes its Czech
 * wording — a new scope cannot reach a user as a raw slug. Scopes that are not
 * `McpScope` values at all (the legacy blanket `api` of the client_credentials
 * REST API, or anything a future release removes) cannot be caught statically,
 * so they are rendered as an explicit, warning-styled "unknown permission" row
 * that tells the user we cannot describe it — never as a bare string that looks
 * like it was understood.
 */
readonly final class DescribeConsentScopes
{
    /**
     * The consent screen's lines, in reading order: what was asked for, then
     * what that implies, then anything we cannot describe.
     *
     * @param list<string> $requested raw scope strings, exactly as the client asked
     *
     * @return list<ConsentScope>
     */
    public function describe(array $requested): array
    {
        /** @var list<ConsentScope> $described */
        $described = [];

        foreach (McpScope::fromStrings($requested) as $scope) {
            $described[] = $this->describeOne($scope);
        }

        foreach (McpScope::fromStrings($requested) as $scope) {
            foreach ($scope->grants() as $granted) {
                if ($granted === $scope || $this->contains($described, $granted->value)) {
                    continue;
                }

                $line = $this->describeOne($granted);

                $described[] = new ConsentScope(
                    $line->value,
                    $line->title,
                    $line->description,
                    true,
                    $this->describeOne($scope)->title,
                );
            }
        }

        foreach ($this->unknown($requested) as $value) {
            $described[] = new ConsentScope(
                $value,
                sprintf('Neznámé oprávnění „%s“', $value),
                'Tato verze WBoostu toto oprávnění nezná a neumí popsat, co aplikaci umožní. '
                . 'Pokud si nejste jistí, přístup nepovolujte.',
                false,
            );
        }

        return $described;
    }

    /**
     * What an approval of `$requested` actually covers — the requested scopes
     * plus everything they imply, de-duplicated, order preserved.
     *
     * Unknown scopes are kept VERBATIM rather than dropped. Dropping them would
     * mean the stored approval could never cover them, so a client asking for
     * one would re-prompt on every single authorization; keeping them records
     * exactly what the user was shown and agreed to (including the loud "we
     * cannot describe this" row).
     *
     * @param list<string> $requested
     *
     * @return list<string>
     */
    public function effectiveValues(array $requested): array
    {
        /** @var list<string> $values */
        $values = [];

        foreach ($this->describe($requested) as $scope) {
            if (in_array($scope->value, $values, true) === false) {
                $values[] = $scope->value;
            }
        }

        return $values;
    }

    /**
     * Re-describes a STORED approval for the management page. Same wording as
     * the consent screen, minus the "implied by" annotation — a stored approval
     * is already the effective set, so every line of it is simply granted.
     *
     * @param list<string> $granted
     *
     * @return list<ConsentScope>
     */
    public function describeGranted(array $granted): array
    {
        return array_map(
            static fn (ConsentScope $scope): ConsentScope => new ConsentScope(
                $scope->value,
                $scope->title,
                $scope->description,
                $scope->known,
            ),
            $this->describe($granted),
        );
    }

    private function describeOne(McpScope $scope): ConsentScope
    {
        // Exhaustive on purpose — see the class docblock. No `default`.
        [$title, $description] = match ($scope) {
            McpScope::TemplatesRead => [
                'Prohlížet vaše projekty a šablony',
                'Aplikace uvidí vaše projekty, šablony, jejich texty a obrázky v galerii '
                . 'včetně náhledů. Nic nemění.',
            ],
            McpScope::TemplatesExport => [
                'Stahovat hotové exporty',
                'Aplikace si z vašich šablon vygeneruje finální obrázky v plné kvalitě '
                . 'a stáhne si je k sobě.',
            ],
            McpScope::TemplatesDesign => [
                'Vytvářet a přepisovat šablony',
                'Aplikace může zakládat nové šablony a měnit obsah i vzhled těch stávajících. '
                . 'Změny uvidí každý, kdo má k projektu přístup.',
            ],
            McpScope::GalleryWrite => [
                'Nahrávat obrázky do galerie',
                'Aplikace může do galerie vašich projektů přidávat nové obrázky.',
            ],
        };

        return new ConsentScope($scope->value, $title, $description, true);
    }

    /**
     * The requested strings that are not {@see McpScope} values, de-duplicated,
     * order preserved.
     *
     * @param list<string> $requested
     *
     * @return list<string>
     */
    private function unknown(array $requested): array
    {
        /** @var list<string> $unknown */
        $unknown = [];

        foreach ($requested as $value) {
            if (McpScope::tryFrom($value) !== null || in_array($value, $unknown, true)) {
                continue;
            }

            $unknown[] = $value;
        }

        return $unknown;
    }

    /**
     * @param list<ConsentScope> $described
     */
    private function contains(array $described, string $value): bool
    {
        foreach ($described as $scope) {
            if ($scope->value === $value) {
                return true;
            }
        }

        return false;
    }
}
