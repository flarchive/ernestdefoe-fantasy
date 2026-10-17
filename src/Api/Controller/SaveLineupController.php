<?php

namespace ErnestDefoe\Fantasy\Api\Controller;

use ErnestDefoe\Fantasy\Service\Lineups;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Save who starts this week. Only for your own franchise. */
class SaveLineupController implements RequestHandlerInterface
{
    use LeagueRequest;

    public function __construct(protected TranslatorInterface $translator, private Lineups $lineups)
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

        $body = (array) ($request->getParsedBody() ?? []);
        $teams = is_array($body['teamIds'] ?? null) ? $body['teamIds'] : [];
        $result = $this->lineups->save($league, $franchise, (int) ($body['weekId'] ?? 0), $teams);

        return $result['ok'] ? new JsonResponse(['saved' => true]) : $this->problem($result['problem']);
    }
}
