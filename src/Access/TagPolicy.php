<?php

namespace Ernestdefoe\Kiosk\Access;

use Ernestdefoe\Kiosk\Service\Gate;
use Flarum\Tags\Tag;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

/** Starting a discussion in a paid teaser tag takes having bought the tag. */
class TagPolicy extends AbstractPolicy
{
    public function __construct(private Gate $gate)
    {
    }

    public function startDiscussion(User $actor, Tag $tag): ?string
    {
        return in_array((int) $tag->id, $this->gate->lockedTagIds($actor), true) ? $this->deny() : null;
    }
}
