<?php

namespace ErnestDefoe\Fantasy\Service;

use ErnestDefoe\Fantasy\League;
use ErnestDefoe\Fantasy\RosterSpot;
use Illuminate\Database\ConnectionInterface;

/** Which teams a league may draft, and taking one. */
class Draft
{
    public function __construct(protected ConnectionInterface $db)
    {
    }

    /**
     * Teams nobody in this league owns.
     *
     * 🚨 Scoped to the teams that actually PLAY in the league's season, not to
     * every row in `picks_teams`. That table held one sport's teams when this
     * idea was written and now holds six, so "a team exists" stopped being the
     * same question as "a team is in this competition" — without the scope a
     * college-football draft board offers the Milwaukee Bucks.
     *
     * 🚨 Asked of the FIXTURES rather than of a league label, which is both
     * provider-neutral and stricter: a club with no games this season is not
     * draftable however it is filed.
     *
     * @return list<array<string, mixed>>
     */
    public function available(League $league, string $search = '', int $limit = 400): array
    {
        $query = $this->db->table('picks_teams as t')
            ->whereNotExists(function ($q) use ($league) {
                $q->selectRaw('1')
                    ->from('fantasy_rosters as r')
                    ->where('r.league_id', $league->id)
                    ->whereColumn('r.team_id', 't.id');
            })
            ->whereExists(function ($q) use ($league) {
                $q->selectRaw('1')
                    ->from('picks_events as e')
                    ->join('picks_weeks as w', 'w.id', '=', 'e.week_id')
                    ->where('w.season_id', $league->season_id)
                    ->where(function ($side) {
                        $side->whereColumn('e.home_team_id', 't.id')
                            ->orWhereColumn('e.away_team_id', 't.id');
                    });
            });

        $search = trim($search);

        if ($search !== '') {
            $like = '%' . $search . '%';
            $query->where(function ($q) use ($like) {
                $q->where('t.name', 'like', $like)
                    ->orWhere('t.conference', 'like', $like)
                    ->orWhere('t.abbreviation', 'like', $like);
            });
        }

        return $query->orderBy('t.name')
            ->limit(max(1, min(1000, $limit)))
            ->get(['t.id', 't.name', 't.conference', 't.logo_path'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /**
     * Take a team.
     *
     * @return array{ok: bool, problem?: string}
     */
    public function pick(League $league, int $franchiseId, int $teamId): array
    {
        if (!$this->playsIn($league, $teamId)) {
            return ['ok' => false, 'problem' => 'no_such_team'];
        }

        $held = RosterSpot::query()
            ->where('league_id', $league->id)
            ->where('franchise_id', $franchiseId)
            ->count();

        if ($held >= $league->roster_size) {
            return ['ok' => false, 'problem' => 'roster_full'];
        }

        try {
            RosterSpot::query()->create([
                'league_id' => $league->id,
                'franchise_id' => $franchiseId,
                'team_id' => $teamId,
                'acquired_via' => 'draft',
            ]);
        } catch (\Throwable) {
            /*
             * 🚨 The UNIQUE index is the arbiter, not a prior read. Two people
             * clicking the same free agent in the same second both pass a
             * "is it taken?" check and only one insert can win — which is the
             * behaviour wanted, and the loser is told so rather than shown a
             * stack trace.
             */
            return ['ok' => false, 'problem' => 'already_taken'];
        }

        return ['ok' => true];
    }

    private function playsIn(League $league, int $teamId): bool
    {
        if ($league->season_id < 1 || $teamId < 1) {
            return false;
        }

        return $this->db->table('picks_events as e')
            ->join('picks_weeks as w', 'w.id', '=', 'e.week_id')
            ->where('w.season_id', $league->season_id)
            ->where(function ($side) use ($teamId) {
                $side->where('e.home_team_id', $teamId)->orWhere('e.away_team_id', $teamId);
            })
            ->exists();
    }
}
