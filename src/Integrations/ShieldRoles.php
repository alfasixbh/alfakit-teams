<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTeams\Integrations;

use AlfaSix\AlfakitShield\ShieldPlugin;
use AlfaSix\AlfakitTeams\Teams;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * The bridge to `alfasix/alfakit-shield`: a member's team role is mirrored to the
 * spatie/laravel-permission role of the same name in the team's scope (spatie teams), so
 * the permissions edited in Shield's role resource apply to team members. Only roles that
 * exist in spatie are assigned; other roles a member was given in Shield are kept.
 *
 * A no-op unless the shield plugin is installed and on the teams panel, `permission.teams`
 * is on, and the user model uses spatie's `HasRoles`.
 */
final class ShieldRoles
{
    public static function isAvailable(): bool
    {
        if (! class_exists(ShieldPlugin::class) || ! class_exists(PermissionRegistrar::class) || ! (bool) config('permission.teams')) {
            return false;
        }

        $plugin = Teams::plugin();
        $panel = Teams::panel();

        return $plugin !== null && $plugin->syncsShieldRoles() && $panel !== null && $panel->hasPlugin(ShieldPlugin::ID);
    }

    /**
     * Give the user the spatie role `$role` in the team's scope (null: none of the team
     * roles).
     */
    public static function sync(Model $team, Model $user, ?string $role): void
    {
        if (! self::isAvailable() || ! method_exists($user, 'assignRole') || ! method_exists($user, 'removeRole') || ! method_exists($user, 'hasRole')) {
            return;
        }

        $panel = Teams::panel();
        $shield = $panel === null ? null : ShieldPlugin::get($panel);

        if ($panel === null || $shield === null) {
            return;
        }

        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $guard = $shield->getGuard($panel);
        $roleClass = $registrar->getRoleClass();

        try {
            $registrar->setPermissionsTeamId($team->getKey());
            $user->unsetRelation('roles');

            $teamRoles = array_keys(Teams::pluginOrDefault()->getRoles());
            $existing = $roleClass::query()->whereIn('name', $teamRoles)->where('guard_name', $guard)->pluck('name')->all();

            foreach ($existing as $name) {
                if ($name !== $role && $user->hasRole($name, $guard)) {
                    $user->removeRole($name);
                }
            }

            if ($role !== null && in_array($role, $existing, true) && ! $user->hasRole($role, $guard)) {
                $user->assignRole($roleClass::findByName($role, $guard));
            }
        } catch (Throwable $exception) {
            report($exception);
        } finally {
            $registrar->setPermissionsTeamId($previous);
            $user->unsetRelation('roles');
        }
    }
}
