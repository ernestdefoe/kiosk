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

    /**
     * @param array{amount?: mixed, context?: array{type?: string, id?: int}} $options
     *   amount: what the buyer chose, for a pay-what-you-want price;
     *   context: what a tip backs ({type: discussion, id}).
     * @return array{url: string, number: string}
     */
    public function start(User $actor, int $priceId, string $providerKey, array $options = []): array
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

        if ($price->kind === Price::CUSTOM) {
            $chosen = \Ernestdefoe\Kiosk\Model\Money::toMinor($options['amount'] ?? null, $price->currency);
            if ($chosen === null || $chosen < $price->amount_cents || $chosen > 100_000_000) {
                throw $this->error('amount');
            }
            $purchase->amount_cents = $chosen;
        }

        $this->applyContext($purchase, $product, $options['context'] ?? null);
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

    /**
     * A tip backs one discussion, which must be in a tag the product tips in
     * and visible to the buyer. Anything else carries no context.
     *
     * @param mixed $context
     */
    private function applyContext(Purchase $purchase, \Ernestdefoe\Kiosk\Model\Product $product, mixed $context): void
    {
        $tipTags = $product->unlocks()->where('type', 'tip')->pluck('target_id')->map(fn ($v) => (int) $v)->all();

        if (! $tipTags) {
            return;
        }

        $id = (int) (is_array($context) ? ($context['id'] ?? 0) : 0);
        $discussion = $id ? \Flarum\Discussion\Discussion::query()->whereVisibleTo(User::query()->findOrFail($purchase->user_id))->find($id) : null;

        if (! $discussion || ! array_intersect($tipTags, resolve(\Ernestdefoe\Kiosk\Service\Gate::class)->tagsOf((int) $discussion->id))) {
            throw $this->error('tip_target');
        }

        $purchase->context_type = 'discussion';
        $purchase->context_id = $discussion->id;
    }

    private function error(string $reason): ValidationException
    {
        return new ValidationException(['checkout' => $this->translator->trans('ernestdefoe-kiosk.forum.errors.'.$reason)]);
    }
}
