<?php

namespace ErnestDefoe\Fantasy\Api\Action;

use ErnestDefoe\Fantasy\League;
use ErnestDefoe\Fantasy\Service\Draft;
use Flarum\Api\Context;
use Flarum\Locale\TranslatorInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;

/** The franchise on the clock takes a team (or the commissioner takes one for it). */
class DraftPickAction
{
    use LeagueRequest;

    public function __construct(protected TranslatorInterface $translator, private Draft $draft)
    {
    }

    public function __invoke(Context $context): ResponseInterface
    {
        $actor = $context->getActor();
        $this->assertPlayer($actor);

        /** @var League $league */
        $league = $context->model;

        $teamId = (int) (((array) ($context->request->getParsedBody() ?? []))['teamId'] ?? 0);
        $result = $this->draft->pickInTurn($league, (int) $actor->id, $league->canManage($actor), $teamId);

        if (! $result['ok']) {
            return $this->problem($result['problem'], $result['problem'] === 'not_your_turn' ? 403 : 422);
        }

        return new JsonResponse(['finished' => $result['finished']]);
    }
}
