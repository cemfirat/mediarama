# Coppermine ratings and popularity audit

Status: **verified behavioral audit**
Date: 2026-09-25
Tracking: #8, #11

Primary source: current Coppermine 1.6 `ratepic.php`, schema/config and meta-album queries.

## Rating enablement

Rating is controlled at more than one level.

A vote requires:

- user/group capability `can_rate_pictures`;
- the media's album `votes = YES`;
- a valid form token;
- no duplicate vote under the active duplicate-prevention rules.

This is another example of global capability + resource policy.

## Rating scale

Coppermine can use:

- the classic 5-star system;
- a configurable star count from 1 to 20.

Internally the aggregate `pictures.pic_rating` is normalized to the 0–10000 five-star-equivalent scale.

Each new vote is converted by:

`rate × (5 / configured_stars) × 2000`

and folded into the existing average.

### Migration consequence

Mediarama's 1–5 rating model can normalize historical values, but source scale must be known for detailed vote rows.

The existing importer already normalizes detailed vote values using source configuration and preserves aggregate history separately when individual votes cannot be reconstructed.

## Duplicate-vote prevention

Coppermine stores a short-lived/retained vote identity in the `votes` table:

- picture ID;
- voter identifier;
- vote timestamp.

For logged-in users the voter key is derived from the user ID.

For anonymous users it uses the anonymous browser/profile identifier.

`keep_votes_time` controls how long those anti-repeat rows remain; current default is 30 days. Old rows are deleted before checking a new vote.

### Anonymous IP behavior

When detailed vote statistics are enabled, anonymous voting also checks the detailed vote table for the same picture + IP.

This means duplicate-prevention semantics can differ depending on whether detailed vote logging is enabled.

### Mediarama implication

Do not reproduce this historical mechanism literally.

A modern rating policy should explicitly define:

- whether one user can rate once forever or update their rating;
- whether guest ratings exist;
- rate limiting;
- whether changing a rating changes the same row;
- privacy-safe anti-abuse handling.

## Rating own media

Configuration supports three policies for rating one's own files:

- no;
- yes for everyone;
- yes only for administrators.

Mediarama should preserve this as an explicit product policy if ratings remain first-class.

## Detailed vote statistics

When `vote_details` is enabled, Coppermine stores:

- picture ID;
- raw rating value;
- IP;
- timestamp;
- referrer;
- browser;
- OS;
- user ID.

The current Mediarama migration intentionally preserves recoverable rating value/user identity but does not copy network/client telemetry.

That remains the safer default.

## Aggregate data

The picture row always carries:

- vote count;
- aggregate rating.

The basic `votes` table does **not** store each historical vote's numeric value.

Therefore, when detailed vote statistics are absent or incomplete, individual ratings cannot be reconstructed honestly.

The existing migration strategy is correct:

- preserve aggregate count/average as historical data;
- import individual ratings only where detailed source rows provide a value and resolvable user;
- report the non-recoverable vote count.

## Top-rated meta album

Coppermine's top-rated result set includes only media whose vote count meets `min_votes_for_rating`.

Ordering is by:

1. aggregate rating;
2. vote count;
3. picture ID.

This threshold is a useful product semantic that is easy to miss when simply sorting by average.

### Mediarama direction

If Mediarama has a "top rated" discovery view, it should avoid tiny-sample distortion.

Possible policies:

- configurable minimum vote count;
- Bayesian/Wilson ranking for larger communities;
- plain average + count display for simpler installations.

The exact ranking should be a Mediarama decision, not a source-compatible formula requirement.

## Administrative reset behavior

Coppermine album administration can reset ratings/votes for all files in an album.

This belongs in the broader maintenance/moderation outcome inventory.

Mediarama should treat rating reset as an explicit privileged operation with audit logging.

## Migration tests still required

- 5-star source;
- non-5-star source;
- old-style rating enabled;
- aggregate-only votes;
- detailed logged-in votes;
- anonymous detailed votes;
- repeated votes after retention window;
- source users missing at migration;
- album ratings disabled;
- own-file policy variants.

## Product classification

Rating itself remains a valid Mediarama capability.

The historical network/browser statistics around ratings are not required for product parity and should remain privacy-reduced unless a specific retention requirement is established.
