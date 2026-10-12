<?php

namespace App\Services\System;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Web push without a library: VAPID keys (RFC 8292, ES256 over P-256) and the aes128gcm message encryption of RFC 8291.
 *
 * `generateKeys()` makes the application's key pair once; the private key is stored encrypted in `system_settings`, the public one is
 * what browsers subscribe with. `encrypt()` and `vapidAuthorization()` are the two protocol pieces and are pure, so the tests decrypt
 * and verify what they produce. `send()` posts to the subscription's push service; a 404 / 410 answer means the subscription is gone
 * and it is deleted. Whether a given browser vendor's push service accepts the message can only be seen against a live browser.
 */
class WebPush
{
    public const SUBJECT_SETTING = 'vapid_subject';

    /**
     * @return array{public: string, private_pem: string}
     */
    public static function generateKeys(): array
    {
        $key = self::newKey();
        $pem = '';

        if (! openssl_pkey_export($key, $pem, null, self::config() === null ? [] : ['config' => self::config()])) {
            throw new RuntimeException('OpenSSL could not export the key.');
        }

        $details = openssl_pkey_get_details($key);

        return ['public' => self::b64(self::point($details)), 'private_pem' => (string) $pem];
    }

    public function keys(): ?array
    {
        $public = DB::table('system_settings')->where('key', 'vapid_public')->value('value');
        $private = DB::table('system_settings')->where('key', 'vapid_private')->value('value');

        if ($public === null || $private === null) {
            return null;
        }

        return ['public' => (string) $public, 'private_pem' => Crypt::decryptString((string) $private)];
    }

    /**
     * Makes the key pair when there is none and returns the public key.
     */
    public function ensureKeys(): string
    {
        $existing = $this->keys();

        if ($existing !== null) {
            return $existing['public'];
        }

        $keys = self::generateKeys();
        DB::table('system_settings')->updateOrInsert(['key' => 'vapid_public'], ['value' => $keys['public'], 'updated_at' => now(), 'created_at' => now()]);
        DB::table('system_settings')->updateOrInsert(['key' => 'vapid_private'], ['value' => Crypt::encryptString($keys['private_pem']), 'updated_at' => now(), 'created_at' => now()]);

        return $keys['public'];
    }

    /**
     * The `Authorization` header value for an endpoint: `vapid t=<jwt>, k=<public key>`.
     */
    public static function vapidAuthorization(string $endpoint, string $subject, string $publicKey, string $privatePem, ?int $now = null): string
    {
        $parts = parse_url($endpoint);
        $audience = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
        $header = self::b64((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64((string) json_encode(['aud' => $audience, 'exp' => ($now ?? time()) + 12 * 3600, 'sub' => $subject]));
        $signed = $header.'.'.$claims;

        openssl_sign($signed, $der, openssl_pkey_get_private($privatePem), OPENSSL_ALGO_SHA256);

        return 'vapid t='.$signed.'.'.self::b64(self::derToRaw($der)).', k='.$publicKey;
    }

    /**
     * Encrypts a payload for a subscription (RFC 8291, aes128gcm, one record).
     *
     * @return array{body: string, server_public: string}
     */
    public static function encrypt(string $payload, string $userPublicB64, string $authSecretB64, ?string $fixedSalt = null, ?string $serverPrivatePem = null): array
    {
        $userPublic = self::unb64($userPublicB64);
        $authSecret = self::unb64($authSecretB64);

        if (strlen($userPublic) !== 65 || $userPublic[0] !== "\x04" || strlen($authSecret) !== 16) {
            throw new RuntimeException('The subscription keys are not valid.');
        }

        if (strlen($payload) > 3800) {
            throw new RuntimeException('The notification text is too long for one push message.');
        }

        $server = $serverPrivatePem !== null ? openssl_pkey_get_private($serverPrivatePem) : self::newKey();
        $serverPublic = self::point(openssl_pkey_get_details($server));
        $peer = openssl_pkey_get_public(self::publicPem($userPublic));
        $shared = openssl_pkey_derive($peer, $server, 32);

        if ($shared === false) {
            throw new RuntimeException('Could not derive the shared secret.');
        }

        $salt = $fixedSalt ?? random_bytes(16);
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0".$userPublic.$serverPublic, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $tag = '';
        $cipher = openssl_encrypt($payload."\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);

        return ['body' => $salt.pack('N', 4096).chr(65).$serverPublic.$cipher.$tag, 'server_public' => self::b64($serverPublic)];
    }

    /**
     * Sends one notification to one subscription. Returns the HTTP status (0 when it could not be sent) and removes a subscription
     * the push service says is gone.
     *
     * @param  array<string, mixed>  $notification
     * @return array{status: int, removed: bool, error: string|null}
     */
    public function send(object $subscription, array $notification): array
    {
        $keys = $this->keys();

        if ($keys === null) {
            return ['status' => 0, 'removed' => false, 'error' => 'No VAPID keys have been generated.'];
        }

        try {
            $subject = (string) (DB::table('system_settings')->where('key', self::SUBJECT_SETTING)->value('value') ?: 'mailto:'.config('mail.from.address', 'admin@example.com'));
            $message = self::encrypt((string) json_encode($notification), (string) $subscription->p256dh, (string) $subscription->auth);
            $response = Http::withHeaders([
                'TTL' => '3600', 'Content-Encoding' => 'aes128gcm', 'Content-Type' => 'application/octet-stream',
                'Authorization' => self::vapidAuthorization((string) $subscription->endpoint, $subject, $keys['public'], $keys['private_pem']),
            ])->timeout(15)->withBody($message['body'], 'application/octet-stream')->post((string) $subscription->endpoint);
        } catch (Throwable $e) {
            return ['status' => 0, 'removed' => false, 'error' => mb_substr($e->getMessage(), 0, 200)];
        }

        if (in_array($response->status(), [404, 410], true)) {
            DB::table('push_subscriptions')->where('id', $subscription->id)->delete();

            return ['status' => $response->status(), 'removed' => true, 'error' => null];
        }

        DB::table('push_subscriptions')->where('id', $subscription->id)->update(['last_used_at' => now()]);

        return ['status' => $response->status(), 'removed' => false, 'error' => $response->successful() ? null : 'The push service answered HTTP '.$response->status().'.'];
    }

    /**
     * A new P-256 key pair. Some Windows installs have no OpenSSL default configuration file, which makes key generation fail; in that
     * case a minimal one is written to the temp folder and used.
     *
     * @throws RuntimeException
     */
    public static function newKey(): \OpenSSLAsymmetricKey
    {
        $options = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
        $key = @openssl_pkey_new($options);

        if ($key === false && self::config() !== null) {
            $key = @openssl_pkey_new($options + ['config' => self::config()]);
        }

        if ($key === false) {
            throw new RuntimeException('OpenSSL could not make an EC key.');
        }

        return $key;
    }

    /**
     * A configuration file for key generation when OpenSSL has no default one (some Windows installs): OPENSSL_CONF, the file that
     * ships next to the PHP binary, or a minimal one written to the temp folder. Null when key generation works without it.
     */
    private static function config(): ?string
    {
        static $path = false;

        if ($path !== false) {
            return $path;
        }

        if (@openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]) !== false) {
            return $path = null;
        }

        $candidates = array_filter([
            getenv('OPENSSL_CONF') ?: null,
            dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf',
            dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'openssl.cnf',
        ]);

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC, 'config' => $candidate]) !== false) {
                return $path = $candidate;
            }
        }

        $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'webpush-openssl.cnf';

        if (! is_file($file)) {
            file_put_contents($file, "openssl_conf = openssl_init\n[openssl_init]\n[req]\ndistinguished_name = req_distinguished_name\n[req_distinguished_name]\n");
        }

        return $path = $file;
    }

    public static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function unb64(string $text): string
    {
        return (string) base64_decode(strtr($text, '-_', '+/').str_repeat('=', (4 - strlen($text) % 4) % 4), true);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private static function point(array $details): string
    {
        return "\x04".str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT).str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
    }

    private static function publicPem(string $uncompressed): string
    {
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$uncompressed;

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    /**
     * An ECDSA signature from DER (what OpenSSL gives) to the raw R||S of 64 bytes a JWT carries.
     */
    private static function derToRaw(string $der): string
    {
        $offset = 3;
        $rLength = ord($der[$offset]);
        $r = substr($der, $offset + 1, $rLength);
        $offset += 1 + $rLength + 1;
        $sLength = ord($der[$offset]);
        $s = substr($der, $offset + 1, $sLength);

        return str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT).str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
    }
}
