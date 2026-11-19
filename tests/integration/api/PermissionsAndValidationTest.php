<?php

namespace ErnestDefoe\Fantasy\Tests\integration\api;

use ErnestDefoe\Fantasy\Tests\integration\SeedsLeagues;
use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

/** Who may see and play, and what a new league must carry. */
class PermissionsAndValidationTest extends TestCase
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
    public function a_forum_closed_to_guests_shows_them_no_leagues()
    {
        // A closed forum: viewing moves from Guests to Members.
        $this->database()->table('group_permission')->where('group_id', Group::GUEST_ID)->where('permission', 'viewForum')->delete();
        $this->database()->table('group_permission')->insert(['group_id' => Group::MEMBER_ID, 'permission' => 'viewForum']);

        $this->assertContains($this->call('GET', '/api/fantasy-leagues')->getStatusCode(), [401, 403]);
        $this->assertContains($this->call('GET', '/api/fantasy-leagues/hoops')->getStatusCode(), [401, 403]);
        $this->assertContains($this->call('GET', '/api/fantasy-leagues/hoops/draft', null, null, [])->getStatusCode(), [401, 403]);
        $this->assertSame(200, $this->call('GET', '/api/fantasy-leagues', 2)->getStatusCode(), 'Members still see them');
    }

    #[Test]
    public function playing_needs_the_play_permission()
    {
        $this->database()->table('group_permission')->where('permission', 'fantasy.play')->delete();

        $this->assertSame(403, $this->call('POST', '/api/fantasy-leagues/saturday-gang/join', 2, ['name' => 'Nope'])->getStatusCode());
        $this->assertSame(0, $this->database()->table('fantasy_franchises')->where('user_id', 2)->where('league_id', 1)->count());
    }

    #[Test]
    public function members_hold_the_play_permission_out_of_the_box()
    {
        $this->assertTrue($this->database()->table('group_permission')
            ->where('group_id', Group::MEMBER_ID)->where('permission', 'fantasy.play')->exists());
    }

    #[Test]
    public function a_league_needs_a_real_season_and_a_bounded_description()
    {
        $this->assertSame(422, $this->createLeague(1, ['name' => 'No season'])->getStatusCode());
        $this->assertSame(422, $this->createLeague(1, ['name' => 'Ghost', 'seasonId' => 99])->getStatusCode());
        $this->assertSame(422, $this->createLeague(1, ['name' => 'Wordy', 'seasonId' => 1, 'description' => str_repeat('x', 2001)])->getStatusCode());
        $this->assertSame(201, $this->createLeague(1, ['name' => 'Fine', 'seasonId' => 1, 'description' => str_repeat('x', 2000)])->getStatusCode());
    }

    #[Test]
    public function two_leagues_with_the_same_long_name_get_different_slugs()
    {
        $name = str_repeat('Long name ', 18); // 180 characters, slugged to about the cap
        $a = $this->body($this->createLeague(1, ['name' => $name, 'seasonId' => 1]))['data']['attributes']['slug'];
        $b = $this->body($this->createLeague(1, ['name' => $name, 'seasonId' => 1]))['data']['attributes']['slug'];

        $this->assertNotSame($a, $b);
        $this->assertSame($a.'-2', $b);
        $this->assertLessThanOrEqual(189, strlen($b));
    }

    #[Test]
    public function the_league_list_comes_a_page_at_a_time()
    {
        $now = date('Y-m-d H:i:s');
        $rows = [];
        for ($i = 10; $i < 62; $i++) {
            $rows[] = ['id' => $i, 'name' => "L$i", 'slug' => "l$i", 'season_id' => 1, 'commissioner_id' => 1, 'status' => 'setup', 'created_at' => $now, 'updated_at' => $now];
        }
        $this->database()->table('fantasy_leagues')->insert($rows);

        $first = $this->body($this->call('GET', '/api/fantasy-leagues'));
        $this->assertCount(50, $first['data']);
        $this->assertArrayHasKey('next', $first['links']);

        $second = $this->body($this->call('GET', '/api/fantasy-leagues', null, null, ['page' => ['offset' => 50]]));
        $this->assertCount(4, $second['data']);
        $this->assertArrayNotHasKey('next', $second['links'] ?? []);
    }
}
