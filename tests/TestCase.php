<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTeams\Tests;

use AlfaSix\Alfakit\Tests\TestCase as AlfakitTestCase;
use AlfaSix\AlfakitTeams\AlfakitTeamsServiceProvider;
use AlfaSix\AlfakitTeams\Models\Team;
use AlfaSix\AlfakitTeams\Teams;
use AlfaSix\AlfakitTeams\Tests\Fixtures\Models\BillableTeam;
use AlfaSix\AlfakitTeams\Tests\Fixtures\Models\TeamsUser;
use AlfaSix\AlfakitTeams\Tests\Fixtures\Panels\TeamsPanelProvider;
use LogicException;
use Pest\TestSuite;

abstract class TestCase extends AlfakitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        BillableTeam::$subscriptions = [];
    }

    protected function tearDown(): void
    {
        Teams::useTeamModel(Team::class);

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            AlfakitTeamsServiceProvider::class,
            TeamsPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', TeamsUser::class);
    }

    /**
     * Only this plugin's tables: the core fixtures have their own `teams` table.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/database/migrations');
        $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');
    }

    /**
     * The running test (Pest binds `$this` to it; this keeps static analysis typed).
     */
    public static function current(): static
    {
        $test = TestSuite::getInstance()->test;

        if (! $test instanceof static) {
            throw new LogicException('No test of this suite is running.');
        }

        return $test;
    }
}
