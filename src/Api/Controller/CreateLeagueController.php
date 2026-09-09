<?php

namespace ErnestDefoe\Fantasy\Api\Controller;

use ErnestDefoe\Fantasy\Service\Leagues;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Start a league.
 *
 * 🚨 The form does NOT ask for the franchise count, the roster size or the
 * lineup size, and that is deliberate rather than an omission.
 *
 * Those three are the forum's settings. Asking a commissioner for them at
 * creation time would mean every league carried whatever the form's own
 * defaults happened to be, and the admin screen would govern nothing — which
 * is precisely the state this extension was in before this controller existed:
 * three settings and no code path that could reach them. A commissioner who
 * wants different numbers changes them on their own league afterwards.
 */
class CreateLeagueController implements RequestHandlerInterface
{
    public function __construct(private Leagues $leagues)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        /*
         * 🚨 A permission, not a role check. Admins pass it for free — Flarum
         * short-circuits every check for them — so this is really asking "has
         * the operator let members do this", and out of the box the answer is
         * no without anything being broken.
         */
        $actor->assertCan('fantasy.createLeague');

        $body = (array) ($request->getParsedBody() ?? []);
        $name = trim((string) ($body['name'] ?? ''));

        if ($name === '') {
            return new JsonResponse(['errors' => [['status' => '422', 'detail' => 'A league needs a name.']]], 422);
        }

        if (mb_strlen($name) > 189) {
            return new JsonResponse(['errors' => [['status' => '422', 'detail' => 'That name is too long.']]], 422);
        }

        $league = $this->leagues->create([
            'name' => $name,
            'description' => (string) ($body['description'] ?? ''),
            'season_id' => (int) ($body['seasonId'] ?? 0),
        ], (int) $actor->id);

        return new JsonResponse([
            'league' => [
                'id' => (int) $league->id,
                'name' => (string) $league->name,
                'slug' => (string) $league->slug,
                'status' => (string) $league->status,
                'maxFranchises' => (int) $league->max_franchises,
                'rosterSize' => (int) $league->roster_size,
                'starters' => (int) $league->starters,
            ],
        ], 201);
    }
}
