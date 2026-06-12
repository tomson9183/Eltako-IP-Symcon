# Eltako IP Konfigurator

Sucht Eltako WLAN-Geräte (Baureihe 62-IP / 64-IPM) im lokalen Netzwerk, meldet sich mit
dem Eltako-Code (PoP) an und legt für jeden steuerbaren Kanal automatisch die passende
Instanz an (Schaltaktor, Dimmer, Rollladen).

## Ablauf

1. **Netzwerk durchsuchen** – das Subnetz wird vorgeschlagen; gefundene Geräte werden
   gelistet. IP markieren und übernehmen.
2. **Anmelden** – Eltako-Code (PoP) eingeben, *Anmelden & Kanäle laden*.
3. **Kanäle** – über *Erstellen* die jeweilige Geräteinstanz anlegen.

## Funktionen (PHP)

| Funktion | Beschreibung |
|---|---|
| `ELTAKOIPCFG_ScanNetwork(int $InstanzID)` | Durchsucht das Subnetz nach Geräten. |
| `ELTAKOIPCFG_Login(int $InstanzID, string $Host, string $PoP)` | Meldet sich an und speichert den API-Key. |
