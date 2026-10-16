# Native demo performance protocol

This kit is for the **isolated** Ubuntu 24 / PHP 8.3 FPM / MySQL 8 demo host.
It does not SSH, invoke Docker, call AWS, change Nginx, alter durability settings,
reset a database, install dependencies, change consent, or modify a catalogue.
Nothing runs on import. It is excluded from the distributed plugin ZIP.

The allowed app is a resolved directory below `/srv/agentenblick-demo`, normally
`/srv/agentenblick-demo/current` pointing at `releases/<release>`. The front
controller must remain in that exact release. Plugin files must resolve to the
exact shared directory `/srv/agentenblick-demo/shared/custom/plugins/AithosAgentenblick`;
other symlink targets are refused. The manifest retains both the selected app
link and exact physical files. A deployment/link change stops the run and
requires manual reconciliation before restoration; reserve the deployment window.
All HTTP calls use
`https://demo-shop.aithos.app` with curl resolving the connection to **127.0.0.1**;
TLS certificate validation remains enabled, and redirects are not followed.
Instrumentation accepts only the loopback peer and an ephemeral capability.
No remote client may trigger the artificial two-second delay. No URL, IP, token,
cookie, body or visitor identifier is written to the measurement records.

## Before preparing a run

Reserve the entire host, including its PHP-FPM service, for this test window.
No builds, Composer installs, deployments, backup jobs or other browser traffic
may run during measurement. Record CPU credit state and storage behavior using
the operator's existing monitoring; this kit does not contact AWS. The resource
guard stops below 3 GiB free disk or 256 MiB available RAM, but those floors alone
do not establish an uncontended host. Use ordinary MySQL durability settings.

The operator must explicitly configure `APP_ENV=prod`, `APP_DEBUG=0`, leave the
production catalogue unchanged, set `AithosAgentenblick.config.offline=true`,
and keep benchmark participation and experimental heuristics off. No pending
withdrawal is allowed. The kit verifies these conditions and does not set them.
It requires `php`, `curl`, Python 3.12, `runuser`, and the existing Shopware vendor
tree. The CLI must be PHP 8.3 and have the application's database extensions.
Use the exact database name in the commands below; the bridge permits only a
local MySQL endpoint and never prints the DSN or framework exception messages.
The kit directory and its parents must be traversable by `www-data`, and
`bridge.php` readable by that user; preflight reports inaccessible extraction
permissions before attempting PHP or database access. The kit explicitly applies
its state/run permissions even when SSM uses `umask 077`; backups and manifests
remain private.

Run from the checked-out kit; the root-only commands are explicit because they
temporarily replace app files and reload **php8.3-fpm** on this dedicated host.
The preflight itself only reads files/configuration and performs SELECT queries:

```sh
sudo python3 scripts/perf-native/measure.py preflight --database=EXACT_DEMO_DB
```

## Separate preparation and experiments

Preparation saves the source bytes, ownership, permissions and SHA-256 hashes,
generates instrumented candidates, and PHP-lints every candidate. It does **not**
install them or reload workers. It prints a private manifest path under
`/var/lib/agentenblick-perf-native/`, creating this root-owned state directory if
it does not exist. Review that preparation before running it:

```sh
sudo python3 scripts/perf-native/measure.py prepare --database=EXACT_DEMO_DB \
  --phase=terminate --ack-isolated-bench
sudo python3 scripts/perf-native/measure.py run \
  --manifest=/var/lib/agentenblick-perf-native/RUN/manifest.json --ack-isolated-bench
```

1. **`terminate`** makes five alternating control/delayed pairs at a random
   temporary route intercepted before Shopware. `fastcgi_finish_request()` sends
   a small JSON response, then the worker sleeps for two seconds. A pass requires
   every delayed client response below one second, with at least 1.9 seconds of
   confirmed worker delay. This proves **FPM/Nginx response flushing only**; it is
   not a direct proof of a Shopware `kernel.terminate` subscriber.
2. **`kernel-terminate`** is the actual Shopware acceptance gate. Prepare a fresh
   manifest with `--phase=kernel-terminate`, then run it explicitly. Four ordinary
   requests warm the homepage before five control/delayed pairs. The temporary
   instrumentation injects a two-second wait **inside the real plugin
   `ObservationSubscriber::onTerminate` method**, for loopback requests with the
   exact capability and delay header only. Every measured storefront response
   must remain 200, byte-identical and below one second, while the delayed worker
   timer must confirm at least 1.9 seconds. Unmarked/public requests never take
   this delay branch. The report records `kernelTerminateReleasedClient` separately
   from the neutral FPM proof. Stop before performance tests if this gate fails.
3. **`diagnostic`**: prepare a fresh manifest with `--phase=diagnostic`, then run
   it explicitly. Four first requests and twenty warmups precede **40 measured**
   requests, half human/unknown UA and half GPTBot declared UA. It instruments
   the real capture/Journey callbacks and termination plus `DailyKey.current`,
   `Classifier.classify`, `ObservationStore.record`, `Health.Observation`,
   `ProxyDiagnostics.record` and `CatalogueProvider.load`. Inspect the actual
   bottleneck before attempting a larger run; do not assume moving hosts fixes it.
4. **`abab`**: only when that diagnostic justifies it, prepare a new manifest with
   `--phase=abab`. This explicitly deactivates/activates the plugin in the order
   OFF / ON / OFF / ON, clears the app cache and reloads FPM for each stage.
   Each stage has 4 first requests, 20 warmups and 1,000 measured requests.
   Every next request waits for the preceding shutdown record outside client
   timing, so termination work does not overlap the next request.

The runner verifies 200 responses, closed numeric timers, the expected evidence
counter changes and identical measured body hashes. Other traffic or differing
responses invalidate the comparison. The two initial populations cover no
verified signatures/IPs, rDNS or cold key-directory requests. Four-population
qualification requires a **separate non-public test fixture**, not local ranges
assigned to real operators or changes to this production catalogue.

## Restoration and evidence

Every run restores the exact targeted source bytes/ownership/permissions and
the original plugin activation, then reloads FPM. Hashes are checked. No catalogue,
key cache or preexisting observations are changed by administrative SQL. The
ordinary synthetic traffic observations **remain** in the disposable database;
this is explicitly not an automatic cleanup/reset. Normal plugin housekeeping
may run during activation/cache operations; use only the dedicated demo database.

The manifest, backups and short-lived capability remain private on the server.
Only the sanitized `report.json` is suitable for copying into acceptance evidence.
If the runner is killed, use the saved manifest for explicit recovery:

```sh
sudo python3 scripts/perf-native/measure.py restore \
  --manifest=/var/lib/agentenblick-perf-native/RUN/manifest.json --ack-isolated-bench
```

Restoration refuses to overwrite a file changed by another deployment. Resolve
that case manually from the saved hashes/backups. Do not remove the private run
directory until source restoration and worker reload have been confirmed.

## What the numbers do and do not establish

`pre` is the union of selected plugin callback durations. **DI, construction,
autoload and outer event dispatch remain outside it**. A result below 0.5 ms
cannot qualify the full pre-response requirement. Matched OFF/ON client timings
include these effects but also full Shopware execution, local TLS and scheduling
variance; a difference between p95 values is not a per-request overhead measure.

`post` is the union of instrumented `onTerminate`/`flush` work. Its measured p95
can be compared with 5 ms for these warmed, sequential, two-population conditions
only. Inclusive child timers overlap and must not be summed. Instrumentation
overhead is included, never subtracted. A small diagnostic
or even a completed ABAB run is not a capacity or production qualification.
Every report keeps `qualifiedProductionBudgets=false`; a later qualifying protocol
must cover the missing pre-response cost, verified profiles, new sessions and
the intended deployment/load conditions. No capture or persistence optimization
is applied by this kit.

Local harness tests require only Python:

```sh
python3 -m unittest discover -s scripts/perf-native -p 'test_*.py'
```
