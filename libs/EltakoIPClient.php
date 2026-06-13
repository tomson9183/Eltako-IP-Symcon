<?php

declare(strict_types=1);

/**
 * Gemeinsame HTTP-Client-Logik für die lokale REST-API der Eltako IP-Geräte
 * (Baureihe 62-IP / 64-IPM, ZGW16WL-IP).
 *
 * Grundlage: offizielle Eltako OpenAPI-Spezifikation
 * https://github.com/Eltako/eltako-openapi-specifications
 *
 * Eckdaten:
 *  - Jedes Gerät ist direkt unter https://{host}:443 erreichbar (selbstsigniertes Zertifikat).
 *  - Discovery (ohne Auth):  GET  /.well-known/eltako/devices
 *  - Login:                  POST /api/v0/login   {"user":"admin","password":"<PoP>"}  -> {"apiKey":"<JWT>"}
 *  - Geräte/Kanäle:          GET  /api/v0/devices
 *  - Steuern:                PUT  /api/v0/devices/{guid}/functions   [{identifier,type,value}, ...]
 *  - Identify (Apple Home):  POST /identify
 *
 * Der API-Key wird im Header "Authorization" mitgegeben.
 */
trait EltakoIPClient
{
    /** Standard-Port der Eltako IP-Geräte. */
    private static $ELTAKO_PORT = 443;

    /** Timeout (Sekunden) für normale Requests. */
    private static $ELTAKO_TIMEOUT = 8;

    /** Timeout (Sekunden) pro Host beim Netzwerk-Scan. */
    private static $ELTAKO_SCAN_TIMEOUT = 3;

    /**
     * Führt einen einzelnen HTTP-Request gegen ein Eltako IP-Gerät aus.
     *
     * @param string      $host   IP-Adresse oder Hostname des Geräts.
     * @param string      $method GET|POST|PUT|DELETE|PATCH.
     * @param string      $path   Pfad inkl. führendem "/", z. B. "/api/v0/devices".
     * @param string|null $apiKey API-Key für den Authorization-Header (null = ohne Auth).
     * @param mixed       $body   Wird als JSON gesendet (null = kein Body).
     *
     * @return array{code:int, body:mixed, error:string} HTTP-Status, dekodierter Body, evtl. Fehlertext.
     */
    protected function EltakoRequest(string $host, string $method, string $path, ?string $apiKey = null, $body = null): array
    {
        $url = sprintf('https://%s:%d%s', $host, self::$ELTAKO_PORT, $path);

        $headers = ['Accept: application/json'];
        if ($apiKey !== null && $apiKey !== '') {
            $headers[] = 'Authorization: ' . $apiKey;
        }

        $ch = curl_init();
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::$ELTAKO_TIMEOUT,
            CURLOPT_TIMEOUT        => self::$ELTAKO_TIMEOUT,
            // Die Geräte liefern selbstsignierte Zertifikate -> Verifikation deaktivieren.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ];

        if ($body !== null) {
            $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $opts[CURLOPT_POSTFIELDS] = $json;
            $headers[] = 'Content-Type: application/json; charset=utf-8';
            $headers[] = 'Content-Length: ' . strlen($json);
        }

        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);
        $code     = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        $decoded = null;
        if (is_string($response) && $response !== '') {
            $decoded = json_decode($response, true);
            if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                $decoded = $response; // kein JSON -> Rohtext zurückgeben
            }
        }

        return ['code' => $code, 'body' => $decoded, 'error' => $error];
    }

    /**
     * Meldet sich am Gerät an und liefert den API-Key zurück.
     *
     * @param string $host IP/Hostname.
     * @param string $pop  Proof-of-Possession (Eltako-Code auf dem Gerät / QR).
     *
     * @return string|null API-Key oder null bei Fehler.
     */
    protected function EltakoLogin(string $host, string $pop): ?string
    {
        $res = $this->EltakoRequest($host, 'POST', '/api/v0/login', null, [
            'user'     => 'admin',
            'password' => $pop,
        ]);

        if ($res['code'] === 200 && is_array($res['body']) && isset($res['body']['apiKey'])) {
            return (string) $res['body']['apiKey'];
        }

        return null;
    }

    /**
     * Liefert die Liste aller (Sub-)Geräte/Kanäle eines physischen Geräts.
     *
     * @return array Liste der DeviceInfo-Objekte oder leeres Array bei Fehler.
     */
    protected function EltakoGetDevices(string $host, string $apiKey): array
    {
        $res = $this->EltakoRequest($host, 'GET', '/api/v0/devices', $apiKey);
        if ($res['code'] === 200 && is_array($res['body'])) {
            return $res['body'];
        }
        return [];
    }

    /**
     * Liest ein einzelnes Gerät anhand seiner GUID.
     */
    protected function EltakoGetDevice(string $host, string $apiKey, string $deviceGuid): ?array
    {
        $res = $this->EltakoRequest($host, 'GET', '/api/v0/devices/' . rawurlencode($deviceGuid), $apiKey);
        if ($res['code'] === 200 && is_array($res['body'])) {
            return $res['body'];
        }
        return null;
    }

    /**
     * Setzt eine oder mehrere Funktions-Charakteristiken eines Geräts.
     *
     * @param array $characteristics Liste von ['identifier'=>..,'type'=>..,'value'=>..].
     *
     * @return bool true bei HTTP 2xx.
     */
    protected function EltakoSetFunctions(string $host, string $apiKey, string $deviceGuid, array $characteristics): bool
    {
        $res = $this->EltakoRequest(
            $host,
            'PUT',
            '/api/v0/devices/' . rawurlencode($deviceGuid) . '/functions',
            $apiKey,
            array_values($characteristics)
        );
        return $res['code'] >= 200 && $res['code'] < 300;
    }

    /**
     * Setzt eine einzelne Zahl-Funktion (z. B. targetBrightness, targetPosition).
     */
    protected function EltakoSetNumber(string $host, string $apiKey, string $deviceGuid, string $identifier, float $value): bool
    {
        return $this->EltakoSetFunctions($host, $apiKey, $deviceGuid, [[
            'identifier' => $identifier,
            'type'       => 'number',
            'value'      => $value,
        ]]);
    }

    /**
     * Setzt eine einzelne Enum-Funktion (z. B. relay = on/off).
     */
    protected function EltakoSetEnum(string $host, string $apiKey, string $deviceGuid, string $identifier, string $value): bool
    {
        return $this->EltakoSetFunctions($host, $apiKey, $deviceGuid, [[
            'identifier' => $identifier,
            'type'       => 'enumeration',
            'value'      => $value,
        ]]);
    }

    /**
     * Fragt den öffentlichen .well-known-Endpunkt ab (ohne Auth).
     */
    protected function EltakoWellKnown(string $host): ?array
    {
        $res = $this->EltakoRequest($host, 'GET', '/.well-known/eltako/devices', null);
        if ($res['code'] === 200 && is_array($res['body'])) {
            return $res['body'];
        }
        return null;
    }

    /**
     * Hilfsfunktion: liefert den Wert einer Charakteristik aus einer Liste
     * (functions/infos/settings), oder $default wenn nicht vorhanden.
     *
     * @param array  $list       Liste von ['identifier'=>..,'value'=>..].
     * @param string $identifier Gesuchter Identifier.
     * @param mixed  $default    Rückgabe falls nicht gefunden.
     *
     * @return mixed
     */
    protected function EltakoFindValue(array $list, string $identifier, $default = null)
    {
        foreach ($list as $entry) {
            if (isset($entry['identifier']) && $entry['identifier'] === $identifier) {
                return $entry['value'] ?? $default;
            }
        }
        return $default;
    }

    /**
     * Durchsucht ein /24-Subnetz nach Eltako IP-Geräten.
     *
     * Erkennung über den API-Endpunkt /api/v0/devices: Eltako-Geräte antworten dort mit
     * HTTP 401 (Endpunkt existiert, benötigt Anmeldung) bzw. 200. Der zuvor genutzte
     * Pfad /.well-known/eltako/devices existiert auf den 62-IP-Geräten nicht (HTTP 404).
     *
     * @param string $subnet Subnetz-Präfix, z. B. "192.168.1." (mit abschließendem Punkt).
     *
     * @return array<int, array{host:string, info:array}> Gefundene Geräte (Host + evtl. Infos).
     */
    protected function EltakoScanSubnet(string $subnet): array
    {
        $found = [];

        // In Blöcken scannen, damit nicht 254 gleichzeitige TLS-Verbindungen das
        // Netzwerk/Router überlasten (was auch echte Geräte verschlucken würde).
        $batchSize = 32;

        for ($start = 1; $start <= 254; $start += $batchSize) {
            $end = min($start + $batchSize - 1, 254);

            $multi = curl_multi_init();
            $handles = [];

            for ($i = $start; $i <= $end; $i++) {
                $host = $subnet . $i;
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL            => sprintf('https://%s:%d/api/v0/devices', $host, self::$ELTAKO_PORT),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => self::$ELTAKO_SCAN_TIMEOUT,
                    CURLOPT_TIMEOUT        => self::$ELTAKO_SCAN_TIMEOUT,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_NOSIGNAL       => 1,
                ]);
                curl_multi_add_handle($multi, $ch);
                $handles[$host] = $ch;
            }

            // Block parallel ausführen.
            do {
                $status = curl_multi_exec($multi, $active);
                if ($active) {
                    curl_multi_select($multi, 1.0);
                }
            } while ($active && $status === CURLM_OK);

            foreach ($handles as $host => $ch) {
                $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $body = curl_multi_getcontent($ch);
                // 401 = Endpunkt vorhanden, Anmeldung nötig (typisch Eltako).
                // 200 = bereits offen erreichbar.
                if ($code === 401 || $code === 200) {
                    $info = [];
                    if ($code === 200 && is_string($body) && $body !== '') {
                        $decoded = json_decode($body, true);
                        if (is_array($decoded)) {
                            $info = $decoded;
                        }
                    }
                    $found[] = ['host' => $host, 'info' => $info];
                }
                curl_multi_remove_handle($multi, $ch);
                curl_close($ch);
            }

            curl_multi_close($multi);
        }

        return $found;
    }

    /**
     * Versucht das lokale /24-Subnetz des Symcon-Servers zu ermitteln.
     * Fällt auf 192.168.0. zurück, wenn keine Bestimmung möglich ist.
     */
    protected function EltakoGuessSubnet(): string
    {
        // Verbindung zu einem externen Ziel öffnen, um die lokale Quell-IP zu ermitteln
        // (es werden keine Daten gesendet).
        $sock = @stream_socket_client('udp://8.8.8.8:53', $errno, $errstr, 1);
        if ($sock) {
            $name = stream_socket_get_name($sock, false);
            fclose($sock);
            if (is_string($name) && strpos($name, ':') !== false) {
                $ip = substr($name, 0, strrpos($name, ':'));
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return substr($ip, 0, strrpos($ip, '.') + 1);
                }
            }
        }

        // Fallback: lokale IP über den Hostnamen ermitteln.
        $ip = @gethostbyname(@gethostname());
        if (is_string($ip)
            && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && strpos($ip, '127.') !== 0) {
            return substr($ip, 0, strrpos($ip, '.') + 1);
        }

        return '192.168.0.';
    }
}
