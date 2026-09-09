<?php

namespace ErnestDefoe\Fantasy\Service;

use ErnestDefoe\Fantasy\League;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * What a started team was worth.
 *
 * 🚨 **A game only scores once it is FINISHED.** `picks_events` carries a live
 * score while a game is being played, and scoring off that would mean a
 * franchise's total moving up and down all afternoon and a matchup being
 * "final" at half time. The gate is `status = finished` AND both scores present
 * — a game with a NULL score has not been reported on, which is not 0–0.
 *
 * 🚨 **Scoring is idempotent.** Every pass upserts by
 * (league, franchise, week, team), so running it twice writes the same row
 * twice and changes nothing. That is what makes it safe on a schedule next to a
 * live-score sync that will re-report a game that was already final.
 */
class Scoring
{
    public function __construct(protected ConnectionInterface $db)
    {
    }

    /** @return array{scored: int} */
    public function week(League $league, int $weekId): array
    {
        $started = $this->db->table('fantasy_lineups')
            ->where('league_id', $league->id)
            ->where('week_id', $weekId)
            ->where('started', true)
            ->get();

        if ($started->isEmpty()) {
            return ['scored' => 0];
        }

        $teamIds = $started->pluck('team_id')->unique()->values()->all();
        $games = $this->gamesFor($weekId, $teamIds);

        $scored = 0;
        $now = Carbon::now();

        foreach ($started as $row) {
            $game = $games[(int) $row->team_id] ?? null;

            if ($game === null || !$this->isFinal($game)) {
                continue;
            }

            $isHome = (int) $game->home_team_id === (int) $row->team_id;
            $for = (int) ($isHome ? $game->home_score : $game->away_score);
            $against = (int) ($isHome ? $game->away_score : $game->home_score);

            $won = $for > $against;
            $shutout = $won && $against === 0;

            /*
             * 🚨 Margin counts only in a WIN, and only the margin of victory.
             * Multiplying a negative margin by the same rate would make a heavy
             * defeat cost more than the points-allowed rule already charges for
             * it — the same penalty twice.
             */
            $margin = $won ? $for - $against : 0;

            $bonus = ($won ? (float) $league->win_bonus : 0.0)
                + ($shutout ? (float) $league->shutout_bonus : 0.0);

            $points = $for * (float) $league->points_per_point
                + $against * (float) $league->points_per_point_allowed
                + $margin * (float) $league->points_per_margin
                + $bonus;

            $this->db->table('fantasy_scores')->updateOrInsert(
                [
                    'league_id' => $league->id,
                    'franchise_id' => (int) $row->franchise_id,
                    'week_id' => $weekId,
                    'team_id' => (int) $row->team_id,
                ],
                [
                    'points' => round($points, 2),
                    // The workings, so an old week stays explainable after a
                    // commissioner changes a rule.
                    'scored' => $for,
                    'allowed' => $against,
                    'won' => $won,
                    'shutout' => $shutout,
                    'bonus' => round($bonus, 2),
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );

            $scored++;
        }

        return ['scored' => $scored];
    }

    /** @param list<int> $teamIds */
    private function gamesFor(int $weekId, array $teamIds): array
    {
        if ($teamIds === []) {
            return [];
        }

        $rows = $this->db->table('picks_events')
            ->where('week_id', $weekId)
            ->where(function ($q) use ($teamIds) {
                $q->whereIn('home_team_id', $teamIds)->orWhereIn('away_team_id', $teamIds);
            })
            ->get();

        $byTeam = [];

        foreach ($rows as $row) {
            $byTeam[(int) $row->home_team_id] = $row;
            $byTeam[(int) $row->away_team_id] = $row;
        }

        return $byTeam;
    }

    private function isFinal(object $game): bool
    {
        return ($game->status ?? '') === 'finished'
            && $game->home_score !== null
            && $game->away_score !== null;
    }
}
