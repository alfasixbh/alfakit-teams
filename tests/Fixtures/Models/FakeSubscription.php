<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTeams\Tests\Fixtures\Models;

class FakeSubscription
{
    /** @var array<int, int> */
    public array $updates = [];

    public function __construct(public int $quantity, public bool $isValid = true) {}

    public function valid(): bool
    {
        return $this->isValid;
    }

    public function updateQuantity(int $quantity): static
    {
        $this->updates[] = $quantity;
        $this->quantity = $quantity;

        return $this;
    }
}
