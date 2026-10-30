import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import IndexPage from 'flarum/forum/components/IndexPage';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import classList from 'flarum/common/utils/classList';
import type ItemList from 'flarum/common/utils/ItemList';
import type Mithril from 'mithril';
import { t } from '../common/t';
import LockedNotice from './components/LockedNotice';

interface LockedTag {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  color: string | null;
  icon: string | null;
}

const locked = (): LockedTag[] => app.forum.attribute<LockedTag[]>('kioskLockedTags') || [];

/**
 * A paid tag the visitor can't see stays visible as a locked tile, and its
 * own page shows what unlocks it, instead of vanishing (or a bare 404).
 */
export default function lockedTags() {
  // The tag's page: the discussion list becomes the offer.
  extend(IndexPage.prototype, 'contentItems', function (items: ItemList<Mithril.Children>) {
    const slug = app.search.state.params().tags;
    const tag = slug && locked().find((x) => x.slug.localeCompare(slug, undefined, { sensitivity: 'base' }) === 0);
    if (!tag) return;

    items.remove('toolbar');
    items.setContent('discussionList', <LockedNotice type="tag" id={tag.id} name={tag.name} />);
  });

  // The Tags page: locked tiles after the ones they can open.
  extend('ext:flarum/tags/forum/components/TagsPage', 'contentItems', function (items: ItemList<Mithril.Children>) {
    const tags = locked();
    if (!tags.length || !items.has('tagTiles')) return;

    items.add(
      'kioskLocked',
      <div className="KioskLockedTags">
        <h3 className="KioskLockedTags-title">{t('forum.locked.tags_title')}</h3>
        <ul className="TagTiles">
          {tags.map((tag) => (
            <li className={classList('TagTile KioskLockedTile', { colored: tag.color })} style={{ '--tag-bg': tag.color || undefined }}>
              <Link className="TagTile-info" href={app.route('tag', { tags: tag.slug })}>
                <div className="TagTile-heading">
                  <Icon name="fas fa-lock" className="KioskLockedTile-lock" />
                  <h3 className="TagTile-name">{tag.name}</h3>
                </div>
                {tag.description ? <p className="TagTile-description">{tag.description}</p> : null}
                <span className="KioskLockedTile-cta">{t('forum.locked.see_how')}</span>
              </Link>
            </li>
          ))}
        </ul>
      </div>,
      95
    );
  });
}
