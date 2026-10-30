<?php

namespace Ernestdefoe\Kiosk\Service;

use Carbon\Carbon;
use Ernestdefoe\Kiosk\Model\Price;
use Ernestdefoe\Kiosk\Model\Purchase;
use Ernestdefoe\Kiosk\Provider\Providers;
use Flarum\Foundation\ValidationException;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;

/**
 * Starting a purchase. Everything about what is charged comes from the price
 * row: the browser sends a price id and a provider, never an amount.
 */
class Checkout
{
    /** Checkouts one member may start in ten minutes. */
    private const PER_TEN_MINUTES = 8;

    public function __construct(
        private Providers $providers,
        private UrlGenerator $url,
        private TranslatorInterface $translator,
    ) {
    }

    /** @return array{url: string, number: string} */
    public function start(User $actor, int $priceId, string $providerKey): array
    {
        $actor->assertRegistered();

        // Flarum treats an unconfirmed account as a guest, so what they bought
        // would not open until they confirmed: ask first, not after paying.
        if (! $actor->is_email_confirmed) {
            throw $this->error('confirm_email');
        }

        /** @var Price|null $price */
        $price = Price::query()->whereNull('archived_at')->with('product')->find($priceId);
        $product = $price?->product;

        if (! $price || ! $product) {
            throw $this->error('unavailable');
        }

        if ($refusal = $product->refusal($actor)) {
            throw $this->error($refusal);
        }

        $provider = $this->providers->get($providerKey);

        if (! $provider || ! $provider->isReady() || ! $provider->supports($price)) {
            throw $this->error('provider_unavailable');
        }

        // 🚨 One subscription to a product at a time: a second would bill
        // twice for access they already have.
        if ($price->isRecurring() && Purchase::query()
            ->where('user_id', $actor->id)
            ->where('product_id', $product->id)
            ->where('kind', Price::RECURRING)
            ->where('status', Purchase::ACTIVE)
            ->exists()) {
            throw $this->error('already_subscribed');
        }

        $recent = Purchase::query()->where('user_id', $actor->id)->where('created_at', '>', Carbon::now()->subMinutes(10))->count();
        if ($recent >= self::PER_TEN_MINUTES) {
            throw $this->error('too_many');
        }

        $purchase = Purchase::start($actor, $price, $product, $provider->key(), $provider->mode());
        $purchase->save();

        $return = $this->url->to('forum')->route('kiosk.return', ['number' => $purchase->number]);
        $cancel = $return.'?cancelled=1';

        try {
            $link = $provider->checkout($purchase, $price, $product, $return, $cancel);
        } catch (\Throwable $e) {
            // Nothing was charged; don't leave a row that looks like a sale.
            $purchase->delete();

            throw $e;
        }

        return ['url' => $link, 'number' => $purchase->number];
    }

    private function error(string $reason): ValidationException
    {
        return new ValidationException(['checkout' => $this->translator->trans('ernestdefoe-kiosk.forum.errors.'.$reason)]);
    }
}
