<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Testing\PageTester;
use AlfaSix\AlfakitTeams\Events\InvitationAccepted;
use AlfaSix\AlfakitTeams\Events\InvitationSent;
use AlfaSix\AlfakitTeams\Exceptions\TeamException;
use AlfaSix\AlfakitTeams\Models\TeamInvitation;
use AlfaSix\AlfakitTeams\Notifications\TeamInvitationNotification;
use AlfaSix\AlfakitTeams\Pages\TeamInvitations;
use AlfaSix\AlfakitTeams\Pages\TeamMembers;
use AlfaSix\AlfakitTeams\Pages\TeamSettings;
use AlfaSix\AlfakitTeams\Role;
use AlfaSix\AlfakitTeams\Teams;
use AlfaSix\AlfakitTeams\Tests\Fixtures\Models\TeamsUser;
use AlfaSix\AlfakitTeams\Tests\Fixtures\Models\VerifyingTeamsUser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

use function AlfaSix\AlfakitTeams\Tests\inertiaGet;
use function AlfaSix\AlfakitTeams\Tests\page;
use function AlfaSix\AlfakitTeams\Tests\plugin;
use function AlfaSix\AlfakitTeams\Tests\team;
use function AlfaSix\AlfakitTeams\Tests\user;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(AlfaSix\AlfakitTeams\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    Notification::fake();
});

/**
 * The relative accept URL of an invitation (signed).
 */
function acceptPath(TeamInvitation $invitation): string
{
    return (string) str_replace('http://localhost', '', Teams::manager()->acceptUrl($invitation));
}

describe('sending', function (): void {
    it('invites by e-mail with a role from the members page and e-mails a signed link', function (): void {
        Event::fake([InvitationSent::class]);
        $ada = user('Ada');
        $team = team($ada, 'Alpha');
        actingAs($ada);

        page(TeamMembers::class, $team)
            ->callAction('invite', ['email' => 'Grace@Example.com', 'role' => 'admin'])
            ->assertHasNoActionErrors();

        $invitation = TeamInvitation::query()->sole();

        expect($invitation->email)->toBe('grace@example.com')
            ->and($invitation->role)->toBe('admin')
            ->and($invitation->invited_by)->toBe($ada->id)
            ->and($invitation->expires_at?->isSameDay(now()->addDays(7)))->toBeTrue();

        Notification::assertSentOnDemand(TeamInvitationNotification::class, function (TeamInvitationNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($invitation): bool {
            $mail = $notification->toMail($notifiable);

            return $notifiable->routes['mail'] === 'grace@example.com'
                && str_contains($notification->acceptUrl, '/teams/team-invitations/'.$invitation->id)
                && str_contains($notification->acceptUrl, 'signature=')
                && $mail->actionUrl === $notification->acceptUrl
                && str_contains((string) $mail->subject, 'Alpha');
        });
        Event::assertDispatched(InvitationSent::class, fn (InvitationSent $event): bool => ! $event->resent && $event->invitedBy?->is($ada) === true);

        expect(new TeamInvitationNotification($invitation, 'x'))->toBeInstanceOf(Illuminate\Contracts\Queue\ShouldQueue::class);
    });

    it('invites in apps that use immutable dates (the starter kits)', function (): void {
        Illuminate\Support\Facades\Date::use(Carbon\CarbonImmutable::class);

        try {
            $ada = user('Ada');
            $team = team($ada, 'Alpha');
            actingAs($ada);

            page(TeamMembers::class, $team)
                ->callAction('invite', ['email' => 'grace@example.com', 'role' => 'member'])
                ->assertHasNoActionErrors();

            expect(TeamInvitation::query()->sole()->expires_at?->isSameDay(now()->addDays(7)))->toBeTrue();
        } finally {
            Illuminate\Support\Facades\Date::useDefault();
        }
    });

    it('rejects members, pending invitations and unknown roles', function (): void {
        $ada = user('Ada');
        $mia = user('Mia', 'mia@example.com');
        $team = team($ada, 'Alpha', ['member' => $mia]);
        Teams::manager()->invite($team, $ada, 'grace@example.com');
        actingAs($ada);

        page(TeamMembers::class, $team)
            ->callAction('invite', ['email' => 'MIA@example.com', 'role' => 'member'])
            ->assertHasActionErrors(['email']);

        page(TeamMembers::class, $team)
            ->callAction('invite', ['email' => 'grace@example.com', 'role' => 'member'])
            ->assertHasActionErrors(['email']);

        page(TeamMembers::class, $team)
            ->callAction('invite', ['email' => 'linus@example.com', 'role' => 'emperor'])
            ->assertHasActionErrors(['role']);

        expect(TeamInvitation::query()->count())->toBe(1);
    });

    it('needs members:invite, and only owners invite owners', function (): void {
        $ada = user('Ada');
        $adam = user('Adam');
        $mia = user('Mia');
        $team = team($ada, 'Alpha', ['admin' => $adam, 'member' => $mia]);

        expect(fn () => Teams::manager()->invite($team, $mia, 'x@example.com'))->toThrow(AuthorizationException::class)
            ->and(fn () => Teams::manager()->invite($team, $adam, 'x@example.com', Role::OWNER))->toThrow(TeamException::class);

        actingAs($mia);
        page(TeamMembers::class, $team)->assertActionHidden('invite');
        inertiaGet('/teams/alpha/team/invitations')->assertForbidden();
    });

    it('limits pending invitations', function (): void {
        plugin()->invitationLimit(2);
        $ada = user('Ada');
        $team = team($ada, 'Alpha');

        Teams::manager()->invite($team, $ada, 'one@example.com');
        Teams::manager()->invite($team, $ada, 'two@example.com');

        expect(fn () => Teams::manager()->invite($team, $ada, 'three@example.com'))
            ->toThrow(TeamException::class, __('alfakit-teams::teams.errors.invitation_limit', ['limit' => 2]));
    });

    it('lists, resends and revokes invitations', function (): void {
        $ada = user('Ada');
        $team = team($ada, 'Alpha');
        $invitation = Teams::manager()->invite($team, $ada, 'grace@example.com');
        $invitation->forceFill(['expires_at' => now()->addDay()])->save();
        $bob = user('Bob');
        $other = Teams::manager()->invite(team($bob, 'Beta'), $bob, 'eve@example.com');
        actingAs($ada);

        $tester = page(TeamInvitations::class, $team)
            ->assertOk()
            ->assertCanSeeTableRecords([$invitation])
            ->assertCanNotSeeTableRecords([$other]);

        $tester->callTableAction('resend', $invitation)->assertHasNoActionErrors();

        expect($invitation->fresh()?->expires_at?->isSameDay(now()->addDays(7)))->toBeTrue();
        Notification::assertSentOnDemandTimes(TeamInvitationNotification::class, 3);

        // Resending again right away is throttled.
        $tester->callTableAction('resend', $invitation)->assertNotified(__('alfakit-teams::teams.errors.resend_throttled', ['seconds' => 60]));

        $tester->callTableAction('revoke', $invitation)->assertHasNoActionErrors();

        expect($invitation->fresh())->toBeNull()
            ->and($other->fresh())->not->toBeNull();
    });
});

describe('accepting', function (): void {
    it('sends guests to sign in, then lets them accept', function (): void {
        Event::fake([InvitationAccepted::class]);
        $ada = user('Ada');
        $team = team($ada, 'Alpha');
        $grace = user('Grace', 'grace@example.com');
        $invitation = Teams::manager()->invite($team, $ada, 'grace@example.com', 'admin');
        $path = acceptPath($invitation);

        get($path)->assertRedirect('http://localhost/teams/login');
        expect(session('url.intended'))->toBe('http://localhost'.$path);

        actingAs($grace);

        $tester = PageTester::make($path)->assertOk()->assertTitle('Join Alpha');
        $tester->callAction('accept')->assertRedirect('http://localhost/teams/alpha/team/members');

        expect($grace->teamRole($team)?->getName())->toBe('admin')
            ->and($invitation->fresh())->toBeNull()
            ->and($grace->fresh()?->current_team_id)->toBe($team->id);
        Event::assertDispatched(InvitationAccepted::class, fn (InvitationAccepted $event): bool => $event->user->is($grace) && $event->team->is($team));
    });

    it('accepts from the buttons as the renderers post them (field:infolist::{key})', function (): void {
        $ada = user('Ada');
        $team = team($ada, 'Alpha');
        $grace = user('Grace', 'grace@example.com');
        $invitation = Teams::manager()->invite($team, $ada, 'grace@example.com', 'member');

        actingAs($grace);

        $tester = PageTester::make(acceptPath($invitation))->assertOk();
        $actions = collect((array) ($tester->getContentBlock('content')['components'] ?? []))
            ->first(fn (mixed $component): bool => is_array($component) && ($component['type'] ?? null) === 'Actions');

        expect($actions)->toBeArray();

        // The Actions component runs its buttons as field actions; outside a form the
        // renderers name the schema `infolist` (packages/core fieldActionContext()).
        $response = \Pest\Laravel\postJson('/teams/_alfakit/actions', [
            'token' => $tester->getToken(),
            'component' => 'field:infolist::'.$actions['key'],
            'action' => 'accept',
        ]);

        $response->assertOk()->assertJsonPath('redirect', 'http://localhost/teams/alpha/team/members');

        expect($grace->teamRole($team)?->getName())->toBe('member')
            ->and($invitation->fresh())->toBeNull();
    });

    it('sends new addresses to the registration page and joins after registering', function (): void {
        $ada = user('Ada');
        $team = team($ada, 'Alpha');
        $invitation = Teams::manager()->invite($team, $ada, 'new@example.com');
        $path = acceptPath($invitation);

        get($path)->assertRedirect('http://localhost/teams/register');

        PageTester::make('/teams/register')
            ->fillForm(['name' => 'Newton', 'email' => 'new@example.com', 'password' => 'Secret-password-1', 'password_confirmation' => 'Secret-password-1'])
            ->call('register')
            ->assertRedirect('http://localhost'.$path);

        $user = TeamsUser::query()->where('email', 'new@example.com')->firstOrFail();
        actingAs($user);

        PageTester::make($path)->callAction('accept');

        expect($user->belongsToTeam($team))->toBeTrue()
            ->and($user->teamRole($team)?->getName())->toBe('member');
    });

    it('rejects links without a valid signature', function (): void {
        $ada = user('Ada');
        $invitation = Teams::manager()->invite(team($ada, 'Alpha'), $ada, 'grace@example.com');
        actingAs(user('Grace', 'grace@example.com'));

        get('/teams/team-invitations/'.$invitation->id)->assertForbidden();
        get(acceptPath($invitation).'x')->assertForbidden();
    });

    it('only lets the invited address accept', function (): void {
        $ada = user('Ada');
        $team = team($ada, 'Alpha');
        $invitation = Teams::manager()->invite($team, $ada, 'grace@example.com');
        $mallory = user('Mallory', 'mallory@example.com');
        actingAs($mallory);

        PageTester::make(acceptPath($invitation))
            ->assertOk()
            ->assertActionHidden('accept')
            ->assertActionVisible('switchAccount');

        expect(fn () => Teams::manager()->acceptInvitation($invitation, $mallory))->toThrow(TeamException::class, __('alfakit-teams::teams.errors.invitation_wrong_user'))
            ->and(fn () => Teams::manager()->declineInvitation($invitation, $mallory))->toThrow(TeamException::class)
            ->and($mallory->belongsToTeam($team))->toBeFalse()
            ->and($invitation->fresh())->not->toBeNull();
    });

    it('refuses expired invitations', function (): void {
        $ada = user('Ada');
        $team = team($ada, 'Alpha');
        $invitation = Teams::manager()->invite($team, $ada, 'grace@example.com');
        $grace = user('Grace', 'grace@example.com');
        $this->travel(8)->days();
        actingAs($grace);

        PageTester::make(acceptPath($invitation))->assertOk()->assertActionHidden('accept');

        expect(fn () => Teams::manager()->acceptInvitation($invitation, $grace))->toThrow(TeamException::class, __('alfakit-teams::teams.errors.invitation_expired'))
            ->and($grace->belongsToTeam($team))->toBeFalse();

        // An expired invitation can be replaced by a new one.
        expect(Teams::manager()->invite($team, $ada, 'grace@example.com')->isExpired())->toBeFalse();
    });

    it('returns not found for revoked invitations', function (): void {
        $ada = user('Ada');
        $invitation = Teams::manager()->invite(team($ada, 'Alpha'), $ada, 'grace@example.com');
        $path = acceptPath($invitation);
        Teams::manager()->revokeInvitation($invitation, $ada);
        actingAs(user('Grace', 'grace@example.com'));

        get($path)->assertNotFound();
    });

    it('declines an invitation', function (): void {
        $ada = user('Ada');
        $team = team($ada, 'Alpha');
        $invitation = Teams::manager()->invite($team, $ada, 'grace@example.com');
        $grace = user('Grace', 'grace@example.com');
        actingAs($grace);

        PageTester::make(acceptPath($invitation))->callAction('decline')->assertRedirect();

        expect($invitation->fresh())->toBeNull()->and($grace->belongsToTeam($team))->toBeFalse();
    });
});

describe('seats', function (): void {
    it('counts members and pending invitations against the seats', function (): void {
        plugin()->seats(fn (): int => 3);
        $ada = user('Ada');
        $team = team($ada, 'Alpha', ['member' => user('Mia')]);
        Teams::manager()->invite($team, $ada, 'one@example.com');

        expect(Teams::manager()->seatsRemaining($team))->toBe(0)
            ->and(fn () => Teams::manager()->invite($team, $ada, 'two@example.com'))->toThrow(TeamException::class, __('alfakit-teams::teams.errors.seats_full'));

        actingAs($ada);
        page(TeamMembers::class, $team)->assertOk();
        expect(page(TeamMembers::class, $team)->getProps('page.subheading'))->toBe('2 of 3 seats used');
    });

    it('enforces the seats when accepting', function (): void {
        $seats = 3;
        plugin()->seats(function () use (&$seats): int {
            return $seats;
        });
        $ada = user('Ada');
        $team = team($ada, 'Alpha', ['member' => user('Mia')]);
        $invitation = Teams::manager()->invite($team, $ada, 'grace@example.com');
        $grace = user('Grace', 'grace@example.com');

        // The plan shrank after the invitation was sent.
        $seats = 2;

        expect(fn () => Teams::manager()->acceptInvitation($invitation, $grace))->toThrow(TeamException::class, __('alfakit-teams::teams.errors.seats_full'))
            ->and($grace->belongsToTeam($team))->toBeFalse();

        $seats = 3;
        Teams::manager()->acceptInvitation($invitation, $grace);

        expect($grace->belongsToTeam($team))->toBeTrue();
    });
});

describe('domain auto-join', function (): void {
    it('joins verified users of the team\'s domain on registration', function (): void {
        $ada = user('Ada', 'ada@acme.test');
        $team = team($ada, 'Acme');
        $team->forceFill(['auto_join_domain' => 'acme.test'])->save();
        team(user('Bob', 'bob@other.test'), 'Other')->forceFill(['auto_join_domain' => 'other.test'])->save();

        $grace = user('Grace', 'grace@ACME.test');
        event(new Registered($grace));

        expect($grace->teamRole($team)?->getName())->toBe('member')
            ->and($grace->teams()->where('slug', 'other')->exists())->toBeFalse();
    });

    it('waits for e-mail verification on users that verify e-mail', function (): void {
        config()->set('auth.providers.users.model', VerifyingTeamsUser::class);
        $ada = user('Ada', 'ada@acme.test');
        $team = team($ada, 'Acme');
        $team->forceFill(['auto_join_domain' => 'acme.test'])->save();

        $grace = VerifyingTeamsUser::query()->create(['name' => 'Grace', 'email' => 'grace@acme.test', 'password' => 'password']);
        event(new Registered($grace));

        expect($grace->belongsToTeam($team))->toBeFalse();

        $grace->markEmailAsVerified();
        event(new Verified($grace));

        expect($grace->belongsToTeam($team))->toBeTrue();
    });

    it('respects the seats and can be turned off', function (): void {
        $ada = user('Ada', 'ada@acme.test');
        $team = team($ada, 'Acme');
        $team->forceFill(['auto_join_domain' => 'acme.test'])->save();

        plugin()->seats(fn (): int => 1);
        expect(Teams::manager()->autoJoin(user('Grace', 'grace@acme.test')))->toBe([]);

        plugin()->seats(null)->domainAutoJoin(false);
        expect(Teams::manager()->autoJoin(user('Linus', 'linus@acme.test')))->toBe([]);
    });

    it('only accepts the admin\'s own, non-public domain in the settings', function (): void {
        $ada = user('Ada', 'ada@acme.test');
        $team = team($ada, 'Acme');
        actingAs($ada);

        page(TeamSettings::class, $team)->fillForm(['auto_join_domain' => 'gmail.com'])->call('save')->assertHasFormErrors(['auto_join_domain']);
        page(TeamSettings::class, $team)->fillForm(['auto_join_domain' => 'evil.test'])->call('save')->assertHasFormErrors(['auto_join_domain']);
        page(TeamSettings::class, $team)->fillForm(['auto_join_domain' => '@ACME.test'])->call('save')->assertHasNoFormErrors();

        expect($team->fresh()?->auto_join_domain)->toBe('acme.test');
    });
});
