<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * A licence is a small signed document: `DIO1.<payload>.<signature>` with both parts base64url. The payload is JSON
 * (installation, company, plan, period, limits, serial); the signature is Ed25519 over exactly those payload bytes, made by
 * the vendor's private key and checked here with the public key built into the product, so no internet call is needed
 * and a changed character anywhere makes the licence invalid.
 */
final class LicenceToken
{
    public const PREFIX = 'DIO1';

    /**
     * Sign a payload. Used by the vendor's tools only; needs the secret key.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function sign(array $payload, string $secretKeyBase64): string
    {
        $secret = base64_decode($secretKeyBase64, true);
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw LicenceException::make('bad_key', 'The signing key is not valid.');
        }
        $json = self::encodePayload($payload);

        return self::PREFIX.'.'.self::b64($json).'.'.self::b64(sodium_crypto_sign_detached($json, $secret));
    }

    /**
     * Check the signature and the shape of a licence and return its payload.
     *
     * @return array{v: int, serial: string, installation: string, company: string, plan: string, valid_from: string, valid_until: string, issued_at: string, max_users: int|null, modules: list<string>|null}
     *
     * @throws LicenceException licensing.invalid_format | licensing.bad_signature | licensing.no_public_key
     */
    public static function verify(string $token, ?string $publicKeyBase64): array
    {
        $parts = explode('.', trim(preg_replace('/\s+/', '', $token) ?? ''));
        if (count($parts) !== 3 || $parts[0] !== self::PREFIX) {
            throw LicenceException::make('invalid_format', 'This is not a licence. Paste the whole text you received, starting with '.self::PREFIX.'.');
        }
        $json = self::unb64($parts[1]);
        $signature = self::unb64($parts[2]);
        if ($json === null || $signature === null || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw LicenceException::make('invalid_format', 'This licence is damaged. Paste the whole text you received.');
        }

        $public = $publicKeyBase64 === null || $publicKeyBase64 === '' ? null : base64_decode($publicKeyBase64, true);
        if ($public === null || $public === false || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw LicenceException::make('no_public_key', 'This installation has no vendor key to check licences with. Contact the vendor.', 500);
        }
        if (! sodium_crypto_sign_verify_detached($signature, $json, $public)) {
            throw LicenceException::make('bad_signature', 'This licence was not issued by the vendor or has been changed.');
        }

        $payload = json_decode($json, true);
        if (! is_array($payload) || ($payload['v'] ?? null) !== 1) {
            throw LicenceException::make('invalid_format', 'This licence is of a version this product does not understand.');
        }
        foreach (['serial', 'installation', 'company', 'plan', 'valid_from', 'valid_until', 'issued_at'] as $field) {
            if (! isset($payload[$field]) || ! is_string($payload[$field]) || $payload[$field] === '') {
                throw LicenceException::make('invalid_format', "The licence is missing '{$field}'.");
            }
        }
        foreach (['valid_from', 'valid_until'] as $field) {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $payload[$field]);
            if ($date === null || $date->toDateString() !== $payload[$field]) {
                throw LicenceException::make('invalid_format', "The licence has an invalid '{$field}'.");
            }
        }
        if ($payload['valid_until'] < $payload['valid_from']) {
            throw LicenceException::make('invalid_format', 'The licence ends before it starts.');
        }
        $payload['max_users'] = isset($payload['max_users']) && is_int($payload['max_users']) && $payload['max_users'] > 0 ? $payload['max_users'] : null;
        $payload['modules'] = isset($payload['modules']) && is_array($payload['modules']) ? array_values(array_filter($payload['modules'], 'is_string')) : null;

        return $payload;
    }

    /**
     * A fresh signing key pair: [public, secret], both base64.
     *
     * @return array{0: string, 1: string}
     */
    public static function generateKeys(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [base64_encode(sodium_crypto_sign_publickey($pair)), base64_encode(sodium_crypto_sign_secretkey($pair))];
    }

    /** @param  array<string, mixed>  $payload */
    private static function encodePayload(array $payload): string
    {
        ksort($payload);

        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function unb64(string $text): ?string
    {
        $decoded = base64_decode(strtr($text, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
