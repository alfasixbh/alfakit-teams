<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTeams\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A team was deleted, with its memberships and invitations.
 */
class TeamDeleted
{
    use Dispatchable;

    public function __construct(
        public readonly Model $team,
        public readonly ?Model $deletedBy = null,
    ) {}
}
