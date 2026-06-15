<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/EltakoIPClient.php';

/**
 * Eltako IP Schaltaktor (ESR62NP-IP / ESR64NP-IPM).
 *
 * Funktion: relay = on/off
 * Info:     powerChannel1 (W)
 */
class EltakoIPSwitch extends IPSModule
{
    use EltakoIPClient;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyString('APIKey', '');
        $this->RegisterPropertyString('DeviceGuid', '');
        $this->RegisterPropertyInteger('UpdateInterval', 0);
        // Impuls-/Tastermodus: nach dem Einschalten automatisch wieder ausschalten
        // (z. B. Garagentor, Türöffner / Entriegelung).
        $this->RegisterPropertyBoolean('PulseMode', false);
        $this->RegisterPropertyInteger('PulseDuration', 2000);

        // Visualisierung (eigene Kachel).
        $this->RegisterPropertyString('VisuStyle', 'auto');
        $this->RegisterPropertyString('VisuTheme', 'auto');

        $this->RegisterVariableBoolean('State', $this->Translate('State'), '~Switch', 10);
        $this->EnableAction('State');

        $this->RegisterTimer('Update', 0, 'ELTAKOIPSW_Update($_IPS[\'TARGET\']);');
        $this->RegisterTimer('PulseOff', 0, 'ELTAKOIPSW_PulseOff($_IPS[\'TARGET\']);');

        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->EltakoEnsureProfiles();

        $this->MaintainVariable('Power', $this->Translate('Power'), VARIABLETYPE_FLOAT, '~Watt', 20, true);

        // Passendes Profil/Icon je nach Betriebsart setzen.
        $stateID = $this->GetIDForIdent('State');
        if ($stateID) {
            @IPS_SetVariableCustomProfile(
                $stateID,
                $this->ReadPropertyBoolean('PulseMode') ? 'ELTAKOIP.Gate' : 'ELTAKOIP.Switch'
            );
        }

        // Eigene Kachel aktivieren (außer bei Stil "none").
        $this->SetVisualizationType($this->ResolveVisuStyle() === 'none' ? 0 : 1);

        $interval = $this->ReadPropertyInteger('UpdateInterval');
        $this->SetTimerInterval('Update', $interval > 0 ? $interval * 1000 : 0);

        $this->ValidateConfiguration();
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        return json_encode($form);
    }

    // ---- Interaktive HTML-Kachel -----------------------------------------

    public function GetVisualizationTile()
    {
        $style = $this->ResolveVisuStyle();
        if ($style === 'none') {
            return '';
        }

        $this->Update();

        $file = __DIR__ . '/visu_' . $style . '.html';
        if (!file_exists($file)) {
            $file = __DIR__ . '/visu_lamp.html';
        }

        $html = strtr(file_get_contents($file), [
            '__THEME__' => $this->VisuThemeClass(),
            '__NAME__'  => htmlspecialchars(IPS_GetName($this->InstanceID), ENT_QUOTES),
        ]);

        return $html . '<script>try{handleMessage(' . json_encode($this->VisuPayload()) . ');}catch(e){}</script>';
    }

    /** Ermittelt den effektiven Kachel-Stil (auto => Gartentor bei Impuls, sonst Außenleuchte). */
    private function ResolveVisuStyle(): string
    {
        $style = $this->ReadPropertyString('VisuStyle');
        if ($style === 'auto') {
            return $this->ReadPropertyBoolean('PulseMode') ? 'gate' : 'lamp';
        }
        return $style;
    }

    private function VisuThemeClass(): string
    {
        $t = $this->ReadPropertyString('VisuTheme');
        return ($t === 'light') ? 'th-light' : (($t === 'dark') ? 'th-dark' : 'th-auto');
    }

    private function VisuPayload(): string
    {
        $on    = (@$this->GetIDForIdent('State') !== false) ? (bool) $this->GetValue('State') : false;
        $power = (@$this->GetIDForIdent('Power') !== false) ? (float) $this->GetValue('Power') : 0.0;
        return json_encode([
            'on'       => $on,
            'power'    => round($power, 1),
            'pulse'    => $this->ReadPropertyBoolean('PulseMode'),
            'duration' => $this->ReadPropertyInteger('PulseDuration'),
        ]);
    }

    private function PushVisu(): void
    {
        $this->UpdateVisualizationValue($this->VisuPayload());
    }

    /**
     * Aktion auf eine Variable (Schalten über das WebFront / Visualisierung).
     */
    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'State') {
            if ($this->ReadPropertyBoolean('PulseMode')) {
                // Im Tastermodus löst "Ein" einen zeitlich begrenzten Impuls aus.
                if ($Value) {
                    $this->Pulse();
                } else {
                    $this->SetTimerInterval('PulseOff', 0);
                    $this->SetState(false);
                }
            } else {
                $this->SetState((bool) $Value);
            }
            return;
        }
        throw new Exception('Invalid Ident: ' . $Ident);
    }

    /**
     * Löst einen Schaltimpuls aus: schaltet ein und nach der eingestellten Impulsdauer
     * (Standard 2 s) automatisch wieder aus. Ideal für Tür-/Toröffner.
     */
    public function Pulse(): bool
    {
        if (!$this->ValidateConfiguration()) {
            return false;
        }

        $ok = $this->SetState(true);
        if ($ok) {
            $duration = $this->ReadPropertyInteger('PulseDuration');
            if ($duration < 200) {
                $duration = 2000;
            }
            if ($duration > 60000) {
                $duration = 60000;
            }
            // Einmaliges Ausschalten nach Ablauf der Impulsdauer.
            $this->SetTimerInterval('PulseOff', $duration);
        }

        return $ok;
    }

    /**
     * Wird vom Impuls-Timer aufgerufen und schaltet den Aktor wieder aus.
     */
    public function PulseOff(): void
    {
        $this->SetTimerInterval('PulseOff', 0);
        $this->SetState(false);
    }

    /**
     * Schaltet den Aktor und liest den Zustand zurück.
     */
    public function SetState(bool $On): bool
    {
        if (!$this->ValidateConfiguration()) {
            return false;
        }

        $ok = $this->EltakoSetEnum(
            $this->ReadPropertyString('Host'),
            $this->ReadPropertyString('APIKey'),
            $this->ReadPropertyString('DeviceGuid'),
            'relay',
            $On ? 'on' : 'off'
        );

        if ($ok) {
            $this->SetValue('State', $On);
            $this->SetStatus(102);
        } else {
            $this->SetStatus(201);
        }

        $this->PushVisu();

        return $ok;
    }

    /**
     * Liest den aktuellen Zustand vom Gerät und aktualisiert die Variablen.
     */
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

        $relay = $this->EltakoFindValue($device['functions'] ?? [], 'relay', null);
        if ($relay !== null) {
            $this->SetValue('State', $relay === 'on');
        }

        $power = $this->EltakoFindValue($device['infos'] ?? [], 'powerChannel1', null);
        if ($power !== null && @$this->GetIDForIdent('Power')) {
            $this->SetValue('Power', (float) $power);
        }

        $this->SetStatus(102);
        $this->PushVisu();
    }

    /**
     * Prüft, ob die Instanz vollständig konfiguriert ist, und setzt den Instanz-Status.
     */
    private function ValidateConfiguration(): bool
    {
        if ($this->ReadPropertyString('Host') === ''
            || $this->ReadPropertyString('APIKey') === ''
            || $this->ReadPropertyString('DeviceGuid') === '') {
            $this->SetStatus(104); // Instanz ist inaktiv (nicht konfiguriert)
            return false;
        }
        return true;
    }
}
