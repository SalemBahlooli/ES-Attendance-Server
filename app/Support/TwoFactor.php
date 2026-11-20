<?php

namespace App\Support;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Cache;
use PragmaRX\Google2FA\Google2FA;
use Throwable;

/**
 * Two-factor authentication with an authenticator app (TOTP, RFC 6238).
 *
 *   mode()        off | optional | required   (Settings → Security)
 *   check()       verify a 6-digit code, refusing a code that was already used
 *   qrSvg()       the QR code shown while linking the app
 *
 * The secret is stored encrypted on users.two_factor_secret; the account counts
 * as "linked" once users.two_factor_confirmed_at is set (the first good code).
 */
class TwoFactor
{
    public const OK = 'ok';

    public const USED = 'used';

    public const INVALID = 'invalid';

    /** Session key: the user id that passed two-factor in this session. */
    public const SESSION_KEY = 'two_factor.passed';

    public static function engine(): Google2FA
    {
        return new Google2FA;
    }

    public static function mode(): string
    {
        $mode = (string) setting('two_factor_mode', 'optional');

        return in_array($mode, ['off', 'optional', 'required'], true) ? $mode : 'optional';
    }

    public static function enabled(): bool
    {
        return self::mode() !== 'off';
    }

    public static function required(): bool
    {
        return self::mode() === 'required';
    }

    public static function generateSecret(): string
    {
        return self::engine()->generateSecretKey(32);
    }

    /** The otpauth:// URL the QR code holds. */
    public static function otpauthUrl(User $user, string $secret): string
    {
        return self::engine()->getQRCodeUrl((string) setting('app_name', config('app.name')), $user->email, $secret);
    }

    public static function qrSvg(string $url, int $size = 200): string
    {
        $renderer = new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString($url);
    }

    /**
     * Check a code against the user's secret (or a pending secret while linking).
     *
     * A code stays valid for about a minute. Without the "already used" check a
     * code read over someone's shoulder could be typed again on another device
     * within that minute, and with code sign-in that would be enough to get in.
     */
    public static function check(User $user, mixed $code, ?string $secret = null): string
    {
        $secret ??= $user->two_factor_secret;
        $code = is_string($code) || is_int($code) ? preg_replace('/\D+/', '', (string) $code) : '';

        if (! $secret || strlen($code) !== 6) {
            return self::INVALID;
        }

        $key = 'two_factor.last_step.'.$user->getKey();
        $last = (int) Cache::get($key, 0);

        try {
            // Returns the 30-second step the code belongs to, only if newer than the last accepted one.
            $step = self::engine()->verifyKeyNewer($secret, $code, $last, 1);

            if ($step === false) {
                return ($last > 0 && self::engine()->verifyKey($secret, $code, 1)) ? self::USED : self::INVALID;
            }
        } catch (Throwable) {
            return self::INVALID; // a secret the library cannot read counts as a wrong code
        }

        Cache::put($key, $step === true ? $last + 1 : (int) $step, now()->addMinutes(5));

        return self::OK;
    }

    /** Cookie that lets a trusted browser skip the code for REMEMBER_DAYS. */
    public const REMEMBER_COOKIE = 'two_factor_device';

    public const REMEMBER_DAYS = 30;

    /** Has this session passed two-factor for this user (or is this a trusted browser)? */
    public static function passed(User $user): bool
    {
        if (session(self::SESSION_KEY) === $user->getKey()) {
            return true;
        }

        $cookie = request()->cookie(self::REMEMBER_COOKIE);
        if (is_string($cookie) && hash_equals(self::deviceToken($user), $cookie)) {
            self::markPassed($user);

            return true;
        }

        return false;
    }

    public static function markPassed(User $user): void
    {
        session()->put(self::SESSION_KEY, $user->getKey());
    }

    /** Trust this browser for REMEMBER_DAYS (until the authenticator is unlinked). */
    public static function rememberDevice(User $user): void
    {
        cookie()->queue(cookie(self::REMEMBER_COOKIE, self::deviceToken($user), self::REMEMBER_DAYS * 24 * 60, null, null, null, true, false, 'lax'));
    }

    public static function forgetDevice(): void
    {
        cookie()->queue(cookie()->forget(self::REMEMBER_COOKIE));
    }

    /** Changes whenever the secret changes, so unlinking the app ends every trusted browser. */
    private static function deviceToken(User $user): string
    {
        return hash_hmac('sha256', $user->getKey().'|'.$user->two_factor_secret, (string) config('app.key'));
    }
}
