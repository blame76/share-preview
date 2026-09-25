# Security

`share-preview` ruft vom Benutzer angegebene URLs serverseitig ab. Deshalb behandelt das Projekt SSRF-Schutz als Teil der Grundfunktion und nicht als optionales Extra.

Wenn du eine Sicherheitslücke findest, veröffentliche bitte keine funktionierende Exploit-Anleitung, bevor eine Korrektur verfügbar ist. Eröffne stattdessen zunächst einen privaten Security Advisory im GitHub-Repository oder kontaktiere den Maintainer über die im Profil genannten Wege.

## Bekannte Grenzen

- Ein öffentlicher Webdienst kann absichtlich oft aufgerufen werden. Diese Version speichert absichtlich keinen Zustand und enthält deshalb kein serverseitiges Rate-Limit.
- Bei Bedarf kann zusätzlich ein Rate-Limit auf Webserver- oder Reverse-Proxy-Ebene eingerichtet werden.
- DNS- und Netzwerkregeln des Hosters können legitime Seiten blockieren.
- Das Tool simuliert Social-Previews anhand veröffentlichter Meta-Tags. Plattformen können zusätzlich eigene Caches, Crawler-Regeln, Bildverarbeitung und Darstellungslogik verwenden.
