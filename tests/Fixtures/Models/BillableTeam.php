<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTeams\Tests\Fixtures\Models;

use AlfaSix\AlfakitTeams\Models\Team;

/**
 * A team with a fake Cashier-like subscription (`subscription()->quantity`, `updateQuantity()`).
 */
class BillableTeam extends Team
{
    /** @var array<string, FakeSubscription> */
    public static array $subscriptions = [];

    public function subscription(string $type = 'default'): ?FakeSubscription
    {
        return static::$subscriptions[$this->getKey().':'.$type] ?? null;
    }
}
