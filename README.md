# LightHUB – IP-Symcon-Modul

Bindet [LightHUB](https://github.com/facegmbh/LightHUB) (FACE GmbH) in IP-Symcon ein: jeder
LightHUB-Player wird ein Gerät mit Variablen, KNX-Anbindung und skriptbaren Funktionen für
Automationen. LightHUB selbst spielt aufgezeichnete Art-Net-Lichtstimmungen (z. B. aus Madrix)
autark ab und läuft auf einem Catan C1, einer Synology oder als Windows-Dienst.

> **Umbenannt 09/2026:** vormals „Art-Net DMX Player“ (Repo `Symcon-ArtNetPlayer`, GitHub leitet
> weiter). **GUIDs, Klassennamen und die Funktions-Präfixe `ANP_`/`ANPP_` sind unverändert** –
> bestehende Instanzen, Skripte und Ereignisse laufen nach dem Update ohne Anpassung weiter.

## Voraussetzungen

- IP-Symcon ab 6.0
- LightHUB (mit Anmeldung) **oder** der Vorgänger Art-Net DMX Player 1.x (ohne Anmeldung)

## Installation / Update

*Kern-Instanzen → Modules* bzw. Module-Verwaltung: `https://github.com/JLDFACE/Symcon-LightHUB`
hinzufügen. Update: `MC_RevertModule` → `MC_UpdateModule` → `MC_ReloadModule`.

## Aufbau

```
Madrix / Art-Net-Quelle ──Aufnahme──▶ LightHUB ──Art-Net / RS485──▶ LED-Nodes, Leuchten
                                        ▲
                                        │ REST (HTTP, Basic Auth)
IP-Symcon: LightHUB Controller ──┬── LightHUB Player (je Player eine Instanz)
                                 └── …          ▲ KNX · WebFront · Skripte · Melder
```

| Modul | Präfix | Rolle |
|---|---|---|
| LightHUB **Controller** | `ANP` | Verbindung zu LightHUB (Host, Port, Anmeldung), Status-Abfrage, Player-Discovery |
| LightHUB **Player** | `ANPP` | je LightHUB-Player eine Geräte-Instanz mit Variablen, KNX, Funktionen |

GUIDs: Controller `{AE7C1A00-0001-47AE-B000-0000000000C1}` · Player
`{AE7C1A00-0002-47AE-B000-0000000000D2}` · Datenschnittstelle `{AE7C1A00-0003-47AE-B000-0000000000E3}`.

LightHUB läuft autark weiter, auch wenn Symcon neu startet; Aufnahmen und Einstellungen liegen in LightHUB.

## Einrichtung

1. **Konto in LightHUB anlegen** (Web-Oberfläche → *Benutzer*), z. B. `symcon`, mit Rolle
   **Admin** – siehe [Rechte](#rechte-in-lighthub).
2. Instanz **LightHUB Controller** anlegen: Host (IP von LightHUB; läuft LightHUB auf demselben
   Catan: `127.0.0.1`), Port (Standard 8000), **Benutzer/Passwort**, Abfrage-Intervall (z. B. 3 s).
   Für den alten Art-Net DMX Player 1.x Benutzer leer lassen – eingetragene Zugangsdaten stören
   ihn aber nicht, das Modul kann also **vor** einem Umstieg aktualisiert werden.
3. Im Controller **„Fehlende Player-Instanzen anlegen“** – je LightHUB-Player eine verbundene Instanz.
4. In jeder Player-Instanz **Player-ID**, **On-/Off-Programm**, Fade-Zeiten und optional KNX setzen.

## Rechte in LightHUB

Das Modul ruft diese LightHUB-Funktionen auf:

| Aufruf | Wofür | Rolle in LightHUB |
|---|---|---|
| `GET /status`, `GET /player/{id}/programs` | Zustand, Programmliste | User |
| `POST /player/{id}/play`, `play_off`, `on`, `off`, `stop`, `pause` | Szenen, Ein/Aus | User |
| `POST /player/{id}/master`, `/group` | Helligkeit, Gruppen-Dimmer | User |
| `POST /player/{id}/config` | Fade-Zeiten der Instanz übertragen, Loop schalten | **Admin** |

Das Konto für Symcon daher mit Rolle **Admin** anlegen. Mit einem *User*-Konto funktioniert alles
außer dem Übertragen der Fade-Zeiten und dem Loop-Schalter.

## Variablen der Player-Instanz

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

## KNX-Anbindung (je Instanz)

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

## Funktionsreferenz

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

## Automations-Rezepte

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

## Versionen

| Build | Änderung |
|---|---|
| 103 | Umbenennung in LightHUB (Anzeige); GUIDs/Präfixe unverändert |
| 102 | Anmeldung an LightHUB (Benutzer/Passwort im Controller), Status „Anmeldung abgelehnt“ |
| 101 | Art-Net DMX Player 1.x: Controller, Player, KNX, Gruppen-Dimmer, Aus-Szenen |

## Fehlersuche

| Symptom | Lösung |
|---|---|
| Controller: „Anmeldung abgelehnt" | Benutzer/Passwort des LightHUB-Kontos prüfen; nach 10 Fehlversuchen ist die IP 15 min gesperrt |
| „Datenfluss inkompatibel" | Controller-Verbindung prüfen; Instanzen über den Controller-Button anlegen |
| Licht geht nicht aus | Off-Programm sollte schwarz enden; sonst greift der Blackout-Tail; „einfach aus" = `ANPP_TurnOff` |
| Bewegung schaltet doppelt | Melder ist gleichzeitig an „Schalten" *und* in einem Ereignis — einen entfernen |
| Tagsüber geht nichts an | Lux über Tag-Schwelle (beabsichtigt) — Schwelle anpassen |
| Kein Art-Net am Node | Ziel-IP prüfen (Broadcast vs. Node-IP), Host-Networking aktiv? |

---

© FACE GmbH · Am Bahnhof 5 · 48455 Bad Bentheim · info@face-gmbh.com · face-gmbh.com
