<?php

declare(strict_types=1);

namespace Utopia\Auth\OAuth2;

/**
 * Proof Key for Code Exchange (RFC 7636) for OAuth2 providers.
 *
 * Adapters are reconstructed statelessly on the callback request, so the
 * verifier generated while building the login URL is gone by token exchange.
 * Providers stash it in `state` with withPKCEState() and restore it with
 * restorePKCEState(). The verifier is encrypted because state is echoed
 * through the provider and is visible in the redirect URL.
 */
trait PKCE
{
    protected const PKCE_STATE_KEY = '_pkce';

    private string $pkceVerifier = '';

    /**
     * RFC 7636 §4.1 requires 43-128 characters from the unreserved set.
     * base64url(random_bytes(64)) is 86 characters.
     */
    protected function getPKCEVerifier(): string
    {
        if ($this->pkceVerifier === '') {
            $this->pkceVerifier = $this->base64UrlEncode(random_bytes(64));
        }

        return $this->pkceVerifier;
    }

    /**
     * RFC 7636 §4.2 defines the S256 challenge as BASE64URL(SHA256(ASCII(verifier))).
     */
    protected function getPKCEChallenge(): string
    {
        return $this->base64UrlEncode(hash('sha256', $this->getPKCEVerifier(), true));
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    protected function withPKCEState(array $state): array
    {
        $state[self::PKCE_STATE_KEY] = $this->encryptPKCEVerifier($this->getPKCEVerifier());

        return $state;
    }

    /**
     * @param array<string, mixed> $parsed
     * @return array<string, mixed>
     */
    protected function restorePKCEState(array $parsed): array
    {
        $pkce = $parsed[self::PKCE_STATE_KEY] ?? null;

        if (\is_array($pkce)) {
            $this->pkceVerifier = $this->decryptPKCEVerifier($pkce);
        } elseif (\is_string($pkce)) {
            $this->pkceVerifier = $pkce;
        }

        unset($parsed[self::PKCE_STATE_KEY]);

        return $parsed;
    }

    /**
     * @return array<string, string>
     */
    private function encryptPKCEVerifier(string $verifier): array
    {
        $ivLength = openssl_cipher_iv_length('aes-128-gcm');
        if ($ivLength === false) {
            throw new \Exception('Failed to encrypt PKCE verifier.');
        }

        $iv = openssl_random_pseudo_bytes($ivLength);
        $tag = null;

        $data = openssl_encrypt($verifier, 'aes-128-gcm', $this->getPKCEStateKey(), OPENSSL_RAW_DATA, $iv, $tag);

        if ($data === false || $tag === null) {
            throw new \Exception('Failed to encrypt PKCE verifier.');
        }

        return [
            'data' => $this->base64UrlEncode($data),
            'iv' => bin2hex($iv),
            'tag' => bin2hex($tag),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function decryptPKCEVerifier(array $payload): string
    {
        $data = $payload['data'] ?? '';
        $iv = $payload['iv'] ?? '';
        $tag = $payload['tag'] ?? '';

        if (!\is_string($data) || !\is_string($iv) || !\is_string($tag)) {
            return '';
        }

        if ($data === '' || $iv === '' || $tag === '') {
            return '';
        }

        if (!$this->isHex($iv) || !$this->isHex($tag)) {
            return '';
        }

        $decodedData = $this->base64UrlDecode($data);
        $decodedIv = hex2bin($iv);
        $decodedTag = hex2bin($tag);

        if ($decodedData === false || $decodedIv === false || $decodedTag === false) {
            return '';
        }

        return openssl_decrypt(
            $decodedData,
            'aes-128-gcm',
            $this->getPKCEStateKey(),
            OPENSSL_RAW_DATA,
            $decodedIv,
            $decodedTag,
        ) ?: '';
    }

    private function isHex(string $value): bool
    {
        return \strlen($value) % 2 === 0 && ctype_xdigit($value);
    }

    private function getPKCEStateKey(): string
    {
        if ($this->stateEncryptionKey === '') {
            throw new \Exception($this->getName() . ' OAuth2 requires a state encryption key to encrypt PKCE state.');
        }

        return $this->stateEncryptionKey;
    }

    protected function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    protected function base64UrlDecode(string $value): string|false
    {
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
