<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTeams;

use Closure;
use Illuminate\Support\Str;

/**
 * A team role and the permissions it grants.
 *
 * ```php
 * Role::make('admin')
 *     ->label('Administrator')
 *     ->description('Manages members and settings.')
 *     ->permissions([Permission::UPDATE_TEAM, 'members:*', 'posts:*']);
 * ```
 *
 * The `owner` role always grants everything; deleting the team and transferring
 * ownership are reserved to owners whatever the permissions say.
 */
class Role
{
    public const OWNER = 'owner';

    protected string|Closure|null $label = null;

    protected string|Closure|null $description = null;

    /** @var array<int, string> */
    protected array $permissions = [];

    protected string|Closure|null $color = null;

    final public function __construct(protected string $name) {}

    public static function make(string $name): static
    {
        return new static($name);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isOwner(): bool
    {
        return $this->name === self::OWNER;
    }

    public function label(string|Closure|null $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getLabel(): string
    {
        $label = value($this->label);

        if (is_string($label) && $label !== '') {
            return $label;
        }

        $key = 'alfakit-teams::teams.roles.'.$this->name.'.label';

        return trans()->has($key) ? (string) __($key) : Str::headline($this->name);
    }

    public function description(string|Closure|null $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getDescription(): ?string
    {
        $description = value($this->description);

        if (is_string($description) && $description !== '') {
            return $description;
        }

        $key = 'alfakit-teams::teams.roles.'.$this->name.'.description';

        return $this->description === null && trans()->has($key) ? (string) __($key) : null;
    }

    /**
     * The badge colour of the role in the members table (`primary`, `success`, `gray`…).
     */
    public function color(string|Closure|null $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function getColor(): string
    {
        $color = value($this->color);

        if (is_string($color) && $color !== '') {
            return $color;
        }

        return $this->isOwner() ? 'primary' : 'gray';
    }

    /**
     * @param  array<int, string>  $permissions
     */
    public function permissions(array $permissions): static
    {
        $this->permissions = array_values(array_unique(array_filter($permissions, static fn (mixed $permission): bool => is_string($permission) && $permission !== '')));

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getPermissions(): array
    {
        return $this->isOwner() ? ['*'] : $this->permissions;
    }

    /**
     * Whether the role grants the permission: listed, `*`, or a `prefix:*` wildcard.
     */
    public function grants(string $permission): bool
    {
        foreach ($this->getPermissions() as $granted) {
            if ($granted === '*' || $granted === $permission) {
                return true;
            }

            if (str_ends_with($granted, ':*') && str_starts_with($permission, substr($granted, 0, -1))) {
                return true;
            }
        }

        return false;
    }
}
