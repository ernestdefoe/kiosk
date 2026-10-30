<?php

namespace Ernestdefoe\Kiosk\Service;

use Carbon\Carbon;
use Ernestdefoe\Kiosk\Model\Entitlement;
use Ernestdefoe\Kiosk\Model\Purchase;
use Ernestdefoe\Kiosk\Notification\PurchaseBlueprint;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Team plans: a purchase with more than one seat. The buyer holds one; they
 * give the rest to other members, and take them back, while it lasts. Every
 * seat holder gets the same access for the same period, through the same
 * entitlements code as the buyer.
 */
class Seats
{
    public function __construct(
        private ConnectionInterface $db,
        private Fulfilment $fulfilment,
        private NotificationSyncer $notifications,
        private TranslatorInterface $translator,
    ) {
    }

    /** @return list<array{id: int, username: string, displayName: string}> */
    public function holders(Purchase $purchase): array
    {
        $ids = $this->db->table('kiosk_seats')->where('purchase_id', $purchase->id)->orderBy('added_at')->pluck('user_id')->all();

        return User::query()->whereIn('id', $ids)->get()
            ->map(fn (User $u) => ['id' => (int) $u->id, 'username' => (string) $u->username, 'displayName' => (string) $u->display_name])
            ->values()->all();
    }

    public function add(Purchase $purchase, string $username): void
    {
        $user = User::query()->where('username', trim($username))->first();

        if (! $user) {
            throw $this->error('no_such_user');
        }
        if (! $purchase->isLive() || $purchase->seats <= 1) {
            throw $this->error('no_seats');
        }
        if ((int) $user->id === $purchase->user_id || $this->db->table('kiosk_seats')->where('purchase_id', $purchase->id)->where('user_id', $user->id)->exists()) {
            throw $this->error('seat_taken');
        }
        if ($this->db->table('kiosk_seats')->where('purchase_id', $purchase->id)->count() >= $purchase->seats - 1) {
            throw $this->error('seats_full');
        }

        $this->db->table('kiosk_seats')->insert(['purchase_id' => $purchase->id, 'user_id' => $user->id, 'added_at' => Carbon::now()]);
        $this->fulfilment->open($purchase);
        $this->notifications->sync(new PurchaseBlueprint($purchase, 'seat'), [$user]);
    }

    public function remove(Purchase $purchase, int $userId): void
    {
        $removed = $this->db->table('kiosk_seats')->where('purchase_id', $purchase->id)->where('user_id', $userId)->delete();

        if ($removed) {
            Entitlement::query()->where('purchase_id', $purchase->id)->where('user_id', $userId)->whereNull('closed_at')->update(['ends_at' => Carbon::now()]);
            $this->fulfilment->closeDue($userId);
        }
    }

    private function error(string $key): ValidationException
    {
        return new ValidationException(['seat' => $this->translator->trans('ernestdefoe-kiosk.forum.errors.'.$key)]);
    }
}
