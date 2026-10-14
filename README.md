# Agentenblick

Server-side AI traffic measurement for self-hosted Shopware. Complements
Shopware Agentic Commerce. Development is proceeding through acceptance gates;
the plugin is not yet ready to install on a merchant shop.

Technical plugin name: `AithosAgentenblick`. License: MIT.

**Current status:** the lot 1a CLI pipeline passes its isolated-bench checks
(61 unit tests and 8 database tests, PHPStan level 8, HTTP/privacy/failure/offline
checks). The [acceptance report](handoffs/2026-10-14-lot-1a-acceptance.md) records
the measured results and remaining limits. Lot 1a was accepted on 2026-10-14.
Development now continues through the remaining lots and production deployment,
with automated acceptance checks at each stage. Lot 1b adds the native
administration, shared CLI/API aggregates, CSV export and versioned IP-range approval.

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

The demo image's short `APP_SECRET` is replaced by a persistent random secret.
The test-only project configuration excludes Shopware's internal DAL fixture
definitions, which otherwise break the administration entity-schema endpoint.
Public assets are installed with `APP_ENV=prod` because Shopware's test filesystem
is in-memory. These adaptations apply only to the disposable bench.

## Administration

`Marketing > AI agents` (`Marketing > KI-Agenten` in German) shows local counts,
7/30-day trends, agent types and proof, sales-channel filters, top route names,
and a CSV of agent aggregates. No human session detail is exposed. API responses
are authenticated and `no-store`; `agentenblick.viewer` reads and exports,
`agentenblick.editor` approves a reviewed IP-range proposal. A concurrent refresh
invalidates an obsolete approval. The proxy diagnostic includes Symfony setup guidance.

Build with `shopware-cli extension build . --no-interaction` (tested CLI 0.18.5).
The standalone esbuild configuration emits legacy assets and Vite manifests.
After copying sources to the bench, run `assets:install` with `APP_ENV=prod`, then
clear the test cache. Run `python3 scripts/acceptance-admin.py` to compare the
authenticated HTTP API and CSV with the CLI report.

The stock image starts in debug mode. Measured on 2026-10-14, a two-second
termination delay held the client for about two additional seconds in debug;
in production mode the delayed requests completed in a median of 15.7 ms while
termination still took two seconds. This is a flushing test, not a plugin
performance result. The measurements are under `handoffs/evidence/`.

## Optional traffic benchmark

Participation is **off by default**. The Benchmark tab and
`bin/console agentenblick:benchmark preview` show the exact JSON bytes that will
be sent. This preview only writes a local frozen draft; it makes no request.
Activation requires explicit consent version `2` in `de-DE` or `en-GB`.

**English consent, version 2:** I agree to send weekly aggregated traffic shares
to Aithos for the Shopware benchmark. The document contains random installation
and channel identifiers, completed ISO week, country, Shopware/plugin versions,
a broad traffic bucket, the Agentic Commerce extension flag, and rounded traffic, journey-stage and evaluated-failure shares.
It contains no domain, IP, URL, query string, user agent, cart, order, customer
identifier or exact traffic count. Shares with fewer than ten sessions in their
denominator are suppressed. Published comparisons require five distinct eligible
installations per metric.
I can preview each document and withdraw at any time. Withdrawal stops new
submissions immediately and requests deletion of this installation's submissions.

**Deutsche Einwilligung, Version 2:** Ich stimme zu, wöchentliche aggregierte
Verkehrsanteile für den Shopware-Benchmark an Aithos zu senden. Das Dokument
enthält zufällige Installations- und Kanal-IDs, eine abgeschlossene Kalenderwoche,
Land, Shopware-/Plugin-Version, eine grobe Größenklasse, den Status der
Agentic-Commerce-Erweiterung und gerundete Verkehrs-, Besuchsschritt- und ausgewertete Fehleranteile. Domains, IP-Adressen, URLs,
Query-Strings, User-Agents, Warenkörbe, Bestellungen, Kundendaten und genaue
Besuchszahlen werden nicht gesendet. Anteile mit weniger als zehn Sitzungen im
Nenner werden unterdrückt. Vergleiche erfordern mindestens fünf unterschiedliche
berechtigte Installationen je Kennzahl.
Ich kann jedes Dokument vorab ansehen und jederzeit widerrufen. Der Widerruf
stoppt neue Übermittlungen sofort und veranlasst die Löschung der Beiträge.

```sh
bin/console agentenblick:benchmark status
bin/console agentenblick:benchmark preview
bin/console agentenblick:benchmark enable --accept-consent=2 --locale=en-GB
bin/console agentenblick:benchmark send
bin/console agentenblick:benchmark history
bin/console agentenblick:benchmark disable
```

Activation registers a preallocated UUIDv4 and random client secret over TLS;
retries preserve both. HMAC-SHA256 signs the exact persisted bytes with the
UTF-8 secret. The secret is authenticated-encrypted using `kernel.secret`, never
returned by an administration endpoint. Keep the kernel secret available until
withdrawal succeeds. An offline or failed withdrawal retains only the local
credentials needed for retry and leaves participation disabled. The daily task
retries withdrawals even while disabled. Destructive uninstall is blocked while
withdrawal is pending; keeping plugin data preserves the retry credentials.

The daily task sends at most twenty pending documents from the last thirteen weeks.
One document per channel and completed week is frozen before transport; exact
bytes, hash and minimized result code remain in the local journal for thirteen
months. Older unsent documents are marked `expired`; permanent schema/cohort
rejections are marked `rejected` and cannot block the next channel. The service
and plugin both limit a week to 32 channels before any document is prepared.
The installation has one cohort per week: country of its oldest active
storefront channel, overall weekly traffic bucket, minor Shopware version and
active `SwagAgenticCommerce` flag. Channel aliases are random UUIDv4, unrelated
to Shopware IDs. Agent distributions use their own ten-session threshold;
rounded values sum to 100.0. No raw counts leave the shop. Schema version 2 adds independent stage and failure
shares for verified and declared agents. Stages use closed sessions; failures use
only sessions in which that condition was evaluated. Unknown cache states do not
become successes. Each denominator has its own k10 threshold. Weekly drafts wait
until at least 45 minutes after the week ends and all relevant sessions close.
Frozen version-1 documents remain unchanged and retryable. Participation enabled
under consent version 1 pauses until the merchant reviews and accepts version 2.

Offline mode (`AithosAgentenblick.config.offline=true`) blocks every benchmark
request, including registration, comparison and withdrawal. Only the explicit
demo setting `AithosAgentenblick.config.benchmarkDemo=true`, or the explicit
`APP_ENV=test` environment, marks new installations as demonstration data.
Set this before the first preview/registration; test catalogues do not enable it.
An existing real identity is blocked in the test environment, including deletion,
so a cloned database cannot mutate real contributions. Demo data never enter real comparisons;
their dashboard requires Cognito sign-in. The fixed destination is
`https://shopware.aithos.app`, redirects are disabled, each request is bounded to
six seconds and each response to 64 KiB. No capture subscriber calls this client.

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

Identity verification uses public catalogue refreshes, catalogued key-directory
fetches and reverse DNS. Benchmark requests additionally require the explicit
participation described above. Offline mode (`AithosAgentenblick.config.offline`)
prevents all these requests and uses available local data. Directory access is restricted to
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
follows the accepted CLI milestone. The optional benchmark service is
maintained in the separate private `agentenblick-benchmark` repository (lot 2).

Runtime background: [Symfony HttpKernel termination](https://symfony.com/doc/current/components/http_kernel.html#the-kernel-terminate-event).
