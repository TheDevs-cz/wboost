<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Meta;

use SensitiveParameter;

/**
 * Meta's `signed_request` (deauthorize + data-deletion callbacks of both the
 * Facebook and the Instagram product): `base64url(HMAC-SHA256 signature)` +
 * `.` + `base64url(JSON payload)`, signed with the APP SECRET of the product
 * that sends it. Returns the payload's `user_id`, or null when the request
 * is malformed or the signature does not match.
 */
final class MetaSignedRequest
{
    public static function userId(string $signedRequest, #[SensitiveParameter] string $appSecret): null|string
    {
        if ($appSecret === '') {
            return null;
        }

        $parts = explode('.', $signedRequest, 2);

        if (count($parts) !== 2) {
            return null;
        }

        [$encodedSignature, $encodedPayload] = $parts;

        $signature = self::base64UrlDecode($encodedSignature);
        $payload = self::base64UrlDecode($encodedPayload);

        if ($signature === null || $payload === null) {
            return null;
        }

        if (!hash_equals(hash_hmac('sha256', $encodedPayload, $appSecret, true), $signature)) {
            return null;
        }

        $data = json_decode($payload, true, flags: JSON_BIGINT_AS_STRING);

        if (!is_array($data)) {
            return null;
        }

        $userId = $data['user_id'] ?? null;

        if (is_int($userId)) {
            return (string) $userId;
        }

        return is_string($userId) && $userId !== '' ? $userId : null;
    }

    /** For tests and fixtures: the inverse of {@see userId()}. */
    public static function sign(string $userId, #[SensitiveParameter] string $appSecret): string
    {
        $payload = self::base64UrlEncode((string) json_encode(['algorithm' => 'HMAC-SHA256', 'user_id' => $userId, 'issued_at' => time()]));

        return self::base64UrlEncode(hash_hmac('sha256', $payload, $appSecret, true)) . '.' . $payload;
    }

    private static function base64UrlDecode(string $input): null|string
    {
        $decoded = base64_decode(strtr($input, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    private static function base64UrlEncode(string $input): string
    {
        return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
    }
}
