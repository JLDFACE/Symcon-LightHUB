<?php

class ArtNetPlayer extends IPSModule
{
    private $DataID = '{AE7C1A00-0003-47AE-B000-0000000000E3}';
    private $ControllerID = '{AE7C1A00-0001-47AE-B000-0000000000C1}';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('PlayerID', 1);
        // Fade-Zeiten (werden ins Tool geschrieben)
        $this->RegisterPropertyInteger('FadeInMs', 800);
        $this->RegisterPropertyInteger('FadeOutMs', 800);
        $this->RegisterPropertyInteger('MasterFadeMs', 150);
        // KNX schaltet -> Programm
        $this->RegisterPropertyString('OnProgram', '');
        $this->RegisterPropertyString('OffProgram', '');
        // KNX-Verknuepfungen
        $this->RegisterPropertyInteger('KnxSwitchVarID', 0);
        $this->RegisterPropertyInteger('KnxAbsDimVarID', 0);
        $this->RegisterPropertyInteger('KnxRelDimVarID', 0);
        $this->RegisterPropertyInteger('KnxStepPercent', 8);
        $this->RegisterPropertyInteger('KnxStatusLevelVarID', 0);
        $this->RegisterPropertyInteger('KnxStatusSwitchVarID', 0);
        // Gruppen-Dimmer <-> KNX: [{group:id, dim:varid, status:varid}]
        $this->RegisterPropertyString('GroupKnx', '[]');

        $this->SetBuffer('Programs', json_encode(array()));
        $this->SetBuffer('RelDir', '1');

        $this->EnsureProfiles();

        $this->RegisterVariableBoolean('Power', 'Ein/Aus', '~Switch', 10);
        $this->EnableAction('Power');
        $this->RegisterVariableInteger('Master', 'Master', 'ANP.Percent', 20);
        $this->EnableAction('Master');
        $this->RegisterVariableInteger('Program', 'Programm', $this->ProgProfile(), 30);
        $this->EnableAction('Program');
        $this->RegisterVariableFloat('Position', 'Position', 'ANP.PercentF', 40);
        $this->RegisterVariableBoolean('Loop', 'Loop', '~Switch', 50);
        $this->EnableAction('Loop');

        $this->RegisterTimer('KnxDim', 0, 'ANPP_KnxDimStep($_IPS[\'TARGET\']);');
        $this->ConnectParent($this->ControllerID);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->EnsureProfiles();
        $this->SetTimerInterval('KnxDim', 0);
        $pid = (int)$this->ReadPropertyInteger('PlayerID');

        // Fade-Zeiten ins Tool schreiben
        $this->SendToParent('config', array('player' => $pid, 'config' => array(
            'fade_in_ms' => (int)$this->ReadPropertyInteger('FadeInMs'),
            'fade_out_ms' => (int)$this->ReadPropertyInteger('FadeOutMs'),
            'master_fade_ms' => (int)$this->ReadPropertyInteger('MasterFadeMs'),
        )));

        // KNX-Messages neu registrieren (nur eingehende)
        foreach ($this->GetMessageList() as $sid => $msgs) {
            foreach ($msgs as $m) {
                if ($m == VM_UPDATE) $this->UnregisterMessage($sid, VM_UPDATE);
            }
        }
        foreach (array('KnxSwitchVarID', 'KnxAbsDimVarID', 'KnxRelDimVarID') as $prop) {
            $vid = (int)$this->ReadPropertyInteger($prop);
            if ($vid > 0 && IPS_VariableExists($vid)) $this->RegisterMessage($vid, VM_UPDATE);
        }
        // KNX-Abs-Dim je Gruppe registrieren
        foreach ($this->GroupKnxMap() as $m) {
            $vid = (int)($m['dim'] ?? 0);
            if ($vid > 0 && IPS_VariableExists($vid)) $this->RegisterMessage($vid, VM_UPDATE);
        }

        // Gruppen-Dimmer-Variablen aus dem Tool anlegen/aktualisieren
        $this->SyncGroupVars($this->AskGroups());

        $this->SendToParent('refresh', array('player' => $pid));
    }

    public function Refresh()
    {
        $this->SendToParent('refresh', array('player' => (int)$this->ReadPropertyInteger('PlayerID')));
    }

    // ---- Public Steuer-Funktionen (fuer Symcon-Skripte/Ereignisse) ----
    // Gezielt ein Programm starten: ANPP_PlayProgram($id, "TV")
    public function PlayProgram(string $name)
    {
        $this->SendToParent('play', array('player' => (int)$this->ReadPropertyInteger('PlayerID'), 'program' => $name));
    }
    // Programm als Aus-Szene abspielen: einmal durch, am Ende echtes Aus.
    // Fuer richtungsabhaengige Aus-Programme (z.B. Treppe): ANPP_PlayProgramOff($id, "Unten Aus")
    public function PlayProgramOff(string $name)
    {
        $this->SendToParent('play_off', array('player' => (int)$this->ReadPropertyInteger('PlayerID'), 'program' => $name));
    }
    // Normaler Ein/Aus wie am KNX-Schalter (nutzt On-/OffProgram der Instanz)
    public function On()  { $this->_switch(true); }
    public function Off() { $this->_switch(false); }
    // Einfach ausschalten (direktes /off, ignoriert OffProgram)
    public function TurnOff() { $this->SendToParent('off', array('player' => (int)$this->ReadPropertyInteger('PlayerID'))); }
    public function Stop()    { $this->SendToParent('stop', array('player' => (int)$this->ReadPropertyInteger('PlayerID'))); }
    public function SetMasterValue(int $v)
    {
        $this->ApplyMaster((int)$v);
    }

    // Helligkeit setzen - EIN Weg fuer WebFront, Modulfunktion, KNX-Abs-Dim und KNX-Rel-Dim.
    // WICHTIG: den Master-Wert VOR dem Einschalten an den Player schicken. Andersherum
    // blendet das On-Programm noch mit dem alten (oft viel hoeheren) Master ein und rampt
    // erst danach herunter - das war das sichtbare Aufblitzen beim Einschalten.
    private function ApplyMaster(int $v)
    {
        $v = max(0, min(100, $v));
        $this->SetValueSafe('Master', $v);
        if ($v <= 0) {
            $this->OffForDim();
            return;
        }
        $this->SendToParent('master', array('player' => (int)$this->ReadPropertyInteger('PlayerID'), 'value' => $v));
        $this->EnsureOnForDim();
    }

    // Helligkeitsaenderung schaltet ein: wenn aus ODER Szene = Aus-Programm laeuft
    // -> On-Programm starten (bzw. 'on', falls kein OnProgram gesetzt).
    private function EnsureOnForDim()
    {
        $pid = (int)$this->ReadPropertyInteger('PlayerID');
        $isOff = !(bool)$this->GetValue('Power');
        if (!$isOff) {
            // Power meldet "an" – laeuft aber gerade das Aus-Programm? Dann auch als aus behandeln.
            $offProg = (string)$this->ReadPropertyString('OffProgram');
            if ($offProg !== '' && $this->CurrentProgram() === $offProg) $isOff = true;
        }
        if (!$isOff) return;
        $this->SetValueSafe('Power', true);
        $onProg = (string)$this->ReadPropertyString('OnProgram');
        if ($onProg !== '') {
            $this->SendToParent('play', array('player' => $pid, 'program' => $onProg));
        } else {
            $this->SendToParent('on', array('player' => $pid));
        }
    }

    // Gegenstueck zu EnsureOnForDim: 0 % Helligkeit = ausschalten (wie KNX-Dimmer).
    private function OffForDim()
    {
        $this->SetValueSafe('Power', false);
        $this->_switch(false);
    }

    // Normaler Ein/Aus.
    // Ein: OnProgram spielen (sonst /on).
    // Aus: NUR wenn wir gerade auf der An-Szene sind, ueber die Aus-Szene
    //      (OffProgram einmal durch -> echtes Aus). Auf jeder anderen Szene
    //      (oder ohne On-/OffProgram): direkt aus (/off).
    private function _switch($on)
    {
        $pid = (int)$this->ReadPropertyInteger('PlayerID');
        if ($on) {
            $onProg = (string)$this->ReadPropertyString('OnProgram');
            if ($onProg !== '') {
                $this->SendToParent('play', array('player' => $pid, 'program' => $onProg));
            } else {
                $this->SendToParent('on', array('player' => $pid));
            }
            return;
        }
        $off = (string)$this->ReadPropertyString('OffProgram');
        $onProg = (string)$this->ReadPropertyString('OnProgram');
        if ($off !== '' && $onProg !== '' && $this->CurrentProgram() === $onProg) {
            $this->SendToParent('play_off', array('player' => $pid, 'program' => $off));  // war auf An-Szene
        } else {
            $this->SendToParent('off', array('player' => $pid));                          // andere Szene -> direkt aus
        }
    }

    // Name des aktuell geladenen Programms (aus Program-Index + Programmliste).
    private function CurrentProgram()
    {
        $names = json_decode($this->GetBuffer('Programs'), true);
        $idx = (int)$this->GetValue('Program');
        return (is_array($names) && isset($names[$idx])) ? (string)$names[$idx] : '';
    }

    // ---- WebFront/Action ----
    public function RequestAction($Ident, $Value)
    {
        $pid = (int)$this->ReadPropertyInteger('PlayerID');
        if (strpos($Ident, 'Grp') === 0) {           // Gruppen-Dimmer (Grp{id})
            $gid = (int)substr($Ident, 3);
            $v = max(0, min(100, (int)$Value));
            $this->SetValueSafe($Ident, $v);
            $this->SendToParent('group', array('player' => $pid, 'id' => $gid, 'value' => $v));
            return;
        }
        if ($Ident == 'Power') {
            $on = (bool)$Value;
            $this->SetValueSafe('Power', $on);
            $this->_switch($on);   // Ein = OnProgram, Aus = Aus-Szene (dann echtes Aus)
        } elseif ($Ident == 'Master') {
            $this->ApplyMaster((int)$Value);
        } elseif ($Ident == 'Program') {
            $idx = (int)$Value;
            $names = json_decode($this->GetBuffer('Programs'), true);
            if (is_array($names) && isset($names[$idx])) {
                $this->SetValueSafe('Program', $idx);
                $this->SendToParent('play', array('player' => $pid, 'program' => $names[$idx]));
            }
        } elseif ($Ident == 'Loop') {
            $on = (bool)$Value;
            $this->SetValueSafe('Loop', $on);
            $this->SendToParent('config', array('player' => $pid, 'config' => array('loop' => $on)));
        }
    }

    // ---- KNX eingehend ----
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message != VM_UPDATE) return;
        $pid = (int)$this->ReadPropertyInteger('PlayerID');

        if ($SenderID == (int)$this->ReadPropertyInteger('KnxSwitchVarID')) {
            $this->_switch((bool)GetValue($SenderID));

        } elseif ($SenderID == (int)$this->ReadPropertyInteger('KnxAbsDimVarID')) {
            $this->ApplyMaster((int)GetValue($SenderID));

        } elseif ($SenderID == (int)$this->ReadPropertyInteger('KnxRelDimVarID')) {
            $raw = (int)GetValue($SenderID);      // KNX 4-bit DPT 3.007
            $stepCode = $raw & 0x07;              // 0 = Stopp, 1..7 = dimmen
            $up = ($raw & 0x08) != 0;             // Bit3: 1 = heller, 0 = dunkler
            if ($stepCode == 0) {
                $this->SetTimerInterval('KnxDim', 0);
            } else {
                $this->SetBuffer('RelDir', $up ? '1' : '0');
                $this->KnxDimStep();
                $this->SetTimerInterval('KnxDim', 700);
            }
            return;
        }

        // KNX-Abs-Dim je Gruppe
        foreach ($this->GroupKnxMap() as $m) {
            if ((int)($m['dim'] ?? 0) === $SenderID && $SenderID > 0) {
                $gid = (int)($m['group'] ?? 0);
                $v = max(0, min(100, (int)GetValue($SenderID)));
                $this->SetValueSafe('Grp' . $gid, $v);
                $this->SendToParent('group', array('player' => $pid, 'id' => $gid, 'value' => $v));
                return;
            }
        }
    }

    public function KnxDimStep()
    {
        $pid = (int)$this->ReadPropertyInteger('PlayerID');
        $up = $this->GetBuffer('RelDir') === '1';
        $step = max(1, (int)$this->ReadPropertyInteger('KnxStepPercent'));
        $cur = (int)$this->GetValue('Master');
        $new = max(0, min(100, $cur + ($up ? $step : -$step)));
        if ($new != $cur) {
            $this->ApplyMaster($new);
        }
        if ($new <= 0 || $new >= 100) $this->SetTimerInterval('KnxDim', 0);
    }

    // ---- Controller -> Kind: Status + KNX-Status raus ----
    public function ReceiveData($JSONString)
    {
        $d = json_decode($JSONString, true);
        if (!is_array($d) || !isset($d['status']['engine']['players'])) return '';
        $pid = (int)$this->ReadPropertyInteger('PlayerID');
        $me = null;
        foreach ($d['status']['engine']['players'] as $p) {
            if ((int)$p['id'] == $pid) { $me = $p; break; }
        }
        if ($me === null) { $this->SetStatus(104); return ''; }
        $this->SetStatus(102);

        $on = ($me['state'] != 'stop');
        $this->SetValueSafe('Power', $on);
        // Aus -> Master 0 anzeigen (Geraet merkt sich die Helligkeit fuers naechste Ein)
        $this->SetValueSafe('Master', $on ? (int)$me['master'] : 0);
        $this->SetValueSafe('Loop', !empty($me['loop']));
        $dur = isset($me['duration_ms']) ? (int)$me['duration_ms'] : 0;
        $pos = isset($me['position_ms']) ? (int)$me['position_ms'] : 0;
        $this->SetValueSafe('Position', $dur > 0 ? round(100.0 * $pos / $dur, 1) : 0.0);

        $names = array();
        if (isset($me['programs']) && is_array($me['programs'])) {
            foreach ($me['programs'] as $pr) if (isset($pr['name'])) $names[] = (string)$pr['name'];
        }
        $this->UpdateProgramProfile($names, isset($me['program']) ? (string)$me['program'] : '');

        // KNX-Status ausgeben (Dimmwert % = Master wenn an, sonst 0; plus Ein/Aus)
        $lvl = $on ? (int)$me['master'] : 0;
        $sl = (int)$this->ReadPropertyInteger('KnxStatusLevelVarID');
        if ($sl > 0 && IPS_VariableExists($sl) && (int)GetValue($sl) != $lvl) @RequestAction($sl, $lvl);
        $sw = (int)$this->ReadPropertyInteger('KnxStatusSwitchVarID');
        if ($sw > 0 && IPS_VariableExists($sw) && (bool)GetValue($sw) != $on) @RequestAction($sw, $on);

        // Gruppen-Dimmer: Variablen anlegen/aktualisieren + KNX-Status je Gruppe
        if (isset($me['groups']) && is_array($me['groups'])) {
            $this->SyncGroupVars($me['groups']);
            $map = $this->GroupKnxMap();
            foreach ($me['groups'] as $g) {
                $gid = (int)$g['id'];
                $lvl = $on ? (int)$g['level'] : 0;   // Aus -> Status 0
                foreach ($map as $m) {
                    if ((int)($m['group'] ?? 0) === $gid) {
                        $sv = (int)($m['status'] ?? 0);
                        if ($sv > 0 && IPS_VariableExists($sv) && (int)GetValue($sv) != $lvl) @RequestAction($sv, $lvl);
                    }
                }
            }
        }
        return '';
    }

    // ---- Konfigurationsformular: Programm-Optionen dynamisch ----
    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $names = $this->AskPrograms();
        $optOn = array(array('caption' => '— (nur einschalten)', 'value' => ''));
        $optOff = array(array('caption' => '— (Aus / Blackout)', 'value' => ''));
        foreach ($names as $n) {
            $optOn[] = array('caption' => $n, 'value' => $n);
            $optOff[] = array('caption' => $n, 'value' => $n);
        }
        // Gruppen-Optionen fuer die KNX-Zuordnungsliste
        $optGrp = array(array('caption' => '—', 'value' => 0));
        foreach ($this->AskGroups() as $g) {
            $optGrp[] = array('caption' => (string)$g['name'] . ' (#' . (int)$g['id'] . ')', 'value' => (int)$g['id']);
        }
        foreach ($form['elements'] as &$el) {
            if (!isset($el['name'])) continue;
            if ($el['name'] == 'OnProgram') $el['options'] = $optOn;
            if ($el['name'] == 'OffProgram') $el['options'] = $optOff;
            if ($el['name'] == 'GroupKnx' && isset($el['columns'])) {
                foreach ($el['columns'] as &$col) {
                    if (($col['name'] ?? '') == 'group') $col['edit']['options'] = $optGrp;
                }
                unset($col);
            }
        }
        return json_encode($form);
    }

    private function AskPrograms()
    {
        $pid = (int)$this->ReadPropertyInteger('PlayerID');
        $res = @$this->SendDataToParent(json_encode(array(
            'DataID' => $this->DataID, 'cmd' => 'get_programs', 'arg' => array('player' => $pid))));
        $d = json_decode((string)$res, true);
        return (isset($d['programs']) && is_array($d['programs'])) ? $d['programs'] : array();
    }

    // ---- Gruppen-Dimmer ----
    private function GroupKnxMap()
    {
        $j = json_decode((string)$this->ReadPropertyString('GroupKnx'), true);
        return is_array($j) ? $j : array();
    }

    // Gruppen vom Tool holen (Namen/Level/Adressen)
    private function AskGroups()
    {
        $pid = (int)$this->ReadPropertyInteger('PlayerID');
        $res = @$this->SendDataToParent(json_encode(array(
            'DataID' => $this->DataID, 'cmd' => 'get_groups', 'arg' => array('player' => $pid))));
        $d = json_decode((string)$res, true);
        return (isset($d['groups']) && is_array($d['groups'])) ? $d['groups'] : array();
    }

    // Je Gruppe eine Dimmer-Variable (Grp{id}) anlegen/aktualisieren; verwaiste entfernen.
    private function SyncGroupVars($groups)
    {
        $keep = array();
        $pos = 60;
        foreach ($groups as $g) {
            $gid = (int)$g['id'];
            $ident = 'Grp' . $gid;
            $keep[$ident] = true;
            $this->MaintainVariable($ident, (string)$g['name'], 1 /*Integer*/, 'ANP.Percent', $pos++, true);
            $this->EnableAction($ident);
            if (isset($g['level'])) $this->SetValueSafe($ident, (int)$g['level']);
        }
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $cid) {
            $ident = IPS_GetObject($cid)['ObjectIdent'];
            if (strpos($ident, 'Grp') === 0 && !isset($keep[$ident])) {
                $this->UnregisterVariable($ident);
            }
        }
    }

    // ---- Profile / Helfer ----
    private function ProgProfile() { return 'ANP.Prog.' . $this->InstanceID; }

    private function EnsureProfiles()
    {
        if (!IPS_VariableProfileExists('ANP.Percent')) {
            IPS_CreateVariableProfile('ANP.Percent', 1);
            IPS_SetVariableProfileIcon('ANP.Percent', 'Intensity');
        }
        IPS_SetVariableProfileValues('ANP.Percent', 0, 100, 1);
        IPS_SetVariableProfileText('ANP.Percent', '', ' %');
        if (!IPS_VariableProfileExists('ANP.PercentF')) {
            IPS_CreateVariableProfile('ANP.PercentF', 2);
            IPS_SetVariableProfileDigits('ANP.PercentF', 1);
        }
        IPS_SetVariableProfileValues('ANP.PercentF', 0, 100, 0);
        IPS_SetVariableProfileText('ANP.PercentF', '', ' %');
        $p = $this->ProgProfile();
        if (!IPS_VariableProfileExists($p)) IPS_CreateVariableProfile($p, 1);
    }

    private function UpdateProgramProfile($names, $current)
    {
        $prev = json_decode($this->GetBuffer('Programs'), true);
        if (!is_array($prev)) $prev = array();
        if ($prev !== $names) {
            $p = $this->ProgProfile();
            foreach (IPS_GetVariableProfile($p)['Associations'] as $as) {
                @IPS_SetVariableProfileAssociation($p, $as['Value'], '', '', -1);
            }
            for ($i = 0; $i < count($names); $i++) {
                @IPS_SetVariableProfileAssociation($p, $i, $names[$i], '', -1);
            }
            $this->SetBuffer('Programs', json_encode($names));
        }
        $idx = array_search($current, $names, true);
        if ($idx !== false) $this->SetValueSafe('Program', (int)$idx);
    }

    private function SetValueSafe($ident, $val)
    {
        $vid = @$this->GetIDForIdent($ident);
        if ($vid && GetValue($vid) != $val) $this->SetValue($ident, $val);
    }

    private function SendToParent($cmd, $arg)
    {
        return @$this->SendDataToParent(json_encode(array(
            'DataID' => $this->DataID, 'cmd' => $cmd, 'arg' => $arg)));
    }
}
