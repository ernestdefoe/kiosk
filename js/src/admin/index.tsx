import app from 'flarum/admin/app';
import { extend } from 'flarum/common/extend';
import KioskPage from './KioskPage';
import SellThis from './SellThis';

export { default as ProductModal } from './ProductModal';

app.initializers.add('ernestdefoe-kiosk', () => {
  app.registry.for('ernestdefoe-kiosk').registerPage(KioskPage);

  // 🚨 Both editors are lazy chunks in Flarum 2: extend them by module name,
  // never by importing the class (it is undefined at boot).
  extend('ext:flarum/tags/admin/components/EditTagModal', 'fields', function (this: any, items: any) {
    if (!this.tag?.exists) return;
    items.add('kiosk', <SellThis type="tag" id={Number(this.tag.id())} name={this.tag.name()} />, -15);
  });

  extend('flarum/admin/components/EditGroupModal', 'fields', function (this: any, items: any) {
    const group = this.group;
    // Admins, guests and members are never for sale.
    if (!group?.exists || ['1', '2', '3'].includes(group.id())) return;
    items.add('kiosk', <SellThis type="group" id={Number(group.id())} name={group.namePlural()} />, -15);
  });
});
