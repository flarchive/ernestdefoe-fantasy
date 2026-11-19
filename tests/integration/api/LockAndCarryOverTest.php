<?php

namespace ErnestDefoe\Fantasy\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Fantasy\League;
use ErnestDefoe\Fantasy\Service\Lineups;
use ErnestDefoe\Fantasy\Tests\integration\SeedsLeagues;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Illuminate\Contracts\Cache\Repository as Cache;
use PHPUnit\Framework\Attributes\Test;

/**
 * The league lock that keeps two members from taking the last place, the
 * carry-over that reads every franchise's roster in one query, and the
 * settings that fall back to their defaults.
 */
class LockAndCarryOverTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use SeedsLeagues;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-picks', 'ernestdefoe-fantasy');
        $this->seedLeagues();
    }

    #[Test]
    public function a_join_while_another_change_holds_the_league_is_refused_not_squeezed_in()
    {
        $lock = $this->app()->getContainer()->make(Cache::class)->getStore()->lock('fantasy.league.1', 10);
        $this->assertTrue($lock->get());

        try {
            $response = $this->call('POST', '/api/fantasy-leagues/saturday-gang/join', 2, ['name' => 'Second']);
        } finally {
            $lock->release();
        }

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(1, $this->database()->table('fantasy_franchises')->where('league_id', 1)->count());

        // Once the other change is done, the same request goes through.
        $this->assertSame(200, $this->call('POST', '/api/fantasy-leagues/saturday-gang/join', 2, ['name' => 'Second'])->getStatusCode());
        $this->assertSame(422, $this->call('POST', '/api/fantasy-leagues/saturday-gang/join', 3, ['name' => 'Third'])->getStatusCode(), 'Two of two places taken');
    }

    #[Test]
    public function carry_over_costs_the_same_queries_however_many_franchises_need_it()
    {
        $yesterday = Carbon::now()->subDay();
        $this->prepareDatabase([
            'picks_weeks' => [
                ['id' => 20, 'season_id' => 2, 'name' => 'Week 20', 'week_number' => 20],
                ['id' => 21, 'season_id' => 2, 'name' => 'Week 21', 'week_number' => 21],
            ],
            'picks_events' => [
                ['id' => 200, 'week_id' => 20, 'home_team_id' => 1, 'away_team_id' => 3, 'match_date' => $yesterday, 'cutoff_date' => $yesterday, 'status' => 'finished', 'home_score' => 1, 'away_score' => 0],
                ['id' => 210, 'week_id' => 21, 'home_team_id' => 1, 'away_team_id' => 3, 'match_date' => $yesterday, 'cutoff_date' => $yesterday, 'status' => 'finished', 'home_score' => 1, 'away_score' => 0],
            ],
            // Franchise 2 started team 2 in week 20; franchise 3 set nothing.
            'fantasy_lineups' => [
                ['league_id' => 2, 'franchise_id' => 2, 'week_id' => 20, 'team_id' => 1, 'started' => false],
                ['league_id' => 2, 'franchise_id' => 2, 'week_id' => 20, 'team_id' => 2, 'started' => true],
            ],
        ]);
        $this->database()->table('fantasy_leagues')->where('id', 2)->update(['activated_at' => Carbon::now()->subDays(3), 'starters' => 1]);
        $lineups = $this->app()->getContainer()->make(Lineups::class);
        $league = fn () => League::query()->find(2);

        $two = $this->queriesFor(fn () => $lineups->carryOver($league(), 21));

        // Week 21: franchise 2 carries its week-20 starter, franchise 3 its first team drafted.
        $started = $this->database()->table('fantasy_lineups')->where('week_id', 21)->where('started', true)
            ->orderBy('franchise_id')->get(['franchise_id', 'team_id'])
            ->map(fn ($r) => [(int) $r->franchise_id, (int) $r->team_id])->all();
        $this->assertSame([[2, 2], [3, 3]], $started);

        // Four more franchises with rosters and no lineup: the same number of queries.
        $this->database()->table('fantasy_lineups')->where('week_id', 21)->delete();
        foreach ([4, 5, 6, 7] as $id) {
            $this->database()->table('users')->insert(['id' => $id, 'username' => "u$id", 'email' => "u$id@machine.local", 'password' => 'x', 'is_email_confirmed' => 1, 'joined_at' => Carbon::now()]);
            $this->database()->table('fantasy_franchises')->insert(['id' => $id, 'league_id' => 2, 'user_id' => $id, 'name' => "F$id", 'created_at' => Carbon::now()]);
            $this->database()->table('picks_teams')->insert(['id' => $id + 10, 'name' => "T$id", 'slug' => "t$id"]);
            $this->database()->table('fantasy_rosters')->insert(['league_id' => 2, 'franchise_id' => $id, 'team_id' => $id + 10]);
        }

        $this->assertSame($two, $this->queriesFor(fn () => $lineups->carryOver($league(), 21)));
        $this->assertSame(6, $this->database()->table('fantasy_lineups')->where('week_id', 21)->where('started', true)->count());
    }

    #[Test]
    public function a_cleared_default_goes_back_to_the_registered_default()
    {
        $this->assertSame(204, $this->send($this->request('POST', '/api/settings', [
            'authenticatedAs' => 1,
            'json' => ['ernestdefoe-fantasy.default_max_franchises' => ''],
        ]))->getStatusCode());

        $forum = $this->body($this->call('GET', '/api'))['data']['attributes'];

        $this->assertSame(12, $forum['fantasyMaxFranchises']);
    }
}
