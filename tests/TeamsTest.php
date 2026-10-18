<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Testing\PageTester;
use AlfaSix\AlfakitTeams\Events\MemberAdded;
use AlfaSix\AlfakitTeams\Events\TeamCreated;
use AlfaSix\AlfakitTeams\Models\Team;
use AlfaSix\AlfakitTeams\Pages\CreateTeam;
use AlfaSix\AlfakitTeams\Pages\TeamMembers;
use AlfaSix\AlfakitTeams\Pages\TeamSettings;
use AlfaSix\AlfakitTeams\Role;
use AlfaSix\AlfakitTeams\Teams;
use AlfaSix\AlfakitTeams\Tests\Fixtures\Models\TeamsUser;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;

use function AlfaSix\AlfakitTeams\Tests\inertiaGet;
use function AlfaSix\AlfakitTeams\Tests\page;
use function AlfaSix\AlfakitTeams\Tests\plugin;
use function AlfaSix\AlfakitTeams\Tests\team;
use function AlfaSix\AlfakitTeams\Tests\user;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(AlfaSix\AlfakitTeams\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

it('configures the panel tenancy, registration and profile pages', function (): void {
    $panel = alfakit()->getPanel('teams');

    expect($panel->hasTenancy())->toBeTrue()
        ->and($panel->getTenantModel())->toBe(Team::class)
        ->and($panel->getTenantSlugAttribute())->toBe('slug')
        ->and($panel->hasTenantRegistration())->toBeTrue()
        ->and($panel->hasTenantProfile())->toBeTrue()
        ->and($panel->getPages())->toContain(TeamMembers::class);
});

it('creates a team on the create team page and makes the creator its owner', function (): void {
    Event::fake([TeamCreated::class, MemberAdded::class]);
    $ada = user('Ada');
    actingAs($ada);

    PageTester::make(CreateTeam::class, [], 'teams')
        ->assertOk()
        ->fillForm(['name' => 'Analytical Engines', 'slug' => 'engines'])
        ->call('register')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $team = Team::query()->where('slug', 'engines')->firstOrFail();

    expect($team->name)->toBe('Analytical Engines')
        ->and($ada->ownsTeam($team))->toBeTrue()
        ->and($ada->fresh()?->current_team_id)->toBe($team->id);

    Event::assertDispatched(TeamCreated::class, fn (TeamCreated $event): bool => $event->team->is($team) && $event->owner?->is($ada) === true);
    Event::assertDispatched(MemberAdded::class, fn (MemberAdded $event): bool => $event->role === Role::OWNER);
});

it('validates the team slug', function (): void {
    $ada = user('Ada');
    team($ada, 'Taken')->forceFill(['slug' => 'taken'])->save();
    actingAs($ada);

    PageTester::make(CreateTeam::class, [], 'teams')
        ->fillForm(['name' => 'Other', 'slug' => 'taken'])
        ->call('register')
        ->assertHasFormErrors(['slug']);

    PageTester::make(CreateTeam::class, [], 'teams')
        ->fillForm(['name' => 'Other', 'slug' => 'Not A Slug!'])
        ->call('register')
        ->assertHasFormErrors(['slug']);
});

it('turns team creation off', function (): void {
    actingAs(user('Ada'));
    plugin()->teamCreation(false);

    inertiaGet('/teams/new')->assertForbidden();
});

it('generates unique slugs', function (): void {
    $ada = user('Ada');

    expect(team($ada, 'Acme')->slug)->toBe('acme')
        ->and(team($ada, 'Acme')->slug)->toBe('acme-2')
        ->and(team($ada, 'Acme')->slug)->toBe('acme-3');
});

it('creates a personal team when a user registers', function (): void {
    $user = TeamsUser::query()->create(['name' => 'Grace Hopper', 'email' => 'grace@example.com', 'password' => 'password']);

    event(new Registered($user));

    $team = $user->personalTeam();

    expect($team)->toBeInstanceOf(Team::class)
        ->and($team?->name)->toBe("Grace's Team")
        ->and($team?->personal_team)->toBeTrue()
        ->and($user->ownsTeam($team))->toBeTrue();

    // Registering never gives a second personal team.
    event(new Registered($user));

    expect($user->teams()->count())->toBe(1);
});

it('names personal teams with a closure and can turn them off', function (): void {
    plugin()->personalTeams(true, fn (TeamsUser $user): string => 'HQ of '.$user->name);
    $user = user('Grace');
    event(new Registered($user));

    expect($user->personalTeam()?->name)->toBe('HQ of Grace');

    plugin()->personalTeams(false);
    $other = user('Linus');
    event(new Registered($other));

    expect($other->teams()->count())->toBe(0);
});

it('registers through the panel and lands in the personal team', function (): void {
    PageTester::make('/teams/register')
        ->fillForm(['name' => 'Grace Hopper', 'email' => 'grace@example.com', 'password' => 'Secret-password-1', 'password_confirmation' => 'Secret-password-1'])
        ->call('register')
        ->assertHasNoFormErrors();

    $user = TeamsUser::query()->where('email', 'grace@example.com')->firstOrFail();

    expect($user->personalTeam())->not->toBeNull();
});

it('lists the user\'s teams in the tenant switcher and remembers the current team', function (): void {
    $ada = user('Ada');
    $alpha = team($ada, 'Alpha');
    $beta = team($ada, 'Beta');
    $other = team(user('Bob'), 'Gamma');
    actingAs($ada);

    $tenancy = inertiaGet('/teams/alpha/team/members')->assertOk()->json('props.alfakit.tenancy');

    $names = array_column($tenancy['tenants'] ?? [], 'name');

    expect($names)->toContain('Alpha', 'Beta')->not->toContain('Gamma');

    // Switching to Beta remembers it as the current team, the panel root goes there.
    inertiaGet('/teams/beta/team/members')->assertOk();

    expect($ada->fresh()?->current_team_id)->toBe($beta->id)
        ->and(alfakit()->getPanel('teams')->getDefaultTenant($ada->fresh()))->toBeInstanceOf(Team::class)
        ->and(alfakit()->getPanel('teams')->getDefaultTenant($ada->fresh())?->is($beta))->toBeTrue();

    // Other teams' URLs are not found.
    inertiaGet('/teams/'.$other->slug.'/team/members')->assertNotFound();
    expect($alpha->exists)->toBeTrue();
});

it('adds members and settings entries to the tenant menu', function (): void {
    $ada = user('Ada');
    team($ada, 'Alpha');
    actingAs($ada);

    $menu = inertiaGet('/teams/alpha/team/members')->assertOk()->json('props.alfakit.tenancy');

    expect(json_encode($menu))->toContain('Members')->toContain('Team settings')->toContain('Create team');
});

it('updates the team settings and follows a new slug', function (): void {
    $ada = user('Ada');
    $team = team($ada, 'Alpha');
    actingAs($ada);

    page(TeamSettings::class, $team)
        ->assertOk()
        ->assertFormSet(['name' => 'Alpha', 'slug' => 'alpha'])
        ->fillForm(['name' => 'Alpha Squad', 'slug' => 'alpha-squad'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertRedirect('http://localhost/teams/alpha-squad/profile');

    expect($team->fresh()?->name)->toBe('Alpha Squad')->and($team->fresh()?->slug)->toBe('alpha-squad');
});

it('only lets members with team:update edit the settings', function (): void {
    $ada = user('Ada');
    $viewer = user('Vic');
    $team = team($ada, 'Alpha', ['viewer' => $viewer]);
    actingAs($viewer);

    page(TeamSettings::class, $team)
        ->assertOk()
        ->assertFormFieldDisabled('name')
        ->assertActionHidden('save');

    expect(fn () => Teams::manager()->updateTeam($team, $viewer, ['name' => 'Hacked']))->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
    expect($team->fresh()?->name)->toBe('Alpha');
});

it('does not open a team page for non-members', function (): void {
    $team = team(user('Ada'), 'Alpha');
    actingAs(user('Mallory'));

    get('/teams/alpha/profile')->assertNotFound();
    expect($team->exists)->toBeTrue();
});

it('leaves spatie roles alone when the shield plugin is not on the panel', function (): void {
    $ada = user('Ada');
    $team = team($ada, 'Alpha');

    expect(AlfaSix\AlfakitTeams\Integrations\ShieldRoles::isAvailable())->toBeFalse();

    // A no-op, not an error, for users without spatie's HasRoles.
    AlfaSix\AlfakitTeams\Integrations\ShieldRoles::sync($team, $ada, 'admin');
    expect($ada->ownsTeam($team))->toBeTrue();
});
