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

        $this->RegisterVariableBoolean('State', $this->Translate('State'), '~Switch', 10);
        $this->EnableAction('State');

        $this->RegisterTimer('Update', 0, 'ELTAKOIPSW_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->MaintainVariable('Power', $this->Translate('Power'), VARIABLETYPE_FLOAT, '~Watt', 20, true);

        $interval = $this->ReadPropertyInteger('UpdateInterval');
        $this->SetTimerInterval('Update', $interval > 0 ? $interval * 1000 : 0);

        $this->ValidateConfiguration();
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        return json_encode($form);
    }

    /**
     * Aktion auf eine Variable (Schalten über das WebFront / Visualisierung).
     */
    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'State') {
            $this->SetState((bool) $Value);
            return;
        }
        throw new Exception('Invalid Ident: ' . $Ident);
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
