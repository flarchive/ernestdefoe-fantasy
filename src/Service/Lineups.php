<?php

namespace ErnestDefoe\Fantasy\Service;

use ErnestDefoe\Fantasy\Franchise;
use ErnestDefoe\Fantasy\League;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/** Who a franchise starts each week. */
class Lineups
{
    public function __construct(protected ConnectionInterface $db)
    {
    }

    /** @return list<array{id: int, name: string}> the league's season, in order */
    public function weeks(League $league): array
    {
        return $this->db->table('picks_weeks')
            ->where('season_id', $league->season_id)
            ->orderBy('week_number')->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn ($w) => ['id' => (int) $w->id, 'name' => (string) $w->name])
            ->all();
    }

    /** @return list<int> the franchise's teams, in the order it drafted them */
    public function roster(League $league, int $franchiseId): array
    {
        return $this->db->table('fantasy_rosters')
            ->where('league_id', $league->id)->where('franchise_id', $franchiseId)
            ->orderBy('id')->pluck('team_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Each team's game this week, keyed by team, with whether it has locked.
     *
     * @return array<int, array<string, mixed>>
     */
    public function games(int $weekId, array $teamIds, ?League $league = null): array
    {
        if ($teamIds === []) {
            return [];
        }

        $rows = $this->db->table('picks_events as e')
            ->leftJoin('picks_teams as h', 'h.id', '=', 'e.home_team_id')
            ->leftJoin('picks_teams as a', 'a.id', '=', 'e.away_team_id')
            ->where('e.week_id', $weekId)
            ->where(fn ($q) => $q->whereIn('e.home_team_id', $teamIds)->orWhereIn('e.away_team_id', $teamIds))
            ->get(['e.home_team_id', 'e.away_team_id', 'e.match_date', 'e.status', 'e.home_score', 'e.away_score',
                'h.name as home_name', 'h.logo_path as home_logo', 'a.name as away_name', 'a.logo_path as away_logo']);

        $now = Carbon::now();
        $games = [];
        foreach ($rows as $g) {
            foreach ([[(int) $g->home_team_id, true], [(int) $g->away_team_id, false]] as [$team, $home]) {
                if (! in_array($team, $teamIds, true)) {
                    continue;
                }
                $kickoff = $g->match_date ? Carbon::parse($g->match_date) : null;
                $games[$team] = [
                    'home' => $home,
                    'opponent' => ['name' => (string) ($home ? $g->away_name : $g->home_name), 'logo' => (string) ($home ? $g->away_logo : $g->home_logo)],
                    'kickoff' => $kickoff?->toIso8601String(),
                    'status' => (string) $g->status,
                    'score' => $g->home_score === null ? null : [(int) ($home ? $g->home_score : $g->away_score), (int) ($home ? $g->away_score : $g->home_score)],
                    // A team is locked once its game is under way: picking a starter
                    // after kickoff would be picking a result.
                    'locked' => $g->status !== 'scheduled' || ($kickoff !== null && $kickoff->lte($now)),
                    // Kicked off before the draft finished: shown, never scored.
                    'beforeDraft' => $league?->activated_at !== null && $kickoff !== null && $kickoff->lt($league->activated_at),
                ];
            }
        }

        return $games;
    }

    /**
     * Who starts this week: what was saved for it; otherwise the lineup from the
     * last week that had one; otherwise the first teams drafted.
     *
     * @return list<int>
     */
    public function starters(League $league, int $franchiseId, int $weekId): array
    {
        $rows = $this->db->table('fantasy_lineups')
            ->where('league_id', $league->id)->where('franchise_id', $franchiseId)->where('week_id', $weekId)
            ->get(['team_id', 'started']);

        if ($rows->isNotEmpty()) {
            return $rows->where('started', true)->pluck('team_id')->map(fn ($id) => (int) $id)->values()->all();
        }

        $order = array_column($this->weeks($league), 'id');
        $before = array_slice($order, 0, max(0, (int) array_search($weekId, $order, true)));
        $last = $before ? $this->db->table('fantasy_lineups')
            ->where('league_id', $league->id)->where('franchise_id', $franchiseId)->whereIn('week_id', $before)
            ->where('started', true)->get(['week_id', 'team_id'])->groupBy('week_id') : collect();

        $roster = $this->roster($league, $franchiseId);
        foreach (array_reverse($before) as $week) {
            if (isset($last[$week])) {
                // Only teams still on the roster carry over.
                return array_values(array_intersect($last[$week]->pluck('team_id')->map(fn ($id) => (int) $id)->all(), $roster));
            }
        }

        return array_slice($roster, 0, (int) $league->starters);
    }

    /**
     * Save a week's starters. Teams whose games have kicked off keep the place
     * they had: a lineup can change around a game in progress, never through it.
     *
     * @param list<int> $teamIds
     * @return array{ok: bool, problem?: string}
     */
    public function save(League $league, Franchise $franchise, int $weekId, array $teamIds): array
    {
        if ($league->status !== 'active') {
            return ['ok' => false, 'problem' => 'not_active'];
        }
        if (! in_array($weekId, array_column($this->weeks($league), 'id'), true)) {
            return ['ok' => false, 'problem' => 'no_such_week'];
        }

        $roster = $this->roster($league, (int) $franchise->id);
        $teamIds = array_values(array_unique(array_map('intval', $teamIds)));

        if (array_diff($teamIds, $roster)) {
            return ['ok' => false, 'problem' => 'not_on_roster'];
        }
        if (count($teamIds) > (int) $league->starters) {
            return ['ok' => false, 'problem' => 'too_many_starters'];
        }

        $games = $this->games($weekId, $roster);
        $current = $this->starters($league, (int) $franchise->id, $weekId);
        foreach ($roster as $team) {
            if (($games[$team]['locked'] ?? false) && in_array($team, $current, true) !== in_array($team, $teamIds, true)) {
                return ['ok' => false, 'problem' => 'locked'];
            }
        }

        $this->write($league, (int) $franchise->id, $weekId, $roster, $teamIds);

        return ['ok' => true];
    }

    /**
     * Give every franchise that set nothing for this week the lineup it would
     * see on its lineup screen, so an owner who is away still plays. Only for
     * weeks after the league went live: a league drafted mid-season must not
     * collect points for weeks it never played.
     */
    public function carryOver(League $league, int $weekId): void
    {
        if ($league->status !== 'active' || $league->activated_at === null) {
            return;
        }

        $firstGame = $this->db->table('picks_events')->where('week_id', $weekId)->min('match_date');
        // Only once the week has begun: filling future weeks now would freeze
        // today's lineup into them, and an owner's later change would not carry.
        if ($firstGame === null || Carbon::parse($firstGame)->lt($league->activated_at) || Carbon::parse($firstGame)->gt(Carbon::now())) {
            return;
        }

        $set = $this->db->table('fantasy_lineups')->where('league_id', $league->id)->where('week_id', $weekId)
            ->distinct()->pluck('franchise_id')->map(fn ($id) => (int) $id)->all();

        foreach (Franchise::query()->where('league_id', $league->id)->whereNotIn('id', $set ?: [0])->pluck('id') as $id) {
            $this->write($league, (int) $id, $weekId, $this->roster($league, (int) $id), $this->starters($league, (int) $id, $weekId));
        }
    }

    /** One row per roster team for the week, started or benched. */
    private function write(League $league, int $franchiseId, int $weekId, array $roster, array $starters): void
    {
        if ($roster === []) {
            return;
        }

        $now = Carbon::now();
        $this->db->table('fantasy_lineups')->upsert(
            array_map(fn ($team) => [
                'league_id' => $league->id, 'franchise_id' => $franchiseId, 'week_id' => $weekId, 'team_id' => $team,
                'started' => in_array($team, $starters, true), 'created_at' => $now, 'updated_at' => $now,
            ], $roster),
            ['league_id', 'franchise_id', 'week_id', 'team_id'],
            ['started', 'updated_at']
        );
    }
}
