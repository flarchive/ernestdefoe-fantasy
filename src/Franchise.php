<?php

namespace ErnestDefoe\Fantasy;

use Flarum\Database\AbstractModel;
use Flarum\User\User;

/**
 * @property int    $id
 * @property int    $league_id
 * @property int    $user_id
 * @property string $name
 */
class Franchise extends AbstractModel
{
    public $timestamps = true;

    protected $table = 'fantasy_franchises';

    protected $fillable = ['league_id', 'user_id', 'name'];

    protected $casts = ['league_id' => 'integer', 'user_id' => 'integer'];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function league()
    {
        return $this->belongsTo(League::class, 'league_id');
    }
}
