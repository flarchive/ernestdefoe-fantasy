<?php

namespace ErnestDefoe\Fantasy\Api\Action;

use ErnestDefoe\Fantasy\League;
use ErnestDefoe\Fantasy\Service\Draft;
use Flarum\Api\Context;
use Flarum\Locale\TranslatorInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;

/** The commissioner closes sign-ups and opens the draft, in a shuffled order. */
class StartDraftAction
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
        if (! $league->canManage($actor)) {
            return $this->problem('not_commissioner', 403);
        }

        $result = $this->draft->start($league);

        return $result['ok'] ? new JsonResponse(['status' => 'drafting']) : $this->problem($result['problem']);
    }
}
