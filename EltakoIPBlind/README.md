# Eltako IP Rollladenaktor

Steuert Eltako IP-Rollladen-/Jalousieaktoren (**ESB62NP-IP**, **ESB64NP-IPM**) lokal per HTTPS.

Enthält eine **interaktive HTML-Kachel** mit animiertem Fenster, **Auf/Stop/Zu-Tasten** und
Positions-Slider (Design hell/dunkel/automatisch wählbar).

## Variablen

| Variable | Typ | Beschreibung |
|---|---|---|
| `Move` | Integer (Auf/Stop/Zu) | Tasten nebeneinander (Presentation-API) |
| `Position` | Integer (0–100 %) | 100 % = offen/oben, 0 % = geschlossen/unten |
| `Tilt` | Integer (0–100 %) | Lamellenposition (nur ESB64-IPM, optional) |
| `Power` | Float (~Watt) | Aktuelle Leistung (`power`) |

## Funktionen (PHP)

| Funktion | Beschreibung |
|---|---|
| `ELTAKOIPBL_SetPosition(int $InstanzID, int $Value)` | Fährt auf eine Zielposition (0–100 %, 100 = offen). |
| `ELTAKOIPBL_Stop(int $InstanzID)` | Hält den Rollladen an (aktuelle Position als Ziel). |
| `ELTAKOIPBL_SetTilt(int $InstanzID, int $Value)` | Setzt die Lamellenposition (0–100 %). |
| `ELTAKOIPBL_Update(int $InstanzID)` | Liest Position/Lamelle vom Gerät. |

## Konfiguration

| Feld | Beschreibung |
|---|---|
| IP-Adresse / Hostname | Adresse des Geräts |
| API-Key | Zugriffstoken (über den Konfigurator erzeugbar) |
| Device-GUID | GUID des Kanals |
| Lamellensteuerung vorhanden | Bei ESB64-IPM aktivieren |
| Richtung umkehren | Falls Auf/Zu vertauscht sind |
| Kachel-Design | Automatisch / Hell / Dunkel |
| Aktualisierungsintervall | Polling in Sekunden (0 = aus) |

## Visualisierung

Die Instanz stellt eine eigene Kachel bereit (HTML-SDK). In der **Kachel-Visualisierung**
einfach die Instanz hinzufügen – Fenster mit Rollladen-Animation, Auf/Stop/Zu und Slider.
