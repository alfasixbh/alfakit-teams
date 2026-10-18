<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTeams\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A member's role changed (ownership transfers included).
 */
class MemberRoleUpdated
{
    use Dispatchable;

    public function __construct(
        public readonly Model $team,
        public readonly Model $user,
        public readonly string $role,
        public readonly ?string $previousRole = null,
    ) {}
}
