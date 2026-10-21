<?php

namespace Webkul\Ondc\Helpers;
use Illuminate\Support\Facades\Log;

class OndcSigner
{
    public function signRequest(string $payload, string $subscriberId, string $privateKeyBase64)
    {
        $created = time();
        $expires = $created + 600; // 10 minutes

        // Decode private key
        $decodedKey = base64_decode($privateKeyBase64);
        $actualLength = strlen($decodedKey);

        Log::info('KEY_VALIDATION', [
            'actual_length' => $actualLength,
        ]);

        // Handle 32-byte seed (derive full keypair) or 64-byte full secret key
        if ($actualLength === 32) {
            // 32-byte seed — derive full Ed25519 keypair
            $keyPair = sodium_crypto_sign_seed_keypair($decodedKey);
            $privateKey = sodium_crypto_sign_secretkey($keyPair);
        } elseif ($actualLength === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            // 64-byte full secret key — use directly
            $privateKey = $decodedKey;
        } else {
            Log::error('INVALID_PRIVATE_KEY_LENGTH', [
                'expected' => '32 or 64',
                'actual' => $actualLength,
            ]);

            throw new \Exception(
                "Invalid private key length: expected 32 or 64 bytes, got {$actualLength}."
            );
        }

        // ✅ Create digest (SHA-256, base64 encoded)
       // ✅ Digest per ONDC spec: BLAKE-512 (libsodium crypto_generichash, 64-byte output)
$digest = base64_encode(sodium_crypto_generichash($payload, '', SODIUM_CRYPTO_GENERICHASH_BYTES_MAX));

Log::info('ONDC_SIGN_INPUT_DEBUG', [
    'body_sha256' => hash('sha256', $payload),
    'body_length' => strlen($payload),
    'created'     => $created,
    'expires'     => $expires,
]);

// ✅ Signing string format per ONDC spec — note: no parens around "digest",
// and the algorithm name "BLAKE-512=" is part of the value, not the label.
$signingString = "(created):{$created}\n(expires):{$expires}\ndigest:BLAKE-512={$digest}";
if (strpos($signingString, 'digest:') === false) {
    throw new \Exception('Signing string format error: missing digest');
}
       

        Log::info('SIGNING_STRING', [
            'string' => $signingString,
        ]);

        // Sign with Ed25519
        $signature = sodium_crypto_sign_detached($signingString, $privateKey);
        $signatureBase64 = base64_encode($signature);

        // Extract public key from private key (last 32 bytes)
        $publicKey = substr($privateKey, 32, 32);

        Log::info('DERIVED_PUBLIC_KEY', [
        'public_key_base64' => base64_encode($publicKey),
    ]);

        // ⚠️ keyId must be the SHORT unique_key_id you were assigned when you
        // registered this keypair with the ONDC registry (e.g. "key1") —
        // NOT the base64-encoded public key itself. The gateway parses
        // keyId as "subscriber_id|unique_key_id|algorithm" and uses
        // unique_key_id to look up your signing_public_key in the registry.
        // Sending the raw public key here breaks that lookup and causes
        // "Signature Validation Error: failed to get validation key" NACKs.
        $keyId = config('services.ondc.unique_key_id', 'key1');

        $authorizationHeader ='Signature keyId="' . $subscriberId . '|' . $keyId . '|ed25519",' .'algorithm="ed25519",' .'created="' . $created . '",' .'expires="' . $expires . '",' .'headers="(created) (expires) digest",' .'signature="' . $signatureBase64 . '"';
        
        Log::info('SIGNATURE_GENERATED', [
            'subscriber_id' => $subscriberId,
            'key_id' => $keyId,
            'created' => $created,
            'expires' => $expires,
            'auth_header' => $authorizationHeader,
        ]);

        return [
            'Authorization' => $authorizationHeader,
            'Digest' => 'BLAKE-512=' . $digest,
        ];
    }
    // public function signRequest($body, $subscriberId)
    // {
    //     $created = time();
    //     $expires = $created + 300;

    //     $digest = base64_encode(hash('sha256', $body, true));

    //     $signingString = "(created): $created\n(expires): $expires\ndigest: SHA-256=$digest";

    //     // ✅ Correct key (64 bytes)
    //     $secretKey = base64_decode(env('ONDC_PRIVATE_KEY'));

    //     if (strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    //         throw new \Exception("Invalid ONDC_PRIVATE_KEY length");
    //     }

    //     $signature = sodium_crypto_sign_detached($signingString, $secretKey);

    //     return [
    //         'Authorization' => 'Signature keyId="' . $subscriberId . '|key1|ed25519",algorithm="ed25519",created="' . $created . '",expires="' . $expires . '",headers="(created) (expires) digest",signature="' . base64_encode($signature) . '"',
    //         'Digest' => 'SHA-256=' . $digest
    //     ];
    // }
}