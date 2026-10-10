<?php

namespace Tests\Feature\GitHub;

/**
 * A throwaway RSA key for the GitHub App, written to a temp file and set as
 * `services.github.private_key_path`. A minimal openssl config is passed so
 * key generation also works on Windows PHP builds without OPENSSL_CONF.
 */
trait GeneratesAppKey
{
    protected string $keyPath;

    protected string $publicKey;

    protected function generateAppKey(): void
    {
        $config = tempnam(sys_get_temp_dir(), 'gh-cnf-');
        file_put_contents($config, "[req]\ndistinguished_name = req_dn\n[req_dn]\n");
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $config];

        $key = openssl_pkey_new($options);
        openssl_pkey_export($key, $pem, null, $options);
        @unlink($config);

        $this->publicKey = openssl_pkey_get_details($key)['key'];
        $this->keyPath = tempnam(sys_get_temp_dir(), 'gh-key-');
        file_put_contents($this->keyPath, $pem);

        config(['services.github.private_key_path' => $this->keyPath]);
    }

    protected function tearDown(): void
    {
        if (isset($this->keyPath)) {
            @unlink($this->keyPath);
        }

        parent::tearDown();
    }
}
