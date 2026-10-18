<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTeams\Tests;

use AlfaSix\Alfakit\Testing\PageTester;
use AlfaSix\AlfakitTeams\Models\Team;
use AlfaSix\AlfakitTeams\Teams;
use AlfaSix\AlfakitTeams\TeamsPlugin;
use AlfaSix\AlfakitTeams\Tests\Fixtures\Models\TeamsUser;
use Illuminate\Testing\TestResponse;

/**
 * Perform an Inertia GET as the renderer does.
 *
 * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
 */
function inertiaGet(string $url): TestResponse
{
    return \Pest\Laravel\get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => '']);
}

function user(string $name = 'Ada Lovelace', ?string $email = null, bool $verified = true): TeamsUser
{
    return TeamsUser::query()->create([
        'name' => $name,
        'email' => $email ?? strtolower(str_replace(' ', '.', $name)).'.'.uniqid().'@example.com',
        'password' => 'password',
        'email_verified_at' => $verified ? now() : null,
    ]);
}

/**
 * A team owned by `$owner`, with extra members by role.
 *
 * @param  array<string, TeamsUser>  $members  role => user
 */
function team(TeamsUser $owner, string $name = 'Acme', array $members = []): Team
{
    $team = Teams::manager()->createTeam($owner, ['name' => $name]);

    foreach ($members as $role => $member) {
        Teams::manager()->addMember($team, $member, preg_replace('/\d+$/', '', $role));
    }

    assert($team instanceof Team);

    return $team;
}

function plugin(): TeamsPlugin
{
    return TeamsPlugin::get(alfakit()->getPanel('teams'));
}

/**
 * @param  class-string<\AlfaSix\Alfakit\Pages\Page>  $page
 */
function page(string $page, Team $team): PageTester
{
    return PageTester::make($page, ['tenant' => $team], 'teams');
}
