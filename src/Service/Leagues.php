<?php

namespace ErnestDefoe\Fantasy\Service;

use ErnestDefoe\Fantasy\Franchise;
use ErnestDefoe\Fantasy\League;
use ErnestDefoe\Fantasy\Service\Sports\Scoring as SportScoring;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/** Creating and joining leagues. */
class Leagues
{
    public function __construct(
        private SettingsRepositoryInterface $settings,
        private TranslatorInterface $translator,
        private LeagueLock $lock,
    ) {
    }

    /**
     * Start a league from its name, description and season. The sizes come
     * from the forum's settings and the scoring from the season's sport.
     *
     * @param array{name: string, description?: string, season_id: int} $input
     */
    public function create(array $input, int $userId): League
    {
        $name = trim($input['name']);
        $roster = min(25, max(1, $this->configured('roster_size')));

        $league = new League();
        $league->fill([
            'name' => Str::limit($name, 189, ''),
            'description' => trim($input['description'] ?? '') ?: null,
            'season_id' => (int) $input['season_id'],
            'commissioner_id' => $userId,
            'status' => 'setup',
            'max_franchises' => min(32, max(2, $this->configured('max_franchises'))),
            'roster_size' => $roster,
            /*
             * 🚨 Never more than the roster it is chosen from, or every lineup
             * screen is a form that cannot be completed.
             */
            'starters' => min($roster, max(1, $this->configured('starters'))),
        ]);

        /*
         * 🚨 The scoring starts from the SPORT this season is played in. The
         * rules travel between sports; the numbers do not. A gridiron team
         * scores about thirty points a game, a basketball team a hundred and
         * ten, a football team one and a half — so 1.0 per point is a sensible
         * week in one, an absurd 110-point week in another and rounding error
         * in the third.
         */
        $defaults = SportScoring::defaultsFor($league->competition());

        foreach (League::SCORING as $column) {
            $league->{$column} = $defaults[$column] ?? 0.0;
        }

        $this->saveWithUniqueSlug($league, $name);

        $this->join($league, $userId, '');

        return $league;
    }

    /**
     * Take a franchise in a league.
     *
     * 🚨 The "is it full / has it started" checks and the insert run under the
     * league lock, so two members claiming the last open place at the same
     * moment cannot both get it.
     *
     * @return array{ok: true, franchise: Franchise}|array{ok: false, problem: string}
     */
    public function join(League $league, int $userId, string $name): array
    {
        return $this->lock->run($league, function (League $league) use ($userId, $name) {
            $existing = Franchise::query()
                ->where('league_id', $league->id)
                ->where('user_id', $userId)
                ->first();

            if ($existing !== null) {
                return ['ok' => true, 'franchise' => $existing];
            }

            if ($league->status !== 'setup') {
                return ['ok' => false, 'problem' => 'league_started'];
            }

            if (Franchise::query()->where('league_id', $league->id)->count() >= (int) $league->max_franchises) {
                return ['ok' => false, 'problem' => 'league_full'];
            }

            $franchise = Franchise::query()->create([
                'league_id' => $league->id,
                'user_id' => $userId,
                'name' => Str::limit(trim($name) ?: $this->defaultName($userId), 119, ''),
            ]);

            return ['ok' => true, 'franchise' => $franchise];
        });
    }

    /**
     * One configured default (registered once, in extend.php), clamped by the
     * caller the same as any other input. An operator who types 0 into the
     * admin screen gets the same treatment as an API client who posts 0, which
     * is the point of clamping where the value is USED rather than where it
     * arrives.
     */
    private function configured(string $key): int
    {
        return (int) $this->settings->get('ernestdefoe-fantasy.default_'.$key);
    }

    /** "Mara Ellison's team" for a member who didn't name theirs. */
    private function defaultName(int $userId): string
    {
        $user = User::query()->find($userId);

        return (string) $this->translator->trans('ernestdefoe-fantasy.api.default_franchise', ['name' => $user ? $user->display_name : '']);
    }

    /**
     * Save under the first free slug for the name. Two leagues created with
     * the same name at the same moment can both find a slug free; the unique
     * index refuses the second, which then tries the next one.
     */
    private function saveWithUniqueSlug(League $league, string $name): void
    {
        // Cut to length BEFORE numbering, so "-2" is never cut off again.
        $base = Str::limit(Str::slug($name) ?: 'league', 180, '');

        for ($attempt = 0; ; $attempt++) {
            $slug = $base;
            $n = 2;

            while (League::query()->where('slug', $slug)->exists()) {
                $slug = $base.'-'.$n++;
            }

            $league->slug = $slug;

            try {
                $league->save();

                return;
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 2) {
                    throw $e;
                }
            }
        }
    }
}
