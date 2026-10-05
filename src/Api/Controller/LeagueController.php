<?php

namespace ErnestDefoe\Fantasy\Api\Controller;

use ErnestDefoe\Fantasy\Franchise;
use ErnestDefoe\Fantasy\League;
use ErnestDefoe\Fantasy\Service\Sports\Scoring as SportScoring;
use Flarum\Api\Exception\ResourceNotFoundException;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** One league: its rules, its franchises and their rosters. */
class LeagueController implements RequestHandlerInterface
{
    public function __construct(protected ConnectionInterface $db)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $slug = (string) ($request->getQueryParams()['slug'] ?? '');
        $league = League::query()->where('slug', $slug)->first();

        if ($league === null) {
            throw new ResourceNotFoundException();
        }

        $competition = $league->competition();

        $franchises = Franchise::query()
            ->where('league_id', $league->id)
            ->with('user')
            ->get();

        // Every roster in one query, keyed by franchise — not one query per
        // franchise, which is a query per row on a page that lists them all.
        $rosters = $this->db->table('fantasy_rosters as r')
            ->join('picks_teams as t', 't.id', '=', 'r.team_id')
            ->where('r.league_id', $league->id)
            ->get(['r.franchise_id', 't.id', 't.name', 't.logo_path'])
            ->groupBy('franchise_id');

        $totals = $this->db->table('fantasy_scores')
            ->where('league_id', $league->id)
            ->selectRaw('franchise_id, SUM(points) AS points, SUM(won) AS wins, COUNT(*) AS games')
            ->groupBy('franchise_id')
            ->get()
            ->keyBy('franchise_id');

        $table = [];

        foreach ($franchises as $franchise) {
            $total = $totals[$franchise->id] ?? null;

            $table[] = [
                'id' => (int) $franchise->id,
                'name' => (string) $franchise->name,
                'member' => (string) ($franchise->user->username ?? ''),
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

        return new JsonResponse([
            'league' => [
                'name' => (string) $league->name,
                'slug' => (string) $league->slug,
                'description' => (string) $league->description,
                'status' => (string) $league->status,
                'sport' => SportScoring::sportOf($competition),
                'rosterSize' => (int) $league->roster_size,
                'starters' => (int) $league->starters,
            ],
            'rules' => [
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
            ],
            'table' => $table,
        ]);
    }
}
