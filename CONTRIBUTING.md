# Contributing

Report reproducible bugs in GitHub Issues. Use synthetic traffic and remove all credentials, IP addresses, cookies, request bodies, query strings and customer data from reports. Report security vulnerabilities privately as described in SECURITY.md.

Use PHP 8.2+, Node 24 and the isolated Shopware bench documented in README.md. Never run acceptance scripts against a merchant shop: some scripts create or remove disposable test fixtures. Integration tests use temporary tables or uniquely named physical tables with cleanup; the original initial acceptance kit resets its own analytics tables.

For an isolated unit-test runtime, run `composer install --working-dir=dev/ci --no-scripts --no-plugins`, then `composer install --working-dir=dev/tools --no-scripts --no-plugins`. Set `SHOPWARE_AUTOLOAD` to the absolute path of `dev/ci/vendor/autoload.php` and run `php dev/ci/vendor/bin/phpunit -c phpunit.xml` and `php .tools/vendor/bin/phpstan analyse -c phpstan.neon`. Run `npm ci --prefix src/Resources/app/administration --ignore-scripts`, then `node --test src/Resources/app/administration/tests/*.test.mjs` for administration behavior and real Vue template compilation.

Keep capture fail-open and data minimized. No raw visitor IP, query string, cookie, token, Referer or request body may be retained. Keep proof levels separate from suspicion. Network probes require explicit actions or existing consent. Use the public-network transport for outbound requests. Preserve benchmark thresholds and frozen-payload retries.

Every behavior change needs focused tests appropriate to its risk. Do not tune heuristic weights against the frozen held-out evaluation; introduce a separate calibration dataset and a new version. Current detection is experimental and disabled by default. Explain changes and validation in the pull request. Contributions are licensed under MIT.
