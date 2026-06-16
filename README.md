# AHS Sportfest

**Turnierverwaltungsplattform für das jährliche Sportfest der Schule.**

Schülerinnen und Schüler melden ihre Teams an, Organisatoren erstellen Turniere, tragen Ergebnisse ein und verfolgen den Live-Spielplan — alles in einem einzigen Webbrowser, ohne Installation auf den Endgeräten.

---

## Tech-Stack

| Schicht | Technologie |
|---|---|
| Backend | PHP 8.2+, PDO (PostgreSQL) |
| Datenbank | PostgreSQL 16 |
| Frontend | Vanilla-JS-SPA (kein Framework, kein Build-Schritt) |
| Webserver | Apache 2.4 mit mod_rewrite |
| Echtzeit | Server-Sent Events (SSE) via `public/sse.php` + PostgreSQL LISTEN/NOTIFY |
| Cache | APCu (Benutzer-Cache) + OPcache (Bytecode) |

---

## Funktionen

- **Mehrformat-Turnier-Engine** — K.-o.-System (einfach & doppelt), Rundenturnier, Schweizer System, mehrstufiges Format (Gruppenphase + K.-o.-System)
- **Rollenbasierte Berechtigungen** — Admin, Organisator, Mitarbeiter, Gast; Zugriffssteuerung pro Turnier
- **Live-Spielverfolgung** — Ergebnisse werden per SSE in Echtzeit an alle verbundenen Clients gesendet, ohne Seiten-Reload
- **Deutschsprachige UI** — alle Texte über `public/assets/js/i18n.js` zentralisiert
- **Einladungssystem** — Turniere können öffentlich oder auf eingeladene Teams beschränkt sein
- **Neubeurteilungsworkflow** — Teams können Ergebnisse anfechten; Organisatoren genehmigen oder lehnen ab

---

## Architektur-Übersicht

```
/
├── src/
│   ├── router.php          ← Alle API-Routen-Handler (REST-ähnlich, kein Framework)
│   ├── Permissions.php     ← Rollen- und Berechtigungsprüfungen
│   ├── Cache.php           ← APCu-Wrapper (Cache::remember / Cache::delete)
│   ├── FormatFactory.php   ← Registrierung aller Turnierformate
│   ├── Formats/
│   │   ├── FormatInterface.php   ← generate(), advance(), standings(), isComplete()
│   │   ├── SingleElim.php
│   │   ├── DoubleElim.php
│   │   ├── RoundRobin.php
│   │   ├── Swiss.php
│   │   └── MultiStage.php
│   ├── auth.php            ← Session-Verwaltung, Login/Logout
│   ├── db.php              ← PDO-Singleton
│   └── Notification.php    ← PostgreSQL NOTIFY-Wrapper
│
├── public/
│   ├── index.php           ← Einstiegspunkt; Router für API vs. SPA
│   ├── sse.php             ← Server-Sent Events (hält 2 PG-Verbindungen pro Client)
│   └── assets/
│       ├── css/app.css
│       └── js/
│           ├── app.js      ← SPA-Kern: Navigation, API-Client, Auth-State
│           ├── router.js   ← Client-seitiges Routing (History API)
│           ├── i18n.js     ← Deutsches Wörterbuch; t('key') überall im Frontend
│           ├── views/      ← Eine Datei pro Seite
│           └── components/ ← Wiederverwendbare UI-Bausteine (Badges, Toasts …)
│
├── migrations/
│   ├── schema.sql          ← Basis-Schema (zuerst ausführen)
│   ├── 002_phase2.sql
│   └── …                   ← In numerischer Reihenfolge ausführen
│
├── tests/                  ← PHPUnit-Testsuite
├── deploy/                 ← Empfohlene Apache- und PHP-Konfigurationsvorlagen
├── seed.sql                ← Produktionsdaten (nur Benutzerkonten)
└── config.example.php      ← Vorlage für config.php (nie committen!)
```

**Format-Engine-Muster:** Jede Klasse in `src/Formats/` implementiert `FormatInterface` und wird in `FormatFactory::make()` unter einem eindeutigen String-Key registriert (z. B. `'single_elim'`, `'round_robin'`). Um ein neues Format hinzuzufügen, genügt es, eine neue Klasse zu erstellen und dort einzutragen — der Rest des Systems erkennt es automatisch.

**SSE-Echtzeit-Schicht:** `public/sse.php` verbindet sich beim Aufbau mit PostgreSQL über `LISTEN`, empfängt `NOTIFY`-Ereignisse aus Datenbank-Triggern (definiert in `migrations/006_sse.sql`) und leitet sie als SSE-Events an den Browser weiter. Jeder aktive SSE-Client hält **zwei** PostgreSQL-Verbindungen.

---

## Lokales Entwicklungssetup

```bash
# 1. Repository klonen
git clone https://github.com/DrRemai/ahs-sportfest.git
cd ahs-sportfest

# 2. Konfiguration anlegen
cp config.example.php config.php
# config.php öffnen und DB_USER / DB_PASS / DB_NAME eintragen

# 3. PHP-Abhängigkeiten installieren (nur PHPUnit für Tests)
composer install

# 4. PostgreSQL-Datenbank einrichten
createdb endgame
psql -U postgres -d endgame -f migrations/schema.sql
psql -U postgres -d endgame -f migrations/002_phase2.sql
psql -U postgres -d endgame -f migrations/003_phase3.sql
psql -U postgres -d endgame -f migrations/004_formats.sql
psql -U postgres -d endgame -f migrations/005_teams.sql
psql -U postgres -d endgame -f migrations/006_sse.sql
psql -U postgres -d endgame -f migrations/007_indexes.sql
psql -U postgres -d endgame -f migrations/008_team_names.sql
psql -U postgres -d endgame -f migrations/009_user_delete_fks.sql
psql -U postgres -d endgame -f migrations/010_live_matches.sql
psql -U postgres -d endgame -f migrations/011_bracket_side_groups.sql

# 5. Benutzerkonten einspielen
psql -U postgres -d endgame -f seed.sql

# 6. Entwicklungsserver starten
php -S localhost:8000 -t public
# oder über XAMPP: DocumentRoot auf /pfad/zum/projekt/public zeigen lassen
```

> **SSE im Entwicklungsmodus:** `php -S` unterstützt Server-Sent Events eingeschränkt, da der eingebettete Server keine parallelen Verbindungen akzeptiert. Für SSE-Tests wird XAMPP oder Apache empfohlen.

---

## Deployment (Produktion)

Siehe [DEPLOYMENT.md](DEPLOYMENT.md) für die vollständige Schritt-für-Schritt-Anleitung zur Einrichtung auf einem Linux-VPS (Ubuntu 24.04, Apache, Let's Encrypt).

---

## Datenbank-Übersicht

| Tabelle | Inhalt |
|---|---|
| `users` | Benutzerkonten (username, display_name, password_hash, is_admin) |
| `tournaments` | Turnier-Metadaten + `format_config` (JSONB) für formatspezifische Einstellungen |
| `tournament_stages` | Phasen eines mehrstufigen Turniers (stage_order, format, config) |
| `teams` | Teams (name, sport, owner_uid) |
| `tournament_teams` | Zuordnung Team ↔ Turnier mit Status (pending / approved / rejected) |
| `tournament_roles` | Rollenverteilung (organiser / staff) pro Turnier |
| `matches` | Alle Spiele (Ergebnis, Status, bracket_side, round, stage_id) |
| `reevaluation_requests` | Neubeurteilungsanträge von Teams |
| `notifications` | Persistente Benachrichtigungen pro Benutzer |

Formatspezifische Einstellungen (Gruppenanzahl, Punkte pro Sieg, Seedingmodus usw.) werden als JSONB in `tournaments.format_config` gespeichert — kein separates Schema pro Format nötig.

---

## Bekannte Einschränkungen dieser Bereitstellung

1. **SSE-Verbindungslimit:** `public/sse.php` hält zwei PostgreSQL-Verbindungen pro aktivem Browser-Tab offen. Bei vielen gleichzeitigen Nutzern (>40–50) kann PostgreSQLs `max_connections` zum Engpass werden. Siehe [DEPLOYMENT.md](DEPLOYMENT.md) und `deploy/sse-notes.md` für Konfigurationsempfehlungen.

2. **Teamnamen-Claim-Modell:** Ein Teamname ist pro Besitzer einmalig, nicht global. Der erste Benutzer, der einen Namen verwendet, "reserviert" ihn dauerhaft für sich. Es gibt keine globale Namenseinzigartigkeit über Benutzer hinweg.

3. **Fixiertes Sportfest-Format:** Diese Bereitstellung ist auf drei feste Sportarten (Fußball, Volleyball, Hockey) und ein einziges Turnierformat beschränkt — 2 Gruppen à 6 Teams mit Kreuzpaarungen in der K.-o.-Runde. Der Turnier-Assistent (`public/assets/js/views/create-tournament.js`) und die Wizard-Schritte wurden **bewusst** für diesen Einsatzzweck vereinfacht und die Konfiguration fest hinterlegt. Ein zukünftiges Jahr mit anderen Regeln oder Sportarten sollte dort als erstem Anlaufpunkt beginnen, um die Einschränkungen zurückzunehmen. Kommentare mit `// SCHOOL:` im gesamten Code markieren alle solchen Stellen.

---

## Hinweis

Erstellt von [Dein Name / Abitur-Jahrgang] für [Name der Schule].
Siehe [CONTRIBUTING.md](CONTRIBUTING.md), falls du das Projekt übernimmst oder weiterentwickeln möchtest.

---

## Lizenz

[MIT](https://opensource.org/licenses/MIT)
