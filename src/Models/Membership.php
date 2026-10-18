<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTeams\Models;

use AlfaSix\AlfakitTeams\Role;
use AlfaSix\AlfakitTeams\Teams;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A user's membership of a team, with their role (`team_user`).
 *
 * @property int $id
 * @property int|string $team_id
 * @property int|string $user_id
 * @property string $role
 */
class Membership extends Pivot
{
    protected $table = 'team_user';

    public $incrementing = true;

    protected $guarded = [];

    /**
     * @return BelongsTo<Model, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Teams::teamModel(), 'team_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Teams::userModel(), 'user_id');
    }

    public function isOwner(): bool
    {
        return $this->role === Role::OWNER;
    }

    public function getRole(): ?Role
    {
        return Teams::pluginOrDefault()->getRole($this->role);
    }
}
