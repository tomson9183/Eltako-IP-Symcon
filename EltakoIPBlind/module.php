<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/EltakoIPClient.php';

/**
 * Eltako IP Rollladen-/Jalousieaktor (ESB62NP-IP / ESB64NP-IPM).
 *
 * Funktion: targetPosition (0-100), optional targetTilt (0-100, nur ESB64-IPM)
 * Info:     currentPosition, currentTilt, power
 *
 * Anzeige in "offen"-Logik: 100 % = offen/oben, 0 % = geschlossen/unten
 * (entspricht Apple Home). Über "Richtung umkehren" anpassbar, falls das Gerät
 * andersherum zählt.
 *
 * Visualisierung: eigene interaktive HTML-Kachel mit Fenster-Animation,
 * Auf/Stop/Zu-Tasten und Positions-Slider.
 */
class EltakoIPBlind extends IPSModule
{
    use EltakoIPClient;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyString('APIKey', '');
        $this->RegisterPropertyString('DeviceGuid', '');
        $this->RegisterPropertyBoolean('HasTilt', false);
        $this->RegisterPropertyBoolean('InvertDirection', false);
        $this->RegisterPropertyString('VisuTheme', 'auto');
        // Hintergrund der Kachel: auto / daytime (Tageszeit-Aussicht) / photo / window.
        $this->RegisterPropertyString('BackgroundMode', 'auto');
        // Eigenes Foto (Base64) als Aussicht hinter dem Rollladen.
        $this->RegisterPropertyString('BackgroundImage', '');
        $this->RegisterPropertyInteger('UpdateInterval', 0);

        // Auf/Stop/Zu als nebeneinanderliegende Tasten (moderne Presentation-API).
        $options = json_encode([
            ['Value' => 0, 'Caption' => 'Auf',  'IconActive' => false, 'IconValue' => '', 'Color' => -1],
            ['Value' => 1, 'Caption' => 'Stop', 'IconActive' => false, 'IconValue' => '', 'Color' => -1],
            ['Value' => 2, 'Caption' => 'Zu',   'IconActive' => false, 'IconValue' => '', 'Color' => -1],
        ]);
        $this->RegisterVariableInteger('Move', $this->Translate('Blind'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS'      => $options,
            'LAYOUT'       => 1, // 1 = Reihe (Tasten nebeneinander)
            'DISPLAY'      => 0, // 0 = Beschriftung
        ], 10);
        $this->EnableAction('Move');

        $this->RegisterVariableInteger('Position', $this->Translate('Position'), '~Intensity.100', 20);
        $this->EnableAction('Position');

        $this->RegisterTimer('Update', 0, 'ELTAKOIPBL_Update($_IPS[\'TARGET\']);');

        // Eigene HTML-Kachel aktivieren.
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->EltakoEnsureProfiles();

        $hasTilt = $this->ReadPropertyBoolean('HasTilt');
        $this->MaintainVariable('Tilt', $this->Translate('Slat position'), VARIABLETYPE_INTEGER, '~Intensity.100', 30, $hasTilt);
        if ($hasTilt) {
            $this->EnableAction('Tilt');
        }

        $this->MaintainVariable('Power', $this->Translate('Power'), VARIABLETYPE_FLOAT, '~Watt', 40, true);

        // Schöne Profile/Icons setzen.
        $positionID = $this->GetIDForIdent('Position');
        if ($positionID) {
            @IPS_SetVariableCustomProfile($positionID, 'ELTAKOIP.Shutter');
        }
        $tiltID = @$this->GetIDForIdent('Tilt');
        if ($tiltID) {
            @IPS_SetVariableCustomProfile($tiltID, 'ELTAKOIP.Shutter');
        }

        $interval = $this->ReadPropertyInteger('UpdateInterval');
        $this->SetTimerInterval('Update', $interval > 0 ? $interval * 1000 : 0);

        $this->ValidateConfiguration();
    }

    public function GetConfigurationForm()
    {
        return json_encode(json_decode(file_get_contents(__DIR__ . '/form.json'), true));
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Position':
                $this->SetPosition((int) $Value);
                return;
            case 'Move':
                $v = (int) $Value;
                if ($v === 0) {            // Auf  -> offen (100 %)
                    $this->SetPosition(100);
                } elseif ($v === 2) {      // Zu   -> geschlossen (0 %)
                    $this->SetPosition(0);
                } else {                   // Stop
                    $this->Stop();
                }
                return;
            case 'Tilt':
                $this->SetTilt((int) $Value);
                return;
        }
        throw new Exception('Invalid Ident: ' . $Ident);
    }

    /**
     * Fährt den Rollladen auf eine Zielposition (0 % = zu/unten, 100 % = auf/oben).
     */
    public function SetPosition(int $Value): bool
    {
        if (!$this->ValidateConfiguration()) {
            return false;
        }

        $Value  = max(0, min(100, $Value));
        $device = $this->ToDevicePosition($Value);

        $ok = $this->EltakoSetNumber(
            $this->ReadPropertyString('Host'),
            $this->ReadPropertyString('APIKey'),
            $this->ReadPropertyString('DeviceGuid'),
            'targetPosition',
            (float) $device
        );

        if ($ok) {
            $this->ReflectPosition($Value);
            $this->SetStatus(102);
        } else {
            $this->SetStatus(201);
        }

        return $ok;
    }

    /**
     * Hält den Rollladen an: aktuelle Ist-Position lesen und als Ziel setzen.
     */
    public function Stop(): bool
    {
        if (!$this->ValidateConfiguration()) {
            return false;
        }

        $device = $this->EltakoGetDevice(
            $this->ReadPropertyString('Host'),
            $this->ReadPropertyString('APIKey'),
            $this->ReadPropertyString('DeviceGuid')
        );

        $raw = null;
        if ($device !== null) {
            $raw = $this->EltakoFindValue($device['infos'] ?? [], 'currentPosition', null);
            if ($raw === null) {
                $raw = $this->EltakoFindValue($device['functions'] ?? [], 'targetPosition', null);
            }
        }
        if ($raw === null) {
            $raw = $this->ToDevicePosition((int) $this->GetValue('Position'));
        }

        $ok = $this->EltakoSetNumber(
            $this->ReadPropertyString('Host'),
            $this->ReadPropertyString('APIKey'),
            $this->ReadPropertyString('DeviceGuid'),
            'targetPosition',
            (float) $raw
        );

        $this->ReflectPosition($this->FromDevicePosition((int) round((float) $raw)));
        $this->SetStatus($ok ? 102 : 201);

        return $ok;
    }

    /**
     * Setzt die Lamellenposition (0-100). Nur bei Geräten mit Lamellensteuerung (ESB64-IPM).
     */
    public function SetTilt(int $Value): bool
    {
        if (!$this->ReadPropertyBoolean('HasTilt') || !$this->ValidateConfiguration()) {
            return false;
        }

        $Value = max(0, min(100, $Value));

        $ok = $this->EltakoSetNumber(
            $this->ReadPropertyString('Host'),
            $this->ReadPropertyString('APIKey'),
            $this->ReadPropertyString('DeviceGuid'),
            'targetTilt',
            (float) $Value
        );

        if ($ok && @$this->GetIDForIdent('Tilt')) {
            $this->SetValue('Tilt', $Value);
            $this->SetStatus(102);
        } elseif (!$ok) {
            $this->SetStatus(201);
        }

        return $ok;
    }

    public function Update(): void
    {
        if (!$this->ValidateConfiguration()) {
            return;
        }

        $device = $this->EltakoGetDevice(
            $this->ReadPropertyString('Host'),
            $this->ReadPropertyString('APIKey'),
            $this->ReadPropertyString('DeviceGuid')
        );

        if ($device === null) {
            $this->SetStatus(201);
            return;
        }

        $raw = $this->EltakoFindValue($device['infos'] ?? [], 'currentPosition', null);
        if ($raw === null) {
            $raw = $this->EltakoFindValue($device['functions'] ?? [], 'targetPosition', null);
        }
        if ($raw !== null) {
            $this->ReflectPosition($this->FromDevicePosition((int) round((float) $raw)));
        }

        if ($this->ReadPropertyBoolean('HasTilt') && @$this->GetIDForIdent('Tilt')) {
            $tilt = $this->EltakoFindValue($device['infos'] ?? [], 'currentTilt', null);
            if ($tilt === null) {
                $tilt = $this->EltakoFindValue($device['functions'] ?? [], 'targetTilt', null);
            }
            if ($tilt !== null) {
                $this->SetValue('Tilt', (int) round((float) $tilt));
            }
        }

        $power = $this->EltakoFindValue($device['infos'] ?? [], 'power', null);
        if ($power !== null && @$this->GetIDForIdent('Power')) {
            $this->SetValue('Power', (float) $power);
        }

        $this->SetStatus(102);
    }

    // ---- Interaktive HTML-Kachel -----------------------------------------

    public function GetVisualizationTile()
    {
        // Beim Öffnen frische Werte holen.
        $this->Update();

        $html = file_get_contents(__DIR__ . '/visualization.html');
        $html = strtr($html, [
            '__THEME__' => $this->VisuThemeClass(),
            '__NAME__'  => htmlspecialchars(IPS_GetName($this->InstanceID), ENT_QUOTES),
            '__PHOTO__' => $this->VisuBackgroundCss(),
            '__MODE__'  => $this->ResolveBackgroundMode(),
        ]);

        return $html . '<script>try{handleMessage(' . json_encode($this->VisuPayload()) . ');}catch(e){}</script>';
    }

    /**
     * Effektiver Hintergrund-Modus der Kachel:
     *  - auto    : Foto falls vorhanden, sonst Tageszeit-Aussicht
     *  - daytime : gezeichnete Aussicht, Himmel wechselt nach Uhrzeit
     *  - photo   : hochgeladenes Foto
     *  - window  : statisch gezeichnetes Fenster (Himmel neutral)
     */
    private function ResolveBackgroundMode(): string
    {
        $mode = $this->ReadPropertyString('BackgroundMode');
        $hasPhoto = trim($this->ReadPropertyString('BackgroundImage')) !== '';
        if ($mode === 'auto') {
            return $hasPhoto ? 'photo' : 'daytime';
        }
        if ($mode === 'photo' && !$hasPhoto) {
            return 'daytime';
        }
        return $mode;
    }

    /**
     * Liefert den CSS-Hintergrund für das Fenster: entweder das hochgeladene Foto
     * (als Data-URI) oder einen gezeichneten Himmel als Fallback.
     */
    private function VisuBackgroundCss(): string
    {
        $img = trim($this->ReadPropertyString('BackgroundImage'));
        if ($img === '') {
            return 'linear-gradient(180deg,#bfe3ff 0%,#eaf6ff 60%,#eaf6e0 100%)';
        }

        // Bereits vollständige Data-URI?
        if (stripos($img, 'data:') === 0) {
            return "url('" . $img . "')";
        }

        // MIME anhand der Base64-Signatur bestimmen.
        $mime = 'image/jpeg';
        if (strpos($img, 'iVBOR') === 0) {
            $mime = 'image/png';
        } elseif (strpos($img, 'R0lGOD') === 0) {
            $mime = 'image/gif';
        } elseif (strpos($img, 'UklGR') === 0) {
            $mime = 'image/webp';
        }

        return "url('data:" . $mime . ";base64," . $img . "')";
    }

    private function VisuThemeClass(): string
    {
        $t = $this->ReadPropertyString('VisuTheme');
        return ($t === 'light') ? 'th-light' : (($t === 'dark') ? 'th-dark' : 'th-auto');
    }

    private function VisuPayload(): string
    {
        $level = (@$this->GetIDForIdent('Position') !== false) ? (int) $this->GetValue('Position') : 0;
        return json_encode(['level' => $level]);
    }

    // ---- interne Helfer ---------------------------------------------------

    /** Setzt Anzeige-Position (Slider) und spiegelt sie in die Auf/Stop/Zu-Tasten + Kachel. */
    private function ReflectPosition(int $level): void
    {
        $level = max(0, min(100, $level));
        if (@$this->GetIDForIdent('Position') !== false) {
            $this->SetValue('Position', $level);
        }
        if (@$this->GetIDForIdent('Move') !== false) {
            // 100 = Auf(0), 0 = Zu(2), dazwischen = Stop(1)
            $m = ($level >= 99) ? 0 : (($level <= 1) ? 2 : 1);
            if ((int) $this->GetValue('Move') !== $m) {
                $this->SetValue('Move', $m);
            }
        }
        $this->UpdateVisualizationValue($this->VisuPayload());
    }

    /** Logische Position (100=offen) -> Geräte-Wert (targetPosition). */
    private function ToDevicePosition(int $level): int
    {
        return $this->ReadPropertyBoolean('InvertDirection') ? (100 - $level) : $level;
    }

    /** Geräte-Wert -> logische Position (100=offen). */
    private function FromDevicePosition(int $raw): int
    {
        return $this->ReadPropertyBoolean('InvertDirection') ? (100 - $raw) : $raw;
    }

    private function ValidateConfiguration(): bool
    {
        if ($this->ReadPropertyString('Host') === ''
            || $this->ReadPropertyString('APIKey') === ''
            || $this->ReadPropertyString('DeviceGuid') === '') {
            $this->SetStatus(104);
            return false;
        }
        return true;
    }
}
