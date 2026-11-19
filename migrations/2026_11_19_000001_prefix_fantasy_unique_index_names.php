<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * The four unique indexes were created with fixed names, and Laravel does not
 * add the table prefix to a name it is given. Two prefixed installs sharing one
 * PostgreSQL or SQLite schema (where index names are schema-wide) would then
 * collide. Laravel's generated names would not fit MySQL's 64 characters once
 * prefixed ("dev_fantasy_lineups_league_id_franchise_id_week_id_team_id_unique"
 * is 66), so the indexes keep short names and gain the prefix. Nothing changes
 * on an install without a prefix.
 */
$indexes = [
    'fantasy_franchises' => ['fantasy_one_franchise', ['league_id', 'user_id']],
    'fantasy_rosters' => ['fantasy_one_owner', ['league_id', 'team_id']],
    'fantasy_lineups' => ['fantasy_lineup_one_each', ['league_id', 'franchise_id', 'week_id', 'team_id']],
    'fantasy_scores' => ['fantasy_score_one_each', ['league_id', 'franchise_id', 'week_id', 'team_id']],
];

$rename = function (Builder $schema, bool $addPrefix) use ($indexes) {
    $prefix = $schema->getConnection()->getTablePrefix();

    if ($prefix === '') {
        return;
    }

    foreach ($indexes as $table => [$name, $columns]) {
        [$from, $to] = $addPrefix ? [$name, $prefix.$name] : [$prefix.$name, $name];

        if ($schema->hasIndex($table, $from)) {
            $schema->table($table, function (Blueprint $t) use ($from, $to, $columns) {
                $t->dropUnique($from);
                $t->unique($columns, $to);
            });
        }
    }
};

return [
    'up' => fn (Builder $schema) => $rename($schema, true),
    'down' => fn (Builder $schema) => $rename($schema, false),
];
