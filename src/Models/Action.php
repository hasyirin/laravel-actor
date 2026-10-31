<?php

namespace Hasyirin\Actor\Models;

use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property CarbonInterface|null $acted_at
 */
class Action extends Model
{
    protected $fillable = [
        'resource_type',
        'resource_id',
        'actor_type',
        'actor_id',
        'acted_at',
        'name',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (Action $action) {
            $action->acted_at ??= now();
        });
    }

    protected function casts(): array
    {
        return [
            'acted_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        return $this->table ?? config('actor.tables.actions', 'actions');
    }

    public function getConnectionName()
    {
        // Relations give a related model without a connection the parent's connection, so pin the default.
        return parent::getConnectionName() ?? config('database.default');
    }

    public function resource(): MorphTo
    {
        return $this->morphTo();
    }

    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeOfResource(Builder $query, Model $resource): Builder
    {
        return $query->where('resource_type', $resource->getMorphClass())
            ->where('resource_id', $resource->getKey());
    }

    public function scopeOfActor(Builder $query, Model $actor): Builder
    {
        return $query->where('actor_type', $actor->getMorphClass())
            ->where('actor_id', $actor->getKey());
    }

    public function scopeOfName(Builder $query, BackedEnum|string $name): Builder
    {
        return $query->where('name', $name instanceof BackedEnum ? (string) $name->value : $name);
    }
}
