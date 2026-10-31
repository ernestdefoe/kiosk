<?php

namespace Ernestdefoe\Kiosk\Seller;

use Ernestdefoe\Kiosk\Model\Product;
use Ernestdefoe\Kiosk\Service\ProductSaver;
use Ernestdefoe\Kiosk\Unlock\Registry;
use Ernestdefoe\Kiosk\Unlock\SellableByMembers;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;
use Illuminate\Support\Arr;

/**
 * A member's listing: a Kiosk product with a seller. It goes through the same
 * saver as the admin's products, but with only what a member may set, only
 * things they own, and (unless they're trusted) back to the moderators after
 * each change.
 */
class Listings
{
    /** How many listings one member may have. */
    public const MAX = 50;

    public function __construct(
        private ProductSaver $saver,
        private Registry $types,
        private TranslatorInterface $translator,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function save(User $seller, ?Product $product, array $data): Product
    {
        if ($product === null && Product::query()->where('seller_id', $seller->id)->count() >= self::MAX) {
            throw $this->invalid('name', 'too_many_listings');
        }

        // What a member may set. Everything else (buyer groups, stock, dates,
        // hidden, slug) is the forum's.
        $allowed = Arr::only($data, ['name', 'description', 'benefits', 'icon', 'color', 'active', 'unlocks', 'prices']);

        foreach ((array) ($allowed['unlocks'] ?? []) as $unlock) {
            $type = $this->types->get((string) Arr::get((array) $unlock, 'type'));
            if (! $type instanceof SellableByMembers || ! $type->owns($seller, (int) Arr::get((array) $unlock, 'targetId'))) {
                throw $this->invalid('unlocks', 'not_yours');
            }
        }

        // No team seats on a member's listing: one buyer, one access.
        if (isset($allowed['prices'])) {
            $allowed['prices'] = array_map(fn ($p) => ['seats' => 1] + (array) $p, (array) $allowed['prices']);
        }

        $onlyToggled = $product !== null && array_keys($allowed) === ['active'];
        $saved = $this->saver->save($product, $allowed);

        $saved->seller_id = $seller->id;
        if (! $onlyToggled || $saved->review === Product::REJECTED) {
            $saved->review = $seller->hasPermission(Sellers::TRUSTED) ? null : Product::PENDING;
            $saved->review_note = null;
        }
        $saved->save();

        return $saved;
    }

    private function invalid(string $field, string $reason): ValidationException
    {
        return new ValidationException([$field => $this->translator->trans('ernestdefoe-kiosk.forum.selling.errors.'.$reason)]);
    }
}
