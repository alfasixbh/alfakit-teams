<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTeams\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Navigation\SubNavigationPosition;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Support\Enums\Color;
use AlfaSix\Alfakit\Tables\Columns\ImageColumn;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Filters\SelectFilter;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\AlfakitTeams\Models\Membership;
use AlfaSix\AlfakitTeams\Pages\Concerns\InteractsWithTeam;
use AlfaSix\AlfakitTeams\Permission;
use AlfaSix\AlfakitTeams\Teams;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The members of the current team with their roles: change a role, remove a member,
 * invite people and leave the team. Every member can see the list.
 */
class TeamMembers extends Page
{
    use InteractsWithTeam;

    protected static ?string $slug = 'team-members';

    protected static ?string $routePath = '/team/members';

    protected static ?string $navigationIcon = 'users';

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    public static function getNavigationLabel(): string
    {
        return (string) __('alfakit-teams::teams.members.label');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return Teams::pluginOrDefault()->getNavigationGroup();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return Teams::pluginOrDefault()->hasMembersNavigation();
    }

    public static function canAccess(): bool
    {
        $team = alfakit()->getTenant();
        $user = alfakit()->getCurrentOrDefaultPanel()->auth()->user();

        return $team instanceof Model && $user instanceof Model && Teams::manager()->membership($team, $user) !== null;
    }

    public function getTitle(): string
    {
        return (string) __('alfakit-teams::teams.members.title');
    }

    public function getSubheading(): ?string
    {
        $team = $this->getTeam();
        $count = $this->teams()->memberCount($team);
        $seats = $this->teamsPlugin()->getSeats($team);

        return $seats === null
            ? trans_choice('alfakit-teams::teams.members.count', $count, ['count' => $count])
            : (string) __('alfakit-teams::teams.seats.used', ['used' => $count, 'seats' => $seats]);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->makeInviteAction(),
            Action::make('leave')
                ->label(__('alfakit-teams::teams.leave.action'))
                ->icon('log-out')
                ->color(Color::Gray)
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
                }),
        ];
    }

    public function table(Table $table): Table
    {
        $panel = $this->getPanel();
        $me = $this->getMember();
        $roles = [];

        foreach ($this->teamsPlugin()->getRoles() as $name => $role) {
            $roles[$name] = $role->getLabel();
        }

        return $table
            ->query(fn (): Builder => Teams::membershipModel()::query()
                ->where('team_id', $this->getTeam()->getKey())
                ->with('user'))
            ->heading(__('alfakit-teams::teams.members.title'))
            ->defaultSort('created_at')
            ->columns([
                ImageColumn::make('avatar')
                    ->label(__('alfakit-teams::teams.fields.avatar'))
                    ->circular()
                    ->size(36)
                    ->getStateUsing(static fn (Membership $record): ?string => $record->user instanceof Authenticatable ? $panel->getUserAvatarUrl($record->user) : null),
                TextColumn::make('user.name')
                    ->label(__('alfakit-teams::teams.fields.member'))
                    ->weight('medium')
                    ->formatStateUsing(static fn (mixed $state, Membership $record): string => (string) $state.((string) $record->user_id === (string) $me->getKey() ? ' '.__('alfakit-teams::teams.members.you') : ''))
                    ->description(static fn (Membership $record): ?string => $record->user?->getAttribute('email'))
                    ->searchable(query: static fn (Builder $query, string $search): Builder => $query->whereHas('user', static fn (Builder $query) => $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"))),
                TextColumn::make('role')
                    ->label(__('alfakit-teams::teams.fields.role'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $this->roleLabel($state))
                    ->color(fn (?string $state): string => $this->roleColor($state)),
                TextColumn::make('created_at')
                    ->label(__('alfakit-teams::teams.fields.joined'))
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->label(__('alfakit-teams::teams.fields.role'))
                    ->options($roles),
            ])
            ->recordActions([
                $this->makeChangeRoleAction(),
                $this->makeRemoveAction(),
            ]);
    }

    protected function canManage(Membership $membership, string $permission): bool
    {
        $me = $this->getMember();

        if ((string) $membership->user_id === (string) $me->getKey() || ! $this->memberCan($permission)) {
            return false;
        }

        return ! $membership->isOwner() || $this->isTeamOwner();
    }

    protected function makeChangeRoleAction(): Action
    {
        return Action::make('changeRole')
            ->label(__('alfakit-teams::teams.members.change_role'))
            ->icon('user-cog')
            ->iconButton()
            ->modalHeading(fn (Membership $record): string => (string) __('alfakit-teams::teams.members.change_role_heading', ['name' => (string) $record->user?->getAttribute('name')]))
            ->modalSubmitActionLabel(__('alfakit-teams::teams.members.change_role'))
            ->visible(fn (Membership $record): bool => $this->canManage($record, Permission::UPDATE_MEMBERS))
            ->fillForm(fn (Membership $record): array => ['role' => $record->role])
            ->schema(fn (Membership $record): array => [$this->makeRoleField($record->role)])
            ->action(function (Membership $record, array $data, Action $action): void {
                $user = $record->user;

                if (! $user instanceof Model) {
                    $action->halt();
                }

                $this->attempt($action, fn () => $this->teams()->updateRole($this->getTeam(), $this->getMember(), $user, (string) $data['role']));

                Notification::make()->success()->title(__('alfakit-teams::teams.members.role_updated', ['name' => (string) $user->getAttribute('name'), 'role' => $this->roleLabel((string) $data['role'])]))->send();
            });
    }

    protected function makeRemoveAction(): Action
    {
        return Action::make('removeMember')
            ->label(__('alfakit-teams::teams.members.remove'))
            ->icon('user-minus')
            ->iconButton()
            ->color(Color::Danger)
            ->requiresConfirmation()
            ->modalHeading(fn (Membership $record): string => (string) __('alfakit-teams::teams.members.remove_heading', ['name' => (string) $record->user?->getAttribute('name')]))
            ->modalDescription(__('alfakit-teams::teams.members.remove_description'))
            ->modalSubmitActionLabel(__('alfakit-teams::teams.members.remove'))
            ->visible(fn (Membership $record): bool => $this->canManage($record, Permission::REMOVE_MEMBERS))
            ->action(function (Membership $record, Action $action): void {
                $user = $record->user;

                if (! $user instanceof Model) {
                    $action->halt();
                }

                $this->attempt($action, fn () => $this->teams()->removeMember($this->getTeam(), $this->getMember(), $user));

                Notification::make()->success()->title(__('alfakit-teams::teams.members.removed', ['name' => (string) $user->getAttribute('name')]))->send();
            });
    }
}
