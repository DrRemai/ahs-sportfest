# Mitwirken am Projekt

Diese Anleitung richtet sich an zukünftige Schülerinnen und Schüler, die das AHS-Sportfest-Projekt übernehmen und weiterentwickeln. Sie setzt grundlegende PHP- und JavaScript-Kenntnisse voraus.

---

## Projektstruktur

```
src/
├── Formats/
│   ├── FormatInterface.php   ← Schnittstelle für alle Turnierformate
│   ├── SingleElim.php        ← K.-o.-System (einfach)
│   ├── DoubleElim.php        ← K.-o.-System (doppelt)
│   ├── RoundRobin.php        ← Rundenturnier
│   ├── Swiss.php             ← Schweizer System
│   └── MultiStage.php        ← Gruppenphase + K.-o.-System
├── router.php                ← Alle API-Routen-Handler
├── Permissions.php           ← Rollen- und Zugriffssteuerung
├── FormatFactory.php         ← Registrierung der Turnierformate
├── Cache.php                 ← APCu-Wrapper
├── auth.php                  ← Session, Login, Logout
├── db.php                    ← PDO-Singleton
├── Notification.php          ← PostgreSQL-NOTIFY-Wrapper
└── helpers.php               ← Hilfsfunktionen

public/assets/js/
├── app.js                    ← SPA-Kern (Navigation, API-Client, Auth-State)
├── router.js                 ← Client-seitiges Routing (History API)
├── i18n.js                   ← Deutsches Wörterbuch (alle UI-Texte)
├── views/                    ← Eine Datei pro Seite/Ansicht
└── components/               ← Wiederverwendbare UI-Bausteine
    ├── toast.js              ← Kurzmeldungen (Erfolg/Fehler)
    ├── confirm.js            ← Bestätigungsdialoge
    └── …

migrations/
├── schema.sql                ← Basis-Schema (zuerst ausführen)
└── 002_phase2.sql … 011_…   ← In numerischer Reihenfolge ausführen

tests/
├── Formats/                  ← Tests für jedes Turnierformat
├── Api/                      ← Integrationstests für API-Endpunkte
├── Permissions/              ← Tests für Berechtigungslogik
├── setup-test-db.bat         ← Legt eine separate Testdatenbank an
└── run.bat                   ← Führt die gesamte PHPUnit-Suite aus
```

> **Migrationsregel:** Migrationen werden **in strikter numerischer Reihenfolge** einmalig gegen die Datenbank ausgeführt. Eine bereits angewendete Migration darf **niemals** nachträglich bearbeitet werden. Stattdessen immer eine neue Datei mit der nächsten Nummer anlegen.

---

## Neues Turnierformat hinzufügen

1. Neue Klasse in `src/Formats/` anlegen, die `FormatInterface` implementiert:

```php
<?php
declare(strict_types=1);

class MeinFormat implements FormatInterface
{
    public function generate(int $tournamentId, array $teamIds, array $config): void
    {
        // Spiele in die matches-Tabelle einfügen
    }

    public function advance(int $matchId): void
    {
        // Aufsteiger in die nächste Runde setzen (falls mehrstufig)
    }

    public function standings(int $tournamentId): array
    {
        // Tabelle/Platzierung zurückgeben
    }

    public function isComplete(int $tournamentId): bool
    {
        // true, wenn alle Spiele abgeschlossen sind
    }
}
```

2. Klasse in `src/FormatFactory.php` unter einem eindeutigen String-Key registrieren:

```php
public static function make(string $format): FormatInterface
{
    return match($format) {
        'single_elim' => new SingleElim(),
        'round_robin' => new RoundRobin(),
        // …
        'mein_format' => new MeinFormat(),   // ← hier eintragen
        default       => throw new \InvalidArgumentException("Unknown format: $format"),
    };
}
```

Das war es. Die API-Schicht, der Bracket-Endpunkt und der Standings-Endpunkt in `src/router.php` rufen alle Methoden über `FormatFactory::make()` auf — kein weiterer Anpassungsbedarf.

---

## UI-Texte hinzufügen oder ändern

Alle deutschen Texte der Benutzeroberfläche stehen im zentralen Wörterbuch:

```
public/assets/js/i18n.js
```

### Neuen Text hinzufügen

```js
// in i18n.js, passendem Abschnitt hinzufügen:
'meinBereich.meinSchluessel': 'Mein deutscher Text',
```

### Text in einer View verwenden

```js
import { t } from '../i18n.js';

// Einfacher Text:
element.textContent = t('meinBereich.meinSchluessel');

// Mit Variablen (Platzhalter {n} im Wörterbuch):
// 'wiz.roundsCalc': 'Runden: {n}'
t('wiz.roundsCalc', { n: 5 })  // → "Runden: 5"
```

---

## Tests ausführen

Die PHPUnit-Testsuite deckt Format-Engine und Berechtigungslogik ab. Für die Integrationstests wird eine **separate** Testdatenbank benötigt.

### Einmalig: Testdatenbank anlegen (Windows)

```bat
tests\setup-test-db.bat
```

Das Skript legt die Datenbank `endgame_test` an und führt alle Migrationen durch.

### Tests ausführen

```bat
tests\run.bat
```

Oder direkt mit Composer:

```bash
composer test
```

> Integrationstests in `tests/Api/` machen echte Datenbankabfragen gegen `endgame_test`. Die Testdatenbank wird vor jedem Testlauf automatisch zurückgesetzt.

---

## Die `// SCHOOL:`-Kommentare

Im gesamten Quellcode gibt es Kommentare der Form:

```php
// SCHOOL: Nur drei feste Sportarten für das aktuelle Sportfest
```

```js
// SCHOOL: Turnierformat fest auf multi_stage gesetzt — kein Auswahlschritt im Wizard
```

Diese Kommentare markieren **bereitstellungsspezifische Entscheidungen**, die für das aktuelle Schulsportfest bewusst vereinfacht oder eingeschränkt wurden:

- Nur drei Sportarten (Fußball, Volleyball, Hockey)
- Fest hinterlegtes Turnierformat (2 Gruppen + Kreuzpaarungen K.-o.-Runde)
- Öffentliche Teamregistrierung deaktiviert (Turniere nur auf Einladung)
- Teamnamen-Claim-Modell (erster Nutzer reserviert einen Namen dauerhaft)

**Ein zukünftiges Jahr, das eine allgemeinere Plattform möchte**, sucht am besten zuerst nach allen `// SCHOOL:`-Stellen — das sind die Ausgangspunkte, um die Einschränkungen zurückzunehmen oder zu verallgemeinern:

```bash
# Alle SCHOOL-Markierungen finden
grep -rn "// SCHOOL:" src/ public/assets/js/
```

---

## Hinweis zur Entwicklungsgeschichte

Der ursprüngliche Entwicklungsprozess hat **Claude** (Anthropic) intensiv genutzt — sowohl für Architektur- und Debugging-Gespräche als auch Claude Code für die direkte Code-Implementierung. Der gesamte Entwicklungsablauf ist in der Commit-Historie des Repositories dokumentiert.

Zukünftige Maintainer, die ebenfalls KI-Unterstützung einsetzen, könnten es nützlich finden, ihren Assistenten auf diese `CONTRIBUTING.md` und die `// SCHOOL:`-Kommentare als schnellen Kontext zu verweisen — das gibt dem Assistenten ein gutes Bild der Architektur und der bewussten Einschränkungen, ohne dass der gesamte Code gelesen werden muss.

---

## Fragen und Weitergabe

Wenn du das Projekt von einem Vorgänger übernimmst und Fragen zum Code hast, die diese Dokumentation nicht beantwortet, schau zuerst in:

1. `deploy/sse-notes.md` — SSE-Architektur und Skalierungsüberlegungen
2. `deploy/apache.conf.recommended` und `deploy/php.ini.recommended` — Produktionskonfiguration
3. Die Commit-Historie (`git log`) — enthält ausführliche Commit-Nachrichten zu allen größeren Änderungen
4. `DEPLOYMENT.md` — Abschnitt "Häufige Fallstricke" mit tatsächlich aufgetretenen Problemen
