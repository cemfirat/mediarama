# Coppermine e-card, contact and report-to-admin audit

Status: **verified behavioral audit**
Date: 2026-09-25
Tracking: #11

Primary sources:

- current 1.6 `ecard.php`
- `db_ecard.php`
- `contact.php`
- `report_file.php`
- `displayreport.php`
- `sql/schema.sql`
- admin configuration

## E-cards

Coppermine has a real e-card workflow rather than a simple "share link" button.

Eligibility is controlled through `USER_CAN_SEND_ECARDS`.

The form supports:

- sender name/email;
- recipient name/email;
- message;
- preview;
- optional CAPTCHA;
- selected media context;
- HTML + plaintext mail.

After successful sending, Coppermine can remember the guest sender name/email in browser profile state.

### E-card logging

When `log_ecards = 1`, Coppermine persists:

- sender name;
- sender email;
- recipient name;
- recipient email;
- encoded link/payload;
- date;
- sender IP.

The admin `db_ecard.php` surface can:

- list the records;
- sort by sender/recipient/date/IP;
- expose mail links;
- perform IP information lookup via plugin hook;
- delete selected logs.

This is privacy-sensitive communication history, not gallery content.

## E-card payload design

The e-card display link contains encoded data representing message/sender/media context.

This is a legacy stateless-sharing mechanism.

Mediarama should not copy opaque user-message payloads into public URLs as its primary sharing architecture.

If a future "send/share" feature exists, prefer:

- explicit share object/token;
- expiry/revocation;
- authorization at creation and access;
- minimal recipient data retention;
- configurable notification provider;
- abuse/rate limits.

## E-card migration classification

Default classification: **intentional omission of operational e-card history** unless the operator explicitly needs an archival export.

Reasons:

- sender/recipient personal data;
- sender IP;
- historical outbound-message payload;
- no direct role in gallery structure/content;
- old links/payloads are coupled to Coppermine runtime.

Migration preflight should still report:

- whether e-card logging was enabled;
- number of logged e-cards;
- whether an explicit archive/export is required before migration.

Do not silently bulk-copy the table into Mediarama application data.

## Contact form

Coppermine has a configurable contact-to-gallery-admin form.

Policy can differ for:

- guests;
- registered users.

Configuration controls:

- whether guests may contact;
- guest name field presence/requirement;
- guest email field presence/requirement;
- whether registered users may contact;
- subject field/content;
- whether the visitor address may be used as sender.

CAPTCHA can be required depending on guest/registered policy and can be supplied by plugins.

The generated admin mail includes request context including IP information.

### Mediarama implication

A contact form is site/communications functionality, not a core media-library domain requirement.

Mediarama can support it later through a generic notification/form extension, but migration does not need to reproduce historic contact submissions because Coppermine does not persist them as a core contact-message table.

Mail logs may contain traces, but those are operational logs rather than first-class content.

## Report-to-admin

Coppermine has a report workflow for:

- a media/file;
- an approved comment.

Reporting is controlled by `report_post` and, in the legacy implementation, also depends on the e-card-send capability.

The report form includes:

- reporter identity/email;
- target media;
- optional comment context;
- predefined reasons;
- free-text message.

Confirmed reason categories include concepts such as:

- obscene;
- offensive;
- misplaced;
- missing;
- issue;
- other.

The report is primarily sent to the administrator by email.

Coppermine also encodes report data into a display-report payload/link, and the report flow reuses e-card-style infrastructure/log storage.

## Mediarama report model

The **reporting capability is worth retaining**, but not the implementation.

Mediarama should model a persistent moderation report:

- report ID;
- target type + target ID;
- reporter user or guest identity;
- reason code(s);
- optional message;
- state;
- assigned/resolving moderator;
- created/resolved timestamps;
- resolution note;
- notification status.

Possible targets should be designed generically:

- media;
- comment;
- collection;
- user/account.

Notifications then become side effects of the report lifecycle rather than the report itself being an email.

## Privacy and retention

The Coppermine legacy flows collect or expose:

- sender/reporter email;
- recipient email for e-cards;
- sender IP;
- encoded message payload;
- mail logs.

Mediarama should define a retention purpose for any comparable data.

Recommended default:

- do not migrate historic e-card logs;
- do not migrate historic IP data;
- do migrate moderation-worthy report state only if a reliable source record exists — Coppermine core does not provide a clean normalized report table;
- provide a source export/archive option for operators who legally/operationally need historical logs.

## Plugin impact

Captcha/report/mail-related plugin hooks can alter real installation behavior.

Migration preflight should inventory installed plugins before assuming that:

- all reports were email-only;
- all e-card behavior was core;
- all contact validation was default.

## Product decision summary

### Preserve as Mediarama product capability

- report/abuse workflow;
- notification routing;
- optional contact capability as extension/site feature.

### Do not carry forward by default

- legacy e-card sending as a core 1.0 requirement;
- old e-card history;
- old report payload URLs;
- sender IP history;
- legacy CAPTCHA/mail implementation.

## Migration preflight checks

Report:

- `log_ecards` state;
- e-card log row count;
- `report_post` state;
- contact guest/registered enablement;
- plugins touching captcha/mail/report hooks.

If e-card history exists, migration output should explicitly say that it is not being imported into Mediarama unless an archival path was chosen.

## Conclusion

Coppermine's mature interaction surface includes more than comments/ratings.

The correct Mediarama response is selective:

> preserve modern moderation/reporting outcomes, while deliberately retiring privacy-heavy e-card/history mechanisms that do not belong in the new core.
