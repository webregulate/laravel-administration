<?php

namespace WebRegulate\LaravelAdministration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;

/**
 * @property int $id
 * @property string $user_id
 * @property string|null $avatar
 * @property string|null $permissions
 * @property string|null $settings
 * @property string|null $data
 * @property string|null $mfa_secret
 */
class WRLAUserDataBase extends Model
{
    protected $table = 'wrla_user_data';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'permissions',
        'settings',
        'data',
    ];

    public function getConnectionName(): ?string
    {
        return parent::getConnectionName()
            ?? config('wr-laravel-administration.wrla_user_data.connection');
    }

    /** Get current logged in user. */
    public static function getCurrentUser(): mixed
    {
        return Auth::user();
    }

    /** Get current logged in user's data. */
    public static function getCurrentUserData(): ?self
    {
        return once(fn() => static::getCurrentUser()?->wrlaUserData);
    }

    /** Attach user to the model. */
    public function attachUser(mixed $user): static
    {
        $this->setRelation('user', $user);
        $this->user_id = WRLAHelper::buildUserId($user);

        return $this;
    }

    /** Whether this user data belongs to the given user model. */
    public function belongsToUser(mixed $user): bool
    {
        $userId = WRLAHelper::buildUserId($user);

        return $this->user_id !== null
            && $userId !== null
            && (string) $this->user_id === (string) $userId;
    }

    /** Get user model by email. */
    public static function getUserByEmail(string $email): mixed
    {
        return WRLAHelper::getUserModelClass()::where('email', $email)->first();
    }

    /** Requires MFA. */
    public function requiresMFA(): bool
    {
        return $this->getSetting('mfa.require') ?? false;
    }

    /** Set MFA secret key. */
    public function setMFASecretKey(string $secretKey): ?string
    {
        return $this->mfa_secret = $secretKey;
    }

    /** Get MFA secret key. */
    public function getMFASecretKey(): ?string
    {
        return $this->mfa_secret;
    }

    /** Check password. */
    public static function checkPassword(string $identifier, string $password): bool
    {
        $user = static::getUserByEmail($identifier);
        if (!$user) {
            return false;
        }

        return Hash::check($password, $user->{static::getPasswordField()});
    }

    /** Generate password. */
    public static function hashPassword(string $password): string
    {
        return Hash::make($password);
    }

    /** Password field on the user model. */
    public static function getPasswordField(): string
    {
        return 'password';
    }

    /** Attempt login. */
    public static function attemptLogin(string $identifier, string $password, bool $remember = true): bool
    {
        return Auth::attempt([
            'email' => $identifier,
            'password' => $password,
        ], $remember);
    }

    /** Implement method from the CanResetPassword interface. */
    public function getEmailForPasswordReset(): string
    {
        return $this->email;
    }

    /** Send the password reset notification. */
    public function sendPasswordResetNotification(string $token): void
    {
        $this->notify(new \WebRegulate\LaravelAdministration\Classes\WRLAResetPasswordNotification($this->email, $token));
    }

    /** Get profile image. */
    public function getProfileAvatar(): string
    {
        $user = $this->user;
        $userAvatarConfig = config('wr-laravel-administration.user_avatar');
        if ($user != null && $userAvatarConfig != null && is_callable($userAvatarConfig)) {
            return $userAvatarConfig($user);
        }

        return static::getAvatarStatic($this->avatar, $this->getFullName());
    }

    /** Get full name. */
    public function getFullName(): string
    {
        return $this->user?->name ?? 'User';
    }

    /** Get header display. */
    public function getHeaderDisplay(): string
    {
        return $this->getFullName();
    }

    /** Get profile avatar statically. */
    public static function getAvatarStatic(?string $avatar, ?string $fullName = null): string
    {
        if (!empty($avatar)) {
            return '/'.ltrim("storage/images/avatars/$avatar", '/');
        }

        $fullName = !empty($fullName) ? $fullName : 'U';
        $name = preg_replace('/\b(\w)|./', '$1', $fullName);

        return 'https://ui-avatars.com/api/?name=' . urlencode((string) $name) . '&color='.ltrim((string) config('wr-laravel-administration.colors.primary.50'), '#').'&background='.ltrim((string) config('wr-laravel-administration.colors.primary.500'), '#').'&size=128&font-size=0.5&rounded=true';
    }

    /** Alias for getUserByEmail. */
    public static function getByEmail(string $email): mixed
    {
        return static::getUserByEmail($email);
    }

    /** Get user permission, such as 'layered.permission.key'. */
    public function getPermission(string $dottedKey): mixed
    {
        return data_get(json_decode($this->permissions ?? '', true), $dottedKey);
    }

    /** Set inner permission by key. */
    public function setPermission(string $dottedKey, $value): void
    {
        $this->discardChanges();
        $permissions = json_decode($this->permissions ?? '', true);

        if (!is_array($permissions)) {
            $permissions = [];
        }

        data_set($permissions, $dottedKey, $value);
        $this->permissions = json_encode($permissions);
        $this->update(['permissions' => $this->permissions]);
    }

    /** Get user setting based on dotted key value, such as 'ui.theme'. */
    public function getSetting(string $dottedKey): mixed
    {
        return data_get(json_decode($this->settings ?? '', true), $dottedKey);
    }

    /** Set inner setting by key. */
    public function setSetting(string $dottedKey, $value): void
    {
        $this->discardChanges();
        $settings = json_decode($this->settings ?? '', true);

        if (!is_array($settings)) {
            $settings = [];
        }

        data_set($settings, $dottedKey, $value);
        $this->settings = json_encode($settings);
        $this->update(['settings' => $this->settings]);
    }

    /** Get user data based on dotted key value, such as 'profile.avatar'. */
    public function getData(string $dottedKey): mixed
    {
        return data_get(json_decode($this->data ?? '', true), $dottedKey);
    }

    /** Set inner data by key. */
    public function setData(string $dottedKey, $value): void
    {
        $this->discardChanges();
        $data = json_decode($this->data ?? '', true);

        if (!is_array($data)) {
            $data = [];
        }

        data_set($data, $dottedKey, $value);
        $this->data = json_encode($data);
        $this->update(['data' => $this->data]);
    }

    /** Get role. */
    public function getRole(): string
    {
        if ($this->isMaster()) {
            return 'Master Administrator';
        } elseif ($this->isAdmin()) {
            return 'Administrator';
        } else {
            return 'User';
        }
    }

    /** Get current theme, or null to use the default theme. */
    public function getCurrentThemeKey(): ?string
    {
        return $this->getSetting('theme.current');
    }

    /** Is user a master. */
    public function isMaster(): bool
    {
        return $this->getPermission('master') == true;
    }

    /** Is user an admin. */
    public function isAdmin(): bool
    {
        return $this->getPermission('admin') == true;
    }

    /** Relationship: belongs to the configured user model. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(WRLAHelper::getUserModelClass());
    }
}
