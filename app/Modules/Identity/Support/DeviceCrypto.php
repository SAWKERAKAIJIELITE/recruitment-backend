<?php

namespace App\Modules\Identity\Support;

use InvalidArgumentException;

final class DeviceCrypto
{
    /** Domain separator: a signature made for this protocol cannot be replayed in another one. */
    public const LOGIN_PAYLOAD_PREFIX = 'recruitment-staff-login-v1';

    /**
     * Validates a client-supplied public key and returns its canonical PEM and fingerprint.
     *
     * @return array{pem: string, fingerprint: string}
     *
     * @throws InvalidArgumentException when it is not a P-256 EC public key
     */
    public static function parsePublicKey(string $pem): array
    {
        $key = @openssl_pkey_get_public($pem);

        if ($key === false) {
            throw new InvalidArgumentException('Not a valid public key.');
        }

        $details = openssl_pkey_get_details($key);

        if (($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC || ($details['ec']['curve_name'] ?? null) !== 'prime256v1') {
            throw new InvalidArgumentException('Only P-256 (prime256v1) EC keys are accepted.');
        }

        $canonical = $details['key'];
        $der = base64_decode(preg_replace('/-----(BEGIN|END) PUBLIC KEY-----|\s+/', '', $canonical), true);

        return ['pem' => $canonical, 'fingerprint' => hash('sha256', $der)];
    }

    public static function loginPayload(string $challengeId, string $challenge, string $deviceId, string $username): string
    {
        return implode("\n", [self::LOGIN_PAYLOAD_PREFIX, $challengeId, $challenge, $deviceId, $username]);
    }

    /** $signatureBase64 is a base64 DER-encoded ECDSA signature (what Node's crypto.sign produces). */
    public static function verify(string $publicKeyPem, string $payload, string $signatureBase64): bool
    {
        $signature = base64_decode($signatureBase64, true);

        if ($signature === false || $signature === '') {
            return false;
        }

        return openssl_verify($payload, $signature, $publicKeyPem, OPENSSL_ALGO_SHA256) === 1;
    }
}
