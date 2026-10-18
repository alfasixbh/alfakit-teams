<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTeams\Events;

use AlfaSix\AlfakitTeams\Models\TeamInvitation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An invitation was sent, or sent again (`$resent`).
 */
class InvitationSent
{
    use Dispatchable;

    public function __construct(
        public readonly TeamInvitation $invitation,
        public readonly Model $team,
        public readonly ?Model $invitedBy = null,
        public readonly bool $resent = false,
    ) {}
}
