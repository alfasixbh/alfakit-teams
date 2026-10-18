<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTeams\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Navigation\SubNavigationPosition;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Support\Enums\Color;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\AlfakitTeams\Models\TeamInvitation;
use AlfaSix\AlfakitTeams\Pages\Concerns\InteractsWithTeam;
use AlfaSix\AlfakitTeams\Permission;
use AlfaSix\AlfakitTeams\Teams;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Pending (and expired) invitations of the current team: invite, resend, revoke. Needs
 * `members:invite`.
 */
class TeamInvitations extends Page
{
    use InteractsWithTeam;

    protected static ?string $slug = 'team-invitations';

    protected static ?string $routePath = '/team/invitations';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    public static function canAccess(): bool
    {
        $team = alfakit()->getTenant();
        $user = alfakit()->getCurrentOrDefaultPanel()->auth()->user();

        return Teams::pluginOrDefault()->hasInvitations()
            && $team instanceof Model
            && $user instanceof Model
            && Teams::manager()->can($user, Permission::INVITE_MEMBERS, $team);
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('alfakit-teams::teams.invitations.label');
    }

    public function getTitle(): string
    {
        return (string) __('alfakit-teams::teams.invitations.title');
    }

    public function getSubheading(): ?string
    {
        $remaining = $this->teams()->seatsRemaining($this->getTeam());

        return $remaining === null
            ? (string) __('alfakit-teams::teams.invitations.description')
            : trans_choice('alfakit-teams::teams.seats.remaining', $remaining, ['count' => $remaining]);
    }

    protected function getHeaderActions(): array
    {
        return [$this->makeInviteAction()];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Teams::invitationModel()::query()
                ->where('team_id', $this->getTeam()->getKey())
                ->with('inviter'))
            ->heading(__('alfakit-teams::teams.invitations.title'))
            ->defaultSort('created_at', 'desc')
            ->emptyStateIcon('mail')
            ->emptyStateHeading(__('alfakit-teams::teams.invitations.empty_heading'))
            ->emptyStateDescription(__('alfakit-teams::teams.invitations.empty_description'))
            ->columns([
                TextColumn::make('email')
                    ->label(__('alfakit-teams::teams.fields.email'))
                    ->weight('medium')
                    ->searchable()
                    ->description(fn (TeamInvitation $record): ?string => ($name = $record->inviter?->getAttribute('name')) === null ? null : (string) __('alfakit-teams::teams.invitations.invited_by', ['name' => (string) $name])),
                TextColumn::make('role')
                    ->label(__('alfakit-teams::teams.fields.role'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $this->roleLabel($state))
                    ->color(fn (?string $state): string => $this->roleColor($state)),
                TextColumn::make('status')
                    ->label(__('alfakit-teams::teams.fields.status'))
                    ->badge()
                    ->getStateUsing(static fn (TeamInvitation $record): string => $record->isExpired() ? 'expired' : 'pending')
                    ->formatStateUsing(static fn (string $state): string => (string) __('alfakit-teams::teams.invitations.status.'.$state))
                    ->color(static fn (string $state): string => $state === 'expired' ? 'danger' : 'warning'),
                TextColumn::make('expires_at')
                    ->label(__('alfakit-teams::teams.fields.expires'))
                    ->since()
                    ->placeholder(__('alfakit-teams::teams.invitations.never'))
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('alfakit-teams::teams.fields.sent'))
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('resend')
                    ->label(__('alfakit-teams::teams.invitations.resend'))
                    ->icon('send')
                    ->iconButton()
                    ->action(function (TeamInvitation $record, Action $action): void {
                        $this->attempt($action, fn () => $this->teams()->resendInvitation($record, $this->getMember()));

                        Notification::make()->success()->title(__('alfakit-teams::teams.invitations.resent', ['email' => $record->email]))->send();
                    }),
                Action::make('revoke')
                    ->label(__('alfakit-teams::teams.invitations.revoke'))
                    ->icon('x')
                    ->iconButton()
                    ->color(Color::Danger)
                    ->requiresConfirmation()
                    ->modalHeading(fn (TeamInvitation $record): string => (string) __('alfakit-teams::teams.invitations.revoke_heading', ['email' => $record->email]))
                    ->modalDescription(__('alfakit-teams::teams.invitations.revoke_description'))
                    ->modalSubmitActionLabel(__('alfakit-teams::teams.invitations.revoke'))
                    ->action(function (TeamInvitation $record, Action $action): void {
                        $this->attempt($action, fn () => $this->teams()->revokeInvitation($record, $this->getMember()));

                        Notification::make()->success()->title(__('alfakit-teams::teams.invitations.revoked', ['email' => $record->email]))->send();
                    }),
            ]);
    }
}
