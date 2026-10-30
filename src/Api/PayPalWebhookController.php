<?php

namespace Ernestdefoe\Kiosk\Api;

use Carbon\Carbon;
use Ernestdefoe\Kiosk\Model\WebhookEvent;
use Ernestdefoe\Kiosk\Provider\PayPal;
use Ernestdefoe\Kiosk\Provider\PayPalClient;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\QueryException;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /kiosk/webhooks/paypal — PayPal telling us something happened.
 *
 * 🚨 A public URL that hands out paid access: nothing is read until PayPal
 * itself has confirmed the delivery is genuine. Then the event is stored —
 * the unique (provider, event_id) index answers "already have it" to a
 * retry — and acted on. A failure is stored with its error and answered 500,
 * so PayPal retries and the admin can see it and press Replay.
 */
class PayPalWebhookController implements RequestHandlerInterface
{
    public function __construct(
        private PayPalClient $api,
        private PayPal $paypal,
        private SettingsRepositoryInterface $settings,
        private LoggerInterface $log,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $raw = (string) $request->getBody();

        if (strlen($raw) > 1_000_000 || $this->api->verifyWebhook($request->getHeaders(), $raw) === null) {
            $this->log->warning('[kiosk] refused a PayPal webhook that PayPal did not vouch for');

            return new EmptyResponse(400);
        }

        $event = (array) json_decode($raw, true);
        $id = (string) ($event['id'] ?? '');

        if ($id === '') {
            return new EmptyResponse(400);
        }

        $this->settings->set('ernestdefoe-kiosk.paypal_last_webhook_at', Carbon::now()->toIso8601String());

        $row = WebhookEvent::query()->where('provider', 'paypal')->where('event_id', $id)->first();

        if ($row?->processed_at) {
            return new EmptyResponse(200);
        }

        if (! $row) {
            $row = new WebhookEvent();
            $row->provider = 'paypal';
            $row->event_id = mb_substr($id, 0, 191);
            $row->type = mb_substr((string) ($event['event_type'] ?? ''), 0, 100);
            $row->payload = $raw;
            $row->received_at = Carbon::now();

            try {
                $row->save();
            } catch (QueryException) {
                // The same event arriving twice at once: the other request has it.
                return new EmptyResponse(200);
            }
        }

        return self::process($row, $this->paypal, $this->log) ? new EmptyResponse(200) : new EmptyResponse(500);
    }

    /** Act on a stored event; the admin's Replay button comes through here too. */
    public static function process(WebhookEvent $row, PayPal $paypal, LoggerInterface $log): bool
    {
        try {
            $paypal->handle((array) json_decode($row->payload, true));
            $row->processed_at = Carbon::now();
            $row->error = null;
            $row->save();

            return true;
        } catch (\Throwable $e) {
            $log->error('[kiosk] PayPal webhook '.$row->event_id.' ('.$row->type.') failed: '.$e->getMessage());
            $row->error = mb_substr($e->getMessage(), 0, 500);
            $row->save();

            return false;
        }
    }
}
