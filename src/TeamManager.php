<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTeams;

use AlfaSix\Alfakit\Panel;
use AlfaSix\AlfakitTeams\Events\InvitationAccepted;
use AlfaSix\AlfakitTeams\Events\InvitationSent;
use AlfaSix\AlfakitTeams\Events\MemberAdded;
use AlfaSix\AlfakitTeams\Events\MemberRemoved;
use AlfaSix\AlfakitTeams\Events\MemberRoleUpdated;
use AlfaSix\AlfakitTeams\Events\OwnershipTransferred;
use AlfaSix\AlfakitTeams\Events\TeamCreated;
use AlfaSix\AlfakitTeams\Events\TeamDeleted;
use AlfaSix\AlfakitTeams\Exceptions\TeamException;
use AlfaSix\AlfakitTeams\Integrations\BillingSeats;
use AlfaSix\AlfakitTeams\Integrations\ShieldRoles;
use AlfaSix\AlfakitTeams\Models\Membership;
use AlfaSix\AlfakitTeams\Models\TeamInvitation;
use AlfaSix\AlfakitTeams\Pages\AcceptInvitation;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;
use UnexpectedValueException;

/**
 * Every team operation, with its rules: permissions (through `TeamPolicy`), owner-only
 * operations, last-owner protection, seat and invitation limits. The plugin's pages call
 * it, and so can your own code:
 *
 * ```php
 * Teams::manager()->invite($team, auth()->user(), 'ada@example.com', 'admin');
 * ```
 *
 * Rule violations throw a `TeamException` with a translated message; missing permissions
 * throw an `AuthorizationException`.
 */
class TeamManager
{
    public function plugin(): TeamsPlugin
    {
        return Teams::pluginOrDefault();
    }

    // ---------------------------------------------------------------- Roles & permissions

    public function membership(Model $team, ?Model $user): ?Membership
    {
        if ($user === null) {
            return null;
        }

        return Teams::membershipModel()::query()
            ->where('team_id', $team->getKey())
            ->where('user_id', $user->getKey())
            ->first();
    }

    public function roleOf(Model $team, ?Model $user): ?Role
    {
        $membership = $this->membership($team, $user);

        return $membership === null ? null : $this->plugin()->getRole($membership->role);
    }

    public function isOwner(Model $team, ?Model $user): bool
    {
        return $this->membership($team, $user)?->isOwner() ?? false;
    }

    /**
     * Whether the user's role in the team grants the permission.
     */
    public function can(?Model $user, string $permission, Model $team): bool
    {
        return $this->roleOf($team, $user)?->grants($permission) ?? false;
    }

    public function ownerCount(Model $team): int
    {
        return Teams::membershipModel()::query()->where('team_id', $team->getKey())->where('role', Role::OWNER)->count();
    }

    public function memberCount(Model $team): int
    {
        return Teams::membershipModel()::query()->where('team_id', $team->getKey())->count();
    }

    public function pendingInvitationCount(Model $team): int
    {
        return Teams::invitationModel()::query()->where('team_id', $team->getKey())->pending()->count();
    }

    /**
     * Seats left (members + pending invitations count against them), or null without a
     * seat limit.
     */
    public function seatsRemaining(Model $team, bool $countInvitations = true): ?int
    {
        $seats = $this->plugin()->getSeats($team);

        if ($seats === null) {
            return null;
        }

        return max(0, $seats - $this->memberCount($team) - ($countInvitations ? $this->pendingInvitationCount($team) : 0));
    }

    // ---------------------------------------------------------------- Teams

    /**
     * Create a team owned by `$owner`. A missing or taken `slug` is generated from the name.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createTeam(Model $owner, array $attributes, bool $personal = false): Model
    {
        $name = trim((string) ($attributes['name'] ?? ''));
        $attributes['name'] = $name === '' ? $this->plugin()->getPersonalTeamName($owner) : $name;
        $attributes['slug'] = $this->uniqueSlug((string) ($attributes['slug'] ?? '') ?: $attributes['name']);
        $attributes['personal_team'] = $personal;

        $team = DB::transaction(function () use ($owner, $attributes): Model {
            $model = Teams::teamModel();
            /** @var Model $team */
            $team = new $model;
            $team->forceFill($attributes)->save();

            Teams::membershipModel()::query()->create(['team_id' => $team->getKey(), 'user_id' => $owner->getKey(), 'role' => Role::OWNER]);

            return $team;
        });

        $this->switchTeam($owner, $team);

        TeamCreated::dispatch($team, $owner);
        $this->memberAdded($team, $owner, Role::OWNER);

        return $team;
    }

    public function createPersonalTeam(Model $user): Model
    {
        return $this->createTeam($user, ['name' => $this->plugin()->getPersonalTeamName($user)], personal: true);
    }

    public function uniqueSlug(string $value, ?Model $ignore = null): string
    {
        $base = Str::slug($value);
        $base = $base === '' ? Str::lower(Str::random(8)) : Str::limit($base, 60, '');
        $slug = $base;
        $suffix = 2;

        while (Teams::teamModel()::query()->where('slug', $slug)->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore?->getKey()))->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /**
     * Update the team's settings (`team:update`).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateTeam(Model $team, Model $actor, array $attributes): Model
    {
        Gate::forUser($actor)->authorize('update', $team);

        $previousLogo = $team->getAttribute('logo_path');

        if (array_key_exists('auto_join_domain', $attributes)) {
            $attributes['auto_join_domain'] = $this->normalizeDomain($attributes['auto_join_domain']);
        }

        $team->fill($attributes)->save();

        if (is_string($previousLogo) && $previousLogo !== '' && $previousLogo !== $team->getAttribute('logo_path')) {
            $this->deleteLogo($previousLogo);
        }

        return $team;
    }

    /**
     * Delete the team (owners only; personal teams can't be deleted).
     */
    public function deleteTeam(Model $team, Model $actor): void
    {
        Gate::forUser($actor)->authorize('delete', $team);

        if ((bool) $team->getAttribute('personal_team')) {
            throw TeamException::because('personal_team');
        }

        $members = Teams::membershipModel()::query()->where('team_id', $team->getKey())->pluck('user_id')->all();
        $logo = $team->getAttribute('logo_path');

        DB::transaction(function () use ($team): void {
            Teams::invitationModel()::query()->where('team_id', $team->getKey())->delete();
            Teams::membershipModel()::query()->where('team_id', $team->getKey())->delete();

            $user = Teams::userModel();
            $user::query()->where('current_team_id', $team->getKey())->update(['current_team_id' => null]);

            $team->delete();
        });

        if (is_string($logo) && $logo !== '') {
            $this->deleteLogo($logo);
        }

        foreach ($members as $member) {
            $user = Teams::userModel()::query()->find($member);

            if ($user instanceof Model) {
                ShieldRoles::sync($team, $user, null);
            }
        }

        TeamDeleted::dispatch($team, $actor);
    }

    /**
     * Make `$newOwner` (a member) an owner and `$actor` (an owner) an admin.
     */
    public function transferOwnership(Model $team, Model $actor, Model $newOwner): void
    {
        Gate::forUser($actor)->authorize('transferOwnership', $team);

        if ($actor->is($newOwner)) {
            throw TeamException::because('transfer_to_self');
        }

        $membership = $this->membership($team, $newOwner) ?? throw TeamException::because('not_a_member');
        $actorMembership = $this->membership($team, $actor) ?? throw TeamException::because('not_a_member');
        $previous = $membership->role;
        $demotedTo = $this->getDemotedOwnerRole();

        DB::transaction(function () use ($membership, $actorMembership, $demotedTo): void {
            $membership->forceFill(['role' => Role::OWNER])->save();
            $actorMembership->forceFill(['role' => $demotedTo])->save();
        });

        ShieldRoles::sync($team, $newOwner, Role::OWNER);
        ShieldRoles::sync($team, $actor, $demotedTo);

        MemberRoleUpdated::dispatch($team, $newOwner, Role::OWNER, $previous);
        MemberRoleUpdated::dispatch($team, $actor, $demotedTo, Role::OWNER);
        OwnershipTransferred::dispatch($team, $actor, $newOwner);
    }

    /**
     * The role a former owner gets after a transfer: `admin` when it exists, else the
     * default role.
     */
    protected function getDemotedOwnerRole(): string
    {
        return $this->plugin()->hasRole('admin') ? 'admin' : $this->plugin()->getDefaultRole();
    }

    public function switchTeam(Model $user, Model $team): void
    {
        if (method_exists($user, 'switchTeam')) {
            $user->switchTeam($team);
        }
    }

    // ---------------------------------------------------------------- Members

    /**
     * Add a user to the team (no permission check: for your own flows). Existing members
     * keep their role.
     */
    public function addMember(Model $team, Model $user, ?string $role = null, bool $enforceSeats = true): Membership
    {
        $existing = $this->membership($team, $user);

        if ($existing !== null) {
            return $existing;
        }

        $role ??= $this->plugin()->getDefaultRole();

        if (! $this->plugin()->hasRole($role)) {
            throw TeamException::because('unknown_role');
        }

        if ($enforceSeats && $this->seatsRemaining($team, countInvitations: false) === 0) {
            throw TeamException::because('seats_full');
        }

        /** @var Membership $membership */
        $membership = Teams::membershipModel()::query()->create(['team_id' => $team->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);

        $this->memberAdded($team, $user, $role);

        return $membership;
    }

    /**
     * Change a member's role. Needs `members:update`; giving or taking the owner role
     * needs an owner; the last owner can't be demoted and nobody changes their own role.
     */
    public function updateRole(Model $team, Model $actor, Model $member, string $role): void
    {
        Gate::forUser($actor)->authorize('updateMembers', $team);

        if (! $this->plugin()->hasRole($role)) {
            throw TeamException::because('unknown_role');
        }

        $membership = $this->membership($team, $member) ?? throw TeamException::because('not_a_member');

        if ($actor->is($member)) {
            throw TeamException::because('own_role');
        }

        if (($role === Role::OWNER || $membership->isOwner()) && ! $this->isOwner($team, $actor)) {
            throw TeamException::because('owners_only');
        }

        if ($membership->isOwner() && $role !== Role::OWNER && $this->ownerCount($team) <= 1) {
            throw TeamException::because('last_owner');
        }

        $previous = $membership->role;

        if ($previous === $role) {
            return;
        }

        $membership->forceFill(['role' => $role])->save();

        ShieldRoles::sync($team, $member, $role);
        MemberRoleUpdated::dispatch($team, $member, $role, $previous);
    }

    /**
     * Remove a member. Needs `members:remove`; removing an owner needs an owner, and the
     * last owner can never be removed. Removing yourself is leaving.
     */
    public function removeMember(Model $team, Model $actor, Model $member): void
    {
        if ($actor->is($member)) {
            $this->leave($team, $actor);

            return;
        }

        Gate::forUser($actor)->authorize('removeMembers', $team);

        $membership = $this->membership($team, $member) ?? throw TeamException::because('not_a_member');

        if ($membership->isOwner() && ! $this->isOwner($team, $actor)) {
            throw TeamException::because('owners_only');
        }

        if ($membership->isOwner() && $this->ownerCount($team) <= 1) {
            throw TeamException::because('last_owner');
        }

        $this->detach($team, $membership, $member, $actor);
    }

    /**
     * Leave the team; the last owner must transfer ownership (or delete the team) first.
     */
    public function leave(Model $team, Model $user): void
    {
        $membership = $this->membership($team, $user) ?? throw TeamException::because('not_a_member');

        if ($membership->isOwner() && $this->ownerCount($team) <= 1) {
            throw TeamException::because('last_owner_leave');
        }

        $this->detach($team, $membership, $user, null);
    }

    protected function detach(Model $team, Membership $membership, Model $user, ?Model $actor): void
    {
        $membership->delete();

        if ((string) $user->getAttribute('current_team_id') === (string) $team->getKey()) {
            $user->forceFill(['current_team_id' => null])->saveQuietly();
        }

        ShieldRoles::sync($team, $user, null);
        MemberRemoved::dispatch($team, $user, $actor);
        $this->syncBilling($team);
    }

    protected function memberAdded(Model $team, Model $user, string $role): void
    {
        ShieldRoles::sync($team, $user, $role);
        MemberAdded::dispatch($team, $user, $role);
        $this->syncBilling($team);
    }

    // ---------------------------------------------------------------- Invitations

    /**
     * Invite `$email` to join as `$role` (`members:invite`; only owners invite owners) and
     * send the invitation notification.
     */
    public function invite(Model $team, Model $actor, string $email, ?string $role = null): TeamInvitation
    {
        Gate::forUser($actor)->authorize('inviteMembers', $team);

        $plugin = $this->plugin();
        $email = mb_strtolower(trim($email));
        $role ??= $plugin->getDefaultRole();

        if (! $plugin->hasRole($role)) {
            throw TeamException::because('unknown_role');
        }

        if ($role === Role::OWNER && ! $this->isOwner($team, $actor)) {
            throw TeamException::because('owners_only');
        }

        if (($problem = $this->invitationEmailProblem($team, $email)) !== null) {
            throw TeamException::because($problem);
        }

        // An expired invitation to the same address is replaced.
        Teams::invitationModel()::query()->where('team_id', $team->getKey())->where('email', $email)->delete();

        $limit = $plugin->getInvitationLimit($team);

        if ($limit !== null && $this->pendingInvitationCount($team) >= $limit) {
            throw TeamException::because('invitation_limit', ['limit' => $limit]);
        }

        if ($this->seatsRemaining($team) === 0) {
            throw TeamException::because('seats_full');
        }

        /** @var TeamInvitation $invitation */
        $invitation = Teams::invitationModel()::query()->create([
            'team_id' => $team->getKey(),
            'email' => $email,
            'role' => $role,
            'invited_by' => $actor->getKey(),
            'expires_at' => $this->expiresAt(),
        ]);

        $this->sendInvitation($invitation);

        InvitationSent::dispatch($invitation, $team, $actor);

        return $invitation;
    }

    /**
     * Why `$email` can't be invited to the team (`already_member`, `already_invited`), or
     * null.
     */
    public function invitationEmailProblem(Model $team, string $email): ?string
    {
        $email = mb_strtolower(trim($email));
        $user = Teams::userModel()::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user instanceof Model && $this->membership($team, $user) !== null) {
            return 'already_member';
        }

        $existing = Teams::invitationModel()::query()->where('team_id', $team->getKey())->where('email', $email)->first();

        return $existing !== null && ! $existing->isExpired() ? 'already_invited' : null;
    }

    /**
     * Send the invitation again with a fresh expiry (once a minute per invitation).
     */
    public function resendInvitation(TeamInvitation $invitation, Model $actor): void
    {
        $team = $this->invitationTeam($invitation);

        Gate::forUser($actor)->authorize('inviteMembers', $team);

        $key = 'alfakit-teams:resend:'.$invitation->getKey();

        if (RateLimiter::tooManyAttempts($key, 1)) {
            throw TeamException::because('resend_throttled', ['seconds' => RateLimiter::availableIn($key)]);
        }

        RateLimiter::hit($key, 60);

        $invitation->forceFill(['expires_at' => $this->expiresAt()])->save();

        $this->sendInvitation($invitation);

        InvitationSent::dispatch($invitation, $team, $actor, resent: true);
    }

    public function revokeInvitation(TeamInvitation $invitation, Model $actor): void
    {
        Gate::forUser($actor)->authorize('inviteMembers', $this->invitationTeam($invitation));

        $invitation->delete();
    }

    /**
     * Why `$user` can't accept the invitation (a `TeamException` reason), or null when
     * they can.
     */
    public function invitationProblem(TeamInvitation $invitation, ?Model $user): ?string
    {
        $team = $invitation->team()->first();

        return match (true) {
            ! $team instanceof Model => 'invitation_invalid',
            $invitation->isExpired() => 'invitation_expired',
            $user === null || ! $invitation->isFor($this->emailOf($user)) => 'invitation_wrong_user',
            $this->membership($team, $user) !== null => null,
            $this->seatsRemaining($team, countInvitations: false) === 0 => 'seats_full',
            default => null,
        };
    }

    /**
     * Join the team as the invitation's role. The invitation must be for the user's
     * e-mail address, not expired, and the team must have a free seat.
     */
    public function acceptInvitation(TeamInvitation $invitation, Model $user): Model
    {
        $problem = $this->invitationProblem($invitation, $user);

        if ($problem !== null) {
            throw TeamException::because($problem);
        }

        $team = $this->invitationTeam($invitation);
        $role = $this->plugin()->hasRole($invitation->role) ? $invitation->role : $this->plugin()->getDefaultRole();

        DB::transaction(function () use ($team, $user, $role, $invitation): void {
            // The invitation already held a seat, so don't count it against itself.
            $this->addMember($team, $user, $role);
            $invitation->delete();
        });

        $this->switchTeam($user, $team);

        InvitationAccepted::dispatch($invitation, $team, $user);

        return $team;
    }

    public function declineInvitation(TeamInvitation $invitation, Model $user): void
    {
        if (! $invitation->isFor($this->emailOf($user))) {
            throw TeamException::because('invitation_wrong_user');
        }

        $invitation->delete();
    }

    /**
     * The signed link that opens the accept page.
     */
    public function acceptUrl(TeamInvitation $invitation, ?Panel $panel = null): string
    {
        $panel ??= Teams::panel() ?? alfakit()->getCurrentOrDefaultPanel();

        return URL::signedRoute($panel->generateRouteName(AcceptInvitation::ROUTE), ['invitation' => $invitation->getKey()]);
    }

    protected function sendInvitation(TeamInvitation $invitation): void
    {
        $notification = app()->make($this->plugin()->getInvitationNotification(), ['invitation' => $invitation, 'acceptUrl' => $this->acceptUrl($invitation)]);

        if (! $notification instanceof \Illuminate\Notifications\Notification) {
            throw new UnexpectedValueException('The invitation notification must be an '.\Illuminate\Notifications\Notification::class.'.');
        }

        Notification::route('mail', $invitation->email)->notify($notification);
    }

    protected function expiresAt(): ?\Illuminate\Support\Carbon
    {
        $days = $this->plugin()->getInvitationExpiryDays();

        return $days === null ? null : now()->addDays($days);
    }

    protected function invitationTeam(TeamInvitation $invitation): Model
    {
        $team = $invitation->team()->first();

        if (! $team instanceof Model) {
            throw TeamException::because('invitation_invalid');
        }

        return $team;
    }

    // ---------------------------------------------------------------- Domain auto-join

    /**
     * Add the user to every team whose auto-join domain is their e-mail domain (verified
     * addresses only when the user model verifies e-mail). Teams without a free seat are
     * skipped. Returns the teams joined.
     *
     * @return array<int, Model>
     */
    public function autoJoin(Model $user): array
    {
        $plugin = $this->plugin();
        $email = $this->emailOf($user);

        if (! $plugin->hasDomainAutoJoin() || $email === null || ! str_contains($email, '@')) {
            return [];
        }

        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            return [];
        }

        $domain = $this->normalizeDomain(Str::afterLast($email, '@'));

        if ($domain === null || in_array($domain, $plugin->getBlockedAutoJoinDomains(), true)) {
            return [];
        }

        $joined = [];

        foreach (Teams::teamModel()::query()->where('auto_join_domain', $domain)->get() as $team) {
            if ($this->membership($team, $user) !== null || $this->seatsRemaining($team, countInvitations: false) === 0) {
                continue;
            }

            $this->addMember($team, $user, $plugin->getAutoJoinRole());
            $joined[] = $team;
        }

        if ($joined !== [] && $user->getAttribute('current_team_id') === null) {
            $this->switchTeam($user, $joined[0]);
        }

        return $joined;
    }

    /**
     * Why `$domain` can't be the auto-join domain of a team set by `$actor` (a
     * `TeamException` reason), or null when it can: it must not be a public e-mail
     * provider, and it must be the actor's own (verified) e-mail domain.
     */
    public function autoJoinDomainProblem(?string $domain, Model $actor): ?string
    {
        $domain = $this->normalizeDomain($domain);

        if ($domain === null) {
            return null;
        }

        if (preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) !== 1) {
            return 'domain_invalid';
        }

        if (in_array($domain, $this->plugin()->getBlockedAutoJoinDomains(), true)) {
            return 'domain_public';
        }

        $email = $this->emailOf($actor);
        $ownDomain = $email === null ? null : $this->normalizeDomain(Str::afterLast($email, '@'));

        if ($ownDomain !== $domain || ($actor instanceof MustVerifyEmail && ! $actor->hasVerifiedEmail())) {
            return 'domain_not_yours';
        }

        return null;
    }

    public function normalizeDomain(mixed $domain): ?string
    {
        if (! is_string($domain)) {
            return null;
        }

        $domain = mb_strtolower(trim(ltrim(trim($domain), '@')));

        return $domain === '' ? null : $domain;
    }

    // ---------------------------------------------------------------- Integrations

    public function syncBilling(Model $team): void
    {
        if (! $this->plugin()->syncsSeatsWithBilling()) {
            return;
        }

        try {
            BillingSeats::syncQuantity($team, $this->memberCount($team), $this->plugin());
        } catch (Throwable $exception) {
            // The membership change stands; the quantity is retried on the next change.
            report($exception);
        }
    }

    protected function emailOf(Model $user): ?string
    {
        $email = $user->getAttribute('email');

        return is_string($email) && $email !== '' ? $email : null;
    }

    protected function deleteLogo(string $path): void
    {
        try {
            Storage::disk($this->plugin()->getLogoDisk())->delete($path);
        } catch (Throwable) {
            // A missing file or disk is not worth failing the request over.
        }
    }
}
