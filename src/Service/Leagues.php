<?php

namespace ErnestDefoe\Fantasy\Service;

use ErnestDefoe\Fantasy\Franchise;
use ErnestDefoe\Fantasy\League;
use ErnestDefoe\Fantasy\Service\Sports\Scoring as SportScoring;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Str;

/** Creating and joining leagues. */
class Leagues
{
    /**
     * What a league starts from when the form does not say, and what the
     * admin screen's three numbers actually change.
     *
     * 🚨 Read here rather than baked into the form. A default typed into the
     * JavaScript is a default only for people who use that form — the API, a
     * seeder and any future importer would all carry their own copy, and the
     * setting would quietly govern one of them.
     */
    private const FALLBACK = ['max_franchises' => 12, 'roster_size' => 8, 'starters' => 4];

    public function __construct(private ?SettingsRepositoryInterface $settings = null)
    {
    }

    public function create(array $input, int $userId): League
    {
        $name = trim((string) ($input['name'] ?? ''));
        $roster = min(25, max(1, (int) ($input['roster_size'] ?? $this->configured('roster_size'))));
        $seasonId = (int) ($input['season_id'] ?? 0);

        $league = new League();
        $league->fill([
            'name' => Str::limit($name, 189, ''),
            'slug' => $this->uniqueSlug($name),
            'description' => trim((string) ($input['description'] ?? '')) ?: null,
            'season_id' => $seasonId,
            'commissioner_id' => $userId,
            'status' => 'setup',
            'max_franchises' => min(32, max(2, (int) ($input['max_franchises'] ?? $this->configured('max_franchises')))),
            'roster_size' => $roster,
            /*
             * 🚨 Never more than the roster it is chosen from, or every lineup
             * screen is a form that cannot be completed.
             */
            'starters' => min($roster, max(1, (int) ($input['starters'] ?? $this->configured('starters')))),
        ]);

        /*
         * 🚨 The scoring starts from the SPORT this season is played in. The
         * rules travel between sports; the numbers do not. A gridiron team
         * scores about thirty points a game, a basketball team a hundred and
         * ten, a football team one and a half — so 1.0 per point is a sensible
         * week in one, an absurd 110-point week in another and rounding error
         * in the third.
         *
         * Anything the form actually sent still wins; this only decides what a
         * commissioner starts from.
         */
        $league->season_id = $seasonId;
        $defaults = SportScoring::defaultsFor($league->competition());

        foreach (League::SCORING as $column) {
            $league->{$column} = array_key_exists($column, $input)
                ? (float) $input[$column]
                : ($defaults[$column] ?? 0.0);
        }

        $league->save();

        $this->join($league, $userId, '');

        return $league;
    }

    public function join(League $league, int $userId, string $name): Franchise
    {
        $existing = Franchise::query()
            ->where('league_id', $league->id)
            ->where('user_id', $userId)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return Franchise::query()->create([
            'league_id' => $league->id,
            'user_id' => $userId,
            'name' => Str::limit(trim($name) ?: 'Franchise', 119, ''),
        ]);
    }

    /**
     * One configured default, clamped by the caller the same as any other
     * input. An operator who types 0 into the admin screen gets the same
     * treatment as an API client who posts 0, which is the point of clamping
     * where the value is USED rather than where it arrives.
     */
    private function configured(string $key): int
    {
        $value = $this->settings?->get('ernestdefoe-fantasy.default_' . $key);

        return $value === null || $value === '' ? self::FALLBACK[$key] : (int) $value;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'league';
        $slug = $base;
        $n = 2;

        while (League::query()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $n++;
        }

        return Str::limit($slug, 189, '');
    }
}
