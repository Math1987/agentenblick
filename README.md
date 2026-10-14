# Agentenblick

Server-side AI traffic measurement for self-hosted Shopware. Complements
Shopware Agentic Commerce. Development is proceeding through acceptance gates;
the plugin is not yet ready to install on a merchant shop.

Technical plugin name: `AithosAgentenblick`. License: MIT.

**Current status:** lot 1a is in progress, not accepted. The first signature and
minimisation tests passed (11 tests, 44 assertions), as did the first synthetic
HTTP traffic run. The last sessionisation changes and additional commands still
need validation. Work is blocked by a full macOS system disk and an unresponsive
Docker daemon; see the dated progress report in `handoffs/`.

## Isolated development bench

The Compose project uses a fresh `dockware/shopware:6.7.15.0` container and
exposes HTTP only on `127.0.0.1:18080`. It does not mount the old pilot, its
database, its environment files, or AithosAgentTrust. Do not use production
credentials or data in this bench. Container recreation resets the bench.

```sh
docker compose up -d
# Wait for dockware's "container IS READY" startup message before proceeding.
docker exec -u root agentenblick-shop sh -c 'printf "\nAPP_ENV=prod\nAPP_DEBUG=0\n" >> /var/www/html/.env.local'
docker exec -u root -e MYSQL_PWD=root agentenblick-shop mysql --protocol=TCP -h127.0.0.1 -u root shopware -e "UPDATE sales_channel_domain SET url='http://127.0.0.1:18080' WHERE url NOT LIKE 'default.headless%';"
docker exec -u www-data agentenblick-shop php /var/www/html/bin/console plugin:deactivate DockwareSamplePlugin
docker exec agentenblick-shop cp -R /opt/agentenblick/probe /var/www/html/custom/plugins/AgentenblickTerminateProbe
docker exec -u www-data agentenblick-shop php /var/www/html/bin/console plugin:refresh
docker exec -u www-data agentenblick-shop php /var/www/html/bin/console plugin:install --activate AgentenblickTerminateProbe
docker exec -u www-data agentenblick-shop php /var/www/html/bin/console cache:clear
python3 scripts/measure-terminate.py
```

The temporary probe uses the real Shopware request and termination events. It
compares client response completion with and without a two-second delay in
`kernel.terminate`. Results are written to `.local/terminate-measurement.json`.
The architecture gate passes only when the termination work runs, the web SAPI
is PHP-FPM, and the extra delay does not hold the client. Stop implementation
and report the evidence if this gate fails.

The probe is development-only, and must be deactivated before traffic acceptance
tests. It is never part of the plugin ZIP.

The stock image starts in debug mode. Measured on 2026-10-14, a two-second
termination delay held the client for about two additional seconds in debug;
in production mode the delayed requests completed in a median of 15.7 ms while
termination still took two seconds. This is a flushing test, not a plugin
performance result. The measurements are under `handoffs/evidence/`.

## Current implementation details

The plugin retains the incoming request metadata before Shopware can inject a
context token. Classification, HMAC generation and database writes happen after
the response. Human visits create provisional session rows and aggregate counters,
never detailed request rows. Bodies are not read; paths are stored as route names
or generic templates without query strings. HMAC keys are installation-local,
rotate on the UTC day boundary, and overwrite the previous key. A daily purge
also rotates the key when no traffic arrives.

Session keys prefer a verified signature key ID, then the incoming context token,
then a Shopware session cookie, then HMAC(IP) plus user-agent and Accept-Language.
Inactivity beyond 30 minutes starts another provisional session. Key-based
grouping is marked probabilistic because a provider key may be shared by several
visits. Session counts split at daily key rotation. HTTP-cache hits without a
resolved sales-channel attribute currently need channel reconciliation before 1a
can be accepted.

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

The public catalogue contains official public-IP snapshots; a separate test-only
catalogue adds a fictitious provider and local ranges. Symfony's
`Request::getClientIp()` is the only client-IP resolver. Configure only the
actual trusted proxies and the headers they overwrite. A CDN-majority diagnostic
uses aggregate hourly counts (minimum ten requests), without recording raw IPs.

## Verification and remaining acceptance work

The Web Bot Auth verifier uses a structured-field parser and checks Ed25519 or
RSA-PSS/SHA-512 signatures against JWK thumbprints. Both dictionary and legacy
string forms of Signature-Agent are covered by published IETF vectors. RFC
9421 B.2.6 validates Ed25519; B.2.4 is an ECDSA response and tests canonicalisation
and exclusion of that unsupported product algorithm. RSA-PSS verification is
covered by the Web Bot Auth vectors. Test public-key material is not an operator
credential.

Outstanding: deferred verification policy and implementation, full fail-open
and SQL minimisation checks, cache-hit channel reconciliation, human browser-family
counters, performance measurement, PHPStan level 8, formatting, repeatable full
acceptance, and validation of the latest code. The traffic script currently uses
Node's HTTP client for deterministic identity tests; Playwright/LLM purchase
scenarios belong to the later journey tests.

No benchmark submission exists in this stage. The only implemented outgoing
requests are public catalogue refreshes, catalogued key-directory fetches and
reverse DNS. Offline mode (`AithosAgentenblick.config.offline`) prevents these
network lookups; it still needs a capture-based acceptance check. Signing-header
reverification after a directory outage is not implemented yet.

## Development gates

1a delivers the measured CLI pipeline and identity verification, minimisation,
failure tests and initial overhead measurement. The administration UI (1b)
starts only after explicit acceptance of 1a. The optional benchmark service is
maintained in the separate private `agentenblick-benchmark` repository (lot 2).

Runtime background: [Symfony HttpKernel termination](https://symfony.com/doc/current/components/http_kernel.html#the-kernel-terminate-event).
