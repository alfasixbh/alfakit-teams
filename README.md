# AlfaKit Teams

<p><picture><source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/alfasixbh/alfakit/main/art/alfasix/alfa-six-micro-white.svg"><img src="https://raw.githubusercontent.com/alfasixbh/alfakit/main/art/alfasix/alfa-six-micro-navy.svg" alt="" width="24" height="18" align="top"></picture> <sub>by <a href="https://alfasixbh.com">Alfa Six</a></sub></p>

> This is a read-only split of the [AlfaKit monorepo](https://github.com/alfasixbh/alfakit/tree/main/packages/plugins/teams). Please open issues and pull requests there.

Jetstream-style teams for [AlfaKit](https://alfakit.dev) panels, built on AlfaKit's multi-tenancy: create team and team settings pages, personal teams, members with configurable roles and permissions, e-mail invitations with signed links, domain auto-join, seat limits and an optional seat hook for the AlfaKit billing plugin. It renders in React, Vue and Svelte with AlfaKit's built-in components.

## Installation

```bash
composer require alfasix/alfakit-teams
php artisan vendor:publish --tag=alfakit-teams-migrations
php artisan migrate
```

## Usage

```php
use AlfaSix\Alfakit\Contracts\HasDefaultTenant;
use AlfaSix\Alfakit\Contracts\HasTenants;
use AlfaSix\AlfakitTeams\Concerns\HasTeams;

class User extends Authenticatable implements HasDefaultTenant, HasTenants
{
    use HasTeams;
}
```

```php
use AlfaSix\AlfakitTeams\Permission;
use AlfaSix\AlfakitTeams\Role;
use AlfaSix\AlfakitTeams\TeamsPlugin;

$panel
    ->login()
    ->registration()
    ->plugin(
        TeamsPlugin::make()
            ->personalTeams()
            ->roles([
                Role::make('owner'),
                Role::make('admin')->permissions([Permission::UPDATE_TEAM, 'members:*']),
                Role::make('member')->permissions(['projects:create']),
            ])
            ->seats(fn ($team) => 10)
            ->domainAutoJoin(),
    );
```

Invitations are queued mail notifications: run a queue worker.

Read the full documentation at [alfakit.dev/docs/plugins/teams](https://alfakit.dev/docs/plugins/teams).

## License

MIT. See [LICENSE.md](LICENSE.md).
