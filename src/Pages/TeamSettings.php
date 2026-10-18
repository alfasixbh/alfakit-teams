<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTeams\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Forms\Components\FileUpload;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Navigation\SubNavigationPosition;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Enums\Color;
use AlfaSix\Alfakit\Tenancy\Pages\EditTenantProfile;
use AlfaSix\AlfakitTeams\Pages\Concerns\InteractsWithTeam;
use AlfaSix\AlfakitTeams\Permission;
use AlfaSix\AlfakitTeams\Role;
use AlfaSix\AlfakitTeams\Teams;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * "Team settings": the panel's tenant profile page. Name, slug, logo and auto-join domain
 * (`team:update`), leaving the team, transferring ownership and deleting the team
 * (owners only).
 */
class TeamSettings extends EditTenantProfile
{
    use InteractsWithTeam;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    public static function getLabel(): string
    {
        return (string) __('alfakit-teams::teams.settings.label');
    }

    public function getTitle(): string
    {
        return static::getLabel();
    }

    public function getSubheading(): ?string
    {
        return $this->getPanel()->getTenantLabel($this->getTeam());
    }

    public function form(Schema $schema): Schema
    {
        $plugin = $this->teamsPlugin();
        $team = $this->getTeam();
        $readOnly = fn (): bool => ! $this->memberCan(Permission::UPDATE_TEAM);

        $profile = [];

        if ($plugin->hasLogos()) {
            $profile[] = FileUpload::make('logo_path')
                ->label(__('alfakit-teams::teams.fields.logo'))
                ->image()
                ->avatar()
                ->disk($plugin->getLogoDisk())
                ->directory($plugin->getLogoDirectory())
                ->visibility('public')
                ->maxSize(2048)
                ->disabled($readOnly);
        }

        foreach (CreateTeam::getTeamFormComponents($team) as $component) {
            $profile[] = $component->disabled($readOnly);
        }

        $sections = [
            Section::make(__('alfakit-teams::teams.settings.profile_heading'))
                ->description(__('alfakit-teams::teams.settings.profile_description'))
                ->aside()
                ->schema($profile),
        ];

        if ($plugin->hasDomainAutoJoin()) {
            $sections[] = Section::make(__('alfakit-teams::teams.settings.auto_join_heading'))
                ->description(__('alfakit-teams::teams.settings.auto_join_description', ['role' => $plugin->getRole($plugin->getAutoJoinRole())?->getLabel() ?? $plugin->getAutoJoinRole()]))
                ->aside()
                ->schema([
                    TextInput::make('auto_join_domain')
                        ->label(__('alfakit-teams::teams.fields.auto_join_domain'))
                        ->helperText(__('alfakit-teams::teams.fields.auto_join_domain_help'))
                        ->prefix('@')
                        ->placeholder('example.com')
                        ->maxLength(255)
                        ->disabled($readOnly)
                        ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            $problem = $this->teams()->autoJoinDomainProblem(is_string($value) ? $value : null, $this->getMember());

                            if ($problem !== null) {
                                $fail(__('alfakit-teams::teams.errors.'.$problem));
                            }
                        }),
                ]);
        }

        return $schema->components($sections)->columns(1);
    }

    protected function getFormActions(): array
    {
        return array_map(
            fn (Action $action): Action => $action->visible(fn (): bool => $this->memberCan(Permission::UPDATE_TEAM)),
            parent::getFormActions(),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(array $data): mixed
    {
        $team = $this->getTeam();
        $slug = $team->getAttribute('slug');

        $attributes = array_intersect_key($data, array_flip(['name', 'slug', 'logo_path', 'auto_join_domain']));

        $this->teams()->updateTeam($team, $this->getMember(), $this->mutateFormDataBeforeSave($attributes));

        Notification::make()->success()->title(__('alfakit-teams::teams.settings.saved'))->send();

        // A new slug is a new URL.
        return $team->getAttribute('slug') === $slug
            ? back()
            : redirect()->to(static::getUrl(['tenant' => $team], panel: $this->getPanel()));
    }

    /**
     * The form, then leaving, ownership and deletion.
     */
    protected function getContentBlocks(): array
    {
        $blocks = parent::getContentBlocks();

        if (($content = $this->getContentSchema()) !== null) {
            $blocks[] = ['type' => 'Schema', 'key' => 'content', 'components' => $content->toSchema()];
        }

        return $blocks;
    }

    public function content(Schema $schema): Schema
    {
        $team = $this->getTeam();
        $isOwner = $this->isTeamOwner();
        $sections = [];

        $sections[] = Section::make(__('alfakit-teams::teams.leave.heading'))
            ->description(__('alfakit-teams::teams.leave.description'))
            ->aside()
            ->footerActions([$this->makeLeaveAction()]);

        if ($isOwner) {
            $sections[] = Section::make(__('alfakit-teams::teams.transfer.heading'))
                ->description(__('alfakit-teams::teams.transfer.description'))
                ->aside()
                ->footerActions([$this->makeTransferAction()]);
        }

        if ($isOwner && ! (bool) $team->getAttribute('personal_team')) {
            $sections[] = Section::make(__('alfakit-teams::teams.delete.heading'))
                ->description(__('alfakit-teams::teams.delete.description'))
                ->aside()
                ->footerActions([$this->makeDeleteAction()]);
        }

        return $schema->components($sections);
    }

    protected function makeLeaveAction(): Action
    {
        return Action::make('leaveTeam')
            ->label(__('alfakit-teams::teams.leave.action'))
            ->icon('log-out')
            ->color(Color::Danger)
            ->outlined()
            ->requiresConfirmation()
            ->modalHeading(__('alfakit-teams::teams.leave.confirm_heading', ['team' => $this->getPanel()->getTenantLabel($this->getTeam())]))
            ->modalDescription(__('alfakit-teams::teams.leave.confirm_description'))
            ->modalSubmitActionLabel(__('alfakit-teams::teams.leave.action'))
            ->action(function (Action $action): mixed {
                $redirect = $this->attempt($action, function () {
                    $this->teams()->leave($this->getTeam(), $this->getMember());

                    return $this->redirectAwayFromTeam();
                });

                Notification::make()->success()->title(__('alfakit-teams::teams.leave.done'))->send();

                return $redirect;
            });
    }

    protected function makeTransferAction(): Action
    {
        return Action::make('transferOwnership')
            ->label(__('alfakit-teams::teams.transfer.action'))
            ->icon('crown')
            ->outlined()
            ->modalHeading(__('alfakit-teams::teams.transfer.action'))
            ->modalDescription(__('alfakit-teams::teams.transfer.confirm_description'))
            ->modalSubmitActionLabel(__('alfakit-teams::teams.transfer.submit'))
            ->visible(fn (): bool => $this->isTeamOwner())
            ->schema([
                Select::make('user_id')
                    ->label(__('alfakit-teams::teams.transfer.new_owner'))
                    ->options(fn (): array => $this->getTransferCandidates())
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $data, Action $action): void {
                $user = Teams::userModel()::query()->find($data['user_id'] ?? null);

                // Only members of this team are candidates.
                if (! $user instanceof Model || ! array_key_exists((string) $user->getKey(), $this->getTransferCandidates())) {
                    $action->halt();
                }

                $this->attempt($action, fn () => $this->teams()->transferOwnership($this->getTeam(), $this->getMember(), $user));

                Notification::make()->success()->title(__('alfakit-teams::teams.transfer.done', ['name' => (string) $user->getAttribute('name')]))->send();
            });
    }

    /**
     * Members who aren't owners, by user key.
     *
     * @return array<string, string>
     */
    protected function getTransferCandidates(): array
    {
        $members = Teams::membershipModel()::query()
            ->where('team_id', $this->getTeam()->getKey())
            ->where('role', '!=', Role::OWNER)
            ->with('user')
            ->get();

        $options = [];

        foreach ($members as $membership) {
            $user = $membership->user;

            if ($user instanceof Model) {
                $options[(string) $user->getKey()] = trim((string) $user->getAttribute('name').' · '.(string) $user->getAttribute('email'), ' ·');
            }
        }

        return $options;
    }

    protected function makeDeleteAction(): Action
    {
        $name = (string) $this->getTeam()->getAttribute('name');

        return Action::make('deleteTeam')
            ->label(__('alfakit-teams::teams.delete.action'))
            ->icon('trash-2')
            ->color(Color::Danger)
            ->modalIcon('triangle-alert')
            ->modalHeading(__('alfakit-teams::teams.delete.confirm_heading', ['team' => $name]))
            ->modalDescription(__('alfakit-teams::teams.delete.confirm_description'))
            ->modalSubmitActionLabel(__('alfakit-teams::teams.delete.action'))
            ->visible(fn (): bool => $this->isTeamOwner() && ! (bool) $this->getTeam()->getAttribute('personal_team'))
            ->schema([
                TextInput::make('confirmation')
                    ->label(__('alfakit-teams::teams.delete.type_name', ['team' => $name]))
                    ->required()
                    ->in([$name])
                    ->validationMessages(['in' => __('alfakit-teams::teams.delete.name_mismatch')]),
            ])
            ->action(function (Action $action): mixed {
                $redirect = $this->attempt($action, function () {
                    $redirect = $this->redirectAwayFromTeam();
                    $this->teams()->deleteTeam($this->getTeam(), $this->getMember());

                    return $redirect;
                });

                Notification::make()->success()->title(__('alfakit-teams::teams.delete.done'))->send();

                return $redirect;
            });
    }
}
