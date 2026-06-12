# Eltako IP Rollladenaktor

Steuert Eltako IP-Rollladen-/Jalousieaktoren (**ESB62NP-IP**, **ESB64NP-IPM**) lokal per HTTPS.

## Variablen

| Variable | Typ | Beschreibung |
|---|---|---|
| `Position` | Integer (~Intensity.100) | Position 0 % (offen/oben) … 100 % (geschlossen/unten) |
| `Tilt` | Integer (~Intensity.100) | Lamellenposition (nur ESB64-IPM, optional) |
| `Power` | Float (~Watt) | Aktuelle Leistung (`power`) |

## Funktionen (PHP)

| Funktion | Beschreibung |
|---|---|
| `ELTAKOIPBL_SetPosition(int $InstanzID, int $Value)` | Fährt auf eine Zielposition (0–100 %). |
| `ELTAKOIPBL_SetTilt(int $InstanzID, int $Value)` | Setzt die Lamellenposition (0–100 %). |
| `ELTAKOIPBL_Update(int $InstanzID)` | Liest Position/Lamelle vom Gerät. |

## Konfiguration

| Feld | Beschreibung |
|---|---|
| IP-Adresse / Hostname | Adresse des Geräts |
| API-Key | Zugriffstoken (über den Konfigurator erzeugbar) |
| Device-GUID | GUID des Kanals |
| Lamellensteuerung vorhanden | Bei ESB64-IPM aktivieren |
| Aktualisierungsintervall | Polling in Sekunden (0 = aus) |
