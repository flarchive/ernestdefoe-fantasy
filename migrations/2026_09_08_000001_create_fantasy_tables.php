<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * Fantasy: leagues, franchises, rosters, lineups and scores.
 *
 * 🚨 Everything about WHAT happened is read from Picks — the fixtures, the
 * results, the weeks. Nothing here duplicates a fixture. A second copy of the
 * schedule is a second copy that disagrees the first time a game moves.
 *
 * 🚨 Scoring rules are COLUMNS, not a JSON blob. The scoring pass multiplies
 * them by numbers out of `picks_events`, and a rule that arrives as a string
 * from `json_decode` silently becomes 0 — a league scored entirely wrong with
 * nothing to see. They are also the thing a commissioner most wants side by
 * side, and a blob does not render as a form.
 */
return [
    'up' => function (Builder $schema) {
        $schema->create('fantasy_leagues', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 190);
            $table->string('slug', 190)->unique();
            $table->text('description')->nullable();

            // The Picks season this league plays. Weeks, fixtures and results
            // are all read through it — including which SPORT it is.
            $table->unsignedInteger('season_id');

            $table->unsignedInteger('commissioner_id')->nullable();

            // setup → drafting → active → complete. A string rather than an
            // enum so a fifth state is code and not a schema change.
            $table->string('status', 20)->default('setup');

            $table->unsignedSmallInteger('max_franchises')->default(12);
            $table->unsignedSmallInteger('roster_size')->default(8);
            $table->unsignedSmallInteger('starters')->default(4);

            $table->decimal('points_per_point', 6, 2)->default(1);
            $table->decimal('points_per_point_allowed', 6, 2)->default(-0.5);
            $table->decimal('win_bonus', 6, 2)->default(10);
            $table->decimal('shutout_bonus', 6, 2)->default(8);
            $table->decimal('points_per_margin', 6, 2)->default(0.25);

            $table->timestamps();
            $table->index('season_id');
        });

        $schema->create('fantasy_franchises', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('league_id');
            $table->unsignedInteger('user_id');
            $table->string('name', 120);
            $table->timestamps();

            // One franchise per member per league, enforced by the index rather
            // than by the application remembering to check.
            $table->unique(['league_id', 'user_id'], 'fantasy_one_franchise');
            $table->index('league_id');
        });

        $schema->create('fantasy_rosters', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('league_id');
            $table->unsignedInteger('franchise_id');
            $table->unsignedInteger('team_id');
            $table->string('acquired_via', 20)->default('draft');
            $table->timestamps();

            /*
             * 🚨 One owner per team per league, as a UNIQUE INDEX rather than a
             * rule the application remembers. Two people clicking Add on the
             * same free agent in the same second is not hypothetical.
             */
            $table->unique(['league_id', 'team_id'], 'fantasy_one_owner');
            $table->index(['league_id', 'franchise_id']);
        });

        $schema->create('fantasy_lineups', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('league_id');
            $table->unsignedInteger('franchise_id');
            $table->unsignedInteger('week_id');
            $table->unsignedInteger('team_id');
            $table->boolean('started')->default(false);
            $table->timestamps();

            $table->unique(['league_id', 'franchise_id', 'week_id', 'team_id'], 'fantasy_lineup_one_each');
            $table->index(['league_id', 'week_id']);
        });

        $schema->create('fantasy_scores', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('league_id');
            $table->unsignedInteger('franchise_id');
            $table->unsignedInteger('week_id');
            $table->unsignedInteger('team_id');

            /*
             * 🚨 The WORKINGS are stored, not just the total. A commissioner
             * fixing a broken rule in week three would otherwise make every
             * earlier week unexplainable: re-deriving an old score under
             * today's rules gives a different answer to the one on the table.
             */
            $table->decimal('points', 8, 2)->default(0);
            $table->smallInteger('scored')->default(0);
            $table->smallInteger('allowed')->default(0);
            $table->boolean('won')->default(false);
            $table->boolean('shutout')->default(false);
            $table->decimal('bonus', 6, 2)->default(0);

            $table->timestamps();

            $table->unique(['league_id', 'franchise_id', 'week_id', 'team_id'], 'fantasy_score_one_each');
            $table->index(['league_id', 'week_id']);
        });
    },

    'down' => function (Builder $schema) {
        foreach (['fantasy_scores', 'fantasy_lineups', 'fantasy_rosters', 'fantasy_franchises', 'fantasy_leagues'] as $table) {
            $schema->dropIfExists($table);
        }
    },
];
