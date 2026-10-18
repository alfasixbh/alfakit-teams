<?php

declare(strict_types=1);

use AlfaSix\AlfakitTeams\Exceptions\TeamException;
use AlfaSix\AlfakitTeams\Integrations\BillingSeats;
use AlfaSix\AlfakitTeams\Role;
use AlfaSix\AlfakitTeams\Teams;
use AlfaSix\AlfakitTeams\Tests\Fixtures\Models\BillableTeam;
use AlfaSix\AlfakitTeams\Tests\Fixtures\Models\FakeSubscription;
use Illuminate\Support\Facades\Notification;

use function AlfaSix\AlfakitTeams\Tests\plugin;
use function AlfaSix\AlfakitTeams\Tests\user;

uses(AlfaSix\AlfakitTeams\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

function billableTeam(Illuminate\Database\Eloquent\Model $owner): BillableTeam
{
    $team = BillableTeam::query()->create(['name' => 'Billed', 'slug' => uniqid('billed')]);
    AlfaSix\AlfakitTeams\Models\Membership::query()->create(['team_id' => $team->id, 'user_id' => $owner->getKey(), 'role' => Role::OWNER]);

    return $team;
}

it('detects the billing plugin without depending on it', function (): void {
    expect(BillingSeats::isAvailable())->toBe(class_exists(AlfaSix\AlfakitBilling\Providers\AbstractBillingProvider::class))
        // No billing provider on the teams panel.
        ->and(BillingSeats::provider())->toBeNull();
});

it('derives the seats from the subscription quantity', function (): void {
    Notification::fake();
    plugin()->seatsFromBilling();
    $ada = user('Ada');
    $team = billableTeam($ada);

    // Without a subscription: one seat (the owner).
    expect(plugin()->getSeats($team))->toBe(1)
        ->and(fn () => Teams::manager()->invite($team, $ada, 'grace@example.com'))->toThrow(TeamException::class);

    BillableTeam::$subscriptions[$team->id.':default'] = new FakeSubscription(quantity: 5);

    expect(plugin()->getSeats($team))->toBe(5)
        ->and(Teams::manager()->seatsRemaining($team))->toBe(4);

    // A cancelled subscription is no subscription.
    BillableTeam::$subscriptions[$team->id.':default'] = new FakeSubscription(quantity: 5, isValid: false);

    expect(plugin()->getSeats($team))->toBe(1);
});

it('derives the seats with a closure', function (): void {
    plugin()->seatsFromBilling(fn (BillableTeam $team, ?object $provider, ?FakeSubscription $subscription): int => ($subscription?->quantity ?? 0) * 10, withoutSubscription: 3);
    $team = billableTeam(user('Ada'));
    BillableTeam::$subscriptions[$team->id.':default'] = new FakeSubscription(quantity: 2);

    expect(plugin()->getSeats($team))->toBe(20);
});

it('syncs the subscription quantity with the member count', function (): void {
    plugin()->syncSeatsWithBilling();
    $ada = user('Ada');
    $mia = user('Mia');
    $team = billableTeam($ada);
    $subscription = BillableTeam::$subscriptions[$team->id.':default'] = new FakeSubscription(quantity: 1);

    Teams::manager()->addMember($team, $mia);

    expect($subscription->quantity)->toBe(2);

    Teams::manager()->removeMember($team, $ada, $mia);

    expect($subscription->quantity)->toBe(1)->and($subscription->updates)->toBe([2, 1]);
});

it('syncs the quantity with a closure', function (): void {
    $synced = [];
    plugin()->syncSeatsWithBilling(using: function (BillableTeam $team, int $quantity) use (&$synced): void {
        $synced[] = $quantity;
    });
    $team = billableTeam(user('Ada'));

    Teams::manager()->addMember($team, user('Mia'));

    expect($synced)->toBe([2]);
});

it('keeps the membership when the billing sync fails', function (): void {
    plugin()->syncSeatsWithBilling(using: fn () => throw new RuntimeException('Stripe is down'));
    $team = billableTeam(user('Ada'));
    $mia = user('Mia');

    Teams::manager()->addMember($team, $mia);

    expect($mia->belongsToTeam($team))->toBeTrue();
});
