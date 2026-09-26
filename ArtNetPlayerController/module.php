<?php

class ArtNetPlayerController extends IPSModule
{
    // Datenschnittstelle Parent <-> Child
    private $DataID = '{AE7C1A00-0003-47AE-B000-0000000000E3}';
    private $PlayerModuleID = '{AE7C1A00-0002-47AE-B000-0000000000D2}';

    public function Create()
    {
        parent::Create();

        // Default 127.0.0.1: Player laeuft als PLCnext-App auf demselben Geraet
        // (Catan C1) im Host-Netzwerk -> lokal immer erreichbar.
        $this->RegisterPropertyString('Host', '127.0.0.1');
        $this->RegisterPropertyInteger('Port', 8000);
        $this->RegisterPropertyInteger('Poll', 3);
        // LightHUB verlangt eine Anmeldung (HTTP Basic Auth). Am besten ein eigenes
        // LightHUB-Konto mit Rolle "User" anlegen (z.B. "symcon"). Leer = ohne Anmeldung
        // (alter Art-Net DMX Player 1.x).
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');

        $this->RegisterTimer('Poll', 0, 'ANP_Poll($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            $this->SetTimerInterval('Poll', 0);
            $this->SetStatus(104);
            return;
        }
        $ms = max(1, (int)$this->ReadPropertyInteger('Poll')) * 1000;
        $this->SetTimerInterval('Poll', $ms);
        $this->Poll();
    }

    // ----- Kind -> Controller: Kommandos, die REST-Aufrufe ausloesen -----
    public function ForwardData($JSONString)
    {
        $d = json_decode($JSONString, true);
        if (!is_array($d) || !isset($d['DataID']) || $d['DataID'] != $this->DataID) {
            return json_encode(array('ok' => false, 'error' => 'bad dataid'));
        }
        $cmd = isset($d['cmd']) ? (string)$d['cmd'] : '';
        $a = (isset($d['arg']) && is_array($d['arg'])) ? $d['arg'] : array();
        $pid = isset($a['player']) ? (int)$a['player'] : 0;

        // Gruppen eines Players zurueckgeben (fuer die Dimmer-Variablen/KNX-Zuordnung)
        if ($cmd == 'get_groups') {
            $ok = true;
            $body = $this->Http('GET', '/status', null, $ok);
            $st = json_decode($body, true);
            $groups = array();
            if (isset($st['engine']['players'])) {
                foreach ($st['engine']['players'] as $p) {
                    if ((int)$p['id'] == $pid) { $groups = isset($p['groups']) ? $p['groups'] : array(); break; }
                }
            }
            return json_encode(array('ok' => $ok, 'groups' => $groups));
        }

        // Programme eines Players zurueckgeben (fuer die Ein/Aus-Programmauswahl)
        if ($cmd == 'get_programs') {
            $ok = true;
            $body = $this->Http('GET', "/player/$pid/programs", null, $ok);
            $list = json_decode($body, true);
            $names = array();
            if (is_array($list)) {
                foreach ($list as $pr) if (isset($pr['name'])) $names[] = (string)$pr['name'];
            }
            return json_encode(array('ok' => $ok, 'programs' => $names));
        }

        $ok = true;
        switch ($cmd) {
            case 'on':     $this->Http('POST', "/player/$pid/on", null, $ok); break;
            case 'off':    $this->Http('POST', "/player/$pid/off", null, $ok); break;
            case 'stop':   $this->Http('POST', "/player/$pid/stop", null, $ok); break;
            case 'pause':  $this->Http('POST', "/player/$pid/pause", null, $ok); break;
            case 'master': $this->Http('POST', "/player/$pid/master", array('value' => (int)$a['value']), $ok); break;
            case 'group':  $this->Http('POST', "/player/$pid/group", array('id' => (int)$a['id'], 'value' => (int)$a['value']), $ok); break;
            case 'play':   $this->Http('POST', "/player/$pid/play", array('program' => (string)$a['program']), $ok); break;
            case 'play_off': $this->Http('POST', "/player/$pid/play_off", array('program' => (string)$a['program']), $ok); break;
            case 'config': $this->Http('POST', "/player/$pid/config", isset($a['config']) ? $a['config'] : array(), $ok); break;
            case 'refresh': break;
            default: $ok = false;
        }
        if ($ok) {
            $this->Poll(); // sofort aktualisieren, damit die Kinder frische Werte sehen
        }
        return json_encode(array('ok' => $ok));
    }

    // ----- Polling: /status holen und an alle Kinder verteilen -----
    public function Poll()
    {
        $ok = true;
        $body = $this->Http('GET', '/status', null, $ok);
        if ($this->lastCode == 401 || $this->lastCode == 403 || $this->lastCode == 429) {
            $this->SetStatus(202);          // Anmeldung abgelehnt / gesperrt - nicht "nicht erreichbar"
            return;
        }
        if (!$ok || $body === '') {
            $this->SetStatus(201);
            return;
        }
        $status = json_decode($body, true);
        if (!is_array($status) || !isset($status['engine'])) {
            $this->SetStatus(201);
            return;
        }
        $this->SetStatus(102);
        $payload = array('DataID' => $this->DataID, 'status' => $status);
        @ $this->SendDataToChildren(json_encode($payload));
    }

    // Öffentlich: liefert das aktuelle /status als JSON-String (z.B. für Skripte)
    public function GetStatus()
    {
        $ok = true;
        return $this->Http('GET', '/status', null, $ok);
    }

    // ----- Discovery: Player aus dem Tool auflisten + Instanzen anlegen -----
    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        // Player vom Tool holen
        $ok = true;
        $players = array();
        $body = $this->Http('GET', '/status', null, $ok);
        if ($ok) {
            $st = json_decode($body, true);
            if (isset($st['engine']['players'])) $players = $st['engine']['players'];
        }

        // vorhandene Player-Instanzen dieses Controllers nach PlayerID
        $existing = array();
        foreach (IPS_GetInstanceListByModuleID($this->PlayerModuleID) as $iid) {
            if (IPS_GetInstance($iid)['ConnectionID'] == $this->InstanceID) {
                $existing[(int)IPS_GetProperty($iid, 'PlayerID')] = $iid;
            }
        }

        $values = array();
        foreach ($players as $p) {
            $pid = (int)$p['id'];
            $values[] = array(
                'name' => isset($p['name']) ? $p['name'] : ('Player ' . $pid),
                'pid'  => $pid,
                'state' => isset($existing[$pid]) ? '✓ Instanz vorhanden' : '– fehlt'
            );
        }

        $form['actions'][] = array(
            'type' => 'List', 'name' => 'PlayerList', 'caption' => 'Player im Tool (Discovery)',
            'rowCount' => max(2, min(14, count($values))),
            'columns' => array(
                array('caption' => 'Player', 'name' => 'name', 'width' => 'auto'),
                array('caption' => 'ID', 'name' => 'pid', 'width' => '70px'),
                array('caption' => 'Symcon', 'name' => 'state', 'width' => '160px')
            ),
            'values' => $values
        );
        $form['actions'][] = array(
            'type' => 'Button', 'caption' => 'Fehlende Player-Instanzen anlegen',
            'onClick' => 'ANP_SyncPlayers($id);'
        );
        return json_encode($form);
    }

    // Legt fuer jeden Player im Tool eine verbundene Instanz an (falls fehlend)
    public function SyncPlayers()
    {
        $ok = true;
        $body = $this->Http('GET', '/status', null, $ok);
        if (!$ok) { echo 'Tool nicht erreichbar – Host/Port pruefen.'; return; }
        $st = json_decode($body, true);
        $players = isset($st['engine']['players']) ? $st['engine']['players'] : array();

        // vorhandene Player-Instanzen nach PlayerID (egal an welchem Parent) sammeln
        $byPid = array();
        foreach (IPS_GetInstanceListByModuleID($this->PlayerModuleID) as $iid) {
            $byPid[(int)IPS_GetProperty($iid, 'PlayerID')][] = $iid;
        }

        $created = 0; $connected = 0;
        foreach ($players as $p) {
            $pid = (int)$p['id'];
            if (isset($byPid[$pid])) {
                // existiert schon -> sicherstellen, dass eine mit DIESEM Controller verbunden ist
                $hasConn = false;
                foreach ($byPid[$pid] as $iid) {
                    if (IPS_GetInstance($iid)['ConnectionID'] == $this->InstanceID) { $hasConn = true; break; }
                }
                if (!$hasConn) {
                    @IPS_ConnectInstance($byPid[$pid][0], $this->InstanceID);
                    IPS_ApplyChanges($byPid[$pid][0]);
                    $connected++;
                }
                continue;
            }
            $iid = IPS_CreateInstance($this->PlayerModuleID);
            IPS_SetName($iid, isset($p['name']) ? $p['name'] : ('Player ' . $pid));
            @IPS_ConnectInstance($iid, $this->InstanceID);
            IPS_SetProperty($iid, 'PlayerID', $pid);
            IPS_ApplyChanges($iid);
            $created++;
        }
        $msg = array();
        if ($created > 0) $msg[] = $created . ' angelegt';
        if ($connected > 0) $msg[] = $connected . ' verbunden';
        echo count($msg) ? ('Player: ' . implode(', ', $msg) . '.') : 'Alle Player sind bereits als verbundene Instanz vorhanden.';
    }

    // ----- HTTP-Helfer (REST gegen LightHUB bzw. den alten Art-Net DMX Player 1.x) -----
    private $lastCode = 0;       // HTTP-Status der letzten Anfrage (0 = keine Antwort)

    private function Http($method, $path, $body, &$ok)
    {
        $ok = true;
        $this->lastCode = 0;
        $host = trim($this->ReadPropertyString('Host'));
        $port = (int)$this->ReadPropertyInteger('Port');
        if ($host === '' || $port <= 0 || $port > 65535) {
            $ok = false;
            return '';
        }
        $url = 'http://' . $host . ':' . $port . $path;

        $headers = array('Connection: close');
        $user = trim($this->ReadPropertyString('Username'));
        if ($user !== '') {
            $headers[] = 'Authorization: Basic ' . base64_encode($user . ':' . $this->ReadPropertyString('Password'));
        }
        $opts = array('http' => array(
            'method'        => $method,
            'timeout'       => 4,
            'ignore_errors' => true
        ));
        if ($body !== null) {
            $json = json_encode($body);
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($json);
            $opts['http']['content'] = $json;
        }
        $opts['http']['header'] = implode("\r\n", $headers) . "\r\n";

        $ctx = stream_context_create($opts);
        // fopen + stream_get_meta_data statt file_get_contents: liefert die Antwort-
        // Header in jeder PHP-Version (ohne das in 8.4 veraltete $http_response_header)
        $fp = @fopen($url, 'r', false, $ctx);
        if ($fp === false) {
            $ok = false;
            return '';
        }
        $meta = stream_get_meta_data($fp);
        $data = stream_get_contents($fp);
        fclose($fp);
        if ($data === false) {
            $ok = false;
            return '';
        }
        // Statuscode auswerten (ignore_errors liefert auch 4xx/5xx-Antworten)
        $resp = isset($meta['wrapper_data']) ? $meta['wrapper_data'] : array();
        if (isset($resp[0]) && preg_match('#HTTP/\S+\s+(\d{3})#', $resp[0], $m)) {
            $this->lastCode = (int)$m[1];
        }
        if ($this->lastCode >= 400) {
            $ok = false;
            if ($this->lastCode == 401) {
                $this->SendDebug('HTTP', 'Anmeldung abgelehnt - Benutzer/Passwort in der Controller-Instanz pruefen', 0);
            }
        }
        return (string)$data;
    }
}
