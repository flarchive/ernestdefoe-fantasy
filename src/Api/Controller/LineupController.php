<?php

namespace ErnestDefoe\Fantasy\Api\Controller;

use ErnestDefoe\Fantasy\Service\Lineups;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Your lineup for one week: every team on your roster with its game, whether
 * it has locked, and who starts. Without a week, the next one still to play.
 */
class LineupController implements RequestHandlerInterface
{
    use LeagueRequest;

    public function __construct(protected TranslatorInterface $translator, private Lineups $lineups, private ConnectionInterface $db)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $league = $this->league($request);
        if ($league === null) {
            return $this->problem('league_not_found', 404);
        }
        $franchise = $this->franchiseOf($league, (int) $actor->id);
        if ($franchise === null) {
            return $this->problem('not_in_league', 403);
        }

        $weeks = $this->lineups->weeks($league);
        $ids = array_column($weeks, 'id');
        $week = (int) ($request->getQueryParams()['week'] ?? 0);
        if (! in_array($week, $ids, true)) {
            // The first week with a game still to kick off; the last one once the season is over.
            $next = $this->db->table('picks_events')->whereIn('week_id', $ids ?: [0])->where('status', 'scheduled')
                ->where('match_date', '>', Carbon::now())->min('week_id');
            $week = $next ? (int) $next : (int) (end($ids) ?: 0);
        }

        $roster = $this->lineups->roster($league, (int) $franchise->id);
        $games = $week ? $this->lineups->games($week, $roster, $league) : [];
        $starters = $week ? $this->lineups->starters($league, (int) $franchise->id, $week) : [];
        $teams = $this->db->table('picks_teams')->whereIn('id', $roster ?: [0])->get(['id', 'name', 'conference', 'logo_path'])->keyBy('id');

        return new JsonResponse([
            'league' => ['name' => (string) $league->name, 'slug' => (string) $league->slug, 'status' => (string) $league->status, 'starters' => (int) $league->starters],
            'franchise' => ['id' => (int) $franchise->id, 'name' => (string) $franchise->name],
            'weeks' => $weeks,
            'week' => $week,
            'teams' => array_map(fn ($id) => [
                'id' => $id,
                'name' => (string) ($teams[$id]->name ?? ''),
                'conference' => (string) ($teams[$id]->conference ?? ''),
                'logo' => (string) ($teams[$id]->logo_path ?? ''),
                'starting' => in_array($id, $starters, true),
                'game' => $games[$id] ?? null,
            ], $roster),
        ]);
    }
}
