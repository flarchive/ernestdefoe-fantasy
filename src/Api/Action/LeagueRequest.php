<?php

namespace ErnestDefoe\Fantasy\Api\Action;

use ErnestDefoe\Fantasy\Franchise;
use ErnestDefoe\Fantasy\League;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;

/** Shared by the league actions: who may play, the actor's franchise, and a translated error. Needs $this->translator. */
trait LeagueRequest
{
    /** Joining, drafting and lineups: a member who holds `fantasy.play` (not suspended, email confirmed). */
    protected function assertPlayer(User $actor): void
    {
        $actor->assertRegistered();
        $actor->assertCan('fantasy.play');
    }

    protected function franchiseOf(League $league, int $userId): ?Franchise
    {
        return $userId < 1 ? null : Franchise::query()->where('league_id', $league->id)->where('user_id', $userId)->first();
    }

    protected function problem(string $key, int $status = 422): JsonResponse
    {
        return new JsonResponse(['errors' => [['status' => (string) $status, 'code' => $key,
            'detail' => $this->translator->trans('ernestdefoe-fantasy.api.'.$key)]]], $status);
    }
}
