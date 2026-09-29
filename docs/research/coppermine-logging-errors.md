# Coppermine logging, debugging and error-handling audit

Status: **verified behavioral audit**
Date: 2026-09-25
Tracking: #11

Primary sources:

- current 1.6 `include/logger.inc.php`
- `include/debugger.inc.php`
- `include/init.inc.php`
- `include/functions.inc.php`
- `viewlog.php`

## Log categories

Coppermine defines separate file logs for:

- security;
- global/application;
- database;
- access;
- error;
- configuration;
- mail.

Log files live under the application `logs/` directory.

Each entry is timestamped and separated with a delimiter.

## Log modes

Core constants define:

- `0` — no logging;
- `1` — normal logging;
- `2` — all logging.

Individual call sites decide what to emit. The low-level writer itself mainly suppresses all writes when logging is disabled.

## File-based log storage

Logs are ordinary files such as:

`logs/security.log.php`

New log files receive a small PHP guard header so direct execution through PHP terminates unless the Coppermine environment is loaded.

The admin log viewer:

- requires gallery-admin mode;
- lists log files;
- displays escaped entries;
- can delete one or all logs.

## Retention

During application initialization, when logging is enabled, Coppermine runs a filesystem "spring cleaning" pass on the logs directory.

A configured retention value may be used; otherwise the default visible in the bootstrap path is two days.

This is request-triggered cleanup, not a background retention worker.

### Mediarama implication

Use explicit retention configuration and scheduled/job-based cleanup rather than relying on arbitrary web traffic.

## Log contents can be sensitive

Observed call sites can include:

- IP addresses;
- usernames;
- email addresses;
- database errors;
- mail delivery failures;
- configuration changes;
- security failures;
- filesystem cleanup paths.

Database error handling can also construct detailed SQL/query diagnostics when debug mode permits.

### Mediarama rule

Structured logging should redact secrets and personal data by default.

Never log:

- passwords;
- session tokens;
- API keys;
- SMTP credentials;
- authorization headers;
- complete private metadata payloads;
- arbitrary raw SQL parameters containing user secrets.

Security/audit retention should be distinct from general debug retention.

## Debugger

Coppermine installs a custom PHP error handler through `include/debugger.inc.php`.

It can collect warnings/notices and later display them in page debug output.

Debug output can include:

- PHP/database/Coppermine version information;
- GD/module information;
- selected configuration;
- installed plugins/actions/filters;
- server restrictions and PHP ini limits;
- memory/performance/query timing;
- database query information;
- notices/warnings.

## Debug-mode risk

This is useful for support but can expose substantial installation internals.

Mediarama should preserve diagnostics without rendering raw internals to ordinary public pages.

Recommended separation:

- public generic error response;
- administrator health/diagnostic view;
- developer-only detailed exception trace;
- structured server logs;
- correlation/request ID shown to the user;
- worker/job failure diagnostics.

## Database failure handling

Coppermine records database errors and, depending on debug mode/admin context, either:

- shows a generic critical error;
- or includes database/query detail.

Mediarama should follow the same broad security principle with cleaner implementation:

- detailed exception stays server-side;
- public response receives a stable error identifier;
- administrator/developer tooling can inspect the detailed event.

## Configuration-change logging

Coppermine can log old/new values when configuration is changed.

This is a useful audit outcome.

Mediarama should distinguish:

- ordinary application log;
- immutable/auditable administrative change event.

Sensitive values must be masked, so an audit record may say "SMTP credential changed" without storing the secret.

## Mail logging

Mail failures/success paths can log addresses and context.

For Mediarama, notification observability should expose:

- notification/job ID;
- provider;
- recipient class or redacted address;
- state;
- failure code/category;
- retry count.

Avoid retaining full message content and recipient personal data in general logs unless operationally required.

## Security logging

Coppermine records events such as:

- failed CAPTCHA;
- denied privileged access;
- failed login/security paths;
- suspicious interaction attempts.

This is worth retaining as a separate security-event stream.

A Mediarama security event should be structured, for example:

- event type;
- actor/user ID when known;
- target;
- timestamp;
- request/correlation ID;
- coarse network information only if policy allows;
- outcome;
- risk/reason code.

## Performance diagnostics

Coppermine tracks page generation time, query time/count and peak values in configuration/debug output.

Mediarama should use actual metrics rather than mutable "peak config values":

- HTTP latency;
- DB query latency/count;
- queue latency;
- processing duration;
- derivative generation duration;
- import throughput;
- storage latency/error rate.

## Request-triggered maintenance lesson

Log cleanup is another example of Coppermine using request traffic for maintenance work.

Mediarama should not reproduce this pattern for:

- log retention;
- expired uploads;
- derivative cleanup;
- import cleanup;
- analytics aggregation.

Use explicit workers/scheduled commands.

## Migration classification

Coppermine log files are **not** gallery content and should not be imported into Mediarama's normal database.

Migration preflight may report their presence/size and advise the operator to archive them separately before cutover if needed.

Do not automatically import:

- security logs;
- mail logs;
- access logs;
- database logs;
- debug logs.

## Product requirements derived from the audit

Before stable 1.0, Mediarama should have:

- structured application logging;
- security events;
- audit trail for privileged administrative changes;
- correlation IDs;
- background-job diagnostics;
- health/readiness checks;
- redaction policy;
- explicit retention;
- no raw exception/query output to public clients.

## Conclusion

Coppermine's logging is operationally mature for its era, but tightly tied to writable local files and page-level debugging.

Mediarama should preserve the **observability outcomes** while replacing the mechanism with structured, privacy-conscious, deployment-friendly diagnostics.
