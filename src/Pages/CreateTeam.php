<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTeams\Pages;

use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Get;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Set;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tenancy\Pages\RegisterTenant;
use AlfaSix\AlfakitTeams\Teams;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * "Create team": the panel's tenant registration page. The creator becomes the owner.
 */
class CreateTeam extends RegisterTenant
{
    public static function getLabel(): string
    {
        return (string) __('alfakit-teams::teams.create.label');
    }

    public static function canAccess(): bool
    {
        return Teams::pluginOrDefault()->canCreateTeams();
    }

    public function getSubheading(): ?string
    {
        return (string) __('alfakit-teams::teams.create.description');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(static::getTeamFormComponents())->columns(1);
    }

    /**
     * The name and slug fields, shared with the team settings page.
     *
     * @return array<int, TextInput>
     */
    public static function getTeamFormComponents(?Model $team = null): array
    {
        $model = Teams::teamModel();
        $table = (new $model)->getTable();

        return [
            TextInput::make('name')
                ->label(__('alfakit-teams::teams.fields.name'))
                ->required()
                ->maxLength(255)
                ->autofocus($team === null)
                ->live(onBlur: true)
                ->afterStateUpdated(function (?string $state, ?string $old, Get $get, Set $set) use ($team): void {
                    // New teams: the slug follows the name until it is edited by hand.
                    $slug = $get('slug');

                    if ($team === null && (blank($slug) || $slug === Str::slug((string) $old))) {
                        $set('slug', Str::slug((string) $state));
                    }
                }),
            TextInput::make('slug')
                ->label(__('alfakit-teams::teams.fields.slug'))
                ->helperText(__('alfakit-teams::teams.fields.slug_help'))
                ->required()
                ->maxLength(64)
                ->alphaDash()
                ->rule('lowercase')
                ->unique(table: $table, column: 'slug', ignorable: $team),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRegistration(array $data): Model
    {
        $user = $this->getPanel()->auth()->user();

        abort_unless($user instanceof Model, 403);

        return Teams::manager()->createTeam($user, ['name' => $data['name'] ?? '', 'slug' => $data['slug'] ?? '']);
    }
}
