<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTeams\Models;

use AlfaSix\Alfakit\Contracts\HasAvatar;
use AlfaSix\AlfakitTeams\Concerns\IsTeam;
use Illuminate\Database\Eloquent\Model;

/**
 * The bundled team model. Extend it, or use `IsTeam` on your own model.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $logo_path
 * @property bool $personal_team
 * @property string|null $auto_join_domain
 */
class Team extends Model implements HasAvatar
{
    use IsTeam;

    protected $table = 'teams';

    protected $fillable = ['name', 'slug', 'logo_path', 'personal_team', 'auto_join_domain'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['personal_team' => 'boolean'];
    }
}
