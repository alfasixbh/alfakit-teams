<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTeams;

use AlfaSix\Alfakit\Panel;
use AlfaSix\AlfakitTeams\Models\Membership;
use AlfaSix\AlfakitTeams\Models\Team;
use AlfaSix\AlfakitTeams\Models\TeamInvitation;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The models the plugin works with (shared by every panel of the app) and the plugin of
 * the current panel. `TeamsPlugin::teamModel()` sets the team model.
 */
final class Teams
{
    /** @var class-string<Model> */
    private static string $teamModel = Team::class;

    /** @var class-string<Membership> */
    private static string $membershipModel = Membership::class;

    /** @var class-string<TeamInvitation> */
    private static string $invitationModel = TeamInvitation::class;

    /**
     * @param  class-string<Model>  $model
     */
    public static function useTeamModel(string $model): void
    {
        if (! is_a($model, Model::class, true)) {
            throw new InvalidArgumentException("The team model [{$model}] must be an Eloquent model.");
        }

        self::$teamModel = $model;
    }

    /**
     * @return class-string<Model>
     */
    public static function teamModel(): string
    {
        return self::$teamModel;
    }

    /**
     * @param  class-string<Membership>  $model
     */
    public static function useMembershipModel(string $model): void
    {
        if (! is_a($model, Membership::class, true)) {
            throw new InvalidArgumentException("The membership model [{$model}] must extend [".Membership::class.'].');
        }

        self::$membershipModel = $model;
    }

    /**
     * @return class-string<Membership>
     */
    public static function membershipModel(): string
    {
        return self::$membershipModel;
    }

    /**
     * @param  class-string<TeamInvitation>  $model
     */
    public static function useInvitationModel(string $model): void
    {
        if (! is_a($model, TeamInvitation::class, true)) {
            throw new InvalidArgumentException("The invitation model [{$model}] must extend [".TeamInvitation::class.'].');
        }

        self::$invitationModel = $model;
    }

    /**
     * @return class-string<TeamInvitation>
     */
    public static function invitationModel(): string
    {
        return self::$invitationModel;
    }

    /**
     * The user model of the default auth provider.
     *
     * @return class-string<Model>
     */
    public static function userModel(): string
    {
        $model = config('auth.providers.users.model');

        return is_string($model) && is_a($model, Model::class, true) ? $model : \Illuminate\Foundation\Auth\User::class;
    }

    /**
     * The teams plugin of `$panel`, else of the current panel, else of the first panel that
     * has it (for events outside a panel request, like a registration in your own app).
     */
    public static function plugin(?Panel $panel = null): ?TeamsPlugin
    {
        $candidates = array_filter([$panel, alfakit()->getCurrentPanel()]);

        if ($panel === null) {
            $candidates = [...$candidates, ...array_values(alfakit()->getPanels())];
        }

        foreach ($candidates as $candidate) {
            if ($candidate->hasPlugin(TeamsPlugin::ID)) {
                $plugin = $candidate->getPlugin(TeamsPlugin::ID);

                if ($plugin instanceof TeamsPlugin) {
                    return $plugin;
                }
            }
        }

        return null;
    }

    /**
     * The panel `plugin()` was found on.
     */
    public static function panel(?Panel $panel = null): ?Panel
    {
        $candidates = array_filter([$panel, alfakit()->getCurrentPanel()]);

        if ($panel === null) {
            $candidates = [...$candidates, ...array_values(alfakit()->getPanels())];
        }

        foreach ($candidates as $candidate) {
            if ($candidate->hasPlugin(TeamsPlugin::ID)) {
                return $candidate;
            }
        }

        return null;
    }

    public static function pluginOrDefault(?Panel $panel = null): TeamsPlugin
    {
        return self::plugin($panel) ?? TeamsPlugin::make();
    }

    public static function manager(): TeamManager
    {
        return app(TeamManager::class);
    }
}
