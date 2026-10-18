<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTeams\Events;

use AlfaSix\AlfakitTeams\Models\TeamInvitation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * `$user` accepted the invitation and joined the team (the invitation is then deleted).
 */
class InvitationAccepted
{
    use Dispatchable;

    public function __construct(
        public readonly TeamInvitation $invitation,
        public readonly Model $team,
        public readonly Model $user,
    ) {}
}
