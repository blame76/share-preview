# share-preview

Ein kleines, zustandsloses Werkzeug zum Prüfen von Social-Share-Metadaten.

**Paste URL → fetch fresh → show metadata.**

- keine Accounts
- keine Cookies
- kein Tracking / keine Analytics
- keine Datenbank
- keine Speicherung von URLs oder Ergebnissen
- kein Framework
- kein Build-Step
- kein Anwendungs-Cache
- keine zusätzlichen Runtime-Abhängigkeiten

Die Oberfläche besteht aus HTML/CSS/Vanilla JS. PHP lädt die Zielseite serverseitig, liest die Meta-Tags und verwirft den Inhalt nach der Anfrage wieder.

## Installation per FTP

1. ZIP entpacken.
2. Den **Inhalt** des Ordners in ein beliebiges Unterverzeichnis deines Webspaces hochladen, z. B. `/tools/share/`, `/preview/` oder `/share-preview/`.
3. Im Browser `https://deine-domain.example/dein-verzeichnis/selfcheck.php` öffnen.
4. Wenn der Selfcheck **Bereit** meldet, die Startseite des Verzeichnisses öffnen.

Der Verzeichnisname ist nicht fest verdrahtet.

Es gibt keine Konfigurationsdatei und keine Schreibrechte einzurichten.

## Voraussetzungen

Notwendig:

- PHP 8.1+
- PHP cURL
- PHP DOM / XML (`DOMDocument`)
- PHP JSON
- DNS-Auflösung aus PHP
- ausgehende HTTP/HTTPS-Verbindungen vom Webserver

Empfohlen:

- HTTPS, damit der native Browser-Share-Button zuverlässig funktioniert
- Apache mit `.htaccess`; auf nginx funktionieren die wichtigen No-Cache-/Security-Header trotzdem direkt aus PHP

`intl` ist optional. Es wird nur benötigt, wenn internationale Domainnamen direkt mit Unicode-Zeichen eingegeben werden sollen. Punycode funktioniert ohne `intl`.

Die mitgelieferte `.htaccess` deaktiviert Directory Listing, schützt interne Dateien und setzt Security-/No-Cache-Header auch für statische Seiten. Sie verwendet nur relative Regeln und funktioniert deshalb unabhängig davon, in welchem Unterverzeichnis das Tool installiert wird.

## Was wird abgerufen?

Pro Prüfung wird genau die eingegebene öffentliche URL geladen. Bis zu fünf HTTP-Weiterleitungen werden manuell verfolgt und vor jedem Abruf erneut geprüft.

Ausgelesen werden u. a.:

- `<title>`
- `meta[name="description"]`
- `canonical`
- `og:title`
- `og:description`
- `og:image`
- `og:url`
- `og:type`
- `twitter:card`
- `twitter:title`
- `twitter:description`
- `twitter:image`

Falls ein Vorschaubild vorhanden ist, versucht der Server es ebenfalls einmal abzurufen und als Data-URI in die Antwort einzubetten. Dadurch muss der Browser des Nutzers die Zielseite nicht selbst für das Bild kontaktieren.

## Sicherheitsgrenzen

Die Anwendung ist bewusst kein allgemeiner Proxy.

- nur HTTP und HTTPS
- nur Standard-Ports 80 und 443
- URLs maximal 4096 Bytes
- keine URLs mit Benutzername/Passwort
- localhost, private, link-lokale und IANA-Sonder-/Reservierungsbereiche werden für IPv4 und IPv6 blockiert
- sämtliche DNS-Antworten werden vor jedem Abruf geprüft; eine einzige nichtöffentliche Antwort blockiert das Ziel
- Ziel-IP wird für den Request gepinnt
- die tatsächlich verwendete cURL-Ziel-IP wird nach dem Request erneut geprüft
- Redirects werden einzeln validiert
- maximal 5 Redirects
- kurze Connect-Timeouts und ein gemeinsames Gesamt-Zeitbudget für die gesamte Redirect-Kette
- HTML maximal 2 MB
- Vorschaubild maximal 3 MB
- Vorschaubilder nur als JPEG, PNG, GIF, WebP oder AVIF mit passender Dateisignatur; kein SVG
- Antwort-Header maximal 64 KiB
- TLS- und Hostname-Prüfung sind ausdrücklich aktiv; Umgebungs-Proxys sind deaktiviert
- kein Weiterreichen von Cookies oder Authorization-Headern
- keine fremden Scripts, Fonts oder Tracker
- Content Security Policy und weitere Basis-Header

## Caching

Share Preview setzt für die eigene Antwort `no-store` / `no-cache` und speichert keine Ergebnisse.

Die Anwendung sendet auch beim Abruf der Zielseite `Cache-Control: no-cache`. Ein vorgeschaltetes CDN oder Cache der **Zielseite** kann trotzdem selbst entscheiden, welche Version es ausliefert. Darauf hat Share Preview keinen Einfluss.

## Datenschutz

Die statische Seite `datenschutz.html` beschreibt die Datenverarbeitung des Werkzeugs und den technisch notwendigen Serverbetrieb. Share Preview selbst setzt keine Cookies, führt keine Sessions und speichert weder eingegebene URLs noch Prüfergebnisse. Daher gibt es bewusst kein Cookie- oder Consent-Banner.

Die Anwendung legt keine eigenen Zugriffslogs an. Ob und wie lange der Webserver oder Hosting-Anbieter Verbindungsdaten protokolliert, muss anhand der tatsächlichen Hosting-Konfiguration geprüft werden.

## Rate-Limit

Version 0.1 enthält bewusst kein PHP-basiertes IP-Rate-Limit und speichert dafür keine IP-Adressen. Missbrauch wird zunächst durch URL-, Protokoll-, Port-, Redirect-, Zeit- und Größenlimits begrenzt.

Bei Bedarf kann zusätzlich ein Rate-Limit auf Webserver- oder Reverse-Proxy-Ebene eingerichtet werden.

## Tests

Die Security-Tests benötigen keine zusätzlichen Pakete:

```bash
php tests/run.php
find . -type f -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l
```

Die Tests prüfen insbesondere verbotene Schemes, lokale/private Ziele, IPv4-mapped IPv6, Credentials, Ports, URL-Länge, gemischte DNS-Antworten und Redirects auf private IPs.

Es werden weder Composer noch npm, externe JavaScript-Bibliotheken, CDN-Ressourcen oder externe Fonts verwendet.

## Share-Button

Der Share-Button teilt die Werkzeugseite selbst, nicht das gerade geprüfte Ergebnis. Falls die Web Share API im Browser nicht verfügbar ist, wird der Link kopiert.

Prüfergebnisse werden bewusst nicht in URLs serialisiert und nicht gespeichert.

## Lizenz

MIT. Siehe [LICENSE](LICENSE).
