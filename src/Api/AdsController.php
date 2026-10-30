<?php

namespace Ernestdefoe\Kiosk\Api;

use Ernestdefoe\Kiosk\Model\Ad;
use Ernestdefoe\Kiosk\Service\Ads;
use Illuminate\Contracts\Cache\Repository as Cache;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /kiosk/ads/seen     — {seen: {adId: count}}, sent in batches by the page
 * GET  /kiosk/ads/{id}/go  — count the click, then go to the ad's link
 *
 * Counts are a guide, not billing: each address may report 30 batches a
 * minute and one click per ad per 10 minutes, and only live ads count.
 */
class AdsController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private Ads $ads, private Cache $cache)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $ip = sha1((string) $request->getAttribute('ipAddress'));

        if ($request->getMethod() === 'POST') {
            $key = 'kiosk.ads.seen.'.$ip.'.'.intdiv(time(), 60);
            $batches = (int) $this->cache->get($key, 0);

            if ($batches < 30) {
                $this->cache->put($key, $batches + 1, 120);
                $live = array_flip($this->ads->liveIds());
                $counts = [];

                foreach (array_slice((array) ($this->body($request)['seen'] ?? []), 0, 30, true) as $id => $n) {
                    if (isset($live[(int) $id])) {
                        $counts[(int) $id] = max(1, min(50, (int) $n));
                    }
                }

                $this->ads->seen($counts);
            }

            return new EmptyResponse();
        }

        $ad = Ad::query()->find($this->routeId($request));

        if (! $ad || $ad->kind !== Ad::IMAGE || ! $ad->link_url) {
            return new EmptyResponse(404);
        }

        if ($this->cache->add('kiosk.ads.click.'.$ip.'.'.$ad->id, 1, 600)) {
            $this->ads->clicked($ad->id);
        }

        return new RedirectResponse($ad->link_url);
    }
}
