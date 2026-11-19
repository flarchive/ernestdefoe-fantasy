<?php

namespace ErnestDefoe\Fantasy\Api\Action;

use ErnestDefoe\Fantasy\League;
use ErnestDefoe\Fantasy\Service\Leagues;
use Flarum\Api\Context;
use Flarum\Locale\TranslatorInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * Take a franchise in a league.
 *
 * 🚨 Refuses once the league is full, and once it has started. Both are
 * checked on the server (in `Leagues::join`, under the league lock) rather
 * than only hidden in the interface: a full league whose
 * Join button is merely not drawn is still joinable by anybody who posts to
 * this route, and a league that gains a thirteenth franchise after the draft
 * has no way to give it anybody to play.
 */
class JoinLeagueAction
{
    use LeagueRequest;

    public function __construct(protected TranslatorInterface $translator, private Leagues $leagues)
    {
    }

    public function __invoke(Context $context): ResponseInterface
    {
        $actor = $context->getActor();
        $this->assertPlayer($actor);

        /** @var League $league */
        $league = $context->model;

        $body = (array) ($context->request->getParsedBody() ?? []);
        $result = $this->leagues->join($league, (int) $actor->id, (string) ($body['name'] ?? ''));

        if (! $result['ok']) {
            return $this->problem($result['problem']);
        }

        $franchise = $result['franchise'];

        return new JsonResponse([
            'franchise' => ['id' => (int) $franchise->id, 'name' => (string) $franchise->name],
            'slug' => (string) $league->slug,
        ]);
    }
}
