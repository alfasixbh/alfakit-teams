<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTeams;

/**
 * The permissions the teams plugin checks. Roles may also grant your own permissions
 * (`posts:create`), checked with `$user->hasTeamPermission($team, 'posts:create')`.
 *
 * A role grants a permission when it lists it, `*`, or a `prefix:*` wildcard (`members:*`).
 */
final class Permission
{
    /** Edit the team's name, slug, logo and auto-join domain. */
    public const UPDATE_TEAM = 'team:update';

    /** Invite people, resend and revoke invitations. */
    public const INVITE_MEMBERS = 'members:invite';

    /** Change members' roles (never to or from owner, which only owners can do). */
    public const UPDATE_MEMBERS = 'members:update';

    /** Remove members (never owners, which only owners can do). */
    public const REMOVE_MEMBERS = 'members:remove';

    /**
     * Every permission of the plugin itself.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [self::UPDATE_TEAM, self::INVITE_MEMBERS, self::UPDATE_MEMBERS, self::REMOVE_MEMBERS];
    }
}
