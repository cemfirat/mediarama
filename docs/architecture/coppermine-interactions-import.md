# Coppermine Comments and Ratings Import

## Comments

Coppermine comments are imported with:

- media association
- mapped user when available
- guest author name when no mapped user exists
- original message body
- original timestamp when valid
- moderation state

Spam comments are preserved as `rejected`. Unapproved comments become `pending_review`.

Source IP addresses and browser-identification fields are deliberately **not** migrated. They are not required to preserve the comment and importing them would unnecessarily carry historical personal/network data into the new installation.

The cross-cutting rationale for this and the other deliberate data reductions is centralized in `docs/research/coppermine-intentional-omissions.md`.

## Ratings

Coppermine stores the current aggregate on `pictures.pic_rating` and `pictures.votes`.

The aggregate is normalized to Coppermine's 0–5 scale (`pic_rating / 2000`) and preserved in `import_rating_aggregates` for migration/audit purposes.

Coppermine's basic `votes` table does **not** contain the value of each individual vote. Therefore Mediarama does not invent individual ratings from aggregate data.

When Coppermine detailed vote statistics are available, rows with a real source user ID and a rating value are mapped to Mediarama users and imported into `ratings`. Values are normalized from the configured Coppermine star count to Mediarama's 1–5 scale.

The importer reports the number of aggregate votes for which no recoverable individual rating value exists.

This preserves as much historical information as the source actually contains without manufacturing identities or ratings.
