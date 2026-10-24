<?php

namespace ErnestDefoe\Fantasy\Tests\integration;

use Carbon\Carbon;
use Flarum\Group\Group;
use Flarum\User\User;

/**
 * Two Picks seasons (college football, NBA), four teams, and two leagues:
 * "Saturday Gang" (football, still in setup, room for two franchises, the
 * admin's) and "Hoops" (NBA, active, two franchises with rosters and scores).
 * Users: 1 admin, 2 member, 3 another member.
 */
trait SeedsLeagues
{
    protected function seedLeagues(): void
    {
        $now = Carbon::now();

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'third', 'email' => 'third@machine.local', 'is_email_confirmed' => 1],
            ],
            'group_user' => [['user_id' => 3, 'group_id' => Group::MEMBER_ID]],
            'picks_seasons' => [
                ['id' => 1, 'name' => 'CFB 2026', 'slug' => 'cfb-2026', 'year' => 2026, 'league' => 'cfb'],
                ['id' => 2, 'name' => 'NBA 2026', 'slug' => 'nba-2026', 'year' => 2026, 'league' => 'nba'],
            ],
            'picks_teams' => [
                ['id' => 1, 'name' => 'Celtics', 'slug' => 'celtics', 'logo_path' => 'celtics.png'],
                ['id' => 2, 'name' => 'Lakers', 'slug' => 'lakers', 'logo_path' => 'lakers.png'],
                ['id' => 3, 'name' => 'Bulls', 'slug' => 'bulls', 'logo_path' => 'bulls.png'],
                ['id' => 4, 'name' => 'Knicks', 'slug' => 'knicks', 'logo_path' => 'knicks.png'],
            ],
            'fantasy_leagues' => [
                ['id' => 1, 'name' => 'Saturday Gang', 'slug' => 'saturday-gang', 'season_id' => 1, 'commissioner_id' => 1, 'status' => 'setup', 'max_franchises' => 2, 'created_at' => $now, 'updated_at' => $now],
                ['id' => 2, 'name' => 'Hoops', 'slug' => 'hoops', 'description' => 'Hardwood', 'season_id' => 2, 'commissioner_id' => 2, 'status' => 'active', 'max_franchises' => 8,
                    'points_per_point' => 0.25, 'points_per_point_allowed' => -0.125, 'win_bonus' => 10, 'shutout_bonus' => 0, 'points_per_margin' => 0.5, 'created_at' => $now, 'updated_at' => $now],
            ],
            'fantasy_franchises' => [
                ['id' => 1, 'league_id' => 1, 'user_id' => 1, 'name' => 'Admin FC', 'created_at' => $now],
                ['id' => 2, 'league_id' => 2, 'user_id' => 2, 'name' => 'Normal Ballers', 'created_at' => $now],
                ['id' => 3, 'league_id' => 2, 'user_id' => 1, 'name' => 'Admin Hoopers', 'created_at' => $now],
            ],
            'fantasy_rosters' => [
                ['league_id' => 2, 'franchise_id' => 2, 'team_id' => 1],
                ['league_id' => 2, 'franchise_id' => 2, 'team_id' => 2],
                ['league_id' => 2, 'franchise_id' => 3, 'team_id' => 3],
            ],
            'fantasy_scores' => [
                ['league_id' => 2, 'franchise_id' => 2, 'week_id' => 1, 'team_id' => 1, 'points' => 20, 'won' => true],
                ['league_id' => 2, 'franchise_id' => 3, 'week_id' => 1, 'team_id' => 3, 'points' => 30.5, 'won' => true],
                ['league_id' => 2, 'franchise_id' => 3, 'week_id' => 2, 'team_id' => 3, 'points' => 4, 'won' => false],
            ],
        ]);
    }

    /** A request that gets past CSRF for a guest, and authenticates anyone else. */
    protected function call(string $method, string $path, ?int $actor = null, ?array $json = null, array $query = [])
    {
        $options = $json === null ? [] : ['json' => $json];

        if ($actor) {
            return $this->send($this->request($method, $path, $options + ['authenticatedAs' => $actor])->withQueryParams($query));
        }

        $request = $this->request($method, $path, $options)->withQueryParams($query);

        return $this->send($method === 'GET' ? $request : $this->requestWithCsrfToken($request));
    }

    protected function body($response): array
    {
        return json_decode((string) $response->getBody(), true) ?? [];
    }

    /** How many queries one request runs. */
    protected function queriesFor(callable $request): int
    {
        $db = $this->database();
        $db->flushQueryLog();
        $db->enableQueryLog();

        $request();

        $db->disableQueryLog();

        return count($db->getQueryLog());
    }
}
