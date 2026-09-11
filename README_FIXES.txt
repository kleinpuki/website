Bernbesse Website - Bugfix v4

Behoben:
- Schreib-Endpunkte akzeptieren nur noch POST.
- HSTS wird bei HTTPS aktiviert.
- Temporäre JSON-Dateien bekommen zufällige Namen; Schreibfehler werden sauber behandelt.
- Admin-Benutzernamen können nicht mehr von normalen Nutzern geändert werden.
- Der reservierte Owner-Name kann nicht registriert werden.
- Galerie-, Server- und Join-Konfiguration sind serverseitig geschützt.
- Serveradresse und Join-Schritte werden serverseitig validiert.
- Chat-Nachrichten werden UTF-8-sicher gekürzt.
- Typing-Status wird immer dem eingeloggten Nutzer zugeordnet.
- Öffentliche Seiten verwenden bei API-Fehlern keine veralteten localStorage-Daten mehr.
- API-Antworten werden ohne Browser-Cache geladen.

Hinweis:
Die bestehende Owner-Authentifizierung bleibt kompatibel. Nach dem Deployment sollte das Owner-Passwort weiterhin über den Admin-Bereich geändert werden.

V7 Arena-Verwaltung
- Neues Admin-Menü "Arena Dateien"
- Upload von .png und .png.mcmeta
- PNG wird serverseitig als echtes PNG geprüft
- PNG.MCMETA wird als gültiges JSON geprüft
- Upload-Größen begrenzt
- Dateinamen werden gegen Pfad-Traversal und gefährliche Endungen geprüft
- Arena-Dateien können im Admin-Bereich aufgelistet und gelöscht werden
- Arena-Ordner blockiert PHP/CGI-Ausführung über .htaccess
- Responsive Arena-Verwaltung für PC, Tablet und Smartphone
