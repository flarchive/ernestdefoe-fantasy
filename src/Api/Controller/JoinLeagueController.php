<?php

namespace ErnestDefoe\Fantasy\Api\Controller;

use ErnestDefoe\Fantasy\Franchise;
use ErnestDefoe\Fantasy\League;
use ErnestDefoe\Fantasy\Service\Leagues;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Take a franchise in a league.
 *
 * 🚨 Refuses once the league is full, and once it has started. Both are
 * checked HERE rather than only hidden in the interface: a full league whose
 * Join button is merely not drawn is still joinable by anybody who posts to
 * this route, and a league that gains a thirteenth franchise after the draft
 * has no way to give it anybody to play.
 */
class JoinLeagueController implements RequestHandlerInterface
{
    public function __construct(protected TranslatorInterface $translator, private Leagues $leagues)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $slug = (string) ($request->getQueryParams()['slug'] ?? '');
        $league = League::query()->where('slug', $slug)->first();

        if ($league === null) {
            return new JsonResponse(['errors' => [['status' => '404', 'detail' => $this->translator->trans('ernestdefoe-fantasy.api.league_not_found')]]], 404);
        }

        $already = Franchise::query()
            ->where('league_id', $league->id)
            ->where('user_id', $actor->id)
            ->first();

        if ($already === null) {
            if ($league->status !== 'setup') {
                return new JsonResponse(['errors' => [['status' => '422', 'detail' => $this->translator->trans('ernestdefoe-fantasy.api.league_started')]]], 422);
            }

            $taken = Franchise::query()->where('league_id', $league->id)->count();

            if ($taken >= (int) $league->max_franchises) {
                return new JsonResponse(['errors' => [['status' => '422', 'detail' => $this->translator->trans('ernestdefoe-fantasy.api.league_full')]]], 422);
            }
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $franchise = $this->leagues->join($league, (int) $actor->id, (string) ($body['name'] ?? ''));

        return new JsonResponse([
            'franchise' => ['id' => (int) $franchise->id, 'name' => (string) $franchise->name],
            'slug' => (string) $league->slug,
        ]);
    }
}
