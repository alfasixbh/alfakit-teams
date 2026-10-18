<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTeams\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A user joined a team: created it, accepted an invitation, auto-joined by e-mail domain or was added.
 */
class MemberAdded
{
    use Dispatchable;

    public function __construct(
        public readonly Model $team,
        public readonly Model $user,
        public readonly string $role,
    ) {}
}
