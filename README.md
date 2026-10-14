# Agentenblick

Server-side AI traffic measurement for self-hosted Shopware. Complements
Shopware Agentic Commerce. Development is proceeding through acceptance gates;
the plugin is not yet ready to install on a merchant shop.

Technical plugin name: `AithosAgentenblick`. License: MIT.

**Current status:** the lot 1a CLI pipeline passes its isolated-bench checks
(61 unit tests and 8 database tests, PHPStan level 8, HTTP/privacy/failure/offline
checks). The [acceptance report](handoffs/2026-10-14-lot-1a-acceptance.md) records
the measured results and remaining limits. Lot 1a awaits explicit user acceptance;
the administration UI (1b) has not started.

## Isolated development bench

The Compose project uses a fresh `dockware/shopware:6.7.15.0` container and
exposes HTTP only on `127.0.0.1:18080`. It does not mount the old pilot, its
database, its environment files, or AithosAgentTrust. Do not use production
credentials or data in this bench. Container recreation resets the bench.

```sh
python3 scripts/setup-bench.py
```

The setup script starts only the isolated Compose service, copies the plugin,
installs its runtime and isolated development dependencies, and runs the
termination gate in production mode with debug disabled. It then configures
the test environment, applies migrations, registers scheduled tasks and starts
the local trusted proxy. Setup stops if the termination gate fails.

The temporary probe uses the real Shopware request and termination events. It
compares client response completion with and without a two-second delay in
`kernel.terminate`. Results are written to `.local/terminate-measurement.json`.
The architecture gate passes only when the termination work runs, the web SAPI
is PHP-FPM, and the extra delay does not hold the client. Stop implementation
and report the evidence if this gate fails.

Setup deactivates the development-only probe after measurement. It is never
part of the plugin ZIP.

The stock image starts in debug mode. Measured on 2026-10-14, a two-second
termination delay held the client for about two additional seconds in debug;
in production mode the delayed requests completed in a median of 15.7 ms while
termination still took two seconds. This is a flushing test, not a plugin
performance result. The measurements are under `handoffs/evidence/`.

## Current implementation details

The plugin retains the incoming request metadata before Shopware can inject a
context token. Classification, HMAC generation and database writes happen after
the response. Human visits create provisional session rows and aggregate counters,
never detailed request rows. Human browser families use a closed vocabulary in
daily counters; full human user-agents are not persisted. Bodies are not read;
paths are stored as route names or generic templates without query strings.
HMAC keys are installation-local, rotate on the UTC day boundary, and overwrite
the previous key. A daily purge
also rotates the key when no traffic arrives.

Session keys prefer a verified signature key ID, then the incoming context token,
then a Shopware session cookie, then HMAC(IP) plus user-agent and Accept-Language.
Inactivity beyond 30 minutes starts another provisional session. Key-based
grouping is marked probabilistic because a provider key may be shared by several
visits. Session counts split at daily key rotation. Each request retains its own
proof; a session uses its strongest synchronous proof, in order: signature, IP,
reverse DNS, declared identity, none. A stronger proof moves the existing session
counter instead of adding another session. Late termination cannot move the
last-seen timestamp or the HMAC key backward.

Known-agent user-agents without proof are `declared_unverified`, never described
as proven impersonators. Reports count observed crawler/agent sessions; they do
not claim to identify all AI use. Browser agents that look like ordinary browsers
are outside lot 1a's detection scope.

Shopware's internal HTTP cache deliberately skips the inner `kernel.terminate`
event on fresh hits. `ObservedKernel` decorates the outside of that cache and
flushes only Agentenblick's pending observation in its post-response `terminate`
method. Normal inner termination clears the pending state first, preventing
double counting. The original response object is returned unchanged. Traffic
served entirely by an upstream CDN or web server remains invisible.

For cache hits without a channel attribute, the plugin resolves the channel
after the response from configured sales-channel domains, using the longest
matching base path. Ambiguous or unmatched configurations remain `unassigned`.
If Shopware does not supply a route, the stored path remains a generic template;
the plugin does not reconstruct detailed page routes from cached responses.

The public catalogue contains official public-IP snapshots; a separate test-only
catalogue adds a fictitious provider and local ranges. Symfony's
`Request::getClientIp()` is the only client-IP resolver. Configure only the
actual trusted proxies and the headers they overwrite. A CDN-majority diagnostic
uses aggregate hourly counts (minimum ten requests), without recording raw IPs.

## Verification and privacy

The Web Bot Auth verifier uses a structured-field parser and checks Ed25519 or
RSA-PSS/SHA-512 signatures against JWK thumbprints. Both dictionary and legacy
string forms of Signature-Agent are covered by published IETF vectors. RFC
9421 B.2.6 validates Ed25519; B.2.4 is an ECDSA response and tests canonicalisation
and exclusion of that unsupported product algorithm. RSA-PSS verification is
covered by the Web Bot Auth vectors. Test public-key material is not an operator
credential.

When `Signature-Agent` is absent, a signature may verify using a key already
known from the embedded catalogue or a cache entry no older than 24 hours.
This lookup never fetches remote directories; unknown or ambiguous keys remain
unverified. When present, `Signature-Agent` must be signed.

Signature time validity is evaluated at receipt. On directory failure, the
request remains unverified and can be retried only if its exact signature base
can be reconstructed from an allowlisted, minimised context. Signed URLs with
query strings, unlisted paths, arbitrary signed headers and opaque signature
parameters are not retained for retry. Such requests stay unverified with reason
`directory_unreachable_privacy`. Even verified requests retain only safe,
normalised signature metadata, never unrelated header members.

`agentenblick:reverify` and the five-minute scheduled task retry eligible rows
for at most 24 hours. A successful retry corrects the existing request and its
aggregate once. Session promotion is conservative: after 30 minutes of
inactivity, every recorded request must verify under the same key and identity.
Mixed, incomplete or still-active sessions keep their previous proof. Deferred
verification never changes session keys or merges previously counted sessions.

No benchmark submission exists in this stage. The only implemented outgoing
requests are public catalogue refreshes, catalogued key-directory fetches and
reverse DNS. Offline mode (`AithosAgentenblick.config.offline`) prevents these
network lookups and uses available local data. Directory access is restricted to
catalogued origins, HTTPS, a two-second deadline and 64 KiB, with no redirects or
private network targets outside the test environment. Cache entries are fresh
for one hour and may be reused for up to 24 hours during an outage.

## Run the lot 1a checks

These commands target the migrated disposable bench with the plugin installed.
For identity acceptance, use `APP_ENV=test`, `APP_DEBUG=0`, the
`tests/fixtures/catalog.json` test catalogue, and the local `test-proxy.mjs`
proxy. Only `127.0.0.1` is trusted for `X-Forwarded-For` on this bench; the proxy
overwrites forwarded headers. Loading the fixture catalogue outside `test` is
refused. The probe plugin must be deactivated. Do not point these tests at a
merchant database.

After setup, run lint, PHPUnit, PHPStan level 8, formatting and script syntax
checks, followed by the database integration suite:

```sh
docker exec -u www-data agentenblick-shop bash -lc \
  'cd /var/www/html/custom/plugins/AithosAgentenblick && bash scripts/check.sh'
docker exec -u www-data -e AGENTENBLICK_DB_TEST=1 agentenblick-shop \
  php /var/www/html/vendor/bin/phpunit \
  --configuration /var/www/html/custom/plugins/AithosAgentenblick/phpunit.xml \
  /var/www/html/custom/plugins/AithosAgentenblick/tests/Integration
```

The database tests cover persistence, purge, concurrent writes and deferred
counter corrections. Temporary tables or isolated synthetic channels keep their
fixtures separate from the kit's observations.

Run the host-side measurements from this repository:

```sh
python3 scripts/measure-overhead.py
python3 scripts/acceptance.py
docker exec -u www-data agentenblick-shop php /var/www/html/bin/console agentenblick:report --days=1 --json
docker exec -u www-data agentenblick-shop php /var/www/html/bin/console agentenblick:selftest
```

The overhead script alternates off/on/off/on, clears the cache each time, warms
10 requests, then measures 50 sequential requests per stage. It reports client
median/p95, response fingerprints and deltas in `.local/overhead.json`, and
attempts to restore the active plugin even on failure. This measures initial
local client overhead, not capacity or all post-response processing cost.

**`acceptance.py` resets Agentenblick observations on the disposable bench.** It
checks identities, trusted and forged proxy headers, concurrent traffic, safe
deferred verification, stored-data minimisation, response stability during
plugin failures, and the CDN warning. Evidence, including the initial kit's
report, is written to `.local/acceptance.json`. Running it after the overhead
measurement keeps that measurement's traffic out of the initial kit report.
The final live CLI report also includes the acceptance script's additional
failure and concurrency traffic. Deterministic identity traffic uses Node's
HTTP client; Playwright/LLM purchase scenarios belong to later journey tests.

After the acceptance traffic has exercised the local key directory, verify
offline mode with a packet capture and a positive network control:

```sh
docker exec -u root agentenblick-shop apt-get update
docker exec -u root agentenblick-shop apt-get install -y tcpdump
python3 scripts/verify-offline.py
docker exec -u root agentenblick-shop apt-get install -y python3-jsonschema
docker exec -u www-data agentenblick-shop python3 /var/www/html/custom/plugins/AithosAgentenblick/scripts/validate-schemas.py
```

The offline script exercises HTTP identities, reverse DNS, range refresh and
deferred verification, restores online mode afterwards, and writes
`.local/offline.json`. The capture checks DNS and non-loopback HTTP(S), plus a
separate counter for the local test directory. It adds synthetic observations;
the initial identity counts remain available in `.local/acceptance.json`.

## Development gates

1a delivers the measured CLI pipeline and identity verification, minimisation,
failure tests and initial overhead measurement. The administration UI (1b)
starts only after explicit acceptance of 1a. The optional benchmark service is
maintained in the separate private `agentenblick-benchmark` repository (lot 2).

Runtime background: [Symfony HttpKernel termination](https://symfony.com/doc/current/components/http_kernel.html#the-kernel-terminate-event).
