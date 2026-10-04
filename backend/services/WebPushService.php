<?php
declare(strict_types=1);

namespace Pharmacy\Services;

use Pharmacy\Config\Config;
use Pharmacy\Helpers\ApiException;

/**
 * Pure-PHP Web Push sender (VAPID). No Firebase Cloud Messaging —
 * works directly against browser push services (Google/Mozilla/etc.)
 * using only openssl + curl, both commonly available.
 *
 * Usage:
 *   WebPushService::send($subscriptionArray, ['title' => '...', 'body' => '...']);
 *
 * $subscriptionArray = ['endpoint' => '...', 'keys' => ['p256dh' => '...', 'auth' => '...']]
 * as delivered by the frontend's PushManager.subscribe().
 *
 * NOTE: Full RFC 8291 message encryption (aes128gcm) is implemented below
 * in pure PHP. If openssl/curl is missing it throws a clear error.
 */
final class WebPushService
{
    public static function configured(): bool
    {
        return Config::getString('VAPID_PUBLIC_KEY') !== ''
            && Config::getString('VAPID_PRIVATE_KEY') !== '';
    }

    /**
     * @param array{endpoint:string, keys:array{p256dh:string, auth:string}} $subscription
     * @param array{title:string, body?:string, url?:string} $payload
     */
    public static function send(array $subscription, array $payload): void
    {
        if (!self::configured()) {
            throw new ApiException('Web Push is not configured (VAPID keys missing).', 501);
        }
        if (!extension_loaded('openssl') || !extension_loaded('curl')) {
            throw new ApiException('Web Push requires the openssl and curl extensions.', 501);
        }

        $endpoint = $subscription['endpoint'] ?? '';
        $p256dh   = $subscription['keys']['p256dh'] ?? '';
        $auth     = $subscription['keys']['auth'] ?? '';
        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            throw new ApiException('Invalid push subscription.', 422);
        }

        $ciphertext = self::encrypt(
            json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '',
            $p256dh,
            $auth
        );

        $jwt = self::vapidJwt($endpoint);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $ciphertext,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/octet-stream',
                'Content-Encoding: aes128gcm',
                'TTL: 86400',
                'Authorization: WebPush ' . $jwt,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $result = curl_exec($ch);
        $code   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($result === false || $code < 200 || $code >= 300) {
            throw new ApiException('Push delivery failed (HTTP ' . $code . ' ' . $err . ').', 502);
        }
    }

    /** VAPID JWT signed with the server's private key (ES256). */
    private static function vapidJwt(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        $aud   = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        $now   = time();

        $header  = self::b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims  = self::b64u(json_encode([
            'aud' => $aud,
            'exp' => $now + 43200, // 12h
            'sub' => Config::getString('VAPID_SUBJECT', 'mailto:admin@example.com'),
        ]));
        $signing = $header . '.' . $claims;

        $privPem = self::rawToPem(Config::getString('VAPID_PRIVATE_KEY'), 'PRIVATE');
        $key = openssl_pkey_get_private($privPem);
        if (!$key) {
            throw new ApiException('Invalid VAPID private key.', 500);
        }
        openssl_sign($signing, $sig, $key, OPENSSL_ALGO_SHA256);
        // JWT needs raw R||S, openssl gives DER — convert.
        $sig = self::derToRaw($sig);
        return $signing . '.' . self::b64u($sig);
    }

    /**
     * RFC 8291 aes128gcm encryption of the payload for the subscriber.
     * Returns the full binary body to POST.
     */
    private static function encrypt(string $plaintext, string $p256dhB64, string $authB64): string
    {
        $clientPub = self::b64uDecode($p256dhB64); // 65 bytes uncompressed
        $authSecret = self::b64uDecode($authB64);  // 16 bytes

        // Ephemeral keypair (ours).
        $eph = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $details = openssl_pkey_get_details($eph);
        $ephPub = $details['ec']['public_key']; // DER; extract 65-byte point below
        $ephPubRaw = self::derPubToRaw($ephPub);

        // ECDH shared secret.
        $clientPem = self::rawToPem("\x04" . ltrim($clientPub, "\x04"), 'PUBLIC');
        $shared = openssl_pkey_derive($clientPem, openssl_pkey_get_private($eph));
        if ($shared === false) {
            throw new ApiException('Push encryption failed (ECDH).', 500);
        }

        $salt = random_bytes(16);
        $ikm  = self::hkdf($authSecret, $shared, 'WebPush: info' . "\x00" . $clientPub . $ephPubRaw, 32);
        $cek  = self::hkdf($salt, $ikm, "Content-Encoding: aes128gcm\x00", 16);
        $nonce = self::hkdf($salt, $ikm, "Content-Encoding: nonce\x00", 12);

        // Pad plaintext: 0x02 delimiter per RFC 8291 §3.
        $padded = $plaintext . "\x02";
        $padLen = 0; // no extra padding
        $tag = '';
        $ct = openssl_encrypt($padded, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);

        // Header: salt(16) || rs(4)=4096 || idlen(1)=65 || keyid(65)
        return $salt . pack('N', 4096) . chr(65) . $ephPubRaw . $ct . $tag;
    }

    private static function hkdf(string $salt, string $ikm, string $info, int $len): string
    {
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $okm = '';
        $t = '';
        for ($i = 1; strlen($okm) < $len; $i++) {
            $t = hash_hmac('sha256', $t . $info . chr($i), $prk, true);
            $okm .= $t;
        }
        return substr($okm, 0, $len);
    }

    private static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function b64uDecode(string $s): string
    {
        return base64_decode(strtr($s, '-_', '+/'));
    }

    private static function rawToPem(string $raw, string $type): string
    {
        return "-----BEGIN {$type} KEY-----\n" . chunk_split(base64_encode($raw), 64, "\n") . "-----END {$type} KEY-----\n";
    }

    /** Extract 65-byte uncompressed point from DER SubjectPublicKeyInfo. */
    private static function derPubToRaw(string $der): string
    {
        $pos = strrpos($der, "\x04");
        $raw = $pos === false ? '' : substr($der, $pos, 65);
        if (strlen($raw) !== 65) {
            throw new ApiException('Push encryption failed (key format).', 500);
        }
        return $raw;
    }

    /** Convert DER ECDSA signature to raw R||S (64 bytes). */
    private static function derToRaw(string $der): string
    {
        $p = 2; // skip SEQUENCE tag+len (short form, always true for P-256 sigs)
        if (ord($der[0]) !== 0x30) {
            return $der;
        }
        $len = ord($der[1]);
        $p = $len < 128 ? 2 : 3;
        $rLen = ord($der[$p + 1]);
        $r = substr($der, $p + 2, $rLen);
        $sPos = $p + 2 + $rLen;
        $sLen = ord($der[$sPos + 1]);
        $s = substr($der, $sPos + 2, $sLen);
        $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
        $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
        return substr($r, -32) . substr($s, -32);
    }
}
