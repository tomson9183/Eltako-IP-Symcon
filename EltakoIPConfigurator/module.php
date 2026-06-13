<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/EltakoIPClient.php';

/**
 * Eltako IP Konfigurator.
 *
 * Sucht Eltako WLAN-Geräte (Baureihe 62-IP / 64-IPM) im lokalen Netzwerk, meldet
 * sich mit dem Proof-of-Possession (Eltako-Code) am gewählten Gerät an und legt für
 * jeden steuerbaren Kanal automatisch die passende Geräteinstanz an:
 *
 *  - relay            -> EltakoIPSwitch  (Schaltaktor)
 *  - targetBrightness -> EltakoIPDimmer  (Dimmer)
 *  - targetPosition   -> EltakoIPBlind   (Rollladen, ggf. mit Lamelle)
 */
class EltakoIPConfigurator extends IPSModule
{
    use EltakoIPClient;

    // GUIDs der Geräte-Module, die der Konfigurator anlegen kann.
    private const MODULE_SWITCH = '{E7F592C7-1F90-48A9-8766-E4DAB04CB52E}';
    private const MODULE_DIMMER = '{29326ADC-B15D-4BB4-AB0A-4E3323694079}';
    private const MODULE_BLIND  = '{A2D274B1-F2F2-4F45-91EC-44B41D0F2B5A}';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyString('ScanSubnet', '');

        // Der nach dem Login ermittelte API-Key wird dauerhaft gespeichert.
        $this->RegisterAttributeString('APIKey', '');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }

    /**
     * Durchsucht das lokale Subnetz nach Eltako IP-Geräten und füllt die Ergebnisliste.
     */
    public function ScanNetwork(string $Subnet = ''): void
    {
        // Wichtig: den im Formular eingetragenen Wert verwenden (dieser ist evtl. noch
        // nicht gespeichert, daher wird er als Parameter übergeben). Erst danach auf die
        // gespeicherte Property bzw. die automatische Erkennung zurückfallen.
        $subnet = trim($Subnet);
        if ($subnet === '') {
            $subnet = trim($this->ReadPropertyString('ScanSubnet'));
        }
        if ($subnet === '') {
            $subnet = $this->EltakoGuessSubnet();
        }
        // Sicherstellen, dass das Subnetz mit einem Punkt endet (z. B. "192.168.1.").
        if (substr($subnet, -1) !== '.') {
            $subnet .= '.';
        }

        $this->UpdateFormField('ScanProgress', 'visible', true);
        $found = $this->EltakoScanSubnet($subnet);
        $this->UpdateFormField('ScanProgress', 'visible', false);

        $rows = [];
        foreach ($found as $entry) {
            $info = $entry['info'];
            $app = $info['preferredApp']['name'] ?? 'Eltako';
            $apiVersion = $info['api']['version'] ?? '?';
            $rows[] = [
                'Host'    => $entry['host'],
                'Product' => $app,
                'Version' => 'API ' . $apiVersion,
            ];
        }

        $this->UpdateFormField('DiscoveredDevices', 'values', json_encode($rows));

        if (count($rows) === 0) {
            echo $this->Translate('No Eltako IP devices found in subnet') . ' ' . $subnet;
        } else {
            echo sprintf($this->Translate('%d device(s) found.'), count($rows));
        }
    }

    /**
     * Übernimmt eine in der Suchliste angeklickte IP in das Host-Feld.
     */
    public function SelectDiscovered(string $Host): void
    {
        if ($Host !== '') {
            $this->UpdateFormField('Host', 'value', $Host);
        }
    }

    /**
     * Testet die Erreichbarkeit einer einzelnen IP über den öffentlichen well-known-Endpunkt
     * und zeigt das Ergebnis als Klartext an. Dient zur Diagnose, wenn die Netzwerksuche
     * nichts findet.
     */
    public function TestConnection(string $Host): void
    {
        $Host = trim($Host);
        if ($Host === '') {
            echo $this->Translate('Please enter an IP address / hostname.');
            return;
        }

        // Mehrere mögliche API-Pfade abklopfen, um die tatsächliche API der Geräte
        // (62-IP vs. 64-IPM, evtl. abweichende Firmware/Version) zu erkennen.
        $paths = [
            '/.well-known/eltako/devices',
            '/.well-known/eltako',
            '/api/v0/devices',
            '/api/v1/devices',
            '/api/v0',
            '/api',
            '/',
        ];

        $lines = [];
        $lines[] = sprintf($this->Translate('Diagnosis for %s (HTTPS, port 443):'), $Host);
        $detected = false;

        foreach ($paths as $path) {
            $res = $this->EltakoRequest($Host, 'GET', $path, null);

            if ($res['error'] !== '') {
                $lines[] = sprintf('  %-30s -> %s', $path, $res['error']);
                continue;
            }

            $hint = '';
            // 401 auf einem API-Pfad: Endpunkt existiert, benötigt Auth -> Eltako-API.
            if ($res['code'] === 401 && strpos($path, '/api') === 0) {
                $hint = '   <== ' . $this->Translate('Eltako API detected (needs login)');
                $detected = true;
            }
            // 200 mit api-Struktur -> öffentlicher Eltako-Endpunkt.
            if ($res['code'] === 200 && is_array($res['body']) && isset($res['body']['api'])) {
                $hint = '   <== ' . $this->Translate('Eltako device detected');
                $detected = true;
            }
            $lines[] = sprintf('  HTTP %-3d %-30s%s', $res['code'], $path, $hint);
        }

        $lines[] = '';
        if ($detected) {
            $lines[] = $this->Translate('Eltako device confirmed. You can log in with the Eltako code (PoP).');
        } else {
            $lines[] = $this->Translate('No Eltako API found on this host. Either it is not an Eltako device or it uses a different firmware/path. Please send this output.');
        }

        echo implode("\n", $lines);
    }

    /**
     * Meldet sich am Gerät an, speichert den API-Key und lädt anschließend die Kanäle.
     */
    public function Login(string $Host, string $PoP): void
    {
        $Host = trim($Host);
        $PoP  = trim($PoP);

        if ($Host === '' || $PoP === '') {
            echo $this->Translate('Please enter host and Eltako code (PoP).');
            return;
        }

        $apiKey = $this->EltakoLogin($Host, $PoP);
        if ($apiKey === null) {
            echo $this->Translate('Login failed. Please check the host and the Eltako code.');
            return;
        }

        // Host als Property und API-Key als Attribut dauerhaft sichern.
        IPS_SetProperty($this->InstanceID, 'Host', $Host);
        IPS_ApplyChanges($this->InstanceID);
        $this->WriteAttributeString('APIKey', $apiKey);

        echo $this->Translate('Login successful. Channels have been loaded.');
        $this->ReloadForm();
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        // Vorbelegung des Subnetz-Feldes mit dem erkannten lokalen Netz.
        if ($this->ReadPropertyString('ScanSubnet') === '') {
            foreach ($form['elements'] as &$element) {
                if (($element['name'] ?? '') === 'ScanField') {
                    foreach ($element['items'] as &$item) {
                        if (($item['name'] ?? '') === 'ScanSubnet') {
                            $item['value'] = $this->EltakoGuessSubnet();
                        }
                    }
                }
            }
            unset($element, $item);
        }

        // Konfigurator-Liste mit den steuerbaren Kanälen befüllen.
        $form['actions'][] = [
            'type'    => 'Configurator',
            'name'    => 'Channels',
            'caption' => 'Kanäle',
            'rowCount' => 10,
            'add'     => false,
            'delete'  => true,
            'columns' => [
                ['caption' => 'Name', 'name' => 'name', 'width' => 'auto'],
                ['caption' => 'Typ', 'name' => 'typeName', 'width' => '160px'],
                ['caption' => 'Device-GUID', 'name' => 'deviceGuid', 'width' => '320px']
            ],
            'values' => $this->BuildChannelList()
        ];

        return json_encode($form);
    }

    /**
     * Liest die Geräteliste vom angemeldeten Gerät und baut daraus die Konfigurator-Einträge.
     */
    private function BuildChannelList(): array
    {
        $host   = $this->ReadPropertyString('Host');
        $apiKey = $this->ReadAttributeString('APIKey');

        if ($host === '' || $apiKey === '') {
            return [];
        }

        $devices = $this->EltakoGetDevices($host, $apiKey);
        $values  = [];

        foreach ($devices as $device) {
            $guid      = $device['deviceGuid'] ?? '';
            $name      = $device['displayName'] ?? $guid;
            $functions = $device['functions'] ?? [];

            $identifiers = array_column($functions, 'identifier');

            $moduleID = '';
            $typeName = '';
            $config   = [
                'Host'       => $host,
                'APIKey'     => $apiKey,
                'DeviceGuid' => $guid,
            ];

            if (in_array('relay', $identifiers, true)) {
                $moduleID = self::MODULE_SWITCH;
                $typeName = $this->Translate('Switch actuator');
            } elseif (in_array('targetBrightness', $identifiers, true)) {
                $moduleID = self::MODULE_DIMMER;
                $typeName = $this->Translate('Dimmer');
            } elseif (in_array('targetPosition', $identifiers, true)) {
                $moduleID = self::MODULE_BLIND;
                $typeName = $this->Translate('Blind');
                $config['HasTilt'] = in_array('targetTilt', $identifiers, true);
            } else {
                // Eingänge/Taster o. Ä. -> kein steuerbares Ausgangs-Modul, überspringen.
                continue;
            }

            $values[] = [
                'name'         => $name,
                'typeName'     => $typeName,
                'deviceGuid'   => $guid,
                'instanceID'   => $this->FindExistingInstance($moduleID, $guid),
                'create'       => [
                    'moduleID'      => $moduleID,
                    'configuration' => $config
                ]
            ];
        }

        return $values;
    }

    /**
     * Sucht eine bereits angelegte Instanz des Moduls für eine bestimmte Device-GUID.
     *
     * @return int InstanzID oder 0, wenn noch nicht angelegt.
     */
    private function FindExistingInstance(string $moduleID, string $deviceGuid): int
    {
        foreach (IPS_GetInstanceListByModuleID($moduleID) as $instanceID) {
            if (@IPS_GetProperty($instanceID, 'DeviceGuid') === $deviceGuid) {
                return $instanceID;
            }
        }
        return 0;
    }
}
