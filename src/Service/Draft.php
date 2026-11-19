<?php

namespace ErnestDefoe\Fantasy\Service;

use ErnestDefoe\Fantasy\Franchise;
use ErnestDefoe\Fantasy\League;
use ErnestDefoe\Fantasy\RosterSpot;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/** Which teams a league may draft, whose turn it is, and taking one. */
class Draft
{
    public function __construct(protected ConnectionInterface $db, private LeagueLock $lock)
    {
    }

    /**
     * Teams nobody in this league owns.
     *
     * 🚨 Scoped to the teams that actually PLAY in the league's season, not to
     * every row in `picks_teams`. That table held one sport's teams when this
     * idea was written and now holds six, so "a team exists" stopped being the
     * same question as "a team is in this competition" — without the scope a
     * college-football draft board offers the Milwaukee Bucks.
     *
     * 🚨 Asked of the FIXTURES rather than of a league label, which is both
     * provider-neutral and stricter: a club with no games this season is not
     * draftable however it is filed.
     *
     * @return list<array<string, mixed>>
     */
    public function available(League $league, string $search = '', int $limit = 400): array
    {
        $query = $this->db->table('picks_teams as t')
            ->whereNotExists(function ($q) use ($league) {
                $q->select('r.id')
                    ->from('fantasy_rosters as r')
                    ->where('r.league_id', $league->id)
                    ->whereColumn('r.team_id', 't.id');
            })
            ->whereExists(function ($q) use ($league) {
                $q->select('e.id')
                    ->from('picks_events as e')
                    ->join('picks_weeks as w', 'w.id', '=', 'e.week_id')
                    ->where('w.season_id', $league->season_id)
                    ->where(function ($side) {
                        $side->whereColumn('e.home_team_id', 't.id')
                            ->orWhereColumn('e.away_team_id', 't.id');
                    });
            });

        $search = trim($search);
        $limit = max(1, min(1000, $limit));

        $teams = $query->orderBy('t.name')
            ->limit($search === '' ? $limit : 1000)
            ->get(['t.id', 't.name', 't.conference', 't.abbreviation', 't.logo_path']);

        /*
         * 🚨 Searched here, not with LIKE '%…%'. The board is already scoped to
         * one season's teams (a few hundred rows at most), and LIKE is
         * case-sensitive on PostgreSQL, so "state" found nothing there.
         */
        if ($search !== '') {
            $teams = $teams->filter(fn ($t) => mb_stripos((string) $t->name, $search) !== false
                || mb_stripos((string) $t->conference, $search) !== false
                || mb_stripos((string) $t->abbreviation, $search) !== false);
        }

        return $teams->take($limit)
            ->map(fn ($row) => ['id' => $row->id, 'name' => $row->name, 'conference' => $row->conference, 'logo_path' => $row->logo_path])
            ->values()
            ->all();
    }

    /**
     * Take a team. Only called by pickInTurn(), under the league lock, which
     * has already checked that nobody in the league holds it.
     *
     * @return array{ok: bool, problem?: string}
     */
    private function pick(League $league, int $franchiseId, int $teamId): array
    {
        if (! $this->playsIn($league, $teamId)) {
            return ['ok' => false, 'problem' => 'no_such_team'];
        }

        $held = RosterSpot::query()
            ->where('league_id', $league->id)
            ->where('franchise_id', $franchiseId)
            ->count();

        if ($held >= $league->roster_size) {
            return ['ok' => false, 'problem' => 'roster_full'];
        }

        RosterSpot::query()->create([
            'league_id' => $league->id,
            'franchise_id' => $franchiseId,
            'team_id' => $teamId,
            'acquired_via' => 'draft',
        ]);

        return ['ok' => true];
    }

    /**
     * Shuffle the franchises into a draft order and open the draft.
     *
     * @return array{ok: bool, problem?: string}
     */
    public function start(League $league): array
    {
        return $this->lock->run($league, function (League $league) {
            if ($league->status !== 'setup') {
                return ['ok' => false, 'problem' => 'league_started'];
            }

            $franchises = Franchise::query()->where('league_id', $league->id)->pluck('id')->shuffle()->values();

            if ($franchises->count() < 2) {
                return ['ok' => false, 'problem' => 'too_few_franchises'];
            }

            foreach ($franchises as $i => $id) {
                Franchise::query()->whereKey($id)->update(['draft_position' => $i + 1]);
            }
            $league->status = 'drafting';
            $league->save();

            return ['ok' => true];
        });
    }

    /** Franchise ids in draft order. */
    public function order(League $league): array
    {
        return Franchise::query()->where('league_id', $league->id)
            // Raw because the builder has no portable "nulls last"; IS NULL works on all three databases.
            ->orderByRaw('draft_position is null')->orderBy('draft_position')->orderBy('id')
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Who picks next, in a snake draft: round 1 runs down the order, round 2
     * back up it, so the franchise that picked last in a round picks first in
     * the next. Null once every roster is full.
     *
     * @return array{franchise: int, round: int, pick: int, overall: int}|null
     */
    public function onTheClock(League $league, ?array $order = null, ?int $made = null): ?array
    {
        $order ??= $this->order($league);
        $made ??= RosterSpot::query()->where('league_id', $league->id)->where('acquired_via', 'draft')->count();
        $teams = count($order);

        if ($teams === 0 || $made >= $teams * (int) $league->roster_size) {
            return null;
        }

        $round = intdiv($made, $teams);
        $slot = $made % $teams;

        return [
            'franchise' => $order[$round % 2 === 0 ? $slot : $teams - 1 - $slot],
            'round' => $round + 1,
            'pick' => $slot + 1,
            'overall' => $made + 1,
        ];
    }

    /**
     * Every pick so far, oldest first, with the team it took.
     *
     * @return list<array<string, mixed>>
     */
    public function history(League $league): array
    {
        return $this->db->table('fantasy_rosters as r')
            ->join('picks_teams as t', 't.id', '=', 'r.team_id')
            ->where('r.league_id', $league->id)
            ->where('r.acquired_via', 'draft')
            ->orderBy('r.id')
            ->get(['r.franchise_id', 't.id', 't.name', 't.conference', 't.logo_path'])
            ->map(fn ($row, $i) => [
                'overall' => $i + 1,
                'franchise' => (int) $row->franchise_id,
                'team' => ['id' => (int) $row->id, 'name' => (string) $row->name, 'conference' => (string) $row->conference, 'logo' => (string) $row->logo_path],
            ])
            ->all();
    }

    /**
     * Take a team for the franchise on the clock. Only that franchise's owner
     * may, or the commissioner on their behalf, so a draft never stalls on
     * someone who wandered off.
     *
     * 🚨 Under the league lock (LeagueLock): two owners pressing "Draft" in
     * the same second would otherwise both read the same pick count, and one
     * franchise would get two teams for one turn.
     *
     * @return array{ok: bool, problem?: string, finished?: bool}
     */
    public function pickInTurn(League $league, int $actorId, bool $isManager, int $teamId): array
    {
        return $this->lock->run($league, function (League $league) use ($actorId, $isManager, $teamId) {
            if ($league->status !== 'drafting') {
                return ['ok' => false, 'problem' => 'not_drafting'];
            }

            $clock = $this->onTheClock($league);
            if ($clock === null) {
                return ['ok' => false, 'problem' => 'not_drafting'];
            }

            $owner = (int) Franchise::query()->whereKey($clock['franchise'])->value('user_id');
            if ($owner !== $actorId && ! $isManager) {
                return ['ok' => false, 'problem' => 'not_your_turn'];
            }

            // Asked first rather than left to the unique index: inside a transaction a
            // failed insert aborts the whole transaction on PostgreSQL. The league lock
            // makes the check reliable, since no other pick in this league runs meanwhile.
            if (RosterSpot::query()->where('league_id', $league->id)->where('team_id', $teamId)->exists()) {
                return ['ok' => false, 'problem' => 'already_taken'];
            }

            $result = $this->pick($league, $clock['franchise'], $teamId);
            if (! $result['ok']) {
                return $result;
            }

            // The last pick, or a board with nothing left on it, ends the draft.
            $finished = $this->onTheClock($league) === null || $this->available($league, '', 1) === [];
            if ($finished) {
                $league->status = 'active';
                $league->activated_at = Carbon::now();
                $league->save();
            }

            return ['ok' => true, 'finished' => $finished];
        });
    }

    private function playsIn(League $league, int $teamId): bool
    {
        if ($league->season_id < 1 || $teamId < 1) {
            return false;
        }

        return $this->db->table('picks_events as e')
            ->join('picks_weeks as w', 'w.id', '=', 'e.week_id')
            ->where('w.season_id', $league->season_id)
            ->where(function ($side) use ($teamId) {
                $side->where('e.home_team_id', $teamId)->orWhere('e.away_team_id', $teamId);
            })
            ->exists();
    }
}
