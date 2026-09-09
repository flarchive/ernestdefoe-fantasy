<?php

namespace ErnestDefoe\Fantasy;

use ErnestDefoe\Fantasy\Api\Controller\CreateLeagueController;
use ErnestDefoe\Fantasy\Api\Controller\JoinLeagueController;
use ErnestDefoe\Fantasy\Api\Controller\LeagueController;
use ErnestDefoe\Fantasy\Api\Controller\LeaguesController;
use ErnestDefoe\Fantasy\Console\ScoreCommand;
use Flarum\Extend;

return [
    /*
     * 🚨 Registered on the FRONTEND as well as the API. Without the frontend
     * route a visitor opening /fantasy directly — from a link, a bookmark, a
     * search result — gets the discussion list, and only in-app navigation
     * works.
     */
    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js')
        ->css(__DIR__ . '/resources/less/forum.less')
        ->route('/fantasy', 'fantasy.index')
        ->route('/fantasy/{slug}', 'fantasy.league'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js'),

    /*
     * 🚨 The label is serialized to the FORUM, not read from the admin bundle.
     * The sidebar link is drawn on every page by every visitor, including ones
     * who will never load the admin frontend, so a setting it reads has to
     * arrive in the forum payload or the link falls back to its default for
     * everybody and looks like the setting does nothing.
     */
    (new Extend\Settings())
        ->serializeToForum('fantasyNavLabel', 'ernestdefoe-fantasy.nav_label')
        /*
         * 🚨 Serialized so the create form can SAY what a new league will be
         * given. The form does not let anybody change them — they are the
         * forum's settings, and a form that offered them would mean every
         * league carried the form's own defaults while the admin screen
         * governed nothing. But a form that silently applies three numbers
         * nobody can see is how an operator concludes the settings are broken.
         *
         * Three attributes rather than one composed object: serializeToForum
         * maps ONE setting to one attribute, and a callback that tried to
         * assemble the other two would only ever see the value it was given.
         */
        ->serializeToForum('fantasyMaxFranchises', 'ernestdefoe-fantasy.default_max_franchises', fn ($v) => (int) ($v ?: 12))
        ->serializeToForum('fantasyRosterSize', 'ernestdefoe-fantasy.default_roster_size', fn ($v) => (int) ($v ?: 8))
        ->serializeToForum('fantasyStarters', 'ernestdefoe-fantasy.default_starters', fn ($v) => (int) ($v ?: 4)),

    new Extend\Locales(__DIR__ . '/resources/locale'),

    (new Extend\Routes('api'))
        ->get('/fantasy/leagues', 'fantasy.api.leagues', LeaguesController::class)
        ->get('/fantasy/league', 'fantasy.api.league', LeagueController::class)
        ->post('/fantasy/leagues', 'fantasy.api.leagues.create', CreateLeagueController::class)
        ->post('/fantasy/leagues/{slug}/join', 'fantasy.api.leagues.join', JoinLeagueController::class),

    (new Extend\Console())
        ->command(ScoreCommand::class)
        /*
         * 🚨 Hourly, not minutely. Scoring reads FINISHED games only, so there
         * is nothing to do between one game ending and the next — and a
         * franchise total that updates within the hour is indistinguishable
         * from one that updates within the minute to everyone except the
         * database.
         */
        ->schedule(ScoreCommand::class, function ($event) {
            $event->hourly()->withoutOverlapping();
        }),
];
