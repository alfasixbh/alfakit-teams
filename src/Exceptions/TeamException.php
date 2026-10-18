<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTeams\Exceptions;

use RuntimeException;

/**
 * A team rule stopped an operation (last owner, seat limit, expired invitation…). The
 * message is translated and safe to show to the user; `$reason` is a stable key.
 */
class TeamException extends RuntimeException
{
    final public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    public static function because(string $reason, array $replace = []): static
    {
        return new static($reason, (string) __('alfakit-teams::teams.errors.'.$reason, $replace));
    }
}
