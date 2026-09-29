# Coppermine Identity Import

Coppermine users and groups are migrated conservatively.

## Passwords

Legacy Coppermine password hashes are **not** copied into Mediarama authentication.

Imported active users receive status `password_reset_required` and no usable Mediarama password hash.

This avoids making Mediarama's authentication layer depend on historical hash algorithms, salts or iteration settings. A later product flow can issue password-reset invitations or support a one-time migration login if explicitly designed and audited.

Inactive source users remain inactive.

## Groups and permissions

Coppermine group capabilities map to stable Mediarama permission keys:

- admin access → `system.admin`
- upload pictures → `media.upload`
- rate pictures → `media.rate`
- post comments → `media.comment`
- create albums → `collection.create`

Primary and additional Coppermine group memberships are preserved in `user_groups`.

## Group-policy preflight

Coppermine derives several effective user policies across all group memberships instead of using only the primary group:

- global capabilities use the most permissive group;
- `group_quota` is unlimited when any group has quota `0`; otherwise the largest quota wins;
- public/private upload approval uses the least restrictive group value;
- `access_level` uses the highest level (`0` none, `1` thumbnails, `2` intermediate, `3` full-size).

Mediarama now persists Coppermine group quota policy in normalized `group_storage_quotas`. Source `group_quota` values are converted from KiB to bytes; `0` remains explicit unlimited. Runtime quota resolution uses the normalized `user_groups` memberships and preserves Coppermine's effective rule: any unlimited group wins, otherwise the largest finite group quota wins.

Preflight therefore no longer treats a valid finite quota as data loss. It still evaluates the **effective policy per existing source user** for policy dimensions that are not yet modeled. Migration is blocked when an existing user would lose:

- a required public/private upload approval rule;
- an access level below full-size;
- or a referenced source group is missing.

Negative source quota values remain invalid source state and block migration before writes.

This is intentionally user-effective rather than a raw per-group comparison, so a liberal supplemental group is handled the same way Coppermine handles it and does not create a false blocker.

Coppermine's `can_send_ecards` capability is not mapped. Mediarama has no e-card feature in the target architecture, so that permission is an intentional product omission rather than a reason to recreate the legacy feature.

## Scope

This stage migrates global group capability. Album/category-specific access is handled separately through the Collection ACL migration.
