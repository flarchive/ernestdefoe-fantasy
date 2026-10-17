<?php

namespace ErnestDefoe\Fantasy\Api\Controller;

use ErnestDefoe\Fantasy\Franchise;
use ErnestDefoe\Fantasy\RosterSpot;
use ErnestDefoe\Fantasy\Service\Draft;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The draft room: the order, who is on the clock, every pick so far, and the
 * teams still available. Readable by anyone who can see the league; the room
 * polls it while the draft is on, so it costs a fixed handful of queries.
 */
class DraftController implements RequestHandlerInterface
{
    use LeagueRequest;

    public function __construct(protected TranslatorInterface $translator, private Draft $draft)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $league = $this->league($request);
        if ($league === null) {
            return $this->problem('league_not_found', 404);
        }

        $franchises = Franchise::query()->where('league_id', $league->id)->with('user')->get()->keyBy('id');
        $order = $this->draft->order($league);
        $history = $this->draft->history($league);
        $clock = $league->status === 'drafting' ? $this->draft->onTheClock($league, $order, count($history)) : null;
        $mine = $actor->exists ? $franchises->first(fn ($f) => (int) $f->user_id === (int) $actor->id) : null;
        $manager = $league->canManage($actor);

        $search = mb_substr(trim((string) ($request->getQueryParams()['q'] ?? '')), 0, 60);

        return new JsonResponse([
            'league' => ['name' => (string) $league->name, 'slug' => (string) $league->slug, 'status' => (string) $league->status,
                'rosterSize' => (int) $league->roster_size, 'starters' => (int) $league->starters],
            'order' => array_map(fn ($id) => [
                'id' => $id,
                'name' => (string) $franchises[$id]->name,
                'member' => (string) ($franchises[$id]->user->display_name ?? ''),
                'picks' => count(array_filter($history, fn ($p) => $p['franchise'] === $id)),
            ], $order),
            'clock' => $clock,
            'history' => $history,
            'available' => $league->status === 'drafting' ? $this->draft->available($league, $search, 200) : [],
            'viewer' => [
                'franchise' => $mine ? (int) $mine->id : null,
                'manager' => $manager,
                'canPick' => $clock !== null && ($manager || ($mine && (int) $mine->id === $clock['franchise'])),
            ],
        ]);
    }
}
