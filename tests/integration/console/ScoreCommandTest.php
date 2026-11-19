<?php

namespace ErnestDefoe\Fantasy\Tests\integration\console;

use Carbon\Carbon;
use ErnestDefoe\Fantasy\Tests\integration\SeedsLeagues;
use Flarum\Testing\integration\ConsoleTestCase;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use PHPUnit\Framework\Attributes\Test;

/**
 * fantasy:score turns finished games into points for every started team, and
 * only finished ones.
 */
class ScoreCommandTest extends ConsoleTestCase
{
    use RetrievesAuthorizedUsers;
    use SeedsLeagues;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-picks', 'ernestdefoe-fantasy');
        $this->seedLeagues();

        $now = Carbon::now();

        $this->prepareDatabase([
            'picks_weeks' => [
                ['id' => 3, 'season_id' => 2, 'name' => 'Week 3'],
            ],
            'picks_events' => [
                // Celtics beat Bulls 110-100: final.
                ['id' => 1, 'week_id' => 3, 'home_team_id' => 1, 'away_team_id' => 3, 'match_date' => $now, 'cutoff_date' => $now, 'status' => 'finished', 'home_score' => 110, 'away_score' => 100],
                // Lakers v Knicks: still being played.
                ['id' => 2, 'week_id' => 3, 'home_team_id' => 2, 'away_team_id' => 4, 'match_date' => $now, 'cutoff_date' => $now, 'status' => 'in_progress', 'home_score' => 50, 'away_score' => 40],
            ],
            'fantasy_lineups' => [
                ['league_id' => 2, 'franchise_id' => 2, 'week_id' => 3, 'team_id' => 1, 'started' => true],
                ['league_id' => 2, 'franchise_id' => 2, 'week_id' => 3, 'team_id' => 2, 'started' => true],
                ['league_id' => 2, 'franchise_id' => 3, 'week_id' => 3, 'team_id' => 3, 'started' => true],
            ],
        ]);
    }

    private function week3(): array
    {
        return $this->database()->table('fantasy_scores')->where('week_id', 3)->orderBy('team_id')
            ->get(['franchise_id', 'team_id', 'points', 'won'])
            ->map(fn ($r) => [(int) $r->franchise_id, (int) $r->team_id, (float) $r->points, (bool) $r->won])
            ->all();
    }

    #[Test]
    public function finished_games_score_and_unfinished_ones_do_not()
    {
        $this->assertStringContainsString('2 team-weeks scored across 1 leagues', $this->runCommand(['command' => 'fantasy:score']));

        // Hoops' rules: 0.25 a point, -0.125 a point allowed, 0.5 a point of
        // winning margin, 10 for a win.
        $this->assertSame([
            [2, 1, 110 * 0.25 - 100 * 0.125 + 10 * 0.5 + 10, true],
            [3, 3, 100 * 0.25 - 110 * 0.125, false],
        ], $this->week3());
    }

    #[Test]
    public function scoring_twice_changes_nothing()
    {
        $this->runCommand(['command' => 'fantasy:score']);
        $first = $this->week3();

        $this->runCommand(['command' => 'fantasy:score']);

        $this->assertSame($first, $this->week3());
        $this->assertCount(2, $first);
    }

    #[Test]
    public function weeks_past_the_window_and_drafting_leagues_are_left_alone()
    {
        // Week 3's games were three weeks ago: already scored, not re-read every hour.
        $this->database()->table('picks_events')->where('week_id', 3)->update(['match_date' => Carbon::now()->subDays(21)]);
        $this->assertStringContainsString('0 team-weeks scored', $this->runCommand(['command' => 'fantasy:score']));
        // …unless asked to catch up on everything.
        $this->assertStringContainsString('2 team-weeks scored', $this->runCommand(['command' => 'fantasy:score', '--all' => true]));
        $this->database()->table('fantasy_scores')->delete();

        // A league still drafting has no lineups to score.
        $this->database()->table('picks_events')->where('week_id', 3)->update(['match_date' => Carbon::now()->subHour()]);
        $this->database()->table('fantasy_leagues')->where('id', 2)->update(['status' => 'drafting']);
        $this->assertStringContainsString('No active leagues', $this->runCommand(['command' => 'fantasy:score']));
        $this->assertSame([], $this->week3());
    }
}
