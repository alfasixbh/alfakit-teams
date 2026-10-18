<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTeams\Tests\Fixtures\Models;

use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail;

class VerifyingTeamsUser extends TeamsUser implements MustVerifyEmail
{
    use MustVerifyEmailTrait;
}
