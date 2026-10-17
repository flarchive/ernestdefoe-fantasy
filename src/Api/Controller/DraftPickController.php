<?php

namespace ErnestDefoe\Fantasy\Api\Controller;

use ErnestDefoe\Fantasy\Service\Draft;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The franchise on the clock takes a team (or the commissioner takes one for it). */
class DraftPickController implements RequestHandlerInterface
{
    use LeagueRequest;

    public function __construct(protected TranslatorInterface $translator, private Draft $draft)
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

        $teamId = (int) (((array) ($request->getParsedBody() ?? []))['teamId'] ?? 0);
        $result = $this->draft->pickInTurn($league, (int) $actor->id, $league->canManage($actor), $teamId);

        if (! $result['ok']) {
            return $this->problem($result['problem'], $result['problem'] === 'not_your_turn' ? 403 : 422);
        }

        return new JsonResponse(['finished' => $result['finished']]);
    }
}
