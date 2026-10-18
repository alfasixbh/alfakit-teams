<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTeams\Concerns;

use AlfaSix\AlfakitTeams\Models\Membership;
use AlfaSix\AlfakitTeams\Role;
use AlfaSix\AlfakitTeams\Teams;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Members, roles and invitations of a team model. Use it on your own `Team` model (with
 * the columns of the plugin's migration) or use `AlfaSix\AlfakitTeams\Models\Team`.
 *
 * @mixin Model
 */
trait IsTeam
{
    /**
     * @return BelongsToMany<Model, $this, Membership>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Teams::userModel(), 'team_user', 'team_id', 'user_id')
            ->using(Teams::membershipModel())
            ->withPivot(['id', 'role'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Teams::membershipModel(), 'team_id');
    }

    /**
     * @return HasMany<\AlfaSix\AlfakitTeams\Models\TeamInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(Teams::invitationModel(), 'team_id');
    }

    /**
     * @return BelongsToMany<Model, $this, Membership>
     */
    public function owners(): BelongsToMany
    {
        return $this->members()->wherePivot('role', Role::OWNER);
    }

    /**
     * The first owner (by membership), e.g. the billing contact.
     */
    public function owner(): ?Model
    {
        return $this->owners()->orderBy('team_user.id')->first();
    }

    public function hasMember(Authenticatable|Model|null $user): bool
    {
        return $user !== null && $this->memberships()->where('user_id', static::memberKey($user))->exists();
    }

    public function membershipOf(Authenticatable|Model|null $user): ?Membership
    {
        return $user === null ? null : $this->memberships()->where('user_id', static::memberKey($user))->first();
    }

    public function roleOf(Authenticatable|Model|null $user): ?Role
    {
        $membership = $this->membershipOf($user);

        return $membership === null ? null : Teams::pluginOrDefault()->getRole($membership->role);
    }

    protected static function memberKey(Authenticatable|Model $user): mixed
    {
        return $user instanceof Model ? $user->getKey() : $user->getAuthIdentifier();
    }

    public function isPersonal(): bool
    {
        return (bool) $this->getAttribute('personal_team');
    }

    public function ownerCount(): int
    {
        return $this->memberships()->where('role', Role::OWNER)->count();
    }

    public function memberCount(): int
    {
        return $this->memberships()->count();
    }

    /**
     * The logo as the tenant avatar (`HasAvatar`), from the plugin's logo disk.
     */
    public function getAlfakitAvatarUrl(): ?string
    {
        $path = $this->getAttribute('logo_path');

        if (! is_string($path) || $path === '') {
            return null;
        }

        try {
            return Storage::disk(Teams::pluginOrDefault()->getLogoDisk())->url($path);
        } catch (Throwable) {
            return null;
        }
    }
}
