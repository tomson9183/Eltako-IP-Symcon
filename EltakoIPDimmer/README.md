# Eltako IP Dimmer

Steuert Eltako IP-Dimmer (**EUD62NPN-IP**, **EUD64NPN-IPM**) lokal per HTTPS.

## Variablen

| Variable | Typ | Beschreibung |
|---|---|---|
| `Brightness` | Integer (~Intensity.100) | Helligkeit 0–100 % (`targetBrightness` / `currentBrightness`) |

## Funktionen (PHP)

| Funktion | Beschreibung |
|---|---|
| `ELTAKOIPDIM_SetBrightness(int $InstanzID, int $Value)` | Setzt die Helligkeit (0–100 %, 0 = aus). |
| `ELTAKOIPDIM_Update(int $InstanzID)` | Liest die aktuelle Helligkeit vom Gerät. |

## Konfiguration

| Feld | Beschreibung |
|---|---|
| IP-Adresse / Hostname | Adresse des Geräts |
| API-Key | Zugriffstoken (über den Konfigurator erzeugbar) |
| Device-GUID | GUID des Kanals |
| Aktualisierungsintervall | Polling in Sekunden (0 = aus) |
