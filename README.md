# LightHUB – IP-Symcon-Modul

> **Umbenannt (09/2026):** vormals „Art-Net DMX Player“ (Repo `Symcon-ArtNetPlayer`). Das Tool
> heißt jetzt **LightHUB** ([facegmbh/LightHUB](https://github.com/facegmbh/LightHUB)).
> **GUIDs, Klassennamen und die Funktions-Präfixe `ANP_`/`ANPP_` sind unverändert** – bestehende
> Instanzen, Skripte und Ereignisse laufen nach dem Update ohne Anpassung weiter.

Aufnahme & autarke Wiedergabe von Art-Net-/DMX-Lichtstimmungen (aus Madrix o.ä.),
gesteuert aus IP-Symcon, per KNX oder direkt in der Weboberfläche.

Das System besteht aus zwei Teilen:

- **Player-Tool** — kleiner Server (Python/FastAPI) als Docker-Container auf der Synology.
  Empfängt Art-Net, zeichnet auf, rendert die Wiedergabe mit fester Framerate und sendet sie an
  die LED-Nodes. Enthält die komplette Web-UI + REST-API. Verzeichnis: [`artnet-player/`](../artnet-player).
- **IP-Symcon-Modul** (dieses Repo) — bindet jeden Player als Gerät ein: Variablen, KNX und
  skriptbare Funktionen für Automationen.

> **Kernidee:** Ein Player spielt immer *ein* Programm. Innerhalb eines Programms lassen sich per
> **Gruppen** einzelne Adressbereiche (z. B. Stufen & Handlauf) getrennt dimmen. Programme sind
> **strikt pro Player getrennt**.

---

## Architektur

```
Madrix / Art-Net-Quelle
        │  Aufnahme (UDP 6454)
        ▼
Player-Tool  (Synology · Docker · Port 8000)
   .dmxrec speichern · Wiedergabe rendern · HTP-Merge · Art-Net-Ausgabe
        ▲ REST            │ Art-Net
        │                 ▼
IP-Symcon  (Controller + Player-Instanzen)         →  LED-Nodes
        ▲ KNX / WebFront / Skripte
   Taster · Bewegungsmelder · IPSView
```

Das Tool läuft autark weiter, auch wenn Symcon neu startet. Aufnahmen und Einstellungen liegen
persistent im Tool.

---

## Teil A — Das Player-Tool

### Deploy / Update (vom Entwickler-Mac)

```bash
cd "…/Symcon Module"
tar czf - --exclude __pycache__ -C artnet-player . \
  | ssh -i ~/.ssh/artnet_synology Jonny@192.168.10.244 'tar xzf - -C /volume1/docker/artnet-player'
ssh -i ~/.ssh/artnet_synology Jonny@192.168.10.244 \
  'cd /volume1/docker/artnet-player && sudo docker compose up -d --build'
```

- Web-UI & API: `http://192.168.10.244:8000`
- Art-Net-Empfang UDP 6454, Versand Broadcast oder je Player per Node-IP
- Persistenz: `/volume1/docker/artnet-player/data/` (`config.json` + `.dmxrec`) — bleibt bei Updates erhalten
- Host-Networking, `restart: unless-stopped`

### Weboberfläche

- **Player-Karten:** Name + Programmauswahl · *Laden* (zustandserhaltend) · *Loop/Halten* (pro Programm) ·
  Master-Fader (dimmt live) · Ein/Aus · Play/Pause/Stop · Fortschritt · Gruppen-Dimmer · Einstellungen (Admin).
- **Aufnahme (Admin):** Ziel-Player + Name wählen → *Aufnahme starten*. FPS wird vom Eingang übernommen.
  Optionen: Loop-Erkennung (mit Crossfade), „erst bei Input-Änderung", Feedback-Schutz.
- **Monitor & Gruppen (Admin):** Live-Ansicht einer Universe (512 Kanäle, Balken = DMX-Wert, Lineal alle 10).
  Kanäle klicken/ziehen → als Gruppe speichern. Gruppen-Dimmer erscheinen auch auf der Player-Karte.
- **Fußzeile:** Live-Stats (Uptime, CPU, RAM) + FACE-Impressum.
- **Zugriff:** Operator offen (bedienen), Admin per Passwort (Aufnahme/Bibliothek/Einstellungen).

### Wiedergabe & Fades

| Einstellung | Wirkung |
|---|---|
| In-Fade / Out-Fade | Ein-/Ausblenden (global je Player) |
| Master-Fade | feste Dauer je Helligkeitsänderung (DALI-Look) |
| Cue-Fade (pro Programm) | überschreibt die globalen Zeiten; leer = global |

Ausschalten blendet aus, sendet danach kurz Schwarz (Blackout-Tail ~0,7 s) und verstummt dann —
kein Dauerkonflikt mit anderen Art-Net-Quellen.

---

## Teil B — Das IP-Symcon-Modul

Zwei Bausteine:

| Modul | Präfix | Rolle |
|---|---|---|
| LightHUB **Controller** | `ANP` | Verbindung zum Tool (Host/Port), Status-Polling, Player-Discovery |
| LightHUB **Player** | `ANPP` | je Tool-Player eine Geräte-Instanz mit Variablen, KNX, Funktionen |

GUIDs: Controller `{AE7C1A00-0001-47AE-B000-0000000000C1}` ·
Player `{AE7C1A00-0002-47AE-B000-0000000000D2}` ·
Datenschnittstelle `{AE7C1A00-0003-47AE-B000-0000000000E3}`.

### Einrichtung

1. Modul in der Symcon-Module-Verwaltung per GitHub-URL hinzufügen.
2. Instanz **LightHUB Controller** anlegen → Host = Synology-IP, Port = 8000, Poll z. B. 3 s.
   **Ab LightHUB (Nachfolger des Tools):** Benutzer und Passwort eines LightHUB-Kontos
   eintragen – am besten eigenes Konto `symcon` mit Rolle *User* (in LightHUB unter
   *Benutzer* anlegen). Leer lassen für den alten Art-Net DMX Player 1.x ohne Anmeldung;
   eingetragene Zugangsdaten stören den alten Player nicht (er ignoriert sie). So kann das
   Modul **vor** dem Umstieg aktualisiert werden – kein Ausfall beim Wechsel.
3. Im Controller **„Fehlende Player-Instanzen anlegen"** → legt je Tool-Player eine verbundene Instanz an.
4. In jeder Player-Instanz **Player-ID**, **On-/Off-Programm** und optional KNX setzen.

Deploy von Modul-Änderungen läuft **über GitHub**; auf der SymBox:
`MC_RevertModule` → `MC_UpdateModule` → `MC_ReloadModule`.

### Variablen der Player-Instanz

| Variable | Ident | Typ | Funktion |
|---|---|---|---|
| Ein/Aus | `Power` | Bool | schaltet über On-/Off-Programm |
| Master | `Master` | 0–100 % | Gesamthelligkeit |
| Programm | `Program` | Auswahl | Szene direkt wählen |
| Position | `Position` | 0–100 % | Wiedergabe-Fortschritt (Anzeige) |
| Loop | `Loop` | Bool | Loop des aktuellen Programms |
| Gruppe … | `Grp{id}` | 0–100 % | je Gruppe ein Dimmer (auto angelegt/entfernt) |

**Verhalten:**
- *Helligkeit schaltet ein* — Master > 0 % schaltet einen ausgeschalteten Player ein (auch während die Aus-Szene läuft).
- *Aus über die Aus-Szene* — Ausschalten spielt das Off-Programm einmal durch und schaltet am Ende wirklich aus,
  **aber nur von der On-Szene aus**. Auf anderen Szenen (z. B. „TV") → direkt aus.

### KNX-Anbindung (je Instanz)

| Feld | Richtung | DPT | Funktion |
|---|---|---|---|
| Schalten | ein | 1.001 | Ein/Aus über On-/Off-Programm |
| Abs. Dimmen | ein | 5.001 | Master 0–100 % |
| Rel. Dimmen | ein | 3.007 | Heller/dunkler (4-bit, mit Schrittgröße) |
| Status Dimmwert | aus | 5.001 | aktueller Master (0 wenn aus) |
| Status Ein/Aus | aus | 1.001 | läuft ein Programm? |
| Gruppen-KNX | ein/aus | 5.001 | je Gruppe optional Abs-Dimmen + Status (Liste im Formular) |

> Steuerst du einen Melder über ein **Symcon-Ereignis** (Sonderlogik), verknüpfe ihn **nicht** zusätzlich
> mit „Schalten" der Instanz — sonst doppelte Reaktion.

### Funktionsreferenz

`$id` = Instanz-ID der Player-Instanz.

| Funktion | Wirkung |
|---|---|
| `ANPP_On($id)` | Ein über On-Programm |
| `ANPP_Off($id)` | Aus über die Aus-Szene (nur von On-Szene, sonst direkt aus) |
| `ANPP_TurnOff($id)` | sofort aus, ohne Aus-Szene |
| `ANPP_PlayProgram($id, "Name")` | Programm/Szene starten |
| `ANPP_PlayProgramOff($id, "Name")` | Programm als Aus-Szene: einmal durch, dann echtes Aus |
| `ANPP_SetMasterValue($id, 0..100)` | Helligkeit (schaltet bei >0 ein) |
| `ANPP_Stop($id)` | Wiedergabe anhalten |
| `ANPP_Refresh($id)` | Status sofort neu holen |
| `ANP_SyncPlayers($ctrlId)` | fehlende Player-Instanzen anlegen |
| `ANP_GetStatus($ctrlId)` | komplettes `/status` als JSON-String |

### Automations-Rezepte

Bewegungsmelder mit Kontext (Beamer an → TV):

```php
if (GetValueBoolean($_IPS['VARIABLE'])) {              // BWM ausgelöst
    if (GetValueBoolean($beamer)) ANPP_PlayProgram($player, "TV");
    else                         ANPP_On($player);
} else {
    if (GetValueFormatted($progVar) === "TV") ANPP_TurnOff($player);
    else                                      ANPP_Off($player);
}
```

Treppe mit Laufrichtung (je Melder ein Ereignis, hier „unten" → startet „Unten An"):

```php
if (GetValueBoolean($_IPS['VARIABLE'])) {
    $cur = GetValueFormatted($progVar);
    $ausSzene = ($cur === "Unten Aus" || $cur === "Oben Aus");
    if (!GetValueBoolean($powerVar) || $ausSzene) ANPP_PlayProgram($player, "Unten An");
} else {                                               // erst wenn BEIDE Melder ruhig
    if (!GetValueBoolean($bwmU) && !GetValueBoolean($bwmO) && GetValueBoolean($powerVar)) {
        $cur = GetValueFormatted($progVar);
        if     ($cur === "Unten An") ANPP_PlayProgramOff($player, "Unten Aus");
        elseif ($cur === "Oben An")  ANPP_PlayProgramOff($player, "Oben Aus");
    }
}
```

Tag/Nacht + Lux-Schwelle (Einstellwerte als Variablen an der Instanz anlegen):

```php
$istTag = GetValueBoolean($tagNacht);
if ($istTag && GetValueFloat($luxVar) > GetValueFloat($schwelle)) return; // hell genug → nichts
ANPP_PlayProgram($player, "An");
ANPP_SetMasterValue($player, (int)GetValue($istTag ? $vTagHell : $vNachtHell));
```

---

## REST-API (Auszug)

Basis `http://192.168.10.244:8000`. Admin-Endpunkte: Header `X-Admin-Password` (falls gesetzt).

| Methode | Pfad | Zweck |
|---|---|---|
| GET | `/status` | Engine + alle Player (Zustand, Programm, Master, Gruppen) |
| GET | `/stats` | Uptime, CPU-Last, RAM |
| GET | `/monitor/{uni}` | Live-DMX-Werte einer Universe |
| GET | `/player/{id}/programs` | Programme eines Players |
| POST | `/player/{id}/play` | Programm starten `{program}` |
| POST | `/player/{id}/play_off` | als Aus-Szene abspielen, dann echtes Aus |
| POST | `/player/{id}/on` `/off` `/stop` `/pause` | Transport |
| POST | `/player/{id}/master` | Helligkeit `{value}` |
| POST | `/player/{id}/group` | Gruppen-Dimmer `{id,value}` |
| POST | `/player/{id}/groups` *(admin)* | Gruppen-Definitionen |
| POST | `/player/{id}/config` *(admin)* | Player-Einstellungen |
| PATCH | `/player/{id}/programs/{name}` *(admin)* | Cue-Fades / Loop pro Programm |
| DELETE | `/player/{id}/programs/{name}` *(admin)* | Programm löschen |
| POST | `/recorder/start` `/recorder/stop` *(admin)* | Aufnahme steuern |
| GET/PUT | `/config` *(admin)* | globale Einstellungen |

---

## Fehlersuche

| Symptom | Lösung |
|---|---|
| Controller: „Anmeldung abgelehnt" | Benutzer/Passwort des LightHUB-Kontos prüfen; nach 10 Fehlversuchen ist die IP 15 min gesperrt |
| „Datenfluss inkompatibel" | Controller-Verbindung prüfen; Instanzen über den Controller-Button anlegen |
| Licht geht nicht aus | Off-Programm sollte schwarz enden; sonst greift der Blackout-Tail; „einfach aus" = `ANPP_TurnOff` |
| Bewegung schaltet doppelt | Melder ist gleichzeitig an „Schalten" *und* in einem Ereignis — einen entfernen |
| Tagsüber geht nichts an | Lux über Tag-Schwelle (beabsichtigt) — Schwelle anpassen |
| Web zeigt alten Stand | Hart neu laden (`Strg/Cmd + Shift + R`) |
| Kein Art-Net am Node | Ziel-IP prüfen (Broadcast vs. Node-IP), Host-Networking aktiv? |

---

© FACE GmbH · Am Bahnhof 5 · 48455 Bad Bentheim · info@face-gmbh.com · face-gmbh.com
