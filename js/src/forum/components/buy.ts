import app from 'flarum/forum/app';
import { checkout } from '../../common/api';
import type { Price, Product, StoreMeta } from '../../common/api';

/**
 * Buy a price: sign in first if needed, ask the terms and the provider only
 * when there is something to ask, then hand over to the provider's page.
 */
export function buy(product: Product, price: Price, meta: StoreMeta): Promise<void> | null {
  if (!app.session.user) {
    // 🚨 Flarum 2 modals are lazy chunks: a static import is undefined.
    app.modal.show(() => import('flarum/forum/components/LogInModal'));
    return null;
  }

  if (meta.termsUrl || meta.providers.length > 1) {
    app.modal.show(() => import('./CheckoutModal'), { product, price, meta });
    return null;
  }

  return go(price, meta.providers[0]?.key || 'paypal');
}

export function go(price: Price, provider: string): Promise<void> {
  return checkout(price.id, provider).then(({ data }) => {
    window.location.href = data.url;
  });
}
