# Agentenblick

Agentenblick misst serverseitig automatisierte Zugriffe auf selbst gehostete Shopware-Shops. Es ergänzt Agentic Commerce. Technischer Pluginname: **AithosAgentenblick**, Lizenz: MIT. Version 0.1.2 ist eine frühe Demoversion; eine Freigabe durch den Shopware Store liegt nicht vor. Die Produktionsqualifikation steht wegen des dokumentierten Leistungsziels nach der Antwort noch aus.

## Funktionen und Grenzen

Die Administration unter **Marketing > KI-Agenten** zeigt Übersicht, Agenten, CSV-Export, beobachtete Besuchsschritte und ausgewertete Fehler. Ein Sitzungsschlüssel ist eine vorläufige Zuordnung, kein Nachweis einer einzelnen Person. Unbeobachtete Schritte werden nicht ergänzt; eine Bestellung beweist keine erfolgreiche Zahlung.

Signaturen, veröffentlichte IP-Bereiche, bestätigtes Reverse-DNS und nicht verifizierte Selbstauskünfte werden getrennt angezeigt. Eine erklärte Identität ohne Nachweis wird nicht automatisch als Fälschung bezeichnet. Ein Browseragent mit gewöhnlichen Browsermerkmalen kann unerkannt bleiben. Die experimentelle Erkennung ist standardmäßig und in der Demo **deaktiviert**. Der eingefrorene Test erkannte 11 von 13 Skriptprofilen; das Ziel von 90 % und eine menschliche Falsch-Positiv-Rate unter 2 % sind nicht bestätigt.

CDN-Zugriffe, die Shopware nicht erreichen, bleiben unsichtbar. Die Client-IP wird ausschließlich über Symfonys vertrauenswürdige Proxys ermittelt. Nur eigene bekannte Proxy-Adressen als vertrauenswürdig konfigurieren; eingehende Forwarded-Header am Proxy bereinigen. Die Admin-/CLI-Warnung zeigt einen hohen Anteil bekannter CDN-Adressen an.

## Daten und Betrieb

Das versionierte ZIP aus dem GitHub-Release unter **Erweiterungen > Meine Erweiterungen** hochladen und installieren/aktivieren. Alternativ das Verzeichnis `AithosAgentenblick` nach `custom/plugins/` entpacken und als Shop-Betriebsbenutzer ausführen:

```sh
bin/console plugin:refresh
bin/console plugin:install --activate AithosAgentenblick
bin/console cache:clear
bin/console assets:install
bin/console agentenblick:selftest
bin/console agentenblick:report --days=7
```

PHP 8.2+, Sodium und OpenSSL werden benötigt. Shopware löst die deklarierten Composer-Abhängigkeiten bei der Installation auf. Die geprüften Zielversionen sind **6.6.10.29** und **6.7.15.0**. Scheduler und Message-Consumer müssen laufen. Nach Updates `agentenblick:selftest` wiederholen. `AithosAgentenblick.config.offline=true` verhindert ausgehende Verifikation, Health-Prüfungen und sämtliche Benchmark-Aufrufe.

Standardmäßig kein zusätzliches Besucher-JavaScript; auch bei optionaler WebMCP-Meldung kein zusätzliches Cookie. Menschliche Anfragen erzeugen keine detaillierte Anfragehistorie. Rohe IP-Adressen, Cookie-Werte, Token, Bodies, Query-Strings und Referer werden nicht gespeichert. Vorläufige Kennungen verwenden täglich wechselnde lokale HMAC-Schlüssel.

Standardaufbewahrung: menschliche/unbekannte Sitzungen 48 Stunden, automatisierte Details 90 Tage, Aggregate 13 Monate. Shopware-Aufgaben und Queue-Verarbeitung müssen laufen, damit Bereinigung und Wartung erfolgen. Die [Datenschutzvorlage](docs/PRIVACY.de-DE.md) muss für den konkreten Shop ergänzt werden.

Die aufwendige Klassifikation und Speicherung laufen nach der Antwort. PHP-FPM mit deaktiviertem Debug wurde auf dem Teststand geprüft. Unter mod_php kann die Terminierungsarbeit den Client weiterhin aufhalten. Die bisherige Leistungsmessung ist unvollständig: Der gemessene p95 nach der Antwort liegt über dem Ziel von 5 ms. Die Vor-Antwort-Timer erfassen weder Dependency Injection noch Service-Konstruktion und Autoload. Einzelheiten und Grenzen stehen im Abnahmebericht.

## Gesundheitsdiagnose

Der Tab **Zugriff** liest gespeicherte Ergebnisse; beim Öffnen wird keine Anfrage nach außen gesendet. Ein Editor kann eine konfigurierte öffentliche HTTPS-Domain ausdrücklich prüfen. Erfasst werden die robots-Regeln und HTTP-Status von llms.txt, llms-full.txt, agents.md, UCP, Agent-Card und MCP. Das beweist keine Protokollkompatibilität. Private Ziele und Weiterleitungen sind gesperrt; nicht prüfbare Ergebnisse bleiben unbekannt. Ein Snapshot gilt 24 Stunden.

Beobachtete Widersprüche zeigen Status, passende öffentliche Regel und Grund: erlaubter Agent erhält 403/429, verbotener Agent wird dennoch beobachtet, UCP/MCP meldet Fehler. Aktivität nach einer erfolgreichen llms-Antwort beweist nicht, dass die Datei gelesen wurde. Der optionale Selbsttest verwendet GPTBot, ClaudeBot und ChatGPT-User, stammt aber von **der Server-IP des Shops**, nicht von den Betreibern. Die eigenen Testanfragen zählen nicht als Besucher.

`bin/console agentenblick:health` liest lokale Ergebnisse. `--probe=<Domain-ID> --auto-test` fordert die Prüfungen ausdrücklich an. Der Offline-Modus verhindert auch diese Aufrufe.

## Optionaler Benchmark

Die Teilnahme ist standardmäßig aus. Vor dem Aktivieren zeigt die Vorschau die exakten zu sendenden JSON-Daten. Einwilligungsversion 2 muss ausdrücklich bestätigt werden. Übermittelt werden gerundete Wochenanteile und grobe Shopmerkmale mit zufälligen Installations-/Kanal-IDs; keine Besucher-, Bestell-, Warenkorb-, Domain- oder URL-Daten und keine genauen Verkehrszahlen.

Unter zehn lokalen Sitzungen wird die betreffende Kennzahl unterdrückt. Veröffentlichte Vergleiche benötigen fünf verschiedene geeignete Installationen je Kennzahl. Demodaten nutzen ausschließlich [demo.shopware.aithos.app](https://demo.shopware.aithos.app) mit eigener Anmeldung; echte Beiträge bleiben auf `https://shopware.aithos.app`. Der bei der Vorbereitung gespeicherte Installationsmodus bestimmt Registrierung, Meldungen und Widerruf. Eine spätere Änderung von `benchmarkDemo` verschiebt keine Identität. Frei wählbare Ziele und Weiterleitungen sind ausgeschlossen.

Alte Demoidentitäten ohne `transportVersion=2` werden mit `transport_migration_required` für Netzwerkaufrufe gesperrt. Lokales Stoppen bleibt möglich; ausstehender Widerruf und Schlüssel bleiben erhalten. Administration oder Support müssen die Daten beim bisherigen Dienst ausdrücklich klären, da dessen Produktions-API Demozugriffe ablehnen kann. Erst danach dürfen alte Identität und Entwürfe stillgelegt und eine neue Demoidentität mit erneuter Einwilligung vorbereitet werden. Keine automatische Übertragung; Modus oder Marker nicht manuell ändern. Bestehende echte Identitäten bleiben unverändert.

Widerruf stoppt neue Übermittlungen sofort. Bei fehlender Verbindung bleibt die entfernte Löschung ausstehend. Eine vollständige lokale Löschung ist dann blockiert, um den nötigen Schlüssel zu erhalten. Nach einer Deinstallation mit Datenerhalt müssen Plugin und Aufgaben für einen erneuten Löschversuch wieder installiert/aktiviert werden. Dieses Verhalten muss vor einer Store-Einreichung angepasst werden.

## Entwicklung und Nachweise

Der isolierte Teststand verwendet dockware/shopware:6.7.15.0 und ist nur über 127.0.0.1:18080 erreichbar. Testskripte niemals gegen einen Händler-Shop ausführen. Die [Paket- und Kompatibilitätsprüfung](https://github.com/Math1987/agentenblick/blob/v0.1.0/handoffs/2026-10-14-lot-6-acceptance.md) enthält die genaue Evidenz für 6.6/6.7 und verbleibende Grenzen.

- [Englische Installations- und Betriebsanleitung](README.md)
- [Lot 1a](https://github.com/Math1987/agentenblick/blob/v0.1.0/handoffs/2026-10-14-lot-1a-acceptance.md), [Lot 3](https://github.com/Math1987/agentenblick/blob/v0.1.0/handoffs/2026-10-14-lot-3-acceptance.md), [experimenteller Lot 4](https://github.com/Math1987/agentenblick/blob/v0.1.0/handoffs/2026-10-14-lot-4-acceptance.md)
- [Beiträge](CONTRIBUTING.md), [Sicherheitsmeldungen](SECURITY.md), [Änderungen](CHANGELOG.md)

## Freiwillige WebMCP-Meldung (experimentell)

Unter Erweiterungen > Meine Erweiterungen > Agentenblick > Konfigurieren kann `webMcpEnabled` aktiviert werden (Standard: aus). Ein lokales Skript bietet ein natives WebMCP-Werkzeug und ein optionales Formular an. Nur eine ausdrückliche Meldung mit Assistenzdienst und grober Aufgabe wird erfasst. Vorhandene Shopware-Sitzungen werden ohne Doppelzählung zugeordnet; kein zusätzliches Cookie und keine nachträgliche Rekonstruktion menschlicher Anfragen. Die Identität bleibt ungeprüft. Unterstützte Browser und freiwillige Teilnahme müssen getrennt getestet werden; siehe die englische README.
