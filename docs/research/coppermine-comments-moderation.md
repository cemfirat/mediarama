# Coppermine comments, moderation and anti-spam audit

Status: **verified behavioral audit — additional edge cases still open**
Date: 2026-09-25
Tracking: #11

Primary source: current Coppermine 1.6 `db_input.php`, `reviewcom.php`, `include/akismet.inc.php`, admin configuration and language definitions.

The core comment submission/moderation files inspected are byte-identical between current 1.6 and 1.7 for:

- `db_input.php`
- `reviewcom.php`
- `include/akismet.inc.php`

This indicates that 1.7 did not redesign comment behavior.

## Per-album comment enablement

Album records carry a comments flag.

A comment submission checks the media's album and rejects posting when comments are disabled for that album.

This is a resource-level interaction policy, not only a global permission.

## Guest vs registered identity

Guest comments store:

- display author name with configurable anonymous prefix;
- an anonymous browser/profile identifier (`author_md5_id`);
- `author_id = 0`.

Registered comments store the source user ID and current user name.

Guest author names are checked so they cannot impersonate a registered username through the ordinary comment form.

## Flood protection

Unless explicitly disabled, Coppermine prevents the same authenticated user — or the same anonymous browser identity — from posting consecutive comments on the same media item.

The denial can be logged.

### Mediarama implication

Rate limiting/flood control should be explicit infrastructure/policy rather than relying on "last comment has same identity", but the anti-spam outcome is worth retaining.

## Comment approval modes

The `comment_approval` configuration is not a simple boolean.

The admin UI exposes three modes:

- no approval;
- approval for everyone;
- approval for guests only.

Observed submission behavior:

- guest comments require approval for either non-zero approval mode;
- registered non-admin comments require approval in the "everyone" mode;
- admin comments can bypass normal registered-user approval;
- when an ordinary user edits a comment, it can be returned to unapproved state depending on approval policy;
- guest edits similarly re-enter approval when approval is active.

This re-moderation-on-edit behavior is a useful product requirement.

## Pending-comment display

Configuration includes:

- showing a placeholder to end users for comments awaiting approval;
- limiting the review-comments admin page to pending comments only.

The moderation page can sort/filter around:

- date;
- author;
- comment body;
- media;
- approval;
- IP;
- Akismet/spam flag.

## CAPTCHA

Comments can use CAPTCHA with policy variants such as:

- disabled;
- everyone;
- guests only.

Plugins can participate through comment-CAPTCHA hooks.

## Akismet integration

Coppermine has a built-in Akismet path controlled by an API key and policy.

It can apply to:

- everyone;
- guests only.

When Akismet marks a comment as spam, configuration can choose among three behaviors:

1. retain it but mark it unapproved/spam;
2. drop it and tell the author it was rejected;
3. drop it while presenting a success-like response to the spammer.

A spam counter is maintained in configuration.

### Migration implication

The stored `comments.spam` flag is meaningful historical moderation state and should remain represented.

The source Akismet API key/counter is installation configuration/history and should not be imported as a Mediarama secret.

## Admin email notification

Coppermine can email administrators when non-admin comments are posted.

This notification is independent from whether Mediarama should adopt email as the only moderation signal.

Mediarama should model moderation events first, then route notifications through configurable channels.

## User editing

Coppermine can allow users to edit their own comments.

Authorization distinguishes:

- gallery admin;
- authenticated source author;
- guest/browser author identity.

Mediarama should preserve the outcome through authenticated ownership and, if guest comments are supported, a safer guest-edit mechanism than a historical browser identifier.

## Plugin extension points

Comment-related hooks observed in the broader plugin audit include:

- comment add/update/approve lifecycle;
- comment CAPTCHA validation.

Plugin behavior can therefore affect moderation semantics in a real installation.

Unknown comment-related plugins must be considered during migration preflight.

## Privacy-sensitive source fields

The comments table stores historical raw/header IP information.

The current Mediarama importer deliberately does not copy those network identifiers.

That remains the preferred default unless a concrete retention/legal requirement is established.

## Mediarama product model suggested by this audit

Comment functionality should separate:

- whether a collection/media permits comments;
- who may comment;
- moderation policy;
- comment ownership/editing policy;
- spam classification;
- rate limiting;
- report/abuse workflow;
- notification routing.

Suggested comment states remain conceptually similar to:

- pending review;
- published;
- rejected/spam;
- deleted.

A state transition should be auditable rather than encoded only as a pair of legacy flags.

## Migration requirements

Before comment migration is considered complete, tests should cover:

- approved registered comment;
- pending registered comment;
- guest approved/pending comment;
- spam-marked comment;
- deleted/missing-media comment;
- source user no longer present;
- edited comment under approval policy;
- comments in albums where future Mediarama policy differs;
- plugin-owned comment extensions if installed.

## 1.6 vs 1.7 conclusion for this area

Because the principal comment submission, moderation and Akismet files checked are byte-identical, the useful behavioral reference is shared between both branches.

Current 1.6 remains the primary operational reference because it continued to receive maintenance elsewhere after 1.7 stopped.
