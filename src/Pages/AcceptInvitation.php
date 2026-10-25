<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTeams\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Schemas\Components\Actions;
use AlfaSix\Alfakit\Schemas\Components\Callout;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Enums\Color;
use AlfaSix\AlfakitTeams\Exceptions\TeamException;
use AlfaSix\AlfakitTeams\Models\TeamInvitation;
use AlfaSix\AlfakitTeams\TeamManager;
use AlfaSix\AlfakitTeams\Teams;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;

/**
 * The page behind the signed invitation link (`/{panel}/team-invitations/{invitation}`).
 * Guests sign in or register first and come back here; the signed-in user accepts or
 * declines. Only the invited address can accept, before the invitation expires, while the
 * team has a free seat.
 */
class AcceptInvitation extends Page
{
    public const ROUTE = 'teams.invitations.accept';

    protected static string $layout = 'auth';

    protected static bool $shouldRegisterNavigation = false;

    protected ?TeamInvitation $invitation = null;

    public static function getRelativeRouteName(): string
    {
        return self::ROUTE;
    }

    protected function flushCaches(): void
    {
        parent::flushCaches();

        $this->invitation = null;
    }

    public function mount(mixed ...$parameters): void
    {
        $invitation = Teams::invitationModel()::query()->find($parameters[0] ?? null);

        // Revoked, accepted or declined invitations are gone.
        abort_unless($invitation instanceof TeamInvitation && $invitation->team()->exists(), 404);

        $this->invitation = $invitation;
    }

    public function getInvitation(): TeamInvitation
    {
        abort_if($this->invitation === null, 404);

        return $this->invitation;
    }

    protected function getUser(): (Model&Authenticatable)|null
    {
        $user = $this->getPanel()->auth()->user();

        return $user instanceof Model ? $user : null;
    }

    protected function getTeamName(): string
    {
        $team = $this->getInvitation()->team()->first();

        return $team instanceof Model ? $this->getPanel()->getTenantLabel($team) : '';
    }

    protected function manager(): TeamManager
    {
        return Teams::manager();
    }

    public function getTitle(): string
    {
        return (string) __('alfakit-teams::teams.accept.title', ['team' => $this->getTeamName()]);
    }

    public function getSubheading(): ?string
    {
        $invitation = $this->getInvitation();
        $inviter = $invitation->inviter()->first();
        $role = $invitation->getRole()?->getLabel() ?? $invitation->role;
        $name = $inviter instanceof Model ? $inviter->getAttribute('name') : null;

        return is_string($name) && $name !== ''
            ? (string) __('alfakit-teams::teams.accept.description_by', ['inviter' => $name, 'team' => $this->getTeamName(), 'role' => $role])
            : (string) __('alfakit-teams::teams.accept.description', ['team' => $this->getTeamName(), 'role' => $role]);
    }

    /**
     * The accept and decline buttons are an `Actions` component in `content()`. The
     * renderers run such buttons as field actions of the `infolist` schema when they
     * are not inside a form, and this page has no infolist: resolve that name to the
     * content so the buttons work from the browser.
     */
    public function getSchema(string $name): ?Schema
    {
        if ($name === 'infolist') {
            return $this->getContentSchema();
        }

        return parent::getSchema($name);
    }

    public function content(Schema $schema): Schema
    {
        $invitation = $this->getInvitation();
        $user = $this->getUser();
        $problem = $this->manager()->invitationProblem($invitation, $user);
        $components = [];

        if ($problem !== null) {
            $components[] = Callout::make(__('alfakit-teams::teams.errors.'.$problem))
                ->description($problem === 'invitation_wrong_user'
                    ? __('alfakit-teams::teams.accept.wrong_user', ['email' => (string) $user?->getAttribute('email')])
                    : null)
                ->danger();
        }

        $components[] = Actions::make([
            Action::make('accept')
                ->label(__('alfakit-teams::teams.accept.accept'))
                ->color(Color::Primary)
                ->button()
                ->extraAttributes(['class' => 'w-full'])
                ->visible($problem === null)
                ->action(fn (Action $action): RedirectResponse => $this->accept($action)),
            Action::make('decline')
                ->label(__('alfakit-teams::teams.accept.decline'))
                ->color(Color::Gray)
                ->outlined()
                ->extraAttributes(['class' => 'w-full'])
                ->visible($problem !== 'invitation_wrong_user')
                ->requiresConfirmation()
                ->modalHeading(__('alfakit-teams::teams.accept.decline_heading', ['team' => $this->getTeamName()]))
                ->modalSubmitActionLabel(__('alfakit-teams::teams.accept.decline'))
                ->action(fn (Action $action): RedirectResponse => $this->decline($action)),
            Action::make('switchAccount')
                ->label(__('alfakit-teams::teams.accept.switch_account'))
                ->color(Color::Gray)
                ->link()
                ->visible($problem === 'invitation_wrong_user')
                ->url($this->getPanel()->getLogoutUrl())
                ->postToUrl(),
        ])->fullWidth();

        return $schema->components($components);
    }

    protected function accept(Action $action): RedirectResponse
    {
        $user = $this->getUser();

        abort_unless($user !== null, 403);

        try {
            $team = $this->manager()->acceptInvitation($this->getInvitation(), $user);
        } catch (TeamException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            $action->halt();
        }

        Notification::make()->success()->title(__('alfakit-teams::teams.accept.joined', ['team' => $this->getPanel()->getTenantLabel($team)]))->send();

        return redirect()->to($this->getPanel()->getUrl($team));
    }

    protected function decline(Action $action): RedirectResponse
    {
        $user = $this->getUser();

        abort_unless($user !== null, 403);

        try {
            $this->manager()->declineInvitation($this->getInvitation(), $user);
        } catch (TeamException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            $action->halt();
        }

        Notification::make()->title(__('alfakit-teams::teams.accept.declined'))->send();

        $next = $this->getPanel()->getDefaultTenant($user);

        return redirect()->to($next === null ? ($this->getPanel()->getTenantRegistrationUrl() ?? url('/')) : $this->getPanel()->getUrl($next));
    }
}
