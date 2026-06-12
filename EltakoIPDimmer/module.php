<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/EltakoIPClient.php';

/**
 * Eltako IP Dimmer (EUD62NPN-IP / EUD64NPN-IPM).
 *
 * Funktion: targetBrightness (0-100)
 * Info:     currentBrightness (0-100)
 */
class EltakoIPDimmer extends IPSModule
{
    use EltakoIPClient;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyString('APIKey', '');
        $this->RegisterPropertyString('DeviceGuid', '');
        $this->RegisterPropertyInteger('UpdateInterval', 0);

        $this->RegisterVariableInteger('Brightness', $this->Translate('Brightness'), '~Intensity.100', 10);
        $this->EnableAction('Brightness');

        $this->RegisterTimer('Update', 0, 'ELTAKOIPDIM_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

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
        if ($Ident === 'Brightness') {
            $this->SetBrightness((int) $Value);
            return;
        }
        throw new Exception('Invalid Ident: ' . $Ident);
    }

    /**
     * Setzt die Helligkeit (0-100 %). 0 = aus.
     */
    public function SetBrightness(int $Value): bool
    {
        if (!$this->ValidateConfiguration()) {
            return false;
        }

        $Value = max(0, min(100, $Value));

        $ok = $this->EltakoSetNumber(
            $this->ReadPropertyString('Host'),
            $this->ReadPropertyString('APIKey'),
            $this->ReadPropertyString('DeviceGuid'),
            'targetBrightness',
            (float) $Value
        );

        if ($ok) {
            $this->SetValue('Brightness', $Value);
            $this->SetStatus(102);
        } else {
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

        // Aktuelle Helligkeit bevorzugt aus den Infos lesen, sonst aus der Funktion.
        $current = $this->EltakoFindValue($device['infos'] ?? [], 'currentBrightness', null);
        if ($current === null) {
            $current = $this->EltakoFindValue($device['functions'] ?? [], 'targetBrightness', null);
        }
        if ($current !== null) {
            $this->SetValue('Brightness', (int) round((float) $current));
        }

        $this->SetStatus(102);
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
