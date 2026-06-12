# Eltako IP – IP-Symcon Modul

Lokale Steuerung der **Eltako WLAN-Geräte (Baureihe 62-IP / 64-IPM)** direkt über das
lokale Netzwerk – **ohne Cloud, ohne Gateway**. Jedes Gerät wird per HTTPS-REST-API
direkt angesprochen.

Enthält einen **Netzwerk-Konfigurator**, der das lokale Netz nach Eltako IP-Geräten
durchsucht, sich am gewählten Gerät anmeldet und für jeden steuerbaren Kanal automatisch
die passende Instanz anlegt.

> Kompatibel mit **IP-Symcon 9.0**.

## Unterstützte Geräte

| Modell | Typ | Modul | Steuerung |
|---|---|---|---|
| ESR62NP-IP, ESR64NP-IPM | Schaltaktor | `EltakoIPSwitch` | Ein/Aus (`relay`), Leistung (W) |
| EUD62NPN-IP, EUD64NPN-IPM | Dimmer | `EltakoIPDimmer` | Helligkeit 0–100 % (`targetBrightness`) |
| ESB62NP-IP | Rollladenaktor | `EltakoIPBlind` | Position 0–100 % (`targetPosition`) |
| ESB64NP-IPM | Rollladenaktor mit Lamelle | `EltakoIPBlind` | Position + Lamelle (`targetPosition`, `targetTilt`) |

> Die WLAN-Geräte sind Apple-Home-zertifiziert und „built for Matter“. Dieses Modul
> nutzt die offene lokale REST-API der Geräte (siehe unten) und ist unabhängig von
> HomeKit oder der ELTAKO-Connect-App.

## Enthaltene Module

- **EltakoIPConfigurator** – Netzwerksuche, Anmeldung, automatisches Anlegen der Geräte.
- **EltakoIPSwitch** – Schaltaktor.
- **EltakoIPDimmer** – Dimmer.
- **EltakoIPBlind** – Rollladen-/Jalousieaktor (optional mit Lamellensteuerung).

## Installation

1. In IP-Symcon den **Module-Store** öffnen → **„Modul über URL hinzufügen“**.
2. URL eintragen: `https://github.com/tomson9183/Eltako-IP-Symcon`
3. Nach der Installation eine Instanz vom Typ **„Eltako IP Konfigurator“** anlegen.

## Einrichtung mit dem Konfigurator

1. **Netzwerk durchsuchen** – das Subnetz wird automatisch vorgeschlagen (z. B.
   `192.168.1.`). Auf *„Netzwerk durchsuchen“* klicken; gefundene Geräte erscheinen
   in der Liste. Gewünschtes Gerät markieren und *„Markierte IP übernehmen“* klicken.
2. **Anmelden** – den **Eltako-Code (PoP)** eingeben. Dieser ist auf dem Gerät bzw.
   dessen Beipackzettel aufgedruckt (Proof-of-Possession). Auf *„Anmelden & Kanäle
   laden“* klicken.
   - Bei Erfolg wird ein **API-Key** erzeugt und gespeichert. Hinweis: Pro Gerät sind
     max. 10 API-Keys möglich; wird ein 11. erzeugt, verfällt der älteste.
3. **Kanäle anlegen** – in der Kanäle-Tabelle erscheint jeder steuerbare Kanal mit dem
   passenden Modultyp. Über *„Erstellen“* wird die jeweilige Instanz inkl. IP, API-Key
   und Device-GUID automatisch angelegt.

### Manuelle Einrichtung (ohne Konfigurator)

Jedes Geräte-Modul kann auch direkt angelegt werden. Benötigt werden:
- **IP-Adresse / Hostname** des Geräts
- **API-Key** (über den Konfigurator oder `POST /api/v0/login` erzeugbar)
- **Device-GUID** des Kanals (aus `GET /api/v0/devices`)

## Lokale REST-API der Geräte

Grundlage ist die offizielle [Eltako OpenAPI-Spezifikation](https://github.com/Eltako/eltako-openapi-specifications)
(Series 64-IPM / 62-IP).

| Zweck | Methode & Pfad |
|---|---|
| Discovery (ohne Auth) | `GET https://{ip}/.well-known/eltako/devices` |
| Login | `POST https://{ip}/api/v0/login` mit `{"user":"admin","password":"<PoP>"}` → `{"apiKey":"…"}` |
| Geräte/Kanäle auflisten | `GET https://{ip}/api/v0/devices` |
| Steuern | `PUT https://{ip}/api/v0/devices/{guid}/functions` |

Authentifizierung über den Header `Authorization: <apiKey>`. Die Geräte verwenden
selbstsignierte TLS-Zertifikate; die Zertifikatsprüfung wird daher deaktiviert.

**Beispiele für `PUT …/functions`:**

```json
// Schaltaktor ein
[{"identifier":"relay","type":"enumeration","value":"on"}]

// Dimmer auf 80 %
[{"identifier":"targetBrightness","type":"number","value":80}]

// Rollladen auf 100 % (zu)
[{"identifier":"targetPosition","type":"number","value":100}]
```

## Hinweise & Grenzen

- Die REST-API ist von Eltako als „prerelease“ gekennzeichnet und kann sich mit
  Firmware-Updates ändern.
- Zustandsänderungen über andere Wege (z. B. Apple Home, Wandtaster) werden nur beim
  Polling (Aktualisierungsintervall) erkannt – setze dazu ein Intervall in der
  jeweiligen Instanz.
- Die Eingangs-/Tasterkanäle (`WiredInput`, EnOcean-Taster) werden vom Konfigurator
  derzeit übersprungen, da sie keine steuerbaren Ausgänge sind.

## Lizenz

[MIT](LICENSE)

---
*Dieses Projekt steht in keiner Verbindung zur Eltako GmbH. „Eltako“ ist eine Marke der
Eltako GmbH.*
