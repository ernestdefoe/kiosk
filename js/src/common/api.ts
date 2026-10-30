import app from 'flarum/common/app';

export type Kind = 'one_off' | 'fixed_term' | 'recurring';
export type Interval = 'day' | 'week' | 'month' | 'year';

export interface Price {
  id: number;
  amountCents: number;
  currency: string;
  kind: Kind;
  interval: Interval | null;
  intervalCount: number;
  termDays: number | null;
  trialDays: number;
}

export interface Unlock {
  type: string;
  targetId: number;
  label: string | null;
  url: string | null;
  missing?: boolean;
}

export interface Product {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  benefits: string[];
  icon: string | null;
  color: string | null;
  hidden: boolean;
  unlocks: Unlock[];
  prices: Price[];
  /** Why the viewer cannot buy it now, or null. */
  refusal: null | 'unavailable' | 'sold_out' | 'login' | 'not_eligible';
  // Admin only:
  active?: boolean;
  buyerGroupIds?: number[];
  stock?: number | null;
  soldCount?: number;
  availableFrom?: string | null;
  availableUntil?: string | null;
  benefitsText?: string;
}

export interface Payment {
  id: number;
  amountCents: number;
  currency: string;
  status: 'completed' | 'refunded' | 'reversed';
  refundedCents: number;
  createdAt: string;
}

export interface Purchase {
  id: number;
  number: string;
  productId: number | null;
  productName: string;
  amountCents: number;
  currency: string;
  kind: Kind;
  interval: Interval | null;
  intervalCount: number;
  termDays: number | null;
  provider: string;
  mode: string | null;
  status: 'pending' | 'active' | 'canceled' | 'ended' | 'expired' | 'refunded';
  live: boolean;
  periodStart: string | null;
  periodEnd: string | null;
  cancelAtPeriodEnd: boolean;
  createdAt: string;
  paidAt: string | null;
  canCancel: boolean;
  unlocks: Unlock[];
  payments: Payment[];
  // Admin only:
  note?: string | null;
  providerRef?: string | null;
  user?: { id: number; username: string; displayName: string; avatarUrl: string | null; email: string } | null;
}

export interface StoreMeta {
  owned: number[];
  providers: { key: string; mode: string }[];
  termsUrl: string | null;
}

const url = (path: string) => app.forum.attribute<string>('apiUrl') + '/kiosk' + path;

export function call<T>(method: string, path: string, body?: object, params?: Record<string, unknown>): Promise<T> {
  return app.request<T>({ method, url: url(path), body: body ? { data: body } : undefined, params });
}

export const store = (lookup?: { type: string; id: number }) => call<{ data: Product[]; meta: StoreMeta }>('GET', '/store', undefined, lookup);
export const checkout = (priceId: number, provider: string) =>
  call<{ data: { url: string; number: string } }>('POST', '/checkout', { priceId, provider });
export const myPurchases = () => call<{ data: Purchase[] }>('GET', '/purchases');
export const purchase = (number: string) => call<{ data: Purchase }>('GET', `/purchases/${number}`);
export const confirm = (number: string) => call<{ data: Purchase }>('POST', `/purchases/${number}/confirm`);
export const cancel = (number: string) => call<{ data: Purchase }>('POST', `/purchases/${number}/cancel`);
