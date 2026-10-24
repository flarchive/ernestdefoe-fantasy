<?php

namespace ErnestDefoe\Fantasy\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Fantasy\Tests\integration\SeedsLeagues;
use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

class LeaguesTest extends TestCase
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
    public function the_forum_knows_the_create_forms_numbers()
    {
        $forum = $this->body($this->call('GET', '/api'))['data']['attributes'];

        $this->assertSame(12, $forum['fantasyMaxFranchises']);
        $this->assertSame(8, $forum['fantasyRosterSize']);
        $this->assertSame(4, $forum['fantasyStarters']);
    }

    #[Test]
    public function the_forum_knows_the_settings_the_admin_chose()
    {
        $this->setting('ernestdefoe-fantasy.default_max_franchises', '10');
        $this->setting('ernestdefoe-fantasy.nav_label', 'Fantasy Hoops');

        $forum = $this->body($this->call('GET', '/api'))['data']['attributes'];

        $this->assertSame(10, $forum['fantasyMaxFranchises']);
        $this->assertSame('Fantasy Hoops', $forum['fantasyNavLabel']);
    }

    #[Test]
    public function anyone_sees_the_leagues_newest_first_with_their_sport()
    {
        $response = $this->call('GET', '/api/fantasy/leagues');

        $this->assertSame(200, $response->getStatusCode());
        $body = $this->body($response);

        $this->assertSame(['hoops', 'saturday-gang'], array_column($body['leagues'], 'slug'));
        $this->assertSame(['hardwood', 'gridiron'], array_column($body['leagues'], 'sport'));
        $this->assertSame([2, 1], array_column($body['leagues'], 'franchises'));
        $this->assertSame(['NBA 2026', 'CFB 2026'], array_column($body['seasons'], 'name'));
        $this->assertFalse($body['canCreate']);
    }

    #[Test]
    public function only_those_granted_it_may_create_a_league()
    {
        $this->assertFalse($this->body($this->call('GET', '/api/fantasy/leagues', 2))['canCreate']);
        $this->assertTrue($this->body($this->call('GET', '/api/fantasy/leagues', 1))['canCreate']);
    }

    #[Test]
    public function a_member_granted_the_permission_may_create_a_league()
    {
        $this->database()->table('group_permission')->insert(['group_id' => Group::MEMBER_ID, 'permission' => 'fantasy.createLeague']);

        $this->assertTrue($this->body($this->call('GET', '/api/fantasy/leagues', 2))['canCreate']);
    }

    #[Test]
    public function the_league_list_costs_the_same_queries_however_many_leagues_exist()
    {
        $this->call('GET', '/api/fantasy/leagues'); // Warms the app's own caches.
        $few = $this->queriesFor(fn () => $this->call('GET', '/api/fantasy/leagues'));

        for ($n = 3; $n <= 8; $n++) {
            $this->database()->table('picks_seasons')->insert(['id' => $n, 'name' => "S$n", 'slug' => "s$n", 'year' => 2026, 'league' => 'mlb']);
            $this->database()->table('fantasy_leagues')->insert(['id' => $n, 'name' => "L$n", 'slug' => "l$n", 'season_id' => $n, 'status' => 'setup', 'created_at' => Carbon::now()]);
            $this->database()->table('fantasy_franchises')->insert(['league_id' => $n, 'user_id' => 2, 'name' => "F$n"]);
        }

        $this->assertSame($few, $this->queriesFor(fn () => $this->call('GET', '/api/fantasy/leagues')));
    }

    #[Test]
    public function a_league_shows_its_table_most_points_first()
    {
        $response = $this->call('GET', '/api/fantasy/league', null, null, ['slug' => 'hoops']);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->body($response);

        $this->assertSame('hardwood', $body['league']['sport']);
        $this->assertSame(['Admin Hoopers', 'Normal Ballers'], array_column($body['table'], 'name'));
        $this->assertEquals([34.5, 20], array_column($body['table'], 'points'));
        $this->assertSame([1, 1], array_column($body['table'], 'wins'));
        $this->assertSame(['Celtics', 'Lakers'], array_column($body['table'][1]['roster'], 'name'));
        $this->assertNull($body['rules']['shutout_bonus'], 'Basketball has no shutouts to reward');
    }

    #[Test]
    public function a_league_that_does_not_exist_is_not_found()
    {
        $this->assertSame(404, $this->call('GET', '/api/fantasy/league', null, null, ['slug' => 'nope'])->getStatusCode());
    }

    #[Test]
    public function a_league_page_costs_the_same_queries_however_many_franchises_it_has()
    {
        $this->call('GET', '/api/fantasy/league', null, null, ['slug' => 'hoops']);
        $few = $this->queriesFor(fn () => $this->call('GET', '/api/fantasy/league', null, null, ['slug' => 'hoops']));

        for ($n = 10; $n <= 15; $n++) {
            $this->database()->table('users')->insert(['id' => $n, 'username' => "u$n", 'email' => "u$n@machine.local", 'password' => '', 'is_email_confirmed' => 1, 'joined_at' => Carbon::now()]);
            $this->database()->table('fantasy_franchises')->insert(['id' => $n, 'league_id' => 2, 'user_id' => $n, 'name' => "F$n"]);
            $this->database()->table('fantasy_rosters')->insert(['league_id' => 2, 'franchise_id' => $n, 'team_id' => 4 + $n]);
            $this->database()->table('picks_teams')->insert(['id' => 4 + $n, 'name' => "T$n", 'slug' => "t$n"]);
        }

        $this->assertSame($few, $this->queriesFor(fn () => $this->call('GET', '/api/fantasy/league', null, null, ['slug' => 'hoops'])));
    }

    #[Test]
    public function a_guest_cannot_create_a_league()
    {
        $this->assertSame(401, $this->call('POST', '/api/fantasy/leagues', null, ['name' => 'Guests'])->getStatusCode());
        $this->assertSame(2, $this->database()->table('fantasy_leagues')->count());
    }

    #[Test]
    public function a_guest_cannot_join_a_league()
    {
        $this->assertSame(401, $this->call('POST', '/api/fantasy/leagues/saturday-gang/join', null, [])->getStatusCode());
        $this->assertSame(3, $this->database()->table('fantasy_franchises')->count());
    }

    #[Test]
    public function a_member_without_the_permission_cannot_create_a_league()
    {
        $this->assertSame(403, $this->call('POST', '/api/fantasy/leagues', 2, ['name' => 'Mine', 'seasonId' => 2])->getStatusCode());
        $this->assertSame(2, $this->database()->table('fantasy_leagues')->count());
    }

    #[Test]
    public function a_new_league_takes_the_forums_numbers_and_its_sports_scoring()
    {
        $this->setting('ernestdefoe-fantasy.default_max_franchises', '6');
        $this->database()->table('group_permission')->insert(['group_id' => Group::MEMBER_ID, 'permission' => 'fantasy.createLeague']);

        $response = $this->call('POST', '/api/fantasy/leagues', 2, ['name' => 'Hoops', 'seasonId' => 2, 'maxFranchises' => 30]);

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $league = $this->body($response)['league'];
        $this->assertSame('hoops-2', $league['slug']);
        $this->assertSame(6, $league['maxFranchises'], 'The forum\'s number, not the form\'s');
        $this->assertSame('setup', $league['status']);

        $row = $this->database()->table('fantasy_leagues')->where('slug', 'hoops-2')->first();
        $this->assertEquals(0.25, $row->points_per_point, 'Basketball scoring for an NBA season');
        $this->assertSame(2, (int) $row->commissioner_id);
        $this->assertSame(1, $this->database()->table('fantasy_franchises')->where('league_id', $row->id)->where('user_id', 2)->count(), 'The commissioner has a franchise');
    }

    #[Test]
    public function a_league_needs_a_sensible_name()
    {
        $this->assertSame(422, $this->call('POST', '/api/fantasy/leagues', 1, ['name' => '  '])->getStatusCode());
        $this->assertSame(422, $this->call('POST', '/api/fantasy/leagues', 1, ['name' => str_repeat('x', 190)])->getStatusCode());
    }

    #[Test]
    public function a_member_joins_once_until_the_league_is_full()
    {
        $first = $this->call('POST', '/api/fantasy/leagues/saturday-gang/join', 2, ['name' => 'Normal FC']);
        $this->assertSame(200, $first->getStatusCode(), (string) $first->getBody());
        $this->assertSame('Normal FC', $this->body($first)['franchise']['name']);

        $again = $this->call('POST', '/api/fantasy/leagues/saturday-gang/join', 2, ['name' => 'Second']);
        $this->assertSame($this->body($first)['franchise']['id'], $this->body($again)['franchise']['id'], 'Joining twice is one franchise');

        $full = $this->call('POST', '/api/fantasy/leagues/saturday-gang/join', 3, []);
        $this->assertSame(422, $full->getStatusCode(), 'Two of two places taken');
        $this->assertSame(2, $this->database()->table('fantasy_franchises')->where('league_id', 1)->count());
    }

    #[Test]
    public function nobody_new_joins_a_league_that_has_started()
    {
        $this->assertSame(422, $this->call('POST', '/api/fantasy/leagues/hoops/join', 3, [])->getStatusCode());
        $this->assertSame(404, $this->call('POST', '/api/fantasy/leagues/nope/join', 3, [])->getStatusCode());
    }
}
