<?php

namespace ErnestDefoe\Fantasy;

use ErnestDefoe\Fantasy\Service\Sports\Scoring as SportScoring;
use Flarum\Database\AbstractModel;

/**
 * @property int    $id
 * @property string $name
 * @property string $slug
 * @property int    $season_id
 * @property string $status
 */
class League extends AbstractModel
{
    public $timestamps = true;

    protected $table = 'fantasy_leagues';

    /**
     * The scoring rules, listed ONCE.
     *
     * 🚨 Three copies of this list — the form, the writer, the reader — is
     * three chances for a rule to exist in one and not another, which presents
     * as a setting that saves and does nothing.
     */
    public const SCORING = [
        'points_per_point',
        'points_per_point_allowed',
        'win_bonus',
        'shutout_bonus',
        'points_per_margin',
    ];

    protected $fillable = [
        'name', 'slug', 'description', 'season_id', 'commissioner_id',
        'status', 'max_franchises', 'roster_size', 'starters',
        ...self::SCORING,
    ];

    protected $casts = [
        'season_id' => 'integer',
        'commissioner_id' => 'integer',
        'max_franchises' => 'integer',
        'roster_size' => 'integer',
        'starters' => 'integer',
        'points_per_point' => 'float',
        'points_per_point_allowed' => 'float',
        'win_bonus' => 'float',
        'shutout_bonus' => 'float',
        'points_per_margin' => 'float',
    ];

    public function franchises()
    {
        return $this->hasMany(Franchise::class, 'league_id');
    }

    /**
     * Which competition this league's season is, or '' when Picks does not say.
     *
     * 🚨 Read through a column probe, not selected outright. Picks gained
     * `picks_seasons.league` after this extension existed, and a Fantasy
     * running against an older Picks is ordinary — not something that should
     * throw an unknown-column error the moment somebody creates a league.
     */
    public function competition(): string
    {
        static $has = null;

        try {
            if ($has === null) {
                $has = $this->getConnection()->getSchemaBuilder()->hasColumn('picks_seasons', 'league');
            }

            if (!$has) {
                return '';
            }

            $row = $this->getConnection()->table('picks_seasons')->where('id', $this->season_id)->first();

            return (string) ($row->league ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * The starting rules for a league in this competition.
     *
     * @return array<string, float>
     */
    public function sportDefaults(): array
    {
        return SportScoring::defaultsFor($this->competition());
    }
}
