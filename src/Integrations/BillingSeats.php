<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTeams\Integrations;

use AlfaSix\AlfakitBilling\Providers\AbstractBillingProvider;
use AlfaSix\AlfakitTeams\Teams;
use AlfaSix\AlfakitTeams\TeamsPlugin;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * The bridge to `alfasix/alfakit-billing`, used only when it is installed: seats from
 * the team's subscription and the subscription quantity kept equal to the member count.
 * Every lookup is guarded, so the teams plugin never depends on the billing plugin.
 */
final class BillingSeats
{
    public static function isAvailable(): bool
    {
        return class_exists(AbstractBillingProvider::class);
    }

    /**
     * The billing provider of the teams panel, if the billing plugin is installed and
     * configured there.
     */
    public static function provider(): ?object
    {
        if (! self::isAvailable()) {
            return null;
        }

        $panel = Teams::panel() ?? alfakit()->getCurrentOrDefaultPanel();

        return AbstractBillingProvider::forPanel($panel);
    }

    /**
     * The team's subscription of the provider's type (`$team->subscription($type)`,
     * Cashier Stripe and Paddle), or null.
     */
    public static function subscription(Model $team, ?object $provider): ?object
    {
        if (! method_exists($team, 'subscription')) {
            return null;
        }

        $type = $provider !== null && method_exists($provider, 'getSubscriptionType') ? $provider->getSubscriptionType() : 'default';
        $subscription = $team->subscription($type);

        return is_object($subscription) ? $subscription : null;
    }

    public static function seats(Model $team, ?Closure $using, ?int $withoutSubscription, TeamsPlugin $plugin): ?int
    {
        $provider = self::provider();
        $subscription = self::subscription($team, $provider);

        if ($using !== null) {
            $seats = $plugin->evaluate($using, ['team' => $team, 'provider' => $provider, 'subscription' => $subscription], [Model::class => $team, $team::class => $team]);

            return is_numeric($seats) ? (int) $seats : null;
        }

        $valid = $subscription !== null && (! method_exists($subscription, 'valid') || (bool) $subscription->valid());
        $quantity = $valid ? ($subscription->quantity ?? null) : null;

        return is_numeric($quantity) ? (int) $quantity : $withoutSubscription;
    }

    public static function syncQuantity(Model $team, int $quantity, TeamsPlugin $plugin): void
    {
        $provider = self::provider();

        if (($using = $plugin->getSyncBillingQuantityUsing()) !== null) {
            $plugin->evaluate($using, ['team' => $team, 'quantity' => $quantity, 'provider' => $provider], [Model::class => $team, $team::class => $team]);

            return;
        }

        $subscription = self::subscription($team, $provider);

        if ($subscription === null || ! method_exists($subscription, 'updateQuantity') || (int) ($subscription->quantity ?? 0) === $quantity) {
            return;
        }

        $subscription->updateQuantity(max(1, $quantity));
    }
}
