<?php

namespace ErnestDefoe\Fantasy\Api\Controller;

use ErnestDefoe\Fantasy\Franchise;
use ErnestDefoe\Fantasy\League;
use ErnestDefoe\Fantasy\Service\Sports\Scoring as SportScoring;
use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Every league on this forum, with its standings. */
class LeaguesController implements RequestHandlerInterface
{
    /*
     * 🚨 The connection is injected, not reached for through the DB facade.
     * Flarum boots the container without setting the facade root, so
     * `DB::table(...)` throws — quietly, inside a controller, as a 500 with
     * nothing useful in it.
     */
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $leagues = League::query()->orderByDesc('id')->get();
        $out = [];

        foreach ($leagues as $league) {
            $franchises = Franchise::query()
                ->where('league_id', $league->id)
                ->with('user')
                ->get();

            $out[] = [
                'id' => (int) $league->id,
                'name' => (string) $league->name,
                'slug' => (string) $league->slug,
                'description' => (string) $league->description,
                'status' => (string) $league->status,
                'sport' => SportScoring::sportOf($league->competition()),
                'franchises' => $franchises->count(),
                'maxFranchises' => (int) $league->max_franchises,
            ];
        }

        /*
         * 🚨 Seasons and the permission travel WITH the list, rather than
         * being two more requests the page has to make before it can draw a
         * button. The page needs all three to render once; fetching them
         * separately is three round trips and two intermediate states where
         * the button flickers into existence.
         */
        $actor = RequestUtil::getActor($request);

        return new JsonResponse([
            'leagues' => $out,
            'seasons' => $this->seasons(),
            'canCreate' => $actor->isGuest() ? false : (bool) $actor->can('fantasy.createLeague'),
        ]);
    }

    /**
     * The seasons a league can be attached to, newest first.
     *
     * 🚨 Read straight from Picks' table and guarded, because Fantasy works
     * without Picks installed — the scoring reads finished games from wherever
     * they come from. No seasons simply means the form offers none, which is a
     * league with no schedule rather than a page that will not load.
     */
    private function seasons(): array
    {
        try {
            $rows = $this->db->table('picks_seasons')
                ->orderByDesc('id')
                ->limit(25)
                ->get(['id', 'name', 'year']);
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row->id,
                'name' => (string) ($row->name ?: ('Season ' . ($row->year ?? $row->id))),
            ];
        }

        return $out;
    }
}
