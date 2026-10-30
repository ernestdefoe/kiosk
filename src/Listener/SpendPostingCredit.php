<?php

namespace Ernestdefoe\Kiosk\Listener;

use Ernestdefoe\Kiosk\Service\Credits;
use Flarum\Discussion\Event\Started;

/**
 * A discussion started in a tag where posting is paid spends one listing
 * credit (a posting pass is not spent). The policy already refused anyone
 * with neither, so here there is always something to spend.
 */
class SpendPostingCredit
{
    public function __construct(private Credits $credits)
    {
    }

    public function handle(Started $event): void
    {
        $paid = $this->credits->tags()['post'];
        if (! $paid) {
            return;
        }

        $tags = $event->discussion->tags()->pluck('tags.id')->map(fn ($v) => (int) $v)->all();
        $here = array_values(array_intersect($tags, $paid));

        if ($here) {
            $this->credits->spend($event->actor, 'post', $here);
        }
    }
}
