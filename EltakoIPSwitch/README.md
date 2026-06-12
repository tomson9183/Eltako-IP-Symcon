# Eltako IP Schaltaktor

Steuert Eltako IP-Schaltaktoren (**ESR62NP-IP**, **ESR64NP-IPM**) lokal per HTTPS.

## Variablen

| Variable | Typ | Beschreibung |
|---|---|---|
| `State` | Boolean (~Switch) | Ein/Aus (`relay`) |
| `Power` | Float (~Watt) | Aktuelle Leistung (`powerChannel1`) |

## Funktionen (PHP)

| Funktion | Beschreibung |
|---|---|
| `ELTAKOIPSW_SetState(int $InstanzID, bool $On)` | Schaltet den Aktor ein/aus. |
| `ELTAKOIPSW_Update(int $InstanzID)` | Liest den aktuellen Zustand vom Gerät. |

## Konfiguration

| Feld | Beschreibung |
|---|---|
| IP-Adresse / Hostname | Adresse des Geräts |
| API-Key | Zugriffstoken (über den Konfigurator erzeugbar) |
| Device-GUID | GUID des Kanals |
| Aktualisierungsintervall | Polling in Sekunden (0 = aus) |
