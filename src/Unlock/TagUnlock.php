<?php

namespace Ernestdefoe\Kiosk\Unlock;

use Ernestdefoe\Kiosk\Service\Access;
use Flarum\Group\Group;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Flarum\Tags\Tag;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Access to a tag: a premium section.
 *
 * Selling a tag makes it restricted and gives a hidden group view, start and
 * reply permissions in it. Buyers count as members of that group for
 * permissions only (see Access::permissionGroups), so nothing is written to
 * group_user, no badge appears, and access ends the moment the entitlement
 * does — even on a forum whose scheduler never runs.
 */
class TagUnlock implements UnlockType
{
    /** Permissions a buyer gets in the tag. */
    private const BUYER = ['viewForum', 'startDiscussion', 'discussion.reply'];

    public function __construct(
        private ConnectionInterface $db,
        private UrlGenerator $url,
        private TranslatorInterface $translator,
        private Access $access,
    ) {
    }

    public function label(): string
    {
        return 'ernestdefoe-kiosk.lib.unlock_types.tag';
    }

    public function icon(): string
    {
        return 'fas fa-tag';
    }

    public function search(User $actor, string $query, int $limit): array
    {
        return Tag::query()
            ->when($query !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes($query, '%_\\').'%'))
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name'])
            ->map(fn (Tag $t) => ['id' => (int) $t->id, 'label' => (string) $t->name])
            ->all();
    }

    public function describe(array $ids): array
    {
        return Tag::query()->whereIn('id', $ids)->get(['id', 'name', 'slug'])
            ->mapWithKeys(fn (Tag $t) => [(int) $t->id => [
                'label' => (string) $t->name,
                'url' => $this->url->to('forum')->route('tag', ['slug' => $t->slug]),
            ]])
            ->all();
    }

    public function grant(User $user, int $targetId): void
    {
        // Access is computed from the entitlement; see Access::permissionGroups.
    }

    public function revoke(User $user, int $targetId): void
    {
    }

    /**
     * Make the tag sellable: restricted, with a hidden group holding the
     * buyers' permissions.
     *
     * 🚨 Restricting a tag takes it away from every group that only had the
     * GLOBAL permission — including moderators, who would silently stop being
     * able to see or moderate it. So each staff group (not guests or members)
     * gets the tag-level copy of the global moderation permissions it has,
     * plus view: the tag goes behind the paywall for members, not for staff.
     */
    public function prepare(int $targetId): void
    {
        /** @var Tag|null $tag */
        $tag = Tag::query()->find($targetId);

        if (! $tag) {
            return;
        }

        $this->db->transaction(function () use ($tag) {
            $groupId = $this->db->table('kiosk_tag_groups')->where('tag_id', $tag->id)->value('group_id');

            if (! $groupId || ! Group::query()->whereKey($groupId)->exists()) {
                $group = new Group();
                $group->name_singular = mb_substr($this->translator->trans('ernestdefoe-kiosk.lib.tag_group.singular', ['{tag}' => $tag->name]), 0, 100);
                $group->name_plural = mb_substr($this->translator->trans('ernestdefoe-kiosk.lib.tag_group.plural', ['{tag}' => $tag->name]), 0, 100);
                $group->icon = 'fas fa-store';
                $group->is_hidden = true;
                $group->save();
                $groupId = $group->id;

                $this->db->table('kiosk_tag_groups')->updateOrInsert(['tag_id' => $tag->id], ['group_id' => $groupId]);
            }

            $rows = [];

            if (! $tag->is_restricted) {
                $rows = $this->staffRows($tag);
                $tag->is_restricted = true;
                $tag->save();
            }

            $perms = array_map(fn ($p) => "tag{$tag->id}.$p", self::BUYER);

            // A child tag is only visible if its parent is, so a restricted
            // parent must let buyers see it too.
            if ($tag->parent_id && $this->db->table('tags')->where('id', $tag->parent_id)->where('is_restricted', true)->exists()) {
                $perms[] = "tag{$tag->parent_id}.viewForum";
            }

            foreach ($perms as $permission) {
                $rows[$groupId.'|'.$permission] = ['group_id' => $groupId, 'permission' => $permission];
            }

            $this->db->table('group_permission')->insertOrIgnore(array_values($rows));
        });

        $this->access->forgetTagGroups();
    }

    /** @return array<string, array{group_id: int, permission: string}> */
    private function staffRows(Tag $tag): array
    {
        $out = [];

        foreach ($this->db->table('group_permission')
            ->whereNotIn('group_id', [Group::GUEST_ID, Group::MEMBER_ID, Group::ADMINISTRATOR_ID])
            ->where('permission', 'like', 'discussion.%')
            ->get(['group_id', 'permission']) as $row) {
            foreach (["tag{$tag->id}.{$row->permission}", "tag{$tag->id}.viewForum"] as $permission) {
                $out[$row->group_id.'|'.$permission] = ['group_id' => (int) $row->group_id, 'permission' => $permission];
            }
        }

        return $out;
    }
}
