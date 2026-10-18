<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTeams;

use AlfaSix\Alfakit\Events\TenantSet;
use AlfaSix\Alfakit\Mail\CoreMails;
use AlfaSix\Alfakit\Mail\MailDefinition;
use AlfaSix\Alfakit\Mail\Mails;
use AlfaSix\AlfakitTeams\Models\Team;
use AlfaSix\AlfakitTeams\Models\TeamInvitation;
use AlfaSix\AlfakitTeams\Notifications\TeamInvitationNotification;
use AlfaSix\AlfakitTeams\Policies\TeamPolicy;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AlfakitTeamsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TeamManager::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'alfakit-teams');

        static::registerPolicy(Team::class);

        static::registerMails();

        // New users: a personal team (`personalTeams()`) and domain auto-join.
        Event::listen(Registered::class, static function (Registered $event): void {
            $plugin = Teams::plugin();
            $user = $event->user;

            if ($plugin === null || ! $user instanceof Model) {
                return;
            }

            if ($plugin->hasPersonalTeams() && ! static::hasTeams($user)) {
                Teams::manager()->createPersonalTeam($user);
            }

            Teams::manager()->autoJoin($user);
        });

        // Verified addresses can auto-join (unverified ones never do on `MustVerifyEmail` users).
        Event::listen(Verified::class, static function (Verified $event): void {
            if (Teams::plugin() !== null && $event->user instanceof Model) {
                Teams::manager()->autoJoin($event->user);
            }
        });

        // Switching teams in the tenant menu remembers the team (`HasTeams::switchTeam()`).
        Event::listen(TenantSet::class, static function (TenantSet $event): void {
            $user = $event->user;

            if ($user instanceof Model && method_exists($user, 'switchTeam') && is_a($event->tenant, Teams::teamModel())) {
                $user->switchTeam($event->tenant);
            }
        });

        if ($this->app->runningInConsole()) {
            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'alfakit-teams-migrations');

            $this->publishes([
                __DIR__.'/../stubs/Team.php.stub' => app_path('Models/Team.php'),
            ], 'alfakit-teams-model');

            $this->publishes([
                __DIR__.'/../resources/lang' => $this->app->langPath('vendor/alfakit-teams'),
            ], 'alfakit-teams-translations');
        }
    }

    /**
     * Register `TeamPolicy` for the team model, unless the app registered its own.
     *
     * @param  class-string<Model>  $model
     */
    public static function registerPolicy(string $model): void
    {
        if (! array_key_exists($model, Gate::policies())) {
            Gate::policy($model, TeamPolicy::class);
        }
    }

    protected static function hasTeams(Model $user): bool
    {
        return Teams::membershipModel()::query()->where('user_id', $user->getKey())->exists();
    }

    /**
     * The invitation mail, for the mail preview page and editable templates.
     */
    protected static function registerMails(): void
    {
        Mails::register(
            MailDefinition::make(TeamInvitationNotification::TEMPLATE)
                ->label(static fn (): string => (string) __('alfakit-teams::teams.mail_templates.invitation'))
                ->group(static fn (): string => (string) __('alfakit-teams::teams.mail_templates.group'))
                ->preview(static function (): TeamInvitationNotification {
                    $notification = new TeamInvitationNotification(new TeamInvitation(['email' => 'grace@example.com', 'role' => 'member', 'expires_at' => now()->addDays(7)]), url('/team-invitations/preview'));
                    $notification->teamName = 'Acme';
                    $notification->inviterName = 'Ada Lovelace';

                    return $notification;
                })
                ->editable(
                    mergeTags: static fn (): array => CoreMails::tags(['team', 'inviter', 'role', 'button', 'url', 'expires', 'app']),
                    subject: static fn (): string => (string) __('alfakit-teams::teams.mail.subject', ['team' => '{{ team }}']),
                    body: static fn (): string => MailDefinition::paragraphs(
                        (string) __('alfakit-teams::teams.mail.greeting'),
                        (string) __('alfakit-teams::teams.mail.intro_by', ['inviter' => '{{ inviter }}', 'team' => '{{ team }}', 'role' => '{{ role }}']),
                        '{{ button }}',
                        (string) __('alfakit-teams::teams.mail.account'),
                        (string) __('alfakit-teams::teams.mail.expires', ['date' => '{{ expires }}']),
                        (string) __('alfakit-teams::teams.mail.ignore'),
                    ),
                ),
        );
    }
}
