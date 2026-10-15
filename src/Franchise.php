<?php

namespace ErnestDefoe\Fantasy;

use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $league_id
 * @property int $user_id
 * @property string $name
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read League|null $league
 */
class Franchise extends AbstractModel
{
    public $timestamps = true;

    protected $table = 'fantasy_franchises';

    protected $fillable = ['league_id', 'user_id', 'name'];

    protected $casts = ['league_id' => 'integer', 'user_id' => 'integer'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<League, $this> */
    public function league(): BelongsTo
    {
        return $this->belongsTo(League::class, 'league_id');
    }
}
