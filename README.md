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
- keine URLs mit Benutzername/Passwort
- localhost, private und reservierte IP-Bereiche werden blockiert
- DNS wird vor jedem Abruf geprüft
- Ziel-IP wird für den Request gepinnt
- Redirects werden einzeln validiert
- maximal 5 Redirects
- kurze Connect-/Gesamt-Timeouts
- HTML maximal 2 MB
- Vorschaubild maximal 3 MB
- kein Weiterreichen von Cookies oder Authorization-Headern
- keine fremden Scripts, Fonts oder Tracker
- Content Security Policy und weitere Basis-Header

## Caching

Share Preview setzt für die eigene Antwort `no-store` / `no-cache` und speichert keine Ergebnisse.

Die Anwendung sendet auch beim Abruf der Zielseite `Cache-Control: no-cache`. Ein vorgeschaltetes CDN oder Cache der **Zielseite** kann trotzdem selbst entscheiden, welche Version es ausliefert. Darauf hat Share Preview keinen Einfluss.

## Share-Button

Der Share-Button teilt die Werkzeugseite selbst, nicht das gerade geprüfte Ergebnis. Falls die Web Share API im Browser nicht verfügbar ist, wird der Link kopiert.

Prüfergebnisse werden bewusst nicht in URLs serialisiert und nicht gespeichert.

## Lizenz

MIT. Siehe [LICENSE](LICENSE).
