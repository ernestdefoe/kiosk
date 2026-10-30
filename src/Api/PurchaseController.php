<?php

namespace Ernestdefoe\Kiosk\Api;

use Ernestdefoe\Kiosk\Model\Purchase;
use Ernestdefoe\Kiosk\Provider\Providers;
use Ernestdefoe\Kiosk\Service\Fulfilment;
use Ernestdefoe\Kiosk\Service\Presenter;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The buyer's own purchases.
 *
 * GET  /kiosk/purchases               — everything they have bought
 * GET  /kiosk/purchases/{number}      — one, for the return page to poll
 * POST /kiosk/purchases/{number}/confirm — back from PayPal: ask it what happened
 * POST /kiosk/purchases/{number}/cancel  — stop a subscription renewing
 */
class PurchaseController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(
        private Presenter $presenter,
        private Providers $providers,
        private Fulfilment $fulfilment,
        private TranslatorInterface $translator,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $actor->assertRegistered();
        $number = $this->param($request, 'number');

        if ($number === '') {
            $purchases = Purchase::query()
                ->where('user_id', $actor->id)
                ->whereNotIn('status', [Purchase::PENDING, Purchase::EXPIRED])
                ->with(['entitlements', 'payments'])
                ->orderByDesc('id')
                ->limit(200)
                ->get();

            return new JsonResponse(['data' => $this->presenter->purchases($purchases)]);
        }

        // Someone else's number is "not found", the same as a wrong one.
        $purchase = Purchase::query()->where('number', $number)->where('user_id', $actor->id)->first()
            ?? throw new ModelNotFoundException();

        $route = (string) $request->getAttribute('routeName');

        if (str_ends_with($route, '.confirm')) {
            $this->providers->get($purchase->provider)?->confirm($purchase);
            $purchase->refresh();
        } elseif (str_ends_with($route, '.cancel')) {
            if (! $purchase->isRecurring() || $purchase->status !== Purchase::ACTIVE || $purchase->provider === Purchase::GRANT) {
                throw new ValidationException(['purchase' => $this->translator->trans('ernestdefoe-kiosk.forum.errors.cannot_cancel')]);
            }
            if (! $this->providers->get($purchase->provider)?->cancel($purchase)) {
                throw new ValidationException(['purchase' => $this->translator->trans('ernestdefoe-kiosk.forum.errors.provider_unreachable')]);
            }
            $this->fulfilment->cancelAtPeriodEnd($purchase);
            $purchase->refresh();
        }

        $purchase->load(['entitlements', 'payments']);

        return new JsonResponse(['data' => $this->presenter->purchases([$purchase])[0]]);
    }
}
