<?php

declare(strict_types=1);

namespace WBoost\Web\Services\EmailSignature;

use Symfony\Component\HttpFoundation\Request;
use WBoost\Web\Exceptions\InvalidDemoEmailAddresses;

/**
 * Parses the `emails[]` fields posted by the demo-send modal into a clean
 * recipient list. Shared by the template and variant demo controllers.
 */
readonly final class DemoEmailAddressesParser
{
    public const int MAX_RECIPIENTS = 5;

    /**
     * @return non-empty-list<string>
     *
     * @throws InvalidDemoEmailAddresses
     */
    public function parse(Request $request): array
    {
        $emails = [];

        foreach ($request->request->all('emails') as $value) {
            if (!is_string($value)) {
                continue;
            }

            $email = trim($value);

            if ($email === '') {
                continue;
            }

            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidDemoEmailAddresses(sprintf('Adresa „%s“ není platná e-mailová adresa.', $email));
            }

            $emails[] = $email;
        }

        $emails = array_values(array_unique($emails));

        if ($emails === []) {
            throw new InvalidDemoEmailAddresses('Zadejte alespoň jednu e-mailovou adresu.');
        }

        if (count($emails) > self::MAX_RECIPIENTS) {
            throw new InvalidDemoEmailAddresses(sprintf('Zkušební e-mail lze odeslat nejvýše na %d adres najednou.', self::MAX_RECIPIENTS));
        }

        return $emails;
    }
}
