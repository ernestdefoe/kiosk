<?php

namespace Ernestdefoe\Kiosk\Seller;

use Ernestdefoe\Kiosk\Provider\Providers;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

/**
 * Members who sell: whether selling is on, who may, and which of their payout
 * accounts can take a buyer's money right now.
 */
class Sellers
{
    public const ENABLED = 'ernestdefoe-kiosk.sellers';

    /** Providers members may connect, comma-separated (empty = every one the forum can bind). */
    public const PROVIDERS = 'ernestdefoe-kiosk.seller_providers';

    /** New listings and price changes wait for a moderator unless the seller has this. */
    public const TRUSTED = 'kiosk.sellTrusted';

    public const PERMISSION = 'kiosk.sell';

    /** @var array<int, array<string, Account>> seller id => provider => account */
    private array $ready = [];

    public function __construct(private SettingsRepositoryInterface $settings, private Providers $providers)
    {
    }

    public function enabled(): bool
    {
        return (bool) $this->settings->get(self::ENABLED);
    }

    public function maySell(User $user): bool
    {
        return $this->enabled() && $user->exists && $user->hasPermission(self::PERMISSION);
    }

    /** @return list<string> provider keys members may connect */
    public function providerKeys(): array
    {
        $chosen = array_filter(array_map('trim', explode(',', (string) $this->settings->get(self::PROVIDERS))));
        $bindable = array_values(array_filter($this->providers->keys(), function (string $key) {
            return $this->providers->get($key) instanceof SellerProvider;
        }));

        return $chosen ? array_values(array_intersect($bindable, $chosen)) : $bindable;
    }

    /**
     * Each seller's accounts that can take money now, in the mode the forum's
     * provider is in. One query for any number of sellers.
     *
     * @param list<int> $sellerIds
     * @return array<int, array<string, Account>>
     */
    public function readyAccounts(array $sellerIds): array
    {
        $missing = array_values(array_diff(array_unique($sellerIds), array_keys($this->ready)));

        if ($missing && $this->enabled()) {
            $keys = $this->providerKeys();
            foreach ($missing as $id) {
                $this->ready[$id] = [];
            }
            $rows = Account::query()->whereIn('user_id', $missing)->where('status', Account::CONNECTED)->whereIn('provider', $keys ?: ['-'])->get();
            foreach ($rows as $account) {
                $forum = $this->providers->get($account->provider);
                $bound = $forum && $forum->mode() === $account->mode ? $this->providers->forAccount($account) : null;
                if ($bound && $bound->isReady()) {
                    $this->ready[$account->user_id][$account->provider] = $account;
                }
            }
        }

        return array_intersect_key($this->ready, array_flip($sellerIds));
    }

    public function account(int $sellerId, string $provider): ?Account
    {
        return $this->readyAccounts([$sellerId])[$sellerId][$provider] ?? null;
    }
}
