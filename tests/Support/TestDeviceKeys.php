<?php

namespace Tests\Support;

/**
 * Fixed P-256 key pairs used ONLY by the test suite.
 *
 * Generating keys inside tests needs PHP to find an openssl.cnf, which often fails on Windows.
 * Loading, signing and verifying with existing keys needs no configuration file, so tests stay portable.
 * These keys protect nothing: never use them anywhere else.
 */
final class TestDeviceKeys
{
    private const PRIVATE_KEYS = [
        <<<'PEM'
-----BEGIN EC PRIVATE KEY-----
MHcCAQEEINeVJ6sjJgKr2sP0igUWD/Ni7AXqCB3WbH2XrJB5qXkLoAoGCCqGSM49
AwEHoUQDQgAETGpZxIxXtsy8iJlelrZC+qtSrh5krAvoaWKnKMcLWdoIrexKO3mz
4MTLs+I2TbY08Oshcq/TAq9crD0Vu57enQ==
-----END EC PRIVATE KEY-----
PEM,
        <<<'PEM'
-----BEGIN EC PRIVATE KEY-----
MHcCAQEEIG4OjNiXCDwrgAJBkqv8vsYzPbB+oRDcaMteJ0V7FZ7GoAoGCCqGSM49
AwEHoUQDQgAEjQRXgFZtTowZEQOjw8QKHT4sjX3Q9so2ASaeHJ3GH12tAzTFl1Zx
ErINe5C+XZBEbRjJzr8JDNbg/CkUyqmycQ==
-----END EC PRIVATE KEY-----
PEM,
        <<<'PEM'
-----BEGIN EC PRIVATE KEY-----
MHcCAQEEIH8qW4HDjlMAKs/ktQVl562er4x3yNox4o4yY3zidYyOoAoGCCqGSM49
AwEHoUQDQgAE+5UsaSyZLGmifQ1gVUK8XYJslJIf8Pfe0j7wZQP25b5ffA+HgQAu
MBxUItfQHOH07yJGikmN4A+rnLmB2Ez5RQ==
-----END EC PRIVATE KEY-----
PEM,
        <<<'PEM'
-----BEGIN EC PRIVATE KEY-----
MHcCAQEEIA/Mqe6y0dQeIwYtMwaS/OZk3IDCSQuFXRl1QGGPUZqDoAoGCCqGSM49
AwEHoUQDQgAE1VSAXpxZ9g0bNqiQVAozl+5W5iU2ipJNYFkxQ++wB81OLhHbLSVP
FopmTMvZrsdrP0cHbgWIJlmSfOyaEb2irg==
-----END EC PRIVATE KEY-----
PEM,
    ];

    public static function count(): int
    {
        return count(self::PRIVATE_KEYS);
    }

    /** @return array{key: \OpenSSLAsymmetricKey, public_pem: string} */
    public static function get(int $index): array
    {
        $key = openssl_pkey_get_private(self::PRIVATE_KEYS[$index]);

        return ['key' => $key, 'public_pem' => openssl_pkey_get_details($key)['key']];
    }
}
