<?php

declare(strict_types=1);

use AlfaSix\AlfakitTeams\Events\MemberRemoved;
use AlfaSix\AlfakitTeams\Events\MemberRoleUpdated;
use AlfaSix\AlfakitTeams\Events\OwnershipTransferred;
use AlfaSix\AlfakitTeams\Events\TeamDeleted;
use AlfaSix\AlfakitTeams\Exceptions\TeamException;
use AlfaSix\AlfakitTeams\Models\Membership;
use AlfaSix\AlfakitTeams\Models\Team;
use AlfaSix\AlfakitTeams\Pages\TeamMembers;
use AlfaSix\AlfakitTeams\Pages\TeamSettings;
use AlfaSix\AlfakitTeams\Permission;
use AlfaSix\AlfakitTeams\Role;
use AlfaSix\AlfakitTeams\Teams;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;

use function AlfaSix\AlfakitTeams\Tests\page;
use function AlfaSix\AlfakitTeams\Tests\plugin;
use function AlfaSix\AlfakitTeams\Tests\team;
use function AlfaSix\AlfakitTeams\Tests\user;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

uses(AlfaSix\AlfakitTeams\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

function membershipOf(Team $team, Illuminate\Database\Eloquent\Model $user): Membership
{
    return Membership::query()->where('team_id', $team->id)->where('user_id', $user->getKey())->firstOrFail();
}

describe('roles and permissions', function (): void {
    it('grants permissions by role, with wildcards and an all-powerful owner', function (): void {
        $admin = Role::make('admin')->permissions([Permission::UPDATE_TEAM, 'members:*', 'posts:create']);

        expect($admin->grants('members:invite'))->toBeTrue()
            ->and($admin->grants('members:remove'))->toBeTrue()
            ->and($admin->grants('posts:create'))->toBeTrue()
            ->and($admin->grants('posts:delete'))->toBeFalse()
            ->and(Role::make('owner')->permissions([])->grants('anything:at-all'))->toBeTrue();
    });

    it('always has an owner role and a non-owner default role', function (): void {
        plugin()->roles([Role::make('editor')->permissions(['posts:*'])])->defaultRole('owner');

        expect(array_keys(plugin()->getRoles()))->toBe(['owner', 'editor'])
            ->and(plugin()->getDefaultRole())->toBe('editor');
    });

    it('checks custom permissions on the user', function (): void {
        plugin()->roles([
            Role::make('owner'),
            Role::make('editor')->permissions(['posts:*']),
            Role::make('viewer')->permissions(['posts:view']),
        ]);
        $ada = user('Ada');
        $eddie = user('Eddie');
        $vic = user('Vic');
        $team = team($ada, 'Alpha', ['editor' => $eddie, 'viewer' => $vic]);

        expect($eddie->hasTeamPermission($team, 'posts:delete'))->toBeTrue()
            ->and($vic->hasTeamPermission($team, 'posts:delete'))->toBeFalse()
            ->and($vic->hasTeamPermission($team, 'posts:view'))->toBeTrue()
            ->and($ada->hasTeamPermission($team, 'posts:delete'))->toBeTrue()
            ->and($vic->teamRole($team)?->getName())->toBe('viewer')
            ->and(user('Stranger')->hasTeamPermission($team, 'posts:view'))->toBeFalse();
    });

    it('authorises team abilities through the policy', function (): void {
        $ada = user('Ada');
        $admin = user('Adam');
        $member = user('Mia');
        $team = team($ada, 'Alpha', ['admin' => $admin, 'member' => $member]);

        expect($admin->can('inviteMembers', $team))->toBeTrue()
            ->and($admin->can('update', $team))->toBeTrue()
            ->and($admin->can('delete', $team))->toBeFalse()
            ->and($member->can('inviteMembers', $team))->toBeFalse()
            ->and($member->can('view', $team))->toBeTrue()
            ->and($ada->can('delete', $team))->toBeTrue()
            ->and($ada->can('transferOwnership', $team))->toBeTrue();
    });
});

describe('members page', function (): void {
    it('lists only the current team\'s members', function (): void {
        $ada = user('Ada');
        $mia = user('Mia');
        $bob = user('Bob');
        $alpha = team($ada, 'Alpha', ['member' => $mia]);
        $beta = team($bob, 'Beta');
        actingAs($ada);

        page(TeamMembers::class, $alpha)
            ->assertOk()
            ->assertCanSeeTableRecords([membershipOf($alpha, $ada), membershipOf($alpha, $mia)])
            ->assertCanNotSeeTableRecords([membershipOf($beta, $bob)])
            ->assertTableColumnFormattedStateSet('role', 'Member', membershipOf($alpha, $mia));
    });

    it('changes a member\'s role', function (): void {
        Event::fake([MemberRoleUpdated::class]);
        $ada = user('Ada');
        $mia = user('Mia');
        $team = team($ada, 'Alpha', ['member' => $mia]);
        actingAs($ada);

        page(TeamMembers::class, $team)
            ->callTableAction('changeRole', membershipOf($team, $mia), ['role' => 'admin'])
            ->assertHasNoActionErrors();

        expect($mia->teamRole($team)?->getName())->toBe('admin');
        Event::assertDispatched(MemberRoleUpdated::class, fn (MemberRoleUpdated $event): bool => $event->role === 'admin' && $event->previousRole === 'member');
    });

    it('hides member management from members without the permission', function (): void {
        $ada = user('Ada');
        $mia = user('Mia');
        $vic = user('Vic');
        $team = team($ada, 'Alpha', ['member' => $mia, 'viewer' => $vic]);
        actingAs($vic);

        page(TeamMembers::class, $team)
            ->assertOk()
            ->assertTableActionHidden('changeRole', membershipOf($team, $mia))
            ->assertTableActionHidden('removeMember', membershipOf($team, $mia))
            ->assertActionHidden('invite');

        expect(fn () => Teams::manager()->updateRole($team, $vic, $mia, 'admin'))->toThrow(AuthorizationException::class)
            ->and(fn () => Teams::manager()->removeMember($team, $vic, $mia))->toThrow(AuthorizationException::class);
    });

    it('lets only owners give or take the owner role', function (): void {
        $ada = user('Ada');
        $adam = user('Adam');
        $mia = user('Mia');
        $team = team($ada, 'Alpha', ['admin' => $adam, 'member' => $mia]);
        actingAs($adam);

        // Admins can't manage the owner, and can't make anyone an owner.
        page(TeamMembers::class, $team)
            ->assertTableActionHidden('changeRole', membershipOf($team, $ada))
            ->assertTableActionHidden('removeMember', membershipOf($team, $ada))
            ->assertTableActionVisible('changeRole', membershipOf($team, $mia));

        expect(fn () => Teams::manager()->updateRole($team, $adam, $mia, Role::OWNER))->toThrow(TeamException::class)
            ->and(fn () => Teams::manager()->updateRole($team, $adam, $ada, 'member'))->toThrow(TeamException::class)
            ->and(fn () => Teams::manager()->removeMember($team, $adam, $ada))->toThrow(TeamException::class);

        // An owner can promote someone to owner.
        Teams::manager()->updateRole($team, $ada, $mia, Role::OWNER);

        expect($mia->ownsTeam($team))->toBeTrue();
    });

    it('never demotes or removes the last owner, and nobody changes their own role', function (): void {
        $ada = user('Ada');
        $mia = user('Mia');
        $team = team($ada, 'Alpha', ['member' => $mia]);

        expect(fn () => Teams::manager()->updateRole($team, $ada, $ada, 'member'))->toThrow(TeamException::class, __('alfakit-teams::teams.errors.own_role'))
            ->and(fn () => Teams::manager()->leave($team, $ada))->toThrow(TeamException::class, __('alfakit-teams::teams.errors.last_owner_leave'));

        // With a second owner, one of them may go.
        Teams::manager()->updateRole($team, $ada, $mia, Role::OWNER);
        Teams::manager()->updateRole($team, $mia, $ada, 'admin');

        expect($ada->teamRole($team)?->getName())->toBe('admin')
            ->and(fn () => Teams::manager()->updateRole($team, $ada, $mia, 'member'))->toThrow(TeamException::class)
            ->and(fn () => Teams::manager()->leave($team, $mia))->toThrow(TeamException::class);
    });

    it('removes a member from the members page', function (): void {
        Event::fake([MemberRemoved::class]);
        $ada = user('Ada');
        $mia = user('Mia');
        $team = team($ada, 'Alpha', ['member' => $mia]);
        actingAs($ada);

        page(TeamMembers::class, $team)
            ->callTableAction('removeMember', membershipOf($team, $mia))
            ->assertHasNoActionErrors();

        expect($mia->belongsToTeam($team))->toBeFalse();
        Event::assertDispatched(MemberRemoved::class, fn (MemberRemoved $event): bool => $event->user->is($mia) && $event->removedBy?->is($ada) === true);
    });

    it('can\'t reach another team\'s memberships through the table', function (): void {
        $ada = user('Ada');
        $bob = user('Bob');
        $eve = user('Eve');
        $alpha = team($ada, 'Alpha');
        $beta = team($bob, 'Beta', ['member' => $eve]);
        actingAs($ada);

        $token = page(TeamMembers::class, $alpha)->getToken();

        $status = postJson('/teams/alpha/_alfakit/actions', ['token' => $token, 'component' => 'table', 'action' => 'removeMember', 'record' => membershipOf($beta, $eve)->id, 'data' => []])->getStatusCode();

        expect($status)->toBeGreaterThanOrEqual(400)
            ->and($eve->belongsToTeam($beta))->toBeTrue();

        // Nor through the other team's URL with this team's token.
        $status = postJson('/teams/beta/_alfakit/actions', ['token' => $token, 'component' => 'table', 'action' => 'removeMember', 'record' => membershipOf($beta, $eve)->id, 'data' => []])->getStatusCode();

        expect($status)->toBe(404)
            ->and($eve->belongsToTeam($beta))->toBeTrue();
    });

    it('lets a member leave the team', function (): void {
        $ada = user('Ada');
        $mia = user('Mia');
        $team = team($ada, 'Alpha', ['member' => $mia]);
        team($mia, 'Mia HQ');
        actingAs($mia);

        page(TeamMembers::class, $team)
            ->callAction('leave')
            ->assertRedirect('http://localhost/teams/mia-hq/team/members');

        expect($mia->belongsToTeam($team))->toBeFalse();
    });

    it('shows a notification when the last owner tries to leave', function (): void {
        $ada = user('Ada');
        $team = team($ada, 'Alpha');
        actingAs($ada);

        page(TeamMembers::class, $team)
            ->callAction('leave')
            ->assertNotified(__('alfakit-teams::teams.errors.last_owner_leave'));

        expect($ada->belongsToTeam($team))->toBeTrue();
    });
});

describe('ownership and deletion', function (): void {
    it('transfers ownership and keeps the old owner as an admin', function (): void {
        Event::fake([OwnershipTransferred::class]);
        $ada = user('Ada');
        $mia = user('Mia');
        $team = team($ada, 'Alpha', ['member' => $mia]);
        actingAs($ada);

        page(TeamSettings::class, $team)
            ->callAction('transferOwnership', ['user_id' => (string) $mia->getKey()])
            ->assertHasNoActionErrors();

        expect($mia->ownsTeam($team))->toBeTrue()
            ->and($ada->teamRole($team)?->getName())->toBe('admin');
        Event::assertDispatched(OwnershipTransferred::class);
    });

    it('only lets owners transfer ownership or delete the team', function (): void {
        $ada = user('Ada');
        $adam = user('Adam');
        $team = team($ada, 'Alpha', ['admin' => $adam]);
        actingAs($adam);

        page(TeamSettings::class, $team)
            ->assertActionDoesNotExist('transferOwnership')
            ->assertActionDoesNotExist('deleteTeam');

        expect(fn () => Teams::manager()->transferOwnership($team, $adam, $adam))->toThrow(AuthorizationException::class)
            ->and(fn () => Teams::manager()->deleteTeam($team, $adam))->toThrow(AuthorizationException::class);
    });

    it('deletes a team after typing its name', function (): void {
        Event::fake([TeamDeleted::class]);
        $ada = user('Ada');
        $mia = user('Mia');
        $team = team($ada, 'Alpha', ['member' => $mia]);
        team($ada, 'Beta');
        actingAs($ada);

        page(TeamSettings::class, $team)
            ->callAction('deleteTeam', ['confirmation' => 'Wrong'])
            ->assertHasActionErrors(['confirmation']);

        expect($team->fresh())->not->toBeNull();

        page(TeamSettings::class, $team)
            ->callAction('deleteTeam', ['confirmation' => 'Alpha'])
            ->assertHasNoActionErrors()
            ->assertRedirect('http://localhost/teams/beta/team/members');

        expect($team->fresh())->toBeNull()
            ->and(Membership::query()->where('team_id', $team->id)->count())->toBe(0);
        Event::assertDispatched(TeamDeleted::class);
    });

    it('never deletes a personal team', function (): void {
        $ada = user('Ada');
        $team = Teams::manager()->createPersonalTeam($ada);
        actingAs($ada);

        page(TeamSettings::class, $team)->assertActionDoesNotExist('deleteTeam');

        expect(fn () => Teams::manager()->deleteTeam($team, $ada))->toThrow(TeamException::class, __('alfakit-teams::teams.errors.personal_team'));
    });
});
