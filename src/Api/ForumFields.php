<?php

namespace ErnestDefoe\Fantasy\Api;

use Flarum\Api\Context;
use Flarum\Api\Schema;

/** Whether this visitor may start a league, so the index page draws the button only when it will work. */
class ForumFields
{
    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('canCreateFantasyLeague')
                ->get(fn ($forum, Context $context) => $context->getActor()->can('fantasy.createLeague')),
        ];
    }
}
