<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTeams\Tests\Fixtures\Panels;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\PanelProvider;
use AlfaSix\AlfakitTeams\TeamsPlugin;

class TeamsPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('teams')
            ->path('teams')
            ->login()
            ->registration()
            ->middleware(['web'])
            ->plugin(TeamsPlugin::make()->personalTeams()->domainAutoJoin());
    }
}
