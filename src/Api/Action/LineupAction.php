<?php

namespace ErnestDefoe\Fantasy\Api\Action;

use ErnestDefoe\Fantasy\League;
use ErnestDefoe\Fantasy\Service\Lineups;
use Flarum\Api\Context;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * Your lineup for one week: every team on your roster with its game, whether
 * it has locked, and who starts. Without a week, the next one still to play.
 */
class LineupAction
{
    use LeagueRequest;

    public function __construct(protected TranslatorInterface $translator, private Lineups $lineups, private ConnectionInterface $db)
    {
    }

    public function __invoke(Context $context): ResponseInterface
    {
        $actor = $context->getActor();
        $actor->assertRegistered();

        /** @var League $league */
        $league = $context->model;
        $franchise = $this->franchiseOf($league, (int) $actor->id);
        if ($franchise === null) {
            return $this->problem('not_in_league', 403);
        }

        $weeks = $this->lineups->weeks($league);
        $ids = array_column($weeks, 'id');
        $week = (int) ($context->request->getQueryParams()['filter']['week'] ?? 0);
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
