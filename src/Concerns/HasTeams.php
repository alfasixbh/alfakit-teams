<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTeams\Concerns;

use AlfaSix\Alfakit\Panel;
use AlfaSix\AlfakitTeams\Models\Membership;
use AlfaSix\AlfakitTeams\Role;
use AlfaSix\AlfakitTeams\Teams;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

/**
 * Teams of a user. Implements `HasTenants` and `HasDefaultTenant` for the panel; add the
 * interfaces to your user model:
 *
 * ```php
 * class User extends Authenticatable implements HasTenants, HasDefaultTenant
 * {
 *     use HasTeams;
 * }
 * ```
 *
 * @mixin Model
 */
trait HasTeams
{
    /**
     * @return BelongsToMany<Model, $this, Membership>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Teams::teamModel(), 'team_user', 'user_id', 'team_id')
            ->using(Teams::membershipModel())
            ->withPivot(['id', 'role'])
            ->withTimestamps();
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(Teams::teamModel(), 'current_team_id');
    }

    /**
     * @return Collection<int, Model>
     */
    public function getTenants(Panel $panel): Collection
    {
        return $this->teams()->orderBy('name')->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return is_a($tenant, Teams::teamModel()) && $this->belongsToTeam($tenant);
    }

    /**
     * The team the user last worked in, else their personal team, else their first team.
     */
    public function getDefaultTenant(Panel $panel): ?Model
    {
        $current = $this->getAttribute('current_team_id') === null ? null : $this->currentTeam()->first();

        if ($current instanceof Model && $this->belongsToTeam($current)) {
            return $current;
        }

        return $this->personalTeam() ?? $this->teams()->orderBy('team_user.id')->first();
    }

    public function personalTeam(): ?Model
    {
        return $this->teams()->where('personal_team', true)->orderBy('team_user.id')->first();
    }

    /**
     * Remember the team as the user's current team (the panel does this when they switch).
     */
    public function switchTeam(Model $team): bool
    {
        if (! $this->belongsToTeam($team)) {
            return false;
        }

        if ((string) $this->getAttribute('current_team_id') !== (string) $team->getKey()) {
            $this->forceFill(['current_team_id' => $team->getKey()])->saveQuietly();
        }

        return true;
    }

    public function belongsToTeam(?Model $team): bool
    {
        return $team !== null && Teams::membershipModel()::query()
            ->where('team_id', $team->getKey())
            ->where('user_id', $this->getKey())
            ->exists();
    }

    public function teamRole(?Model $team): ?Role
    {
        if ($team === null) {
            return null;
        }

        $role = Teams::membershipModel()::query()
            ->where('team_id', $team->getKey())
            ->where('user_id', $this->getKey())
            ->value('role');

        return is_string($role) ? Teams::pluginOrDefault()->getRole($role) : null;
    }

    public function ownsTeam(?Model $team): bool
    {
        return $this->teamRole($team)?->isOwner() ?? false;
    }

    public function hasTeamRole(?Model $team, string $role): bool
    {
        return $this->teamRole($team)?->getName() === $role;
    }

    /**
     * Whether the user's role in the team grants the permission (`members:invite`,
     * `posts:create`…). Owners have every permission.
     */
    public function hasTeamPermission(?Model $team, string $permission): bool
    {
        return $this->teamRole($team)?->grants($permission) ?? false;
    }

    /**
     * @return array<int, string>
     */
    public function teamPermissions(?Model $team): array
    {
        return $this->teamRole($team)?->getPermissions() ?? [];
    }
}
