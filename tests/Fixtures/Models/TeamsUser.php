<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTeams\Tests\Fixtures\Models;

use AlfaSix\Alfakit\Contracts\HasDefaultTenant;
use AlfaSix\Alfakit\Contracts\HasTenants;
use AlfaSix\AlfakitTeams\Concerns\HasTeams;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class TeamsUser extends Authenticatable implements HasDefaultTenant, HasTenants
{
    use HasTeams;
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed'];
    }
}
