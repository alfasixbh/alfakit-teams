<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTeams\Models;

use AlfaSix\AlfakitTeams\Role;
use AlfaSix\AlfakitTeams\Teams;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A pending invitation to join a team. Accepting or revoking it deletes it.
 *
 * @property int $id
 * @property int|string $team_id
 * @property string $email
 * @property string $role
 * @property int|string|null $invited_by
 * @property Carbon|null $expires_at
 */
class TeamInvitation extends Model
{
    protected $table = 'team_invitations';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

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
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(Teams::userModel(), 'invited_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function getRole(): ?Role
    {
        return Teams::pluginOrDefault()->getRole($this->role);
    }

    /**
     * Whether `$email` is the address this invitation was sent to (case-insensitive).
     */
    public function isFor(?string $email): bool
    {
        return is_string($email) && mb_strtolower(trim($email)) === mb_strtolower(trim($this->email));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
