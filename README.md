# Agentenblick

Server-side AI traffic measurement for self-hosted Shopware. Complements
Shopware Agentic Commerce. Version 0.1.0 is an early demonstration release.

Technical plugin name: `AithosAgentenblick`. License: MIT.

**Current status:** CLI, native administration, identity checks, journeys, Health
diagnostics and the opt-in benchmark are implemented on the isolated Shopware bench.
The benchmark demo is deployed at [shopware.aithos.app](https://shopware.aithos.app).
Experimental undeclared-automation detection is disabled by default: the frozen
evaluation found 11 of 13 scripted profiles, below the 90% target, and no real
human reference set is available. See the [lot 4 report](https://github.com/Math1987/agentenblick/blob/v0.1.0/handoffs/2026-10-14-lot-4-acceptance.md).
The [Health acceptance report](https://github.com/Math1987/agentenblick/blob/v0.1.0/handoffs/2026-10-14-lot-5-acceptance.md) and
[release qualification report](https://github.com/Math1987/agentenblick/blob/v0.1.0/handoffs/2026-10-14-lot-6-acceptance.md) give exact
versions, commands, measurements and remaining limitations. Shopware Store
submission is deferred; no Store acceptance is claimed. Production qualification
is also pending the post-response performance target documented in the release report.

[Deutsch](README.de.md) · [Contributing](CONTRIBUTING.md) · [Security](SECURITY.md)

## Installation and operation

Use the versioned `AithosAgentenblick` ZIP from the GitHub release, with its
published SHA-256 checksum. Upload it through **Extensions > My extensions**, or
extract its `AithosAgentenblick` directory into `custom/plugins/` and run:

```sh
bin/console plugin:refresh
bin/console plugin:install --activate AithosAgentenblick
bin/console cache:clear
bin/console assets:install
bin/console agentenblick:selftest
bin/console agentenblick:report --days=7
```

PHP 8.2+, Sodium and OpenSSL are required. The plugin declares its Composer
dependencies and asks Shopware to resolve them during installation. Run these
commands as the shop's normal deployment user. Keep the Shopware scheduler and
message consumers running: catalogue refresh, deferred verification, session
closure, retention and optional benchmark delivery rely on them. Confirm
`agentenblick:selftest` after installation and after an update.

The qualification targets are Shopware **6.6.10.29** and **6.7.15.0**, PHP-FPM,
with debug disabled. Results do not establish performance on every hosting
configuration. Under mod_php, termination work can still delay the visitor;
verify response flushing on the target host before enabling production traffic.
Trust only the real proxy addresses in Symfony's `TRUSTED_PROXIES`; configure the
forwarded headers your proxy overwrites. Never trust arbitrary visitor-supplied
`X-Forwarded-For` or `CF-Connecting-IP` values.

Benchmark participation and experimental automation detection start disabled.
The offline setting disables outbound identity verification and Health probes
as well as all benchmark requests. Deactivation stops capture and preserves
data. Uninstall offers data retention; read the pending-withdrawal limitation
below before requesting complete deletion.

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
After uninstalling with data retained, withdrawal retries require reinstalling
and reactivating the plugin: its scheduled handler does not run while absent.
This privacy safeguard conflicts with the Store requirement for unconditional
uninstall and must be resolved before Store submission. The current demo does
not claim Store compliance.

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

## Health diagnostics

The **Health** tab reads saved results and observed contradictions. Opening it
does not contact a shop or external service. An editor can explicitly probe a
configured sales-channel domain; the API accepts its database ID, never a
free-form URL. Probes require public HTTPS and enforce private-network blocking,
no redirects, two seconds per resource and twenty seconds for the whole run.
HTTP-only development domains remain visible with an explanation and cannot be
probed through the product.

The snapshot records the served robots policy and HTTP status of `llms.txt`,
`llms-full.txt`, `agents.md`, `AGENTS.md`, `/.well-known/ucp`,
`/.well-known/agent-card.json` and `/store-api/_mcp`. Robots and `.well-known`
probes use the origin root; llms, agents and MCP probes respect the configured
domain's path prefix. MCP uses OPTIONS: an HTTP response is not proof of protocol support.
The optional auto-test calls the selected domain's homepage with GPTBot,
ClaudeBot and ChatGPT-User user-agents. These requests originate from **the
shop server's IP**, not the operators' IPs; results cannot establish how a WAF
treats real operator traffic. One-use request capabilities exclude these probes
from traffic statistics.

Robots evaluation merges applicable groups, supports wildcards and anchors,
normalizes percent-encoding, and resolves equal specificity in favor of Allow.
The parser is bounded to 512,000 bytes, 4,096 rules and a matching work budget.
Unsupported/oversized responses and redirects remain unknown. Redirects are
deliberately not followed, so this diagnostic is not a complete crawler
implementation of RFC 9309. Saved policies expire after 24 hours; configuration
changes invalidate the corresponding snapshot. Google-Extended appears as a
policy token, not an independently identified visitor.

For observed agents, local aggregates identify an allowed request receiving
403/429, a disallowed request still arriving, or UCP/MCP returning an error. Each
finding includes its HTTP status, public matched rule when available, reason
and snapshot time. Paths and query strings used for rule matching remain in
memory. Later activity after a successful llms response is a temporal
association; it does not prove that the agent read or obeyed the file.

```sh
bin/console agentenblick:health
bin/console agentenblick:health --domain=<configured-domain-id> --days=30
bin/console agentenblick:health --probe=<configured-domain-id> --auto-test
```

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

For the final cached-request phase experiment, reserve the bench exclusively
and run `python3 scripts/measure-phases.py`. It alternates four stages of 1,000
measured requests each, separates first requests and warmup, and reports each
population independently: human, declared agent, fictitious IP-verified agent
and signature with a cached public key. Temporary instrumentation runs only in
container copies and restores/hash-checks them in `finally`. This adds synthetic
traffic to the bench. It is not a load test, a network-key-lookup measurement or
a guarantee for every storefront route. The selected pre-response timers exclude
dependency injection, service construction and autoload before the first measured
method; they do not establish the complete 0.5 ms pre-response overhead target.
The experiment exercises four warm sessions, not new-session churn or lock
contention. See the release report for results and the unmet targets.

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

## Experimental detection of undeclared automation

**Disabled by default, and disabled for this demonstration.** The experimental
score is not calibrated on real labelled human sessions. No claim of a human
false-positive rate below 2% is made. A `suspected` result means possible
automation, not proof of AI use or an operator identity. Signed, IP-verified,
reverse-DNS-verified and declared agents keep their separate classifications.

An editor can inspect and save the switch and threshold (30–100, default 60) in
the administration; viewing the page does not enable collection. All scoring
runs after the response, without external lookups. Only bounded counters and
named boolean signals are retained for unknown sessions. Raw Referer, Client
Hints, cookie values and client IPs are not added to storage. The embedded
hosting snapshot covers a limited set of official AWS EC2 eu-west-3 prefixes;
it is a weak hint, not a complete ASN or VPN database. A resolved CDN address
does not contribute this hint.

While the experiment is enabled, new unknown sessions use an unassigned
aggregate bucket: no browser family is stored on individual sessions. When a
session crosses the threshold, exactly one session counter moves out of that
bucket. Earlier human requests stay as counters; no detailed history is
reconstructed. The displayed timeline begins at detection. Partial journeys do
not contribute inferred cart or checkout abandonments.
The local report lists suspected automation separately from identified/declared
agent totals. Benchmark schema 2 keeps it only in the all-session denominator,
never in agent shares, operator distributions, or verified/declared funnels.

## Experimentelle Erkennung nicht deklarierter Automatisierung

**Standardmäßig und in dieser Demo deaktiviert.** Der experimentelle Score ist
nicht mit echten, gekennzeichneten menschlichen Sitzungen kalibriert. Eine
Falsch-Positiv-Rate unter 2 % wird nicht behauptet. `suspected` bedeutet mögliche
Automatisierung, weder einen KI-Nachweis noch die Identität eines Betreibers.
Verifizierte und deklarierte Agenten bleiben getrennt ausgewiesen.

Nur Bearbeitungsberechtigte können die Erkennung und den Schwellenwert
(30–100, Standard 60) ausdrücklich speichern. Das Öffnen der Ansicht aktiviert
nichts. Die Auswertung erfolgt nach der Antwort ohne externe Abfragen. Begrenzte
Zähler und benannte boolesche Signale ersetzen rohe Header und Cookie-Werte.
Der schwache Hosting-Hinweis deckt nur einen begrenzten, eingebetteten AWS-EC2-
Bereich ab, keine vollständige ASN- oder VPN-Datenbank; CDN-Adressen sind davon
ausgenommen. Neue unbekannte Sitzungen werden im Experiment ohne Browserfamilie
aggregiert; keine Browserfamilie wird an einzelnen Sitzungen gespeichert.
Bei Erkennung wird eine Sitzung atomar umgebucht. Frühere
menschliche Anfragen werden nicht rekonstruiert. Die Zeitlinie beginnt erst bei
der Erkennung. Aus Teilverläufen werden keine Abbrüche abgeleitet.
Verdachtsfälle sind lokal getrennt sichtbar und gehen im
Benchmark v2 ausschließlich in die Gesamtzahl der Sitzungen ein.
