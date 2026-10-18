<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTeams\Policies;

use AlfaSix\AlfakitTeams\Permission;
use AlfaSix\AlfakitTeams\Teams;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Team abilities from the member's role. Registered for the team model unless you
 * register your own policy; extend this one to change a rule:
 *
 * ```php
 * Gate::policy(Team::class, MyTeamPolicy::class); // class MyTeamPolicy extends TeamPolicy
 * ```
 */
class TeamPolicy
{
    public function view(Model $user, Model $team): bool
    {
        return Teams::manager()->membership($team, $user) !== null;
    }

    public function update(Model $user, Model $team): Response
    {
        return $this->permission($user, $team, Permission::UPDATE_TEAM);
    }

    public function inviteMembers(Model $user, Model $team): Response
    {
        return $this->permission($user, $team, Permission::INVITE_MEMBERS);
    }

    public function updateMembers(Model $user, Model $team): Response
    {
        return $this->permission($user, $team, Permission::UPDATE_MEMBERS);
    }

    public function removeMembers(Model $user, Model $team): Response
    {
        return $this->permission($user, $team, Permission::REMOVE_MEMBERS);
    }

    /**
     * Owners only, whatever the permissions say.
     */
    public function transferOwnership(Model $user, Model $team): Response
    {
        return Teams::manager()->isOwner($team, $user) ? Response::allow() : Response::deny(__('alfakit-teams::teams.errors.owners_only'));
    }

    /**
     * Owners only, whatever the permissions say.
     */
    public function delete(Model $user, Model $team): Response
    {
        return Teams::manager()->isOwner($team, $user) ? Response::allow() : Response::deny(__('alfakit-teams::teams.errors.owners_only'));
    }

    protected function permission(Model $user, Model $team, string $permission): Response
    {
        return Teams::manager()->can($user, $permission, $team) ? Response::allow() : Response::deny(__('alfakit-teams::teams.errors.forbidden'));
    }
}
