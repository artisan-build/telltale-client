# Telltale Client

Device-side analytics and error reporting for NativePHP Mobile and Desktop applications.

The auto-discovered provider captures supported platform signals into a local SQLite outbox. Capture
never sends an HTTP request inline. Mobile's database queue worker or Desktop's queue/scheduler drains
the outbox later.

## Correlation Header

Laravel HTTP requests receive `X-Telltale-Session` automatically. Plain Guzzle clients can push the
container-resolved `TraceHeaderMiddleware` onto their handler stack. Its stable v1 value is:

```text
v1;session=<raw-session-uuid>;install=<sha256-install-hash>
```

The raw install id, ingest value, and install bearer token are never included. Registration and ingest
requests are excluded, and opting out removes the header.

## v1 Error Coverage

Laravel-reported exceptions and failed queue jobs are captured.

### Not captured in v1

- Mobile AsyncTask failures are consumed by NativePHP before global Laravel dispatch.
- Exceptions inside SuperNative screens are rendered by NativePHP without reaching Laravel's exception handler.

Telltale's NativePHP asks are a globally dispatched AsyncTask failure event (or supported hook) and a
reporting hook for SuperNative screen exceptions. Telltale does not add framework or plugin workarounds
for either limitation in v1.
