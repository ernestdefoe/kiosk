import app from 'flarum/forum/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import LinkButton from 'flarum/common/components/LinkButton';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import { t } from '../../common/t';
import { priceLabel } from '../../common/money';
import type { Price, Product, StoreMeta } from '../../common/api';
import { buy } from './buy';

export interface ProductCardAttrs extends ComponentAttrs {
  product: Product;
  meta: StoreMeta;
  highlight?: boolean;
}

/**
 * A product as a buyer sees it: what it is, what it opens, its prices and a
 * Buy button. Exported for other extensions (ext:ernestdefoe/kiosk/forum/components/ProductCard),
 * so every Buy button on the forum looks and works the same.
 */
export default class ProductCard extends Component<ProductCardAttrs> {
  selected: Price | null = null;
  busy = false;

  oninit(vnode: Mithril.Vnode<ProductCardAttrs, this>) {
    super.oninit(vnode);
    this.selected = this.attrs.product.prices[0] || null;
  }

  view() {
    const { product, meta, highlight } = this.attrs;
    const owned = meta.owned.includes(product.id);
    const style = product.color ? { '--kiosk-accent': product.color } : undefined;

    return (
      <article
        className={classList('KioskCard', { 'KioskCard--highlight': highlight, 'KioskCard--owned': owned })}
        style={style}
        id={'kiosk-' + product.slug}
      >
        <header className="KioskCard-head">
          <span className="KioskCard-icon" aria-hidden="true">
            <Icon name={product.icon || 'fas fa-star'} />
          </span>
          <h3 className="KioskCard-name">{product.name}</h3>
        </header>

        {product.description ? <p className="KioskCard-description">{product.description}</p> : null}

        {product.benefits.length ? (
          <ul className="KioskCard-benefits">
            {product.benefits.map((b) => (
              <li>
                <Icon name="fas fa-check" />
                <span>{b}</span>
              </li>
            ))}
          </ul>
        ) : null}

        {product.unlocks.some((u) => u.label) ? (
          <div className="KioskCard-unlocks">
            <span className="KioskCard-unlocksLabel">{t('forum.card.includes')}</span>
            {product.unlocks
              .filter((u) => u.label)
              .map((u) =>
                u.url ? (
                  <a className="KioskChip" href={u.url}>
                    {u.label}
                  </a>
                ) : (
                  <span className="KioskChip">{u.label}</span>
                )
              )}
          </div>
        ) : null}

        <div className="KioskCard-foot">
          {product.prices.length > 1 ? (
            <div className="KioskCard-prices" role="radiogroup" aria-label={String(t('forum.card.choose_price'))}>
              {product.prices.map((price) => (
                <button
                  type="button"
                  role="radio"
                  aria-checked={this.selected?.id === price.id}
                  className={classList('KioskPrice', { 'KioskPrice--selected': this.selected?.id === price.id })}
                  onclick={() => (this.selected = price)}
                >
                  {priceLabel(price)}
                  {price.trialDays ? <small>{t('forum.card.trial', { count: price.trialDays })}</small> : null}
                </button>
              ))}
            </div>
          ) : this.selected ? (
            <p className="KioskCard-price">
              {priceLabel(this.selected)}
              {this.selected.trialDays ? <small>{t('forum.card.trial', { count: this.selected.trialDays })}</small> : null}
            </p>
          ) : null}

          {this.action(owned)}
        </div>
      </article>
    );
  }

  action(owned: boolean): Mithril.Children {
    const { product, meta } = this.attrs;

    if (owned) {
      return (
        <LinkButton className="Button KioskCard-button" icon="fas fa-circle-check" href={app.route('kiosk.purchases')}>
          {t('forum.card.owned')}
        </LinkButton>
      );
    }

    if (product.refusal && product.refusal !== 'login') {
      return <p className="KioskCard-refusal">{t('forum.card.refusal.' + product.refusal)}</p>;
    }

    if (!meta.providers.length) {
      return <p className="KioskCard-refusal">{t('forum.card.refusal.no_provider')}</p>;
    }

    return (
      <Button
        className="Button Button--primary KioskCard-button"
        loading={this.busy}
        disabled={!this.selected}
        onclick={() => {
          const started = this.selected && buy(product, this.selected, meta);
          if (!started) return;
          this.busy = true;
          // Success leaves the page for the provider's; only a failure comes back here.
          started.catch(() => {
            this.busy = false;
            m.redraw();
          });
        }}
      >
        {app.session.user ? t('forum.card.buy') : t('forum.card.login_to_buy')}
      </Button>
    );
  }
}
