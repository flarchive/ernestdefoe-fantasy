<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * A league's scoring rules were DECIMAL(6,2), but the basketball defaults
 * include -0.125 a point allowed: MySQL and PostgreSQL stored it as -0.13, so
 * every basketball score came out half a point off a game. Three decimal places
 * hold every default in Sports\Scoring.
 *
 * Leagues created before this keep the rounded value they were stored with;
 * a commissioner can set it again.
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('fantasy_leagues', function (Blueprint $table) {
            $table->decimal('points_per_point', 8, 3)->default(1)->change();
            $table->decimal('points_per_point_allowed', 8, 3)->default(-0.5)->change();
            $table->decimal('win_bonus', 8, 3)->default(10)->change();
            $table->decimal('shutout_bonus', 8, 3)->default(8)->change();
            $table->decimal('points_per_margin', 8, 3)->default(0.25)->change();
        });
    },

    'down' => function (Builder $schema) {
        // Left wide: narrowing would round the rules again.
    },
];
