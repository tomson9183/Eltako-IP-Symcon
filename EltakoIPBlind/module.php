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
        // Mitlauf-Timer: lässt die Anzeige während der Fahrt synchron zum Motor laufen.
        $this->RegisterTimer('Travel', 0, 'ELTAKOIPBL_TravelTick($_IPS[\'TARGET\']);');

        // Fahrzeit (ms) für 0->100 %, aus den Geräteeinstellungen (Fallback 16 s).
        $this->RegisterAttributeInteger('RuntimeMs', 16000);
        // Mitlauf-Status.
        $this->RegisterAttributeInteger('MoveFrom', 0);
        $this->RegisterAttributeInteger('MoveTo', 0);
        $this->RegisterAttributeString('MoveStart', '');
        $this->RegisterAttributeBoolean('Traveling', false);

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

        // Travel-Timer bei Konfigurationsänderung stoppen.
        $this->SetTimerInterval('Travel', 0);

        // Fahrzeit aus den Geräteeinstellungen übernehmen (best effort, lokal).
        if ($this->ValidateConfiguration()) {
            $device = $this->EltakoGetDevice(
                $this->ReadPropertyString('Host'),
                $this->ReadPropertyString('APIKey'),
                $this->ReadPropertyString('DeviceGuid')
            );
            if ($device !== null) {
                $rt = $this->EltakoFindValue($device['settings'] ?? [], 'runtime', null);
                if ($rt === null) {
                    $rt = $this->EltakoFindValue($device['infos'] ?? [], 'maxRuntime', null);
                }
                if ($rt !== null && (int) $rt > 0) {
                    $this->WriteAttributeInteger('RuntimeMs', (int) $rt);
                }
            }
        }
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
            // Anzeige NICHT sofort auf das Ziel springen lassen, sondern synchron zur
            // physischen Fahrzeit mitlaufen lassen (Bestätigung per Rückmeldung am Ende).
            $this->StartTravel($Value);
            $this->SetStatus(102);
        } else {
            $this->SetStatus(201);
        }

        return $ok;
    }

    /**
     * Hält den Rollladen an: Mitlauf stoppen, geschätzte Ist-Position als Ziel setzen.
     */
    public function Stop(): bool
    {
        if (!$this->ValidateConfiguration()) {
            return false;
        }

        // Mitlauf beenden und aktuelle (geschätzte) Position ermitteln.
        $this->SetTimerInterval('Travel', 0);
        $this->WriteAttributeBoolean('Traveling', false);
        $est = $this->EstimatePosition();
        $this->WriteAttributeString('MoveStart', '');

        $ok = $this->EltakoSetNumber(
            $this->ReadPropertyString('Host'),
            $this->ReadPropertyString('APIKey'),
            $this->ReadPropertyString('DeviceGuid'),
            'targetPosition',
            (float) $this->ToDevicePosition($est)
        );

        $this->ReflectPosition($est);
        $this->SetStatus($ok ? 102 : 201);

        return $ok;
    }

    // ---- Mitlauf (Anzeige fährt synchron zum Motor) -----------------------

    /**
     * Startet den Anzeige-Mitlauf zur Zielposition. Dauer = Fahrzeit des Geräts
     * (runtime) * Weg/100. Am Ende wird per Rückmeldung (currentPosition) bestätigt.
     */
    private function StartTravel(int $target): void
    {
        $target = max(0, min(100, $target));
        $from   = (int) $this->GetValue('Position');

        $this->WriteAttributeInteger('MoveFrom', $from);
        $this->WriteAttributeInteger('MoveTo', $target);
        $this->WriteAttributeString('MoveStart', (string) microtime(true));

        // Während der Fahrt die Tasten auf "Stop" (=fährt) spiegeln.
        if (@$this->GetIDForIdent('Move') !== false && (int) $this->GetValue('Move') !== 1) {
            $this->SetValue('Move', 1);
        }

        $rt = $this->ReadAttributeInteger('RuntimeMs');
        if ($rt <= 0) {
            $rt = 16000;
        }
        $dur = (int) round($rt * abs($target - $from) / 100);

        if ($dur < 400 || $from === $target) {
            // Kein nennenswerter Weg -> direkt setzen.
            $this->SetTimerInterval('Travel', 0);
            $this->WriteAttributeBoolean('Traveling', false);
            $this->ReflectPosition($target);
            return;
        }

        $this->WriteAttributeBoolean('Traveling', true);
        $this->SetTimerInterval('Travel', 300);
    }

    /**
     * Zeitbasierte Schätzung der aktuellen Position (während der Fahrt).
     */
    private function EstimatePosition(): int
    {
        $from  = (int) $this->ReadAttributeInteger('MoveFrom');
        $to    = (int) $this->ReadAttributeInteger('MoveTo');
        $start = (float) $this->ReadAttributeString('MoveStart');
        $rt    = $this->ReadAttributeInteger('RuntimeMs');
        if ($rt <= 0) {
            $rt = 16000;
        }
        $dur = $rt * abs($to - $from) / 100;
        if ($dur <= 0 || $start <= 0.0) {
            return $to;
        }
        $elapsed = (microtime(true) - $start) * 1000.0;
        $frac = $elapsed / $dur;
        if ($frac > 1) {
            $frac = 1;
        }
        if ($frac < 0) {
            $frac = 0;
        }
        return (int) round($from + ($to - $from) * $frac);
    }

    /**
     * Timer-Tick: schiebt die Anzeige gleichmäßig zur Zielposition (synchron zur Fahrzeit).
     */
    public function TravelTick(): void
    {
        $cur = $this->EstimatePosition();
        if (@$this->GetIDForIdent('Position') !== false) {
            $this->SetValue('Position', $cur);
        }
        $this->UpdateVisualizationValue($this->VisuPayload());

        $from  = (int) $this->ReadAttributeInteger('MoveFrom');
        $to    = (int) $this->ReadAttributeInteger('MoveTo');
        $start = (float) $this->ReadAttributeString('MoveStart');
        $rt    = $this->ReadAttributeInteger('RuntimeMs');
        if ($rt <= 0) {
            $rt = 16000;
        }
        $dur = $rt * abs($to - $from) / 100;

        if (($start > 0.0 && (microtime(true) - $start) * 1000.0 >= $dur) || $cur === $to) {
            $this->FinishTravel();
        }
    }

    /**
     * Fahrt beenden, Zielposition setzen und mit der echten Rückmeldung bestätigen.
     */
    private function FinishTravel(): void
    {
        $this->SetTimerInterval('Travel', 0);
        $this->WriteAttributeBoolean('Traveling', false);
        $this->ReflectPosition((int) $this->ReadAttributeInteger('MoveTo'));
        $this->WriteAttributeString('MoveStart', '');
        // Echte Ist-Position vom Gerät holen und Anzeige bestätigen/korrigieren.
        $this->Update();
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

        // Während einer laufenden Anzeige-Fahrt nicht überschreiben.
        if ($this->ReadAttributeBoolean('Traveling')) {
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
