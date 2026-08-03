<?php

namespace App\Statistics;

use Illuminate\Database\Eloquent\Model;

/**
 * What a set of metrics is *about*.
 *
 * A scope is the answer to "who or what are we measuring" — the requesting
 * user, one of their lists, and later an author / genre / format. Metrics
 * build their queries from the scope rather than knowing which page asked,
 * which is what lets one registry serve every statistics surface.
 *
 * `userId` is always the authenticated user, even for non-user scopes: a
 * list's average rating still means "the rating *I* gave", not the list
 * owner's, and every read-derived metric filters on it.
 */
class Scope
{
    public const USER = 'user';

    public const LIST = 'list';

    public function __construct(
        public readonly string $type,
        public readonly ?int $userId,
        public readonly ?int $id = null,
        public readonly ?Model $model = null,
    ) {}

    public function is(string $type): bool
    {
        return $this->type === $type;
    }

    /**
     * Stable identifier for caching and logging — `user`, `list:12`.
     */
    public function key(): string
    {
        return $this->id === null ? $this->type : "{$this->type}:{$this->id}";
    }

    public function toArray(): array
    {
        return ['type' => $this->type, 'id' => $this->id];
    }
}
