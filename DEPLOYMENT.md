# Deployment-Anleitung (Produktion)

Diese Anleitung beschreibt das tatsächlich durchgeführte Deployment auf einem Hetzner-Cloud-VPS mit Ubuntu 24.04, Apache 2.4, PostgreSQL 16 und Let's Encrypt. Alle Befehle sind als `root` oder per `sudo` auszuführen, sofern nicht anders angegeben.

---

## 1. VPS-Empfehlung

**Hetzner CX22** (2 vCPU, 4 GB RAM, 40 GB SSD) ist für ein Schulsportfest mit ~50–100 gleichzeitigen Nutzern ausreichend. Bei deutlich mehr Nutzern oder dauerhaftem Betrieb CX32 oder höher wählen.

---

## 2. DNS konfigurieren

Beim Domain-Anbieter einen **A-Record** anlegen, der auf die öffentliche IPv4-Adresse des VPS zeigt:

```
Typ:  A
Name: @          (oder sportfest.deineschule.at)
Wert: <IPv4-Adresse des VPS>
TTL:  300
```

Optional: weiteren A-Record für `www` auf dieselbe IP setzen.

> DNS-Änderungen können bis zu 48 Stunden brauchen, bis sie weltweit sichtbar sind. In der Praxis meist unter einer Stunde.

---

## 3. Server-Grundeinrichtung

```bash
apt update && apt upgrade -y

# Apache, PHP und benötigte Erweiterungen
apt install -y apache2 php php-pgsql php-apcu php-mbstring php-xml php-curl libapache2-mod-php

# Apache-Module aktivieren
a2enmod rewrite headers

systemctl enable apache2
systemctl start apache2
```

---

## 4. PostgreSQL installieren und einrichten

```bash
apt install -y postgresql postgresql-contrib
systemctl enable postgresql
systemctl start postgresql
```

### Datenbankbenutzer und Datenbank anlegen

```bash
# Als postgres-Systembenutzer
sudo -u postgres psql
```

Innerhalb der psql-Konsole:

```sql
CREATE USER sportfest_user WITH PASSWORD 'sicheres_passwort_hier';
CREATE DATABASE endgame OWNER sportfest_user;
\q
```

### Schema und Migrationen einspielen

```bash
# Als postgres-Benutzer oder mit Superuser-Rechten
psql -U postgres -d endgame -f /var/www/endgame/migrations/schema.sql
psql -U postgres -d endgame -f /var/www/endgame/migrations/002_phase2.sql
psql -U postgres -d endgame -f /var/www/endgame/migrations/003_phase3.sql
psql -U postgres -d endgame -f /var/www/endgame/migrations/004_formats.sql
psql -U postgres -d endgame -f /var/www/endgame/migrations/005_teams.sql
psql -U postgres -d endgame -f /var/www/endgame/migrations/006_sse.sql
psql -U postgres -d endgame -f /var/www/endgame/migrations/007_indexes.sql
psql -U postgres -d endgame -f /var/www/endgame/migrations/008_team_names.sql
psql -U postgres -d endgame -f /var/www/endgame/migrations/009_user_delete_fks.sql
psql -U postgres -d endgame -f /var/www/endgame/migrations/010_live_matches.sql
psql -U postgres -d endgame -f /var/www/endgame/migrations/011_bracket_side_groups.sql
```

### Benutzerkonten einspielen

```bash
psql -U postgres -d endgame -f /var/www/endgame/seed.sql
```

### Tabellenberechtigungen setzen

> **Wichtig:** `GRANT ALL PRIVILEGES ON DATABASE` allein reicht **nicht**. PostgreSQL gewährt damit nur das Recht, sich mit der Datenbank zu verbinden — nicht auf einzelne Tabellen zuzugreifen. Die Tabellenberechtigungen müssen separat erteilt werden.

```bash
sudo -u postgres psql -d endgame
```

```sql
-- Bestehende Tabellen und Sequenzen
GRANT ALL PRIVILEGES ON ALL TABLES    IN SCHEMA public TO sportfest_user;
GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO sportfest_user;

-- Zukünftige Tabellen und Sequenzen (z. B. nach neuen Migrationen)
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT ALL ON TABLES    TO sportfest_user;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT ALL ON SEQUENCES TO sportfest_user;

\q
```

> `ALTER DEFAULT PRIVILEGES` muss vom selben Superuser ausgeführt werden, der auch die Tabellen erstellt — sonst greift es nicht für neue Tabellen.

---

## 5. Projektdateien deployen

```bash
# Repository nach /var/www/endgame klonen
git clone https://github.com/DrRemai/ahs-sportfest.git /var/www/endgame

# Konfigurationsdatei anlegen (nie committen!)
cp /var/www/endgame/config.example.php /var/www/endgame/config.php
nano /var/www/endgame/config.php
# DB_USER, DB_PASS, DB_NAME eintragen und speichern

# Composer-Abhängigkeiten installieren (PHPUnit für Tests)
cd /var/www/endgame && composer install --no-dev

# Verzeichnisrechte setzen
chown -R www-data:www-data /var/www/endgame
chmod -R 755 /var/www/endgame
```

---

## 6. Apache Virtual Host konfigurieren

Die Vorlage liegt unter `deploy/apache.conf.recommended`. Die Datei kopieren und anpassen:

```bash
cp /var/www/endgame/deploy/apache.conf.recommended \
   /etc/apache2/sites-available/endgame.conf

nano /etc/apache2/sites-available/endgame.conf
```

Mindest-Konfiguration (bereits in der Vorlage enthalten, `yourdomain.com` ersetzen):

```apache
<VirtualHost *:80>
    ServerName sportfest.deineschule.at
    DocumentRoot /var/www/endgame/public

    <Directory /var/www/endgame/public>
        AllowOverride All
        Require all granted
        Options -Indexes

        RewriteEngine On
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteCond %{REQUEST_URI} !^/api/
        RewriteRule ^ index.php [L]
    </Directory>

    <Location /sse.php>
        Header set X-Accel-Buffering "no"
    </Location>

    ErrorLog  /var/log/apache2/endgame_error.log
    CustomLog /var/log/apache2/endgame_access.log combined
</VirtualHost>
```

```bash
a2ensite endgame
a2dissite 000-default   # Standard-Site deaktivieren
systemctl reload apache2
```

---

## 7. SSL mit Let's Encrypt (Certbot)

```bash
apt install -y certbot python3-certbot-apache

certbot --apache -d sportfest.deineschule.at -d www.sportfest.deineschule.at
# E-Mail-Adresse angeben und den Bedingungen zustimmen
# Certbot ergänzt die Apache-Konfiguration automatisch um HTTPS und Redirect
```

Zertifikate werden automatisch alle 90 Tage erneuert (Certbot richtet einen systemd-Timer ein).

---

## 8. PHP für Produktion konfigurieren

Die Vorlage liegt unter `deploy/php.ini.recommended`. Die wichtigsten Einstellungen:

```bash
nano /etc/php/8.2/apache2/php.ini
```

Folgende Werte setzen (oder die Vorlage vollständig übernehmen):

```ini
; Fehler nie an den Browser ausgeben
display_errors = Off
log_errors     = On
error_log      = /var/log/php/error.log

; OPcache aktivieren
opcache.enable                = 1
opcache.memory_consumption    = 128
opcache.validate_timestamps   = 0

; APCu (für src/Cache.php)
apc.enabled   = 1
apc.shm_size  = 64M

; Session-Sicherheit
session.cookie_httponly  = 1
session.cookie_secure    = 1
session.cookie_samesite  = Strict
session.use_strict_mode  = 1
```

```bash
mkdir -p /var/log/php
chown www-data:www-data /var/log/php
systemctl restart apache2
```

---

## 9. PostgreSQL für SSE-Last konfigurieren

Jeder aktive SSE-Client hält **zwei** PostgreSQL-Verbindungen. PostgreSQLs Standard-`max_connections` beträgt 100, was bei ~40 gleichzeitigen Nutzern ausgereizt ist.

```bash
nano /etc/postgresql/16/main/postgresql.conf
```

```ini
max_connections = 200    # erlaubt ~90 gleichzeitige SSE-Nutzer mit Reserve
shared_buffers  = 256MB  # zusammen mit max_connections erhöhen
```

```bash
systemctl reload postgresql
```

Weitere Details und Apache-MPM-Empfehlungen: `deploy/sse-notes.md`.

---

## 10. Firewall einrichten

```bash
ufw allow OpenSSH
ufw allow 'Apache Full'
ufw enable
ufw status
```

---

## 11. Betrieb und Updates

```bash
# Neue Version deployen
cd /var/www/endgame
git pull origin main

# Neue Migration ausführen (Beispiel)
psql -U postgres -d endgame -f migrations/012_neue_migration.sql

# Apache nach PHP-Änderungen neu laden
systemctl reload apache2
```

---

## Häufige Fallstricke

Diese Probleme sind beim ursprünglichen Deployment tatsächlich aufgetreten:

---

### 1. `declare(strict_types=1)` muss die buchstäblich erste Zeile sein

Jede PHP-Datei beginnt mit:

```php
<?php
declare(strict_types=1);
```

Alles vor `<?php` — auch ein unsichtbares Leerzeichen, ein BOM-Zeichen oder eine Leerzeile, die beim Bearbeiten mit `nano` versehentlich eingefügt wurde — verursacht einen **Fatal Error**. PHP muss `declare` sehen, bevor irgendein Output erzeugt wird.

**Symptom:** Weiße Seite oder `500 Internal Server Error` ohne weiteren Hinweis im Browser. Im Apache-Log steht: `Cannot use declare(strict_types=1) here`.

---

### 2. `GRANT ALL PRIVILEGES ON DATABASE` gewährt **keinen** Tabellenzugriff

Ein häufiger PostgreSQL-Anfängerfehler: `GRANT ALL PRIVILEGES ON DATABASE endgame TO user` erlaubt nur die Verbindungsaufnahme, nicht das Lesen oder Schreiben einzelner Tabellen. Die Tabellen- und Sequenzberechtigungen müssen separat erteilt werden (siehe Abschnitt 4).

**Symptom:** PHP meldet `SQLSTATE[42501]: Insufficient privilege` bei jedem Datenbankzugriff, obwohl der Benutzer sich verbinden kann.

---

### 3. SSE blockiert alle anderen Anfragen desselben Browsers

PHP sperrt die Session-Datei für die gesamte Laufzeit einer Anfrage. `sse.php` läuft bis zu 3600 Sekunden — ohne Gegenmaßnahme blockiert es jeden anderen Tab desselben Browsers, der dieselbe Session verwendet.

**Lösung:** In `sse.php` wird `session_write_close()` direkt nach dem Lesen der Session-Daten aufgerufen. Wer `sse.php` anpasst, muss darauf achten, diesen Aufruf zu erhalten.

---

### 4. PDO `ATTR_EMULATE_PREPARES = false` und PHP-Boolean-Werte

Mit `PDO::ATTR_EMULATE_PREPARES => false` (wie in diesem Projekt verwendet) sendet PDO PHP-`false`-Booleans als leere Zeichenkette `''` an PostgreSQL, statt als gültiges Boolean-Literal. PostgreSQL lehnt `''` als Boolean-Wert ab.

**Lösung:** Boolean-Werte immer als `'t'`/`'f'`-Strings binden, nicht als PHP-`true`/`false`. Im gesamten Projekt wird dies so gehandhabt — bei neuen Datenbankabfragen darauf achten.
