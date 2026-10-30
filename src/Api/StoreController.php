<?php

namespace Ernestdefoe\Kiosk\Api;

use Ernestdefoe\Kiosk\Model\Product;
use Ernestdefoe\Kiosk\Provider\Providers;
use Ernestdefoe\Kiosk\Service\Presenter;
use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /kiosk/store — what is for sale. With ?type=&id= it answers "which
 * products unlock this item?" for a Buy button, hidden products included,
 * because a hidden product is exactly one sold only from its item.
 *
 * Everything is local rows: no provider is called to render the store.
 */
class StoreController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private Presenter $presenter, private Providers $providers, private SettingsRepositoryInterface $settings)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $q = $this->query($request);
        $type = isset($q['type']) ? (string) $q['type'] : null;
        $id = isset($q['id']) ? (int) $q['id'] : 0;

        $products = Product::query()
            ->where('is_active', true)
            ->when($type !== null,
                fn ($query) => $query->whereIn('id', fn ($sub) => $sub->select('product_id')->from('kiosk_product_unlocks')->where('type', $type)->where('target_id', $id)),
                fn ($query) => $query->where('is_hidden', false))
            ->with(['unlocks', 'prices'])
            ->orderBy('position')
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->filter(fn (Product $p) => $p->prices->isNotEmpty());

        return new JsonResponse([
            'data' => $this->presenter->products($products, $actor),
            'meta' => [
                'providers' => array_map(fn ($p) => ['key' => $p->key(), 'mode' => $p->mode()], $this->providers->ready()),
                'termsUrl' => $this->settings->get('ernestdefoe-kiosk.terms_url') ?: null,
            ],
        ]);
    }
}
