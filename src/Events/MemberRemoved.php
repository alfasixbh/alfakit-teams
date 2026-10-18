<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTeams\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A user left a team or was removed from it.
 */
class MemberRemoved
{
    use Dispatchable;

    public function __construct(
        public readonly Model $team,
        public readonly Model $user,
        public readonly ?Model $removedBy = null,
    ) {}
}
