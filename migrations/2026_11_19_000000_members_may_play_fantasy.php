<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

/*
 * Joining a league, drafting and setting a lineup need `fantasy.play`, given to
 * members. A suspended account or one whose email is not confirmed does not
 * hold member permissions, so it can watch but not play.
 */
return Migration::addPermissions([
    'fantasy.play' => Group::MEMBER_ID,
]);
