<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTeams;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Contracts\Plugin;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\AlfakitTeams\Http\Middleware\RedirectGuestsToInvitationAuth;
use AlfaSix\AlfakitTeams\Integrations\BillingSeats;
use AlfaSix\AlfakitTeams\Models\Team;
use AlfaSix\AlfakitTeams\Notifications\TeamInvitationNotification;
use AlfaSix\AlfakitTeams\Pages\AcceptInvitation;
use AlfaSix\AlfakitTeams\Pages\CreateTeam;
use AlfaSix\AlfakitTeams\Pages\TeamInvitations;
use AlfaSix\AlfakitTeams\Pages\TeamMembers;
use AlfaSix\AlfakitTeams\Pages\TeamSettings;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Routing\Router;
use InvalidArgumentException;

/**
 * Jetstream-style teams on top of the panel's multi-tenancy.
 *
 * ```php
 * $panel->plugin(
 *     TeamsPlugin::make()
 *         ->personalTeams()
 *         ->roles([
 *             Role::make('owner'),
 *             Role::make('admin')->permissions([Permission::UPDATE_TEAM, 'members:*']),
 *             Role::make('member')->permissions(['posts:create']),
 *         ])
 *         ->seats(fn (Team $team): int => 10)
 *         ->domainAutoJoin(),
 * );
 * ```
 *
 * The plugin makes the team model the panel's tenant (`/app/{team:slug}`), uses its
 * pages as the tenant registration ("Create team") and tenant profile ("Team settings")
 * pages, and adds the members and invitations pages and the invitation accept route.
 */
class TeamsPlugin implements Plugin
{
    use EvaluatesClosures;

    public const ID = 'teams';

    /** @var class-string<Model> */
    protected string $teamModel = Team::class;

    protected bool $configuresTenancy = true;

    protected string $ownershipRelationship = 'team';

    /** @var array<int, Role>|null */
    protected ?array $roles = null;

    protected string $defaultRole = 'member';

    protected bool|Closure $hasPersonalTeams = false;

    protected ?Closure $personalTeamNameUsing = null;

    protected bool|Closure $canCreateTeams = true;

    protected bool $hasInvitations = true;

    protected int|Closure|null $invitationExpiryDays = 7;

    protected int|Closure|null $invitationLimit = null;

    /** @var class-string<\Illuminate\Notifications\Notification> */
    protected string $invitationNotification = TeamInvitationNotification::class;

    protected bool|Closure $hasDomainAutoJoin = false;

    protected string $autoJoinRole = 'member';

    /** @var array<int, string> */
    protected array $blockedAutoJoinDomains = [
        'gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.co.uk', 'outlook.com', 'hotmail.com', 'live.com', 'msn.com',
        'icloud.com', 'me.com', 'mac.com', 'aol.com', 'proton.me', 'protonmail.com', 'gmx.com', 'gmx.de', 'web.de',
        'yandex.com', 'yandex.ru', 'mail.ru', 'qq.com', '163.com', 'zoho.com', 'hey.com', 'fastmail.com',
    ];

    protected ?Closure $seatsUsing = null;

    protected bool|Closure $syncsBillingQuantity = false;

    protected ?Closure $syncBillingQuantityUsing = null;

    protected bool|Closure $syncsShieldRoles = true;

    protected bool|Closure $hasLogos = true;

    protected ?string $logoDisk = null;

    protected string $logoDirectory = 'team-logos';

    protected bool|Closure $hasTenantMenuItems = true;

    protected bool|Closure $hasMembersNavigation = false;

    protected string|Closure|null $navigationGroup = null;

    /** @var class-string<CreateTeam> */
    protected string $createTeamPage = CreateTeam::class;

    /** @var class-string<TeamSettings> */
    protected string $settingsPage = TeamSettings::class;

    /** @var class-string<TeamMembers> */
    protected string $membersPage = TeamMembers::class;

    /** @var class-string<TeamInvitations> */
    protected string $invitationsPage = TeamInvitations::class;

    /** @var class-string<AcceptInvitation> */
    protected string $acceptInvitationPage = AcceptInvitation::class;

    /** @var array<string, true> */
    protected array $bootedPanels = [];

    final public function __construct() {}

    public static function make(): static
    {
        return new static;
    }

    /**
     * The plugin of the current panel, else the first panel that has it.
     */
    public static function get(?Panel $panel = null): static
    {
        $plugin = Teams::plugin($panel);

        if (! $plugin instanceof static) {
            throw new InvalidArgumentException('The teams plugin is not registered on any panel.');
        }

        return $plugin;
    }

    public function getId(): string
    {
        return self::ID;
    }

    protected function getEvaluationIdentifier(): string
    {
        return 'plugin';
    }

    /**
     * @return array<mixed>
     */
    protected function resolveDefaultClosureDependencyForEvaluationByName(string $parameterName): array
    {
        $panel = alfakit()->getCurrentPanel() ?? Teams::panel();

        return match ($parameterName) {
            'panel' => [$panel],
            'user' => [$panel?->auth()->user()],
            'tenant' => [alfakit()->getTenant()],
            default => [],
        };
    }

    public function register(Panel $panel): void
    {
        Teams::useTeamModel($this->teamModel);
        AlfakitTeamsServiceProvider::registerPolicy($this->teamModel);

        if ($this->configuresTenancy) {
            $panel->tenant($this->teamModel, slugAttribute: 'slug', ownershipRelationship: $this->ownershipRelationship);
        }

        $panel
            ->tenantRegistration($this->createTeamPage)
            ->tenantProfile($this->settingsPage)
            ->pages($this->hasInvitations ? [$this->membersPage, $this->invitationsPage] : [$this->membersPage])
            ->routes(function (Router $router, Panel $panel): void {
                if (! $this->hasInvitations) {
                    return;
                }

                $router->get('/team-invitations/{invitation}', $this->acceptInvitationPage)
                    ->name(AcceptInvitation::ROUTE)
                    ->middleware([
                        ValidateSignature::class,
                        RedirectGuestsToInvitationAuth::class.':'.$panel->getId(),
                        ...$panel->getAuthMiddleware(),
                    ]);
            });
    }

    public function boot(Panel $panel): void
    {
        if (isset($this->bootedPanels[$panel->getId()]) || ! $panel->hasTenancy()) {
            return;
        }

        $this->bootedPanels[$panel->getId()] = true;

        // Add "Members" to whatever tenant menu items the panel was configured with, and
        // hide "Create team" when team creation is off.
        $items = (fn (): array|Closure => $this->tenantMenuItems)->call($panel);

        $panel->tenantMenuItems(function () use ($panel, $items): array {
            $configured = (array) ($panel->evaluate($items) ?? []);

            if (! array_key_exists('register', $configured)) {
                $configured['register'] = fn (Action $action): Action => $action
                    ->label(__('alfakit-teams::teams.create.label'))
                    ->icon('plus')
                    ->visible(fn (): bool => $this->canCreateTeams());
            }

            if (! array_key_exists('profile', $configured)) {
                $configured['profile'] = fn (Action $action): Action => $action
                    ->label(__('alfakit-teams::teams.settings.label'))
                    ->icon('settings');
            }

            if (! $this->hasTenantMenuItems() || $panel->getTenant() === null) {
                return $configured;
            }

            $members = $this->membersPage;

            return [
                Action::make('teamMembers')
                    ->label(__('alfakit-teams::teams.members.label'))
                    ->icon('users')
                    ->url(fn (): string => $members::getUrl(panel: $panel)),
                ...$configured,
            ];
        });
    }

    // ---------------------------------------------------------------- Models & tenancy

    /**
     * Your own team model (with `IsTeam` and the migration's columns). Defaults to
     * `AlfaSix\AlfakitTeams\Models\Team`.
     *
     * @param  class-string<Model>  $model
     */
    public function teamModel(string $model): static
    {
        if (! is_a($model, Model::class, true) || ! in_array(Concerns\IsTeam::class, class_uses_recursive($model), true)) {
            throw new InvalidArgumentException("The team model [{$model}] must be an Eloquent model that uses [".Concerns\IsTeam::class.'].');
        }

        $this->teamModel = $model;

        return $this;
    }

    /**
     * @return class-string<Model>
     */
    public function getTeamModel(): string
    {
        return $this->teamModel;
    }

    /**
     * Keep the panel's own `tenant()` configuration instead of making the team model the
     * tenant (slug attribute `slug`). The panel must still use the team model as tenant.
     */
    public function configureTenancy(bool $condition = true): static
    {
        $this->configuresTenancy = $condition;

        return $this;
    }

    /**
     * The relationship of tenant-owned models to the team (`team()` by default).
     */
    public function ownershipRelationship(string $relationship): static
    {
        $this->ownershipRelationship = $relationship;

        return $this;
    }

    // ---------------------------------------------------------------- Roles

    /**
     * The team roles, most powerful first. An `owner` role is always added (and always
     * grants everything).
     *
     * @param  array<int, Role>  $roles
     */
    public function roles(array $roles): static
    {
        $this->roles = array_values($roles);

        return $this;
    }

    /**
     * @return array<string, Role>
     */
    public function getRoles(): array
    {
        $roles = $this->roles ?? [
            Role::make(Role::OWNER),
            Role::make('admin')->color('success')->permissions([Permission::UPDATE_TEAM, 'members:*', 'read', 'create', 'update', 'delete']),
            Role::make('member')->color('info')->permissions(['read', 'create', 'update']),
            Role::make('viewer')->permissions(['read']),
        ];

        $keyed = [];

        foreach ($roles as $role) {
            $keyed[$role->getName()] = $role;
        }

        if (! isset($keyed[Role::OWNER])) {
            $keyed = [Role::OWNER => Role::make(Role::OWNER), ...$keyed];
        }

        return $keyed;
    }

    public function getRole(string $name): ?Role
    {
        return $this->getRoles()[$name] ?? null;
    }

    public function hasRole(string $name): bool
    {
        return $this->getRole($name) !== null;
    }

    /**
     * The role of invited and added members unless another is chosen (`member`).
     */
    public function defaultRole(string $role): static
    {
        $this->defaultRole = $role;

        return $this;
    }

    public function getDefaultRole(): string
    {
        if ($this->hasRole($this->defaultRole) && $this->defaultRole !== Role::OWNER) {
            return $this->defaultRole;
        }

        foreach ($this->getRoles() as $name => $role) {
            if (! $role->isOwner()) {
                return $name;
            }
        }

        return Role::OWNER;
    }

    /**
     * The roles `$actor` may give in `$team`: owners any role, everyone else any role but
     * owner.
     *
     * @return array<string, Role>
     */
    public function getAssignableRoles(Model $team, ?Authenticatable $actor): array
    {
        $isOwner = $actor instanceof Model && Teams::manager()->roleOf($team, $actor)?->isOwner() === true;

        return array_filter($this->getRoles(), static fn (Role $role): bool => $isOwner || ! $role->isOwner());
    }

    // ---------------------------------------------------------------- Teams

    /**
     * Give every new user a personal team on registration (`Registered` event). Name it
     * with `fn (Model $user): string => "{$user->name}'s Team"`.
     */
    public function personalTeams(bool|Closure $condition = true, ?Closure $name = null): static
    {
        $this->hasPersonalTeams = $condition;
        $this->personalTeamNameUsing = $name;

        return $this;
    }

    public function hasPersonalTeams(): bool
    {
        return (bool) $this->evaluate($this->hasPersonalTeams);
    }

    public function getPersonalTeamName(Model $user): string
    {
        if ($this->personalTeamNameUsing !== null) {
            $name = $this->evaluate($this->personalTeamNameUsing, ['user' => $user], [Model::class => $user, $user::class => $user]);

            if (is_string($name) && trim($name) !== '') {
                return trim($name);
            }
        }

        $userName = trim((string) ($user->getAttribute('name') ?? ''));

        return $userName === ''
            ? (string) __('alfakit-teams::teams.personal_team.fallback')
            : (string) __('alfakit-teams::teams.personal_team.name', ['name' => explode(' ', $userName, 2)[0]]);
    }

    /**
     * Whether users may create teams on the "Create team" page (on by default).
     */
    public function teamCreation(bool|Closure $condition = true): static
    {
        $this->canCreateTeams = $condition;

        return $this;
    }

    public function canCreateTeams(): bool
    {
        return $this->evaluateUserAuthorization($this->canCreateTeams);
    }

    // ---------------------------------------------------------------- Invitations

    /**
     * Turn invitations (the invitations page and the accept route) on or off.
     */
    public function invitations(bool $condition = true): static
    {
        $this->hasInvitations = $condition;

        return $this;
    }

    public function hasInvitations(): bool
    {
        return $this->hasInvitations;
    }

    /**
     * Days before an invitation expires (7); null never expires. Resending restarts it.
     */
    public function invitationExpiry(int|Closure|null $days): static
    {
        $this->invitationExpiryDays = $days;

        return $this;
    }

    public function getInvitationExpiryDays(): ?int
    {
        $days = $this->evaluate($this->invitationExpiryDays);

        return is_numeric($days) && (int) $days > 0 ? (int) $days : null;
    }

    /**
     * The most pending invitations a team may have at once: `fn (Team $team): int`.
     */
    public function invitationLimit(int|Closure|null $limit): static
    {
        $this->invitationLimit = $limit;

        return $this;
    }

    public function getInvitationLimit(Model $team): ?int
    {
        $limit = $this->evaluate($this->invitationLimit, ['team' => $team], [Model::class => $team, $team::class => $team]);

        return is_numeric($limit) ? max(0, (int) $limit) : null;
    }

    /**
     * The notification sent to invitees; its constructor receives the invitation and the
     * accept URL (`new $class(TeamInvitation $invitation, string $acceptUrl)`).
     *
     * @param  class-string<\Illuminate\Notifications\Notification>  $notification
     */
    public function invitationNotification(string $notification): static
    {
        $this->invitationNotification = $notification;

        return $this;
    }

    /**
     * @return class-string<\Illuminate\Notifications\Notification>
     */
    public function getInvitationNotification(): string
    {
        return $this->invitationNotification;
    }

    // ---------------------------------------------------------------- Domain auto-join

    /**
     * Let team admins set an e-mail domain (their own) whose users join the team
     * automatically, as `$role`, once their address is verified.
     */
    public function domainAutoJoin(bool|Closure $condition = true, string $role = 'member'): static
    {
        $this->hasDomainAutoJoin = $condition;
        $this->autoJoinRole = $role;

        return $this;
    }

    public function hasDomainAutoJoin(): bool
    {
        return (bool) $this->evaluate($this->hasDomainAutoJoin);
    }

    public function getAutoJoinRole(): string
    {
        return $this->hasRole($this->autoJoinRole) && $this->autoJoinRole !== Role::OWNER ? $this->autoJoinRole : $this->getDefaultRole();
    }

    /**
     * Public e-mail providers that can never be an auto-join domain (a list of common ones
     * by default); `$merge` adds to it.
     *
     * @param  array<int, string>  $domains
     */
    public function blockedAutoJoinDomains(array $domains, bool $merge = true): static
    {
        $domains = array_map(static fn (string $domain): string => mb_strtolower(trim($domain)), $domains);
        $this->blockedAutoJoinDomains = array_values(array_unique($merge ? [...$this->blockedAutoJoinDomains, ...$domains] : $domains));

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getBlockedAutoJoinDomains(): array
    {
        return $this->blockedAutoJoinDomains;
    }

    // ---------------------------------------------------------------- Seats & billing

    /**
     * The most members a team may have: `fn (Team $team): ?int` (null = unlimited).
     * Enforced when inviting (members + pending invitations), accepting and auto-joining.
     */
    public function seats(?Closure $seats): static
    {
        $this->seatsUsing = $seats;

        return $this;
    }

    /**
     * Seats from the team's subscription (billing plugin): the subscription quantity by
     * default, or `fn (Team $team, ?AbstractBillingProvider $provider, ?object $subscription): ?int`.
     * Teams without a subscription get `$withoutSubscription` seats. A no-op without the
     * billing plugin.
     */
    public function seatsFromBilling(?Closure $using = null, ?int $withoutSubscription = 1): static
    {
        $this->seatsUsing = fn (Model $team): ?int => BillingSeats::seats($team, $using, $withoutSubscription, $this);

        return $this;
    }

    public function getSeats(Model $team): ?int
    {
        if ($this->seatsUsing === null) {
            return null;
        }

        $seats = $this->evaluate($this->seatsUsing, ['team' => $team], [Model::class => $team, $team::class => $team]);

        return is_numeric($seats) ? max(0, (int) $seats) : null;
    }

    /**
     * Keep the subscription quantity equal to the member count (per-seat billing). By
     * default `$team->subscription($type)?->updateQuantity($count)` (Cashier Stripe and
     * Paddle); `fn (Team $team, int $quantity, ?AbstractBillingProvider $provider)` replaces it.
     */
    public function syncSeatsWithBilling(bool|Closure $condition = true, ?Closure $using = null): static
    {
        $this->syncsBillingQuantity = $condition;
        $this->syncBillingQuantityUsing = $using;

        return $this;
    }

    public function syncsSeatsWithBilling(): bool
    {
        return (bool) $this->evaluate($this->syncsBillingQuantity);
    }

    public function getSyncBillingQuantityUsing(): ?Closure
    {
        return $this->syncBillingQuantityUsing;
    }

    // ---------------------------------------------------------------- Shield

    /**
     * Mirror each member's team role to the spatie/laravel-permission role of the same
     * name scoped to the team, when the panel uses the shield plugin with teams (on by
     * default; a no-op otherwise).
     */
    public function syncShieldRoles(bool|Closure $condition = true): static
    {
        $this->syncsShieldRoles = $condition;

        return $this;
    }

    public function syncsShieldRoles(): bool
    {
        return (bool) $this->evaluate($this->syncsShieldRoles);
    }

    // ---------------------------------------------------------------- Logos

    /**
     * Let teams upload a logo (on by default), stored on `$disk` (the default
     * filesystem disk) in `$directory`.
     */
    public function logos(bool|Closure $condition = true, ?string $disk = null, string $directory = 'team-logos'): static
    {
        $this->hasLogos = $condition;
        $this->logoDisk = $disk;
        $this->logoDirectory = $directory;

        return $this;
    }

    public function hasLogos(): bool
    {
        return (bool) $this->evaluate($this->hasLogos);
    }

    public function getLogoDisk(): string
    {
        return $this->logoDisk ?? (string) config('filesystems.default', 'public');
    }

    public function getLogoDirectory(): string
    {
        return $this->logoDirectory;
    }

    // ---------------------------------------------------------------- Navigation

    /**
     * Add "Members" to the tenant menu (on by default).
     */
    public function tenantMenuItems(bool|Closure $condition = true): static
    {
        $this->hasTenantMenuItems = $condition;

        return $this;
    }

    public function hasTenantMenuItems(): bool
    {
        return (bool) $this->evaluate($this->hasTenantMenuItems);
    }

    /**
     * Also list the members page in the sidebar navigation (off by default), in `$group`.
     */
    public function membersNavigation(bool|Closure $condition = true, string|Closure|null $group = null): static
    {
        $this->hasMembersNavigation = $condition;
        $this->navigationGroup = $group;

        return $this;
    }

    public function hasMembersNavigation(): bool
    {
        return (bool) $this->evaluate($this->hasMembersNavigation);
    }

    public function getNavigationGroup(): ?string
    {
        $group = $this->evaluate($this->navigationGroup);

        return is_string($group) && $group !== '' ? $group : null;
    }

    // ---------------------------------------------------------------- Pages

    /**
     * @param  class-string<CreateTeam>  $page
     */
    public function createTeamPage(string $page): static
    {
        $this->createTeamPage = $this->ensurePage($page, CreateTeam::class);

        return $this;
    }

    /**
     * @param  class-string<TeamSettings>  $page
     */
    public function settingsPage(string $page): static
    {
        $this->settingsPage = $this->ensurePage($page, TeamSettings::class);

        return $this;
    }

    /**
     * @return class-string<TeamSettings>
     */
    public function getSettingsPage(): string
    {
        return $this->settingsPage;
    }

    /**
     * @param  class-string<TeamMembers>  $page
     */
    public function membersPage(string $page): static
    {
        $this->membersPage = $this->ensurePage($page, TeamMembers::class);

        return $this;
    }

    /**
     * @return class-string<TeamMembers>
     */
    public function getMembersPage(): string
    {
        return $this->membersPage;
    }

    /**
     * @param  class-string<TeamInvitations>  $page
     */
    public function invitationsPage(string $page): static
    {
        $this->invitationsPage = $this->ensurePage($page, TeamInvitations::class);

        return $this;
    }

    /**
     * @return class-string<TeamInvitations>
     */
    public function getInvitationsPage(): string
    {
        return $this->invitationsPage;
    }

    /**
     * @param  class-string<AcceptInvitation>  $page
     */
    public function acceptInvitationPage(string $page): static
    {
        $this->acceptInvitationPage = $this->ensurePage($page, AcceptInvitation::class);

        return $this;
    }

    /**
     * @template T of object
     *
     * @param  class-string  $page
     * @param  class-string<T>  $base
     * @return class-string<T>
     */
    protected function ensurePage(string $page, string $base): string
    {
        if (! is_a($page, $base, true)) {
            throw new InvalidArgumentException("The page [{$page}] must extend [{$base}].");
        }

        return $page;
    }
}
