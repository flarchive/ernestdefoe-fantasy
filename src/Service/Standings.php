<?php

namespace ErnestDefoe\Fantasy\Service;

use ErnestDefoe\Fantasy\Franchise;
use ErnestDefoe\Fantasy\League;
use ErnestDefoe\Fantasy\Service\Sports\Scoring as SportScoring;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Collection;

/** What a league's page shows: its rules, its standings and what this visitor can do. */
class Standings
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    /**
     * @return array{rules: array<string, float|null>, table: list<array<string, mixed>>, viewer: array<string, mixed>}
     */
    public function forLeague(League $league, User $actor): array
    {
        $franchises = Franchise::query()->where('league_id', $league->id)->with('user')->get();
        $mine = $actor->exists ? $franchises->first(fn ($f) => (int) $f->user_id === (int) $actor->id) : null;

        return [
            'rules' => $this->rules($league, $league->competition()),
            'table' => $this->table($league, $franchises),
            // What the page offers this visitor: join, run the draft, set a lineup.
            'viewer' => [
                'franchise' => $mine ? (int) $mine->id : null,
                'manager' => $league->canManage($actor),
                'canJoin' => $actor->exists && ! $mine && $league->status === 'setup'
                    && $franchises->count() < (int) $league->max_franchises && $actor->can('fantasy.play'),
            ],
        ];
    }

    /** @return array<string, float|null> */
    private function rules(League $league, string $competition): array
    {
        return [
            'points_per_point' => (float) $league->points_per_point,
            'points_per_point_allowed' => (float) $league->points_per_point_allowed,
            'win_bonus' => (float) $league->win_bonus,
            /*
             * 🚨 The shutout rule is sent as null where it cannot happen, so
             * the client omits the row entirely. A basketball league starts
             * with no shutout bonus because a basketball shutout is
             * impossible, and a rule listed at 0.00 that can never pay out
             * reads as a broken rule rather than as an impossible event.
             */
            'shutout_bonus' => SportScoring::shutoutsHappen($competition) ? (float) $league->shutout_bonus : null,
            'points_per_margin' => (float) $league->points_per_margin,
        ];
    }

    /**
     * Each franchise with its points, wins and roster, most points first.
     *
     * @param Collection<int, Franchise> $franchises
     * @return list<array<string, mixed>>
     */
    private function table(League $league, Collection $franchises): array
    {
        // Every roster in one query, keyed by franchise — not one query per
        // franchise, which is a query per row on a page that lists them all.
        $rosters = $this->db->table('fantasy_rosters as r')
            ->join('picks_teams as t', 't.id', '=', 'r.team_id')
            ->where('r.league_id', $league->id)
            ->get(['r.franchise_id', 't.id', 't.name', 't.logo_path'])
            ->groupBy('franchise_id');

        // Raw only for the aggregates: SUM/COUNT with a CASE run the same on MySQL, PostgreSQL and SQLite.
        $totals = $this->db->table('fantasy_scores')
            ->where('league_id', $league->id)
            ->selectRaw('franchise_id, SUM(points) AS points, SUM(CASE WHEN won THEN 1 ELSE 0 END) AS wins, COUNT(*) AS games')
            ->groupBy('franchise_id')
            ->get()
            ->keyBy('franchise_id');

        $table = [];

        foreach ($franchises as $franchise) {
            $total = $totals[$franchise->id] ?? null;

            $table[] = [
                'id' => (int) $franchise->id,
                'name' => (string) $franchise->name,
                'member' => (string) ($franchise->user->display_name ?? ''),
                'points' => round((float) ($total->points ?? 0), 2),
                'wins' => (int) ($total->wins ?? 0),
                'games' => (int) ($total->games ?? 0),
                'roster' => collect($rosters[$franchise->id] ?? [])
                    ->map(fn ($t) => ['id' => (int) $t->id, 'name' => (string) $t->name, 'logo' => (string) $t->logo_path])
                    ->values()
                    ->all(),
            ];
        }

        // Most points first — the table everybody actually reads.
        usort($table, fn (array $a, array $b): int => $b['points'] <=> $a['points']);

        return $table;
    }
}
