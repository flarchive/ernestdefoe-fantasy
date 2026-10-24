<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * What the draft and the lineup screens need to keep: each franchise's slot in
 * the draft order, and when a league finished drafting. The second matters for
 * scoring: a lineup only carries over into weeks played after the league went
 * live, so a league drafted in week 8 never collects points for weeks 1 to 7.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasColumn('fantasy_franchises', 'draft_position')) {
            $schema->table('fantasy_franchises', function (Blueprint $table) {
                $table->unsignedSmallInteger('draft_position')->nullable()->after('name');
            });
        }
        if (! $schema->hasColumn('fantasy_leagues', 'activated_at')) {
            $schema->table('fantasy_leagues', function (Blueprint $table) {
                $table->dateTime('activated_at')->nullable()->after('status');
            });
        }
    },
    'down' => function (Builder $schema) {
        $schema->table('fantasy_franchises', fn (Blueprint $table) => $table->dropColumn('draft_position'));
        $schema->table('fantasy_leagues', fn (Blueprint $table) => $table->dropColumn('activated_at'));
    },
];
