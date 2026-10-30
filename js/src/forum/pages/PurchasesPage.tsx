import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import PageStructure from 'flarum/forum/components/PageStructure';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import Button from 'flarum/common/components/Button';
import LinkButton from 'flarum/common/components/LinkButton';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import extractText from 'flarum/common/utils/extractText';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import { t } from '../../common/t';
import { date, money, priceLabel } from '../../common/money';
import { cancel, myPurchases } from '../../common/api';
import type { Purchase } from '../../common/api';

/** /store/purchases — what the member holds, when it renews or ends, and their payments. */
export default class PurchasesPage extends Page {
  purchases: Purchase[] = [];
  loading = true;
  busy: string | null = null;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.bodyClass = 'App--kiosk';

    if (!app.session.user) {
      m.route.set(app.route('kiosk.store'));
      return;
    }

    const title = extractText(t('forum.purchases.title'));
    app.setTitle(title);
    app.history.push('kiosk.purchases', title);

    myPurchases().then(
      ({ data }) => {
        this.purchases = data;
        this.loading = false;
        m.redraw();
      },
      () => {
        this.loading = false;
        m.redraw();
      }
    );
  }

  view() {
    return (
      <PageStructure className="KioskPurchasesPage" sidebar={() => <IndexSidebar />}>
        <div className="KioskPageHead">
          <h2 className="KioskPageHead-title">{t('forum.purchases.title')}</h2>
          <LinkButton className="Button" icon="fas fa-store" href={app.route('kiosk.store')}>
            {t('forum.store.title')}
          </LinkButton>
        </div>

        {this.loading ? (
          <LoadingIndicator display="block" />
        ) : this.purchases.length ? (
          <ul className="KioskPurchases">{this.purchases.map((p) => this.row(p))}</ul>
        ) : (
          <p className="KioskEmpty">{t('forum.purchases.empty')}</p>
        )}
      </PageStructure>
    );
  }

  row(p: Purchase): Mithril.Children {
    return (
      <li className={classList('KioskPurchase', { 'KioskPurchase--live': p.live })}>
        <div className="KioskPurchase-main">
          <h3 className="KioskPurchase-name">{p.productName}</h3>
          <span className={'KioskStatus KioskStatus--' + (p.live ? 'live' : p.status)}>
            {t('lib.status.' + (p.live ? (p.status === 'canceled' ? 'canceled' : 'active') : p.status))}
          </span>
        </div>
        <p className="KioskPurchase-terms">
          {p.provider === 'grant' ? t('forum.purchases.gift') : priceLabel(p)}
          {' · '}
          {this.when(p)}
        </p>
        {p.unlocks.length ? (
          <div className="KioskCard-unlocks">
            {p.unlocks.map((u) =>
              u.url && p.live ? (
                <a className="KioskChip" href={u.url}>
                  {u.label}
                </a>
              ) : (
                <span className="KioskChip">{u.label || t('forum.purchases.removed')}</span>
              )
            )}
          </div>
        ) : null}
        {p.payments.length ? (
          <details className="KioskPurchase-payments">
            <summary>{t('forum.purchases.payments', { count: p.payments.length })}</summary>
            <ul>
              {p.payments.map((pay) => (
                <li>
                  {date(pay.createdAt)} — {money(pay.amountCents, pay.currency)}
                  {pay.status !== 'completed' ? ' (' + extractText(t('lib.payment_status.' + pay.status)) + ')' : ''}
                </li>
              ))}
            </ul>
            <small className="KioskPurchase-number">{t('forum.purchases.number', { number: p.number })}</small>
          </details>
        ) : null}
        {p.canCancel ? (
          <Button className="Button Button--link KioskPurchase-cancel" loading={this.busy === p.number} onclick={() => this.cancel(p)}>
            {t('forum.purchases.cancel')}
          </Button>
        ) : null}
      </li>
    );
  }

  when(p: Purchase): Mithril.Children {
    if (!p.periodEnd) return t('forum.purchases.forever');
    if (!p.live) return t('forum.purchases.ended_on', { date: date(p.periodEnd) });
    if (p.kind === 'recurring' && p.status === 'active') return t('forum.purchases.renews_on', { date: date(p.periodEnd) });

    return t('forum.purchases.until', { date: date(p.periodEnd) });
  }

  cancel(p: Purchase) {
    if (!confirm(extractText(t('forum.purchases.cancel_confirm', { date: date(p.periodEnd) })))) return;

    this.busy = p.number;
    cancel(p.number).then(
      ({ data }) => {
        this.purchases = this.purchases.map((x) => (x.number === data.number ? data : x));
        this.busy = null;
        m.redraw();
      },
      () => {
        this.busy = null;
        m.redraw();
      }
    );
  }
}
