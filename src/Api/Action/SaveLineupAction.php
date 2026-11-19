<?php

namespace ErnestDefoe\Fantasy\Api\Action;

use ErnestDefoe\Fantasy\League;
use ErnestDefoe\Fantasy\Service\Lineups;
use Flarum\Api\Context;
use Flarum\Locale\TranslatorInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;

/** Save who starts this week. Only for your own franchise. */
class SaveLineupAction
{
    use LeagueRequest;

    public function __construct(protected TranslatorInterface $translator, private Lineups $lineups)
    {
    }

    public function __invoke(Context $context): ResponseInterface
    {
        $actor = $context->getActor();
        $this->assertPlayer($actor);

        /** @var League $league */
        $league = $context->model;
        $franchise = $this->franchiseOf($league, (int) $actor->id);
        if ($franchise === null) {
            return $this->problem('not_in_league', 403);
        }

        $body = (array) ($context->request->getParsedBody() ?? []);
        $teams = is_array($body['teamIds'] ?? null) ? $body['teamIds'] : [];
        $result = $this->lineups->save($league, $franchise, (int) ($body['weekId'] ?? 0), $teams);

        return $result['ok'] ? new JsonResponse(['saved' => true]) : $this->problem($result['problem']);
    }
}
