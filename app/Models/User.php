<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use JoelButcher\Socialstream\HasConnectedAccounts;
use JoelButcher\Socialstream\SetsProfilePhotoFromUrl;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Jetstream\HasTeams;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasTenants
{
    use HasApiTokens;
    use HasConnectedAccounts;
    use HasFactory;
    use HasProfilePhoto {
        HasProfilePhoto::profilePhotoUrl as getPhotoUrl;
    }
    // Both traits declare `teams()`. Jetstream's is the real team-membership
    // relationship that tenancy, DeleteUser and EditTeam depend on; Spatie's
    // returns permission scopes and is inert while `permission.teams` is off.
    use HasRoles, HasTeams {
        HasTeams::teams insteadof HasRoles;
        HasRoles::teams as permissionTeams;
    }
    use Notifiable;
    use SetsProfilePhotoFromUrl;
    use TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'current_team_id',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
        ];
    }

    /**
     * The team the user is currently viewing.
     *
     * Overrides Jetstream's HasTeams::currentTeam(), which lazily writes a
     * personal team onto users that have none. This app assigns teams
     * explicitly, so resolving the relationship must stay side-effect free.
     */
    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'current_team_id');
    }

    /**
     * The teams available to this user as Filament tenants.
     *
     * @return array<Model>|Collection
     */
    /**
     * Gate access to each Filament panel.
     *
     * Filament\Http\Middleware\Authenticate aborts with 403 whenever the user model
     * does not implement FilamentUser and the environment is anything other than
     * `local` — before permissions or tenancy are consulted. Without this method the
     * panels were unreachable in production no matter how the roles were configured,
     * and the failure surfaced as a bare 403 with nothing in the log.
     *
     * The app panel allows any authenticated user because it has registration
     * enabled and is tenant-scoped, so a new account only ever sees its own team.
     * The admin panel hosts Shield's role and permission management, so it is
     * restricted to super_admin.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'admin' => $this->hasRole('super_admin'),
            default => true,
        };
    }

    public function getTenants(Panel $panel): array | Collection
    {
        return $this->allTeams();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->belongsToTeam($tenant);
    }

    /**
     * Get the URL to the user's profile photo.
     */
    protected function profilePhotoUrl(): Attribute
    {
        return filter_var($this->profile_photo_path, FILTER_VALIDATE_URL)
            ? Attribute::get(fn () => $this->profile_photo_path)
            : $this->getPhotoUrl();
    }
}
