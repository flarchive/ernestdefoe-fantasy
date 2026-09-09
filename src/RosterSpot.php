<?php

namespace ErnestDefoe\Fantasy;

use Flarum\Database\AbstractModel;

/**
 * One team on one franchise's roster.
 *
 * 🚨 Named `RosterSpot` rather than `Roster` because `Roster` is the sports
 * roster extension's word, and two `Roster` classes in one autoloader is a
 * confusion nobody needs to have twice.
 *
 * @property int    $league_id
 * @property int    $franchise_id
 * @property int    $team_id
 * @property string $acquired_via
 */
class RosterSpot extends AbstractModel
{
    public $timestamps = true;

    protected $table = 'fantasy_rosters';

    protected $fillable = ['league_id', 'franchise_id', 'team_id', 'acquired_via'];

    protected $casts = ['league_id' => 'integer', 'franchise_id' => 'integer', 'team_id' => 'integer'];
}
