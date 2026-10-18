<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTeams\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A team was created; `$owner` is its first owner (null for teams created without one).
 */
class TeamCreated
{
    use Dispatchable;

    public function __construct(
        public readonly Model $team,
        public readonly ?Model $owner = null,
    ) {}
}
