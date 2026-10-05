<?php

namespace ErnestDefoe\Fantasy\Console;

use ErnestDefoe\Fantasy\League;
use ErnestDefoe\Fantasy\Service\Scoring;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;

/**
 * Score every open week of every active league.
 *
 * 🚨 Idempotent by construction — the scoring pass upserts — so running it
 * twice writes the same rows and changes nothing. That is what makes it safe to
 * put on a schedule beside a live-score sync that will re-report a game which
 * was already final.
 */
class ScoreCommand extends Command
{
    protected $signature = 'fantasy:score';

    protected $description = 'Score finished games for every active fantasy league.';

    public function handle(Scoring $scoring, ConnectionInterface $db): int
    {
        $leagues = League::query()->whereIn('status', ['active', 'drafting'])->get();

        if ($leagues->isEmpty()) {
            $this->line('No active leagues.');

            return self::SUCCESS;
        }

        $total = 0;

        foreach ($leagues as $league) {
            $weeks = $db->table('picks_weeks')
                ->where('season_id', $league->season_id)
                ->pluck('id');

            foreach ($weeks as $weekId) {
                $total += $scoring->week($league, (int) $weekId)['scored'];
            }
        }

        $this->line($total . ' team-weeks scored across ' . $leagues->count() . ' leagues.');

        return self::SUCCESS;
    }
}
