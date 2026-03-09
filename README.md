# Google Login Test (React + PHP + MySQL)

Dieses Projekt nutzt Google Login und zeigt danach kein JSON mehr an, sondern ein bearbeitbares Formular.
Pro Google-Account gibt es genau einen Formular-Datensatz und maximal ein Video.

## Funktionen

1. Login per Google OAuth.
2. Formular mit optionalen Feldern (nichts ist Pflicht):
   - Vorname (auto-aus Google)
   - Nachname (auto-aus Google)
   - E-Mail (auto-aus Google)
   - Ungefaehre Adresse
   - Ideale Erreichbarkeit
   - Social-Media-Name(n)
   - Sonstiges
3. Speichern in MySQL (Upsert pro Google-User).
4. Video aufnehmen/hochladen:
   - max. 3 Minuten
   - max. 300MB
   - pro Google-User immer nur 1 Video (neu hochladen ersetzt altes)
   - Video kann geloescht werden

## Voraussetzungen

- PHP 8.1+
- Node.js 18+
- MySQL/MariaDB
- Ein Google Cloud Projekt mit OAuth 2.0 Client ID (Web Application)

## 1) Google OAuth konfigurieren

- OAuth Consent Screen konfigurieren
- OAuth Client ID vom Typ `Web application` erstellen
- Bei `Authorized redirect URIs` exakt eintragen:
  - `http://localhost:8000/api/callback.php`

## 2) Backend konfigurieren

Datei `backend/config.php` anpassen:

- `google_client_id`
- `google_client_secret`
- `google_redirect_uri` (muss mit Google Console uebereinstimmen)
- `frontend_url` (z. B. `http://localhost:5173`)
- `db`-Block (host, port, database, username, password, charset)

Die Tabelle `user_forms` wird beim ersten Zugriff automatisch angelegt.

## 3) Upload-Limits fuer PHP setzen

Damit 300MB Uploads funktionieren, muessen deine PHP-Limits passen (z. B. in `php.ini`):

- `upload_max_filesize = 300M`
- `post_max_size = 320M`
- `max_execution_time` ausreichend hoch

Hinweis zur Videodauer:
- Clientseitig wird auf max. 3 Minuten geprueft.
- Serverseitig wird zusaetzlich geprueft, wenn `ffprobe` verfuegbar ist.

## 4) Backend starten

Im Projektordner:

```bash
php -S localhost:8000 -t backend
```

## 5) Frontend starten

```bash
cd frontend
npm install
npm run dev
```

Dann im Browser oeffnen:

- `http://localhost:5173`

## 6) SFTP Deploy

Deployment-Skript ausfuehren:

```bash
powershell -ExecutionPolicy Bypass -File .\deployment\deploy-sftp.ps1
```

Optional ohne neuen Frontend-Build:

```bash
powershell -ExecutionPolicy Bypass -File .\deployment\deploy-sftp.ps1 -SkipBuild
```

## API-Uebersicht

- `GET /api/login.php` Google OAuth starten
- `GET /api/callback.php` OAuth Callback
- `GET /api/me.php` Session/User-Status
- `POST /api/logout.php` Logout
- `GET /api/form.php` Formular laden
- `POST /api/form.php` Formular speichern
- `POST /api/video-upload.php` Video speichern/ersetzen
- `POST /api/video-delete.php` Video loeschen
- `GET /api/video.php` Eigenes gespeichertes Video laden

## Sicherheitshinweis

Das ist ein Testprojekt. Fuer Produktion zusaetzlich umsetzen:

- HTTPS + sichere Cookie-/Session-Settings
- Rotieren von OAuth- und DB-Secrets bei Leaks
- Logging/Monitoring
- Striktes Error-Handling ohne interne Details
- Optional: ID-Token-Signatur/Claims explizit validieren
