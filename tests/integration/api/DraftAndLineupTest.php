<?php

namespace ErnestDefoe\Fantasy\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Fantasy\League;
use ErnestDefoe\Fantasy\Service\Scoring;
use ErnestDefoe\Fantasy\Tests\integration\SeedsLeagues;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * "Saturday Gang" (league 1, admin's, setup, two places) is joined by user 2,
 * drafted two teams each, and played: week 10 is over (teams 1 v 2 finished),
 * week 11 kicks off tomorrow (1 v 3, 2 v 4).
 */
class DraftAndLineupTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use SeedsLeagues;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-picks', 'ernestdefoe-fantasy');
        $this->seedLeagues();
        $past = Carbon::now()->subDays(3);
        $soon = Carbon::now()->addDay();
        $this->prepareDatabase([
            'picks_weeks' => [
                ['id' => 10, 'season_id' => 1, 'name' => 'Week 10', 'week_number' => 10],
                ['id' => 11, 'season_id' => 1, 'name' => 'Week 11', 'week_number' => 11],
            ],
            'picks_events' => [
                ['id' => 100, 'week_id' => 10, 'home_team_id' => 1, 'away_team_id' => 2, 'match_date' => $past, 'cutoff_date' => $past, 'status' => 'finished', 'home_score' => 28, 'away_score' => 14],
                ['id' => 101, 'week_id' => 10, 'home_team_id' => 3, 'away_team_id' => 4, 'match_date' => $past, 'cutoff_date' => $past, 'status' => 'finished', 'home_score' => 10, 'away_score' => 17],
                ['id' => 110, 'week_id' => 11, 'home_team_id' => 1, 'away_team_id' => 3, 'match_date' => $soon, 'cutoff_date' => $soon, 'status' => 'scheduled'],
                ['id' => 111, 'week_id' => 11, 'home_team_id' => 2, 'away_team_id' => 4, 'match_date' => $soon, 'cutoff_date' => $soon, 'status' => 'scheduled'],
            ],
        ]);
        $this->database()->table('fantasy_leagues')->where('id', 1)->update(['roster_size' => 2, 'starters' => 1]);
    }

    private function joinAndStart(): array
    {
        $this->assertSame(200, $this->call('POST', '/api/fantasy/leagues/saturday-gang/join', 2, ['name' => 'Normal Nine'])->getStatusCode());
        $this->assertSame(200, $this->call('POST', '/api/fantasy/leagues/saturday-gang/draft/start', 1)->getStatusCode());

        return $this->body($this->call('GET', '/api/fantasy/draft', 1, null, ['slug' => 'saturday-gang']));
    }

    /** The user who owns the franchise on the clock. */
    private function onTheClock(): int
    {
        $board = $this->body($this->call('GET', '/api/fantasy/draft', 1, null, ['slug' => 'saturday-gang']));

        return (int) $this->database()->table('fantasy_franchises')->where('id', $board['clock']['franchise'])->value('user_id');
    }

    #[Test]
    public function the_league_page_says_what_a_visitor_can_do()
    {
        $page = fn (?int $as) => $this->body($this->call('GET', '/api/fantasy/league', $as, null, ['slug' => 'saturday-gang']))['viewer'];

        $this->assertSame(['franchise' => 1, 'manager' => true, 'canJoin' => false], $page(1));
        $this->assertSame(['franchise' => null, 'manager' => false, 'canJoin' => true], $page(2));
        $this->assertFalse($page(null)['canJoin']);
    }

    #[Test]
    public function only_the_commissioner_starts_a_draft_and_it_needs_two_franchises()
    {
        $this->assertSame(422, $this->call('POST', '/api/fantasy/leagues/saturday-gang/draft/start', 1)->getStatusCode());

        $this->call('POST', '/api/fantasy/leagues/saturday-gang/join', 2, ['name' => 'Normal Nine']);
        $this->assertSame(403, $this->call('POST', '/api/fantasy/leagues/saturday-gang/draft/start', 2)->getStatusCode());
        $this->assertSame(200, $this->call('POST', '/api/fantasy/leagues/saturday-gang/draft/start', 1)->getStatusCode());

        $this->assertSame('drafting', League::query()->find(1)->status);
        $this->assertEqualsCanonicalizing([1, 2], $this->database()->table('fantasy_franchises')->where('league_id', 1)->pluck('draft_position')->map(fn ($p) => (int) $p)->all());
    }

    #[Test]
    public function the_draft_snakes_enforces_turns_and_ends_when_rosters_are_full()
    {
        $board = $this->joinAndStart();
        $first = $board['order'][0]['id'];
        $second = $board['order'][1]['id'];
        $this->assertSame(['franchise' => $first, 'round' => 1, 'pick' => 1, 'overall' => 1], $board['clock']);
        $this->assertCount(4, $board['available']);

        $owner = $this->onTheClock();

        // Out of turn: refused, even though the team is free. (The admin is the
        // commissioner and may pick for anyone, so only the member can be out of turn.)
        if ($owner === 1) {
            $this->assertSame(403, $this->call('POST', '/api/fantasy/leagues/saturday-gang/draft/pick', 2, ['teamId' => 1])->getStatusCode());
        }
        $this->assertSame(200, $this->call('POST', '/api/fantasy/leagues/saturday-gang/draft/pick', $owner, ['teamId' => 1])->getStatusCode());

        // Taken is taken.
        $this->assertSame(422, $this->call('POST', '/api/fantasy/leagues/saturday-gang/draft/pick', $this->onTheClock(), ['teamId' => 1])->getStatusCode());

        // Snake: the second franchise picks twice in a row, then the first again.
        $clock = fn () => $this->body($this->call('GET', '/api/fantasy/draft', 1, null, ['slug' => 'saturday-gang']))['clock'];
        $this->assertSame($second, $clock()['franchise']);
        $this->call('POST', '/api/fantasy/leagues/saturday-gang/draft/pick', $this->onTheClock(), ['teamId' => 2]);
        $this->assertSame($second, $clock()['franchise']);
        $this->assertSame(2, $clock()['round']);
        $this->call('POST', '/api/fantasy/leagues/saturday-gang/draft/pick', $this->onTheClock(), ['teamId' => 3]);
        $this->assertSame($first, $clock()['franchise']);

        $last = $this->body($this->call('POST', '/api/fantasy/leagues/saturday-gang/draft/pick', $this->onTheClock(), ['teamId' => 4]));
        $this->assertTrue($last['finished']);

        $league = League::query()->find(1);
        $this->assertSame('active', $league->status);
        $this->assertNotNull($league->activated_at);
        $this->assertSame(422, $this->call('POST', '/api/fantasy/leagues/saturday-gang/draft/pick', 1, ['teamId' => 4])->getStatusCode());
    }

    #[Test]
    public function a_member_cannot_pick_out_of_turn_but_the_commissioner_can_pick_for_them()
    {
        $this->joinAndStart();
        // Make the member's franchise first on the clock.
        $this->database()->table('fantasy_franchises')->where('league_id', 1)->where('user_id', 2)->update(['draft_position' => 1]);
        $this->database()->table('fantasy_franchises')->where('league_id', 1)->where('user_id', 1)->update(['draft_position' => 2]);

        $this->assertTrue($this->body($this->call('GET', '/api/fantasy/draft', 2, null, ['slug' => 'saturday-gang']))['viewer']['canPick']);
        $this->assertSame(403, $this->call('POST', '/api/fantasy/leagues/saturday-gang/draft/pick', 3, ['teamId' => 1])->getStatusCode());

        // The commissioner, picking while the member is on the clock, picks FOR the member.
        $this->assertSame(200, $this->call('POST', '/api/fantasy/leagues/saturday-gang/draft/pick', 1, ['teamId' => 2])->getStatusCode());
        $memberFranchise = (int) $this->database()->table('fantasy_franchises')->where('league_id', 1)->where('user_id', 2)->value('id');
        $this->assertSame($memberFranchise, (int) $this->database()->table('fantasy_rosters')->where('league_id', 1)->where('team_id', 2)->value('franchise_id'));
    }

    /** A finished draft: the admin holds teams 1 and 4, the member 2 and 3. */
    private function drafted(): int
    {
        $this->call('POST', '/api/fantasy/leagues/saturday-gang/join', 2, ['name' => 'Normal Nine']);
        $member = (int) $this->database()->table('fantasy_franchises')->where('league_id', 1)->where('user_id', 2)->value('id');
        $now = Carbon::now();
        $this->database()->table('fantasy_rosters')->insert([
            ['league_id' => 1, 'franchise_id' => 1, 'team_id' => 1, 'acquired_via' => 'draft', 'created_at' => $now],
            ['league_id' => 1, 'franchise_id' => $member, 'team_id' => 2, 'acquired_via' => 'draft', 'created_at' => $now],
            ['league_id' => 1, 'franchise_id' => $member, 'team_id' => 3, 'acquired_via' => 'draft', 'created_at' => $now],
            ['league_id' => 1, 'franchise_id' => 1, 'team_id' => 4, 'acquired_via' => 'draft', 'created_at' => $now],
        ]);
        $this->database()->table('fantasy_leagues')->where('id', 1)->update(['status' => 'active', 'activated_at' => Carbon::now()->subWeeks(2)]);

        return $member;
    }

    #[Test]
    public function the_lineup_screen_opens_on_the_next_week_with_the_first_teams_drafted_starting()
    {
        $this->drafted();
        $lineup = $this->body($this->call('GET', '/api/fantasy/lineup', 2, null, ['slug' => 'saturday-gang']));

        $this->assertSame(11, $lineup['week']);
        $this->assertSame([2, 3], array_column($lineup['teams'], 'id'));
        $this->assertSame([true, false], array_column($lineup['teams'], 'starting'));
        $this->assertSame('Celtics', $lineup['teams'][1]['game']['opponent']['name']);
        $this->assertFalse($lineup['teams'][0]['game']['locked']);

        $this->assertSame(403, $this->call('GET', '/api/fantasy/lineup', 3, null, ['slug' => 'saturday-gang'])->getStatusCode());
    }

    #[Test]
    public function a_lineup_keeps_to_the_roster_the_starter_count_and_kickoff()
    {
        $this->drafted();
        $save = fn (array $teams, int $week = 11) => $this->call('POST', '/api/fantasy/leagues/saturday-gang/lineup', 2, ['weekId' => $week, 'teamIds' => $teams]);

        $this->assertSame(422, $save([2, 3])->getStatusCode(), 'more than one starter');
        $this->assertSame(422, $save([1])->getStatusCode(), 'not on the roster');
        $this->assertSame(422, $save([3], 10)->getStatusCode(), 'week 10 has kicked off');
        $this->assertSame(200, $save([3])->getStatusCode());

        $lineup = $this->body($this->call('GET', '/api/fantasy/lineup', 2, null, ['slug' => 'saturday-gang', 'week' => 11]));
        $this->assertSame([false, true], array_column($lineup['teams'], 'starting'));
    }

    #[Test]
    public function an_owner_who_sets_nothing_plays_last_weeks_lineup_but_not_before_the_league_went_live()
    {
        $member = $this->drafted();
        $league = League::query()->find(1);
        $scoring = $this->app()->getContainer()->make(Scoring::class);

        // Week 10 kicked off after the league went live: the member never set a lineup,
        // so the first team drafted (2, who lost 14–28) plays, and is scored.
        $scoring->week($league, 10);
        $this->assertSame(1, $this->database()->table('fantasy_scores')->where('franchise_id', $member)->where('week_id', 10)->count());

        // A league that went live AFTER week 10 collects nothing for it.
        $this->database()->table('fantasy_scores')->delete();
        $this->database()->table('fantasy_lineups')->delete();
        $this->database()->table('fantasy_leagues')->where('id', 1)->update(['activated_at' => Carbon::now()]);
        $scoring->week(League::query()->find(1), 10);
        $this->assertSame(0, $this->database()->table('fantasy_scores')->count());
    }

    #[Test]
    public function a_game_already_played_when_the_draft_ended_never_scores()
    {
        $member = $this->drafted();
        // The draft ended AFTER week 10's games were final; the member starts team 2 for it anyway.
        $this->database()->table('fantasy_leagues')->where('id', 1)->update(['activated_at' => Carbon::now()]);
        $this->database()->table('fantasy_lineups')->insert(['league_id' => 1, 'franchise_id' => $member, 'week_id' => 10, 'team_id' => 2, 'started' => true]);

        $this->app()->getContainer()->make(Scoring::class)->week(League::query()->find(1), 10);

        $this->assertSame(0, $this->database()->table('fantasy_scores')->where('league_id', 1)->count());
        $lineup = $this->body($this->call('GET', '/api/fantasy/lineup', 2, null, ['slug' => 'saturday-gang', 'week' => 10]));
        $this->assertTrue($lineup['teams'][0]['game']['beforeDraft']);
    }

    #[Test]
    public function a_franchise_nobody_named_is_named_after_its_owner()
    {
        $this->call('POST', '/api/fantasy/leagues/saturday-gang/join', 2, ['name' => '']);

        // Through the translator with the owner's name (the test forum has no language
        // pack, so the wording itself is not here to compare against).
        $expected = $this->app()->getContainer()->make('translator')->trans('ernestdefoe-fantasy.api.default_franchise', ['name' => 'normal']);
        $this->assertSame($expected, $this->database()->table('fantasy_franchises')->where('league_id', 1)->where('user_id', 2)->value('name'));
        $this->assertNotSame('Franchise', $expected);
    }

    #[Test]
    public function the_draft_board_costs_the_same_however_many_picks_it_shows()
    {
        $this->joinAndStart();
        $board = fn () => $this->queriesFor(fn () => $this->call('GET', '/api/fantasy/draft', 2, null, ['slug' => 'saturday-gang']));

        $before = $board();
        $this->call('POST', '/api/fantasy/leagues/saturday-gang/draft/pick', $this->onTheClock(), ['teamId' => 1]);
        $this->call('POST', '/api/fantasy/leagues/saturday-gang/draft/pick', $this->onTheClock(), ['teamId' => 2]);

        $this->assertSame($before, $board());
    }
}
