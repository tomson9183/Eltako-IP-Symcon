<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/EltakoIPClient.php';

/**
 * Eltako IP Rollladen-/Jalousieaktor (ESB62NP-IP / ESB64NP-IPM).
 *
 * Funktion: targetPosition (0-100), optional targetTilt (0-100, nur ESB64-IPM)
 * Info:     currentPosition, currentTilt, power
 *
 * Positionssemantik gemäß Eltako: 0 = offen/oben, 100 = geschlossen/unten.
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
        $this->RegisterPropertyInteger('UpdateInterval', 0);

        $this->RegisterVariableInteger('Position', $this->Translate('Position'), '~Intensity.100', 10);
        $this->EnableAction('Position');

        $this->RegisterTimer('Update', 0, 'ELTAKOIPBL_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $hasTilt = $this->ReadPropertyBoolean('HasTilt');
        $this->MaintainVariable('Tilt', $this->Translate('Slat position'), VARIABLETYPE_INTEGER, '~Intensity.100', 20, $hasTilt);
        if ($hasTilt) {
            $this->EnableAction('Tilt');
        }

        $this->MaintainVariable('Power', $this->Translate('Power'), VARIABLETYPE_FLOAT, '~Watt', 30, true);

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
            case 'Tilt':
                $this->SetTilt((int) $Value);
                return;
        }
        throw new Exception('Invalid Ident: ' . $Ident);
    }

    /**
     * Fährt den Rollladen auf eine Zielposition (0 = offen/oben, 100 = geschlossen/unten).
     */
    public function SetPosition(int $Value): bool
    {
        if (!$this->ValidateConfiguration()) {
            return false;
        }

        $Value = max(0, min(100, $Value));

        $ok = $this->EltakoSetNumber(
            $this->ReadPropertyString('Host'),
            $this->ReadPropertyString('APIKey'),
            $this->ReadPropertyString('DeviceGuid'),
            'targetPosition',
            (float) $Value
        );

        if ($ok) {
            $this->SetValue('Position', $Value);
            $this->SetStatus(102);
        } else {
            $this->SetStatus(201);
        }

        return $ok;
    }

    /**
     * Setzt die Lamellenposition (0-100). Nur bei Geräten mit Lamellensteuerung (ESB64-IPM).
     */
    public function SetTilt(int $Value): bool
    {
        if (!$this->ReadPropertyBoolean('HasTilt')) {
            return false;
        }
        if (!$this->ValidateConfiguration()) {
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

        $position = $this->EltakoFindValue($device['infos'] ?? [], 'currentPosition', null);
        if ($position === null) {
            $position = $this->EltakoFindValue($device['functions'] ?? [], 'targetPosition', null);
        }
        if ($position !== null) {
            $this->SetValue('Position', (int) round((float) $position));
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
