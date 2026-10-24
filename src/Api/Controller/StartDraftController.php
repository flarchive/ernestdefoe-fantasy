<?php

namespace ErnestDefoe\Fantasy\Api\Controller;

use ErnestDefoe\Fantasy\Service\Draft;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The commissioner closes sign-ups and opens the draft, in a shuffled order. */
class StartDraftController implements RequestHandlerInterface
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
        if (! $league->canManage($actor)) {
            return $this->problem('not_commissioner', 403);
        }

        $result = $this->draft->start($league);

        return $result['ok'] ? new JsonResponse(['status' => 'drafting']) : $this->problem($result['problem']);
    }
}
