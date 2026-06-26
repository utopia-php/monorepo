<?php

declare(strict_types=1);

namespace Utopia\NATS\Auth;

use Utopia\NATS\Exception\AuthenticationException;

final class NKeyAuth implements Authenticator
{
    public function __construct(
        private readonly string $publicKey,
        private readonly string $seed,
    ) {
        if (!\function_exists('sodium_crypto_sign_detached')) {
            throw new AuthenticationException('NKey authentication requires the sodium PHP extension');
        }
    }

    public function authenticate(?string $nonce = null): array
    {
        if ($nonce === null) {
            throw new AuthenticationException('NKey authentication requires a server nonce');
        }

        $rawSeed = $this->decodeSeed($this->seed);
        $keyPair = sodium_crypto_sign_seed_keypair($rawSeed);
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $signature = sodium_crypto_sign_detached($nonce, $secretKey);

        return [
            'nkey' => $this->publicKey,
            'sig' => $this->base32Encode($signature),
        ];
    }

    private function decodeSeed(string $seed): string
    {
        $decoded = $this->base32Decode($seed);
        if (\strlen($decoded) < 4) {
            throw new AuthenticationException('Invalid NKey seed');
        }

        // Remove the 2-byte prefix and 2-byte CRC suffix
        return substr($decoded, 2, -2);
    }

    private function base32Decode(string $input): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $input = strtoupper(rtrim($input, '='));
        $output = '';
        $buffer = 0;
        $bitsLeft = 0;

        for ($i = 0, $len = \strlen($input); $i < $len; $i++) {
            $val = strpos($alphabet, $input[$i]);
            if ($val === false) {
                throw new AuthenticationException('Invalid base32 character in NKey seed');
            }
            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= \chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $output;
    }

    private function base32Encode(string $input): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $output = '';
        $buffer = 0;
        $bitsLeft = 0;

        for ($i = 0, $len = \strlen($input); $i < $len; $i++) {
            $buffer = ($buffer << 8) | \ord($input[$i]);
            $bitsLeft += 8;

            while ($bitsLeft >= 5) {
                $bitsLeft -= 5;
                $output .= $alphabet[($buffer >> $bitsLeft) & 0x1F];
            }
        }

        if ($bitsLeft > 0) {
            $output .= $alphabet[($buffer << (5 - $bitsLeft)) & 0x1F];
        }

        return $output;
    }
}
