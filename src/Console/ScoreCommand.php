<?php

namespace ErnestDefoe\Fantasy\Console;

use ErnestDefoe\Fantasy\League;
use ErnestDefoe\Fantasy\Service\Scoring;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * Score the recent weeks of every active league: weeks with a game in the
 * last fourteen days (the window a late or corrected final can still arrive
 * in). Older weeks are already scored and stay as they are; a league that is
 * still drafting has no lineups to score. `--all` scores every week, to catch
 * up after the scheduler has been down longer than the window.
 *
 * 🚨 Idempotent by construction — the scoring pass upserts — so running it
 * twice writes the same rows and changes nothing. That is what makes it safe to
 * put on a schedule beside a live-score sync that will re-report a game which
 * was already final.
 */
class ScoreCommand extends Command
{
    private const WINDOW_DAYS = 14;

    protected $signature = 'fantasy:score {--all : Score every week of the season, not only the last 14 days}';

    protected $description = 'Score finished games for every active fantasy league.';

    public function handle(Scoring $scoring, ConnectionInterface $db): int
    {
        $leagues = League::query()->where('status', 'active')->get();

        if ($leagues->isEmpty()) {
            $this->line('No active leagues.');

            return self::SUCCESS;
        }

        // Every league's recent weeks in one query, grouped by season.
        $weeks = $db->table('picks_weeks as w')
            ->whereIn('w.season_id', $leagues->pluck('season_id')->unique()->all())
            ->when(! $this->option('all'), fn ($weeks) => $weeks->whereExists(fn ($q) => $q->select('e.id')->from('picks_events as e')
                ->whereColumn('e.week_id', 'w.id')
                ->whereBetween('e.match_date', [Carbon::now()->subDays(self::WINDOW_DAYS), Carbon::now()])))
            ->orderBy('w.id')
            ->get(['w.id', 'w.season_id'])
            ->groupBy('season_id');

        $total = 0;

        foreach ($leagues as $league) {
            foreach ($weeks[$league->season_id] ?? [] as $week) {
                $total += $scoring->week($league, (int) $week->id)['scored'];
            }
        }

        $this->line($total.' team-weeks scored across '.$leagues->count().' leagues.');

        return self::SUCCESS;
    }
}
