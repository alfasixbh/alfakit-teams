<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTeams\Pages\Concerns;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Forms\Components\Radio;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Navigation\NavigationItem;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\AlfakitTeams\Exceptions\TeamException;
use AlfaSix\AlfakitTeams\Permission;
use AlfaSix\AlfakitTeams\Role;
use AlfaSix\AlfakitTeams\TeamManager;
use AlfaSix\AlfakitTeams\Teams;
use AlfaSix\AlfakitTeams\TeamsPlugin;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;

/**
 * Shared by the team settings, members and invitations pages: the current team, the
 * signed-in member, permission checks, the settings / members / invitations tabs and
 * turning `TeamException`s into notifications.
 */
trait InteractsWithTeam
{
    public function getTeam(): Model
    {
        $team = $this->getPanel()->getTenant();

        abort_unless($team instanceof Model, 404);

        return $team;
    }

    public function getMember(): Model&Authenticatable
    {
        $user = $this->getPanel()->auth()->user();

        abort_unless($user instanceof Model, 403);

        return $user;
    }

    protected function teams(): TeamManager
    {
        return Teams::manager();
    }

    protected function teamsPlugin(): TeamsPlugin
    {
        return Teams::pluginOrDefault($this->getPanel());
    }

    protected function memberCan(string $permission): bool
    {
        return $this->teams()->can($this->getMember(), $permission, $this->getTeam());
    }

    protected function isTeamOwner(): bool
    {
        return $this->teams()->isOwner($this->getTeam(), $this->getMember());
    }

    /**
     * Run a team operation, showing a rule violation as a danger notification and
     * keeping the action's modal open.
     */
    protected function attempt(Action $action, Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (TeamException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            $action->halt();
        }
    }

    /**
     * Role choices `$this` member may give: options, descriptions and the default.
     *
     * @return array{options: array<string, string>, descriptions: array<string, string>}
     */
    protected function getRoleChoices(): array
    {
        $options = [];
        $descriptions = [];

        foreach ($this->teamsPlugin()->getAssignableRoles($this->getTeam(), $this->getMember()) as $name => $role) {
            $options[$name] = $role->getLabel();

            if (($description = $role->getDescription()) !== null) {
                $descriptions[$name] = $description;
            }
        }

        return ['options' => $options, 'descriptions' => $descriptions];
    }

    protected function makeRoleField(?string $default = null): Radio
    {
        $choices = $this->getRoleChoices();

        return Radio::make('role')
            ->label(__('alfakit-teams::teams.fields.role'))
            ->options($choices['options'])
            ->descriptions($choices['descriptions'])
            ->default($default ?? $this->teamsPlugin()->getDefaultRole())
            ->in(array_keys($choices['options']))
            ->required();
    }

    /**
     * "Invite member": e-mail and role, with the seat count in the modal.
     */
    protected function makeInviteAction(): Action
    {
        return Action::make('invite')
            ->label(__('alfakit-teams::teams.invitations.invite'))
            ->icon('user-plus')
            ->modalIcon('user-plus')
            ->modalHeading(__('alfakit-teams::teams.invitations.invite_heading'))
            ->modalDescription(function (): string {
                $remaining = $this->teams()->seatsRemaining($this->getTeam());

                return $remaining === null
                    ? (string) __('alfakit-teams::teams.invitations.invite_description')
                    : trans_choice('alfakit-teams::teams.seats.remaining', $remaining, ['count' => $remaining]);
            })
            ->modalSubmitActionLabel(__('alfakit-teams::teams.invitations.send'))
            ->visible(fn (): bool => $this->teamsPlugin()->hasInvitations() && $this->memberCan(Permission::INVITE_MEMBERS))
            ->schema(fn (): array => [
                TextInput::make('email')
                    ->label(__('alfakit-teams::teams.fields.email'))
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->autofocus()
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        $problem = is_string($value) ? $this->teams()->invitationEmailProblem($this->getTeam(), $value) : null;

                        if ($problem !== null) {
                            $fail(__('alfakit-teams::teams.errors.'.$problem));
                        }
                    }),
                $this->makeRoleField(),
            ])
            ->action(function (array $data, Action $action): void {
                $this->attempt($action, fn () => $this->teams()->invite($this->getTeam(), $this->getMember(), (string) $data['email'], (string) ($data['role'] ?? '') ?: null));

                Notification::make()->success()
                    ->title(__('alfakit-teams::teams.invitations.sent', ['email' => mb_strtolower(trim((string) $data['email']))]))
                    ->send();
            });
    }

    protected function roleLabel(?string $role): string
    {
        if ($role === null) {
            return '';
        }

        return $this->teamsPlugin()->getRole($role)?->getLabel() ?? $role;
    }

    protected function roleColor(?string $role): string
    {
        return ($role === null ? null : $this->teamsPlugin()->getRole($role)?->getColor()) ?? ($role === Role::OWNER ? 'primary' : 'gray');
    }

    /**
     * Where to go after leaving or deleting the current team: the user's next team, else
     * the "Create team" page.
     */
    protected function redirectAwayFromTeam(): RedirectResponse
    {
        $panel = $this->getPanel();
        $user = $this->getMember();
        $current = $this->getTeam();

        $next = collect($panel->getUserTenants($user))->first(static fn (Model $team): bool => ! $team->is($current));

        if ($next instanceof Model) {
            return redirect()->to($panel->getUrl($next));
        }

        return redirect()->to($panel->getTenantRegistrationUrl() ?? url('/'));
    }

    /**
     * Settings, members and invitations, as tabs above each of these pages.
     *
     * @return array<NavigationItem>
     */
    public function getSubNavigation(): array
    {
        $panel = $this->getPanel();
        $plugin = $this->teamsPlugin();
        $route = static fn (string $name): bool => request()->routeIs($panel->generateRouteName($name));

        $items = [];

        if (($settingsUrl = $panel->getTenantProfileUrl()) !== null) {
            $items[] = NavigationItem::make(__('alfakit-teams::teams.settings.tab'))
                ->icon('settings')
                ->url($settingsUrl)
                ->isActiveWhen(static fn (): bool => $route('tenant.profile'));
        }

        $members = $plugin->getMembersPage();
        $items[] = NavigationItem::make(__('alfakit-teams::teams.members.label'))
            ->icon('users')
            ->url($members::getUrl(panel: $panel))
            ->badge($this->teams()->memberCount($this->getTeam()))
            ->isActiveWhen(static fn (): bool => $route($members::getRelativeRouteName()));

        if ($plugin->hasInvitations() && $this->memberCan(Permission::INVITE_MEMBERS)) {
            $invitations = $plugin->getInvitationsPage();
            $pending = $this->teams()->pendingInvitationCount($this->getTeam());
            $items[] = NavigationItem::make(__('alfakit-teams::teams.invitations.label'))
                ->icon('mail')
                ->url($invitations::getUrl(panel: $panel))
                ->badge($pending > 0 ? $pending : null)
                ->isActiveWhen(static fn (): bool => $route($invitations::getRelativeRouteName()));
        }

        return count($items) > 1 ? $items : [];
    }
}
