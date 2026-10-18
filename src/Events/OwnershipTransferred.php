<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTeams\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * `$from` handed ownership of the team to `$to` (and became an admin).
 */
class OwnershipTransferred
{
    use Dispatchable;

    public function __construct(
        public readonly Model $team,
        public readonly Model $from,
        public readonly Model $to,
    ) {}
}
