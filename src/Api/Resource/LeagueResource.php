<?php

namespace ErnestDefoe\Fantasy\Api\Resource;

use ErnestDefoe\Fantasy\Api\Action;
use ErnestDefoe\Fantasy\League;
use ErnestDefoe\Fantasy\Service\Leagues;
use ErnestDefoe\Fantasy\Service\Sports\Scoring as SportScoring;
use ErnestDefoe\Fantasy\Service\Standings;
use Flarum\Api\Context as FlarumContext;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;
use Illuminate\Database\Eloquent\Builder;
use Psr\Http\Message\ResponseInterface;
use Tobyz\JsonApiServer\Context;

/**
 * Leagues: the list (a page at a time, newest first), one league by its slug
 * with its rules, standings and what the visitor can do, and starting one.
 *
 * @extends AbstractDatabaseResource<League>
 */
class LeagueResource extends AbstractDatabaseResource
{
    public function __construct(
        private Leagues $leagues,
        private Standings $standings,
        private Action\JoinLeagueAction $join,
        private Action\StartDraftAction $startDraft,
        private Action\DraftAction $draftRoom,
        private Action\DraftPickAction $pick,
        private Action\LineupAction $lineup,
        private Action\SaveLineupAction $saveLineup,
    ) {
    }

    public function type(): string
    {
        return 'fantasy-leagues';
    }

    public function model(): string
    {
        return League::class;
    }

    public function scope(Builder $query, Context $context): void
    {
        $query->withCount('franchises');
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()->can('viewForum')->defaultSort('-id')->paginate(50, 50),
            Endpoint\Show::make()->can('viewForum'),
            Endpoint\Create::make()->authenticated()->can('fantasy.createLeague'),

            // The game itself, on one league: two screens and four actions.
            $this->action('draft', 'GET', '/{id}/draft', fn (FlarumContext $c) => ($this->draftRoom)($c))->can('viewForum'),
            $this->action('lineup', 'GET', '/{id}/lineup', fn (FlarumContext $c) => ($this->lineup)($c))->authenticated(),
            $this->action('join', 'POST', '/{id}/join', fn (FlarumContext $c) => ($this->join)($c))->authenticated(),
            $this->action('draft.start', 'POST', '/{id}/draft/start', fn (FlarumContext $c) => ($this->startDraft)($c))->authenticated(),
            $this->action('draft.pick', 'POST', '/{id}/draft/pick', fn (FlarumContext $c) => ($this->pick)($c))->authenticated(),
            $this->action('lineup.save', 'POST', '/{id}/lineup', fn (FlarumContext $c) => ($this->saveLineup)($c))->authenticated(),
        ];
    }

    /**
     * A custom endpoint on one league (found by its slug) whose action
     * answers with its own JSON: the draft room and lineup screens are built
     * from several tables, and the actions report a game outcome, not a
     * league.
     *
     * The handler is a closure so the action is only reached when a request
     * runs: core also reads endpoints() to build routes, without constructing
     * the resource.
     *
     * @param \Closure(FlarumContext): ResponseInterface $handler
     */
    private function action(string $name, string $method, string $path, \Closure $handler): Endpoint\Endpoint
    {
        return Endpoint\Endpoint::make($name)
            ->route($method, $path)
            ->action($handler)
            ->response(fn (FlarumContext $context, ResponseInterface $response) => $response);
    }

    public function sorts(): array
    {
        return [SortColumn::make('id')];
    }

    /**
     * A league's address is its slug; its numeric id (which `links.self` and
     * the Create response's Location use) finds it too. A slug wins when a
     * league happens to be named with a number.
     */
    public function find(string $id, Context $context): ?object
    {
        return $this->query($context)->where('slug', $id)->first()
            ?? (ctype_digit($id) ? $this->query($context)->find((int) $id) : null);
    }

    /** Each league's sport for a whole page in one query, not one per league. */
    public function results(object $query, Context $context): iterable
    {
        $leagues = $query->get();
        $competitions = League::competitionsFor($leagues->pluck('season_id')->all());

        foreach ($leagues as $league) {
            $league->knownCompetition = $competitions[(int) $league->season_id] ?? '';
        }

        return $leagues;
    }

    /**
     * Started through the Leagues service: the sizes come from the forum's
     * settings, the scoring from the season's sport, and the creator takes
     * the first franchise.
     *
     * @param League $model
     * @param FlarumContext $context
     */
    public function create(object $model, Context $context): object
    {
        return $this->leagues->create([
            'name' => (string) $model->name,
            'description' => (string) $model->description,
            'season_id' => (int) $model->season_id,
        ], (int) $context->getActor()->id);
    }

    public function fields(): array
    {
        $showing = fn ($league, Context $context) => $context->showing(self::class);

        return [
            Schema\Str::make('name')->requiredOnCreate()->writableOnCreate()->maxLength(189),
            Schema\Str::make('description')->nullable()->writableOnCreate()->maxLength(2000),
            // A league plays one Picks season; one that does not exist could never be drafted.
            Schema\Integer::make('seasonId')->property('season_id')->requiredOnCreate()->writableOnCreate()
                ->rule('exists:picks_seasons,id'),
            Schema\Str::make('slug'),
            Schema\Str::make('status'),
            Schema\Str::make('sport')->get(fn (League $league) => SportScoring::sportOf($league->competition())),
            Schema\Integer::make('franchiseCount')->get(fn (League $league) => (int) $league->franchises_count),
            Schema\Integer::make('maxFranchises')->property('max_franchises'),
            Schema\Integer::make('rosterSize')->property('roster_size'),
            Schema\Integer::make('starters'),
            // The league page's own data: one league at a time, never per item in the list.
            Schema\Arr::make('page')->visible($showing)
                ->get(fn (League $league, FlarumContext $context) => $this->standings->forLeague($league, $context->getActor())),
        ];
    }
}
