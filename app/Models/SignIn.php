<?php

namespace App\Models;

use App\Support\UserAgent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sign-in attempt. Written only by App\Services\SignInLog.
 */
class SignIn extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public const METHODS = [
        'password' => 'Password',
        'authenticator' => 'Authenticator code',
        'two_factor' => 'Password + two-factor',
        'microsoft' => 'Microsoft',
        'remember_me' => 'Remembered device',
        'registration' => 'New account',
        'other' => 'Other',
    ];

    public const REASONS = [
        'bad_password' => 'Wrong password',
        'unknown_user' => 'Unknown account',
        'inactive' => 'Account turned off',
        'locked_out' => 'Locked out (too many attempts)',
        'two_factor_invalid' => 'Wrong two-factor code',
        'code_reused' => 'Code already used',
        'code_paused' => 'Code sign-in paused',
        'password_disabled' => 'Password sign-in is off',
        'sso_unknown_user' => 'No account for this Microsoft user',
        'sso_failed' => 'Microsoft sign-in failed',
    ];

    protected function casts(): array
    {
        return [
            'successful' => 'boolean',
            'new_device' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function prunable(): Builder
    {
        $days = (int) setting('sign_in_retention_days', 365);

        return $days > 0
            ? static::where('created_at', '<', now()->subDays($days))
            : static::whereRaw('1 = 0');
    }

    public function agent(): array
    {
        return UserAgent::parse($this->user_agent);
    }

    public function methodLabel(): string
    {
        return __(self::METHODS[$this->method] ?? ($this->method ?: 'Other'));
    }

    public function reasonLabel(): ?string
    {
        return $this->reason ? __(self::REASONS[$this->reason] ?? $this->reason) : null;
    }
}
