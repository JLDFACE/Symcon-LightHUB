<?php

// Eine Cue-Liste von LightHUB als Geraet: Go/Zurueck/Release/Stopp, Cue direkt anwaehlen,
// Master, KNX. Gedacht fuer freie Cue-Listen (keinem Player zugeordnet); Cue-Listen an einem
// Player erscheinen dort ohnehin in der Programmauswahl.
class LightHUBCueList extends IPSModule
{
    private $DataID = '{AE7C1A00-0003-47AE-B000-0000000000E3}';
    private $ControllerID = '{AE7C1A00-0001-47AE-B000-0000000000C1}';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('CuelistID', 1);
        // KNX-Verknuepfungen
        $this->RegisterPropertyInteger('KnxGoVarID', 0);
        $this->RegisterPropertyInteger('KnxBackVarID', 0);
        $this->RegisterPropertyInteger('KnxSwitchVarID', 0);
        $this->RegisterPropertyInteger('KnxAbsDimVarID', 0);
        $this->RegisterPropertyInteger('KnxStatusSwitchVarID', 0);
        $this->RegisterPropertyInteger('KnxStatusLevelVarID', 0);
        $this->RegisterPropertyInteger('KnxStatusCueVarID', 0);

        $this->SetBuffer('CueNames', json_encode(array()));
        $this->SetBuffer('CueCount', '0');

        $this->EnsureProfiles();

        $this->RegisterVariableInteger('Action', 'Steuerung', 'LHC.Action', 10);
        $this->EnableAction('Action');
        $this->RegisterVariableBoolean('Running', 'Läuft', '~Switch', 20);
        $this->EnableAction('Running');
        $this->RegisterVariableInteger('Cue', 'Cue', $this->CueProfile(), 30);
        $this->EnableAction('Cue');
        $this->RegisterVariableString('CueName', 'Aktueller Cue', '', 40);
        $this->RegisterVariableString('NextName', 'Nächster Cue', '', 50);
        $this->RegisterVariableInteger('Master', 'Master', 'ANP.Percent', 60);
        $this->EnableAction('Master');

        // Cue-Namen nicht mitten im Status-Empfang nachladen, sondern kurz danach
        $this->RegisterTimer('Names', 0, 'LHC_UpdateCueNames($_IPS[\'TARGET\']);');
        $this->ConnectParent($this->ControllerID);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->EnsureProfiles();

        foreach ($this->GetMessageList() as $sid => $msgs) {
            foreach ($msgs as $m) {
                if ($m == VM_UPDATE) $this->UnregisterMessage($sid, VM_UPDATE);
            }
        }
        foreach (array('KnxGoVarID', 'KnxBackVarID', 'KnxSwitchVarID', 'KnxAbsDimVarID') as $prop) {
            $vid = (int)$this->ReadPropertyInteger($prop);
            if ($vid > 0 && IPS_VariableExists($vid)) $this->RegisterMessage($vid, VM_UPDATE);
        }

        $this->UpdateCueNames();
        $this->Refresh();
    }

    // ---- Public Steuer-Funktionen (fuer Symcon-Skripte/Ereignisse) ----
    // Naechster Cue: LHC_Go($id)
    public function Go()       { $this->Send('go'); }
    // Bestimmten Cue anfahren (1-basiert): LHC_GoCue($id, 3)
    public function GoCue(int $cue) { $this->Send('go', array('cue' => max(1, (int)$cue))); }
    public function Back()     { $this->Send('back'); }
    // Ausblenden mit der Release-Zeit der Cue-Liste
    public function Release()  { $this->Send('release'); }
    // Sofort aus
    public function Stop()     { $this->Send('stop'); }
    public function SetMasterValue(int $v)
    {
        $v = max(0, min(100, (int)$v));
        $this->SetValueSafe('Master', $v);
        $this->Send('master', array('value' => $v));
    }

    public function Refresh()
    {
        $this->SendToParent('refresh', array());
    }

    // ---- WebFront/Action ----
    public function RequestAction($Ident, $Value)
    {
        if ($Ident == 'Action') {
            switch ((int)$Value) {
                case 1: $this->Go(); break;
                case 2: $this->Back(); break;
                case 3: $this->Release(); break;
                case 4: $this->Stop(); break;
            }
        } elseif ($Ident == 'Running') {
            $this->SwitchList((bool)$Value);
        } elseif ($Ident == 'Cue') {
            if ((int)$Value >= 1) $this->GoCue((int)$Value);
        } elseif ($Ident == 'Master') {
            $this->SetMasterValue((int)$Value);
        }
    }

    // Ein = Go (nur wenn die Liste nicht schon laeuft), Aus = Release
    private function SwitchList($on)
    {
        $this->SetValueSafe('Running', $on);
        if (!$on) {
            $this->Release();
        } elseif (!$this->IsRunning()) {
            $this->Go();
        }
    }

    private function IsRunning()
    {
        return $this->GetBuffer('Releasing') !== '1' && (bool)$this->GetBuffer('RunningHub');
    }

    // ---- KNX eingehend ----
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message != VM_UPDATE || $SenderID <= 0) return;
        if ($SenderID == (int)$this->ReadPropertyInteger('KnxGoVarID')) {
            if ((bool)GetValue($SenderID)) $this->Go();          // Taster: nur die 1 zaehlt
        } elseif ($SenderID == (int)$this->ReadPropertyInteger('KnxBackVarID')) {
            if ((bool)GetValue($SenderID)) $this->Back();
        } elseif ($SenderID == (int)$this->ReadPropertyInteger('KnxSwitchVarID')) {
            $this->SwitchList((bool)GetValue($SenderID));
        } elseif ($SenderID == (int)$this->ReadPropertyInteger('KnxAbsDimVarID')) {
            $this->SetMasterValue((int)GetValue($SenderID));
        }
    }

    // ---- Controller -> Kind: Status ----
    public function ReceiveData($JSONString)
    {
        $d = json_decode($JSONString, true);
        if (!is_array($d) || !isset($d['status'])) return '';
        $lid = (int)$this->ReadPropertyInteger('CuelistID');
        $me = null;
        $list = isset($d['status']['playbacks']) && is_array($d['status']['playbacks']) ? $d['status']['playbacks'] : array();
        foreach ($list as $pb) {
            if ((int)$pb['id'] == $lid) { $me = $pb; break; }
        }
        if ($me === null) { $this->SetStatus(104); return ''; }   // unbekannt oder alter Art-Net DMX Player
        $this->SetStatus(102);

        $running = !empty($me['running']);
        $releasing = !empty($me['releasing']);
        $this->SetBuffer('RunningHub', $running ? '1' : '');
        $this->SetBuffer('Releasing', $releasing ? '1' : '');
        $on = $running && !$releasing;
        $cue = isset($me['cue']) ? (int)$me['cue'] : 0;
        $master = isset($me['master']) ? (int)round((float)$me['master']) : 100;

        $this->SetValueSafe('Running', $on);
        $this->SetValueSafe('Cue', $cue);
        $this->SetValueSafe('CueName', isset($me['cue_name']) ? (string)$me['cue_name'] : '');
        $this->SetValueSafe('NextName', isset($me['next_name']) ? (string)$me['next_name'] : '');
        $this->SetValueSafe('Master', $master);

        // Cue-Namen fuer die Auswahl nachladen, wenn sich die Liste geaendert hat
        $count = isset($me['cues']) ? (int)$me['cues'] : 0;
        $names = json_decode($this->GetBuffer('CueNames'), true);
        if (!is_array($names)) $names = array();
        $this->SetBuffer('CueCount', (string)$count);
        $stale = count($names) != $count;
        // Namen vergleichen nur, wenn sie aus LightHUB kamen (sonst stuende "Cue 1" immer als geaendert da)
        if (!$stale && $this->GetBuffer('NamesFallback') !== '1' && $cue >= 1 && isset($me['cue_name'])
            && ($names[$cue - 1] ?? null) !== (string)$me['cue_name']) {
            $stale = true;
        }
        if ($stale) $this->SetTimerInterval('Names', 200);

        // KNX-Status
        $this->Out('KnxStatusSwitchVarID', $on);
        $this->Out('KnxStatusLevelVarID', $master);
        $this->Out('KnxStatusCueVarID', $cue);
        return '';
    }

    // Cue-Namen holen (braucht in LightHUB die Rolle Admin; sonst "Cue 1", "Cue 2", ...)
    public function UpdateCueNames()
    {
        $this->SetTimerInterval('Names', 0);
        $res = $this->SendToParent('get_cuelist', array());
        $d = json_decode((string)$res, true);
        if (!empty($d['ok']) && isset($d['cues']) && is_array($d['cues'])) {
            $names = array_map('strval', $d['cues']);
            $this->SetBuffer('NamesFallback', '');
        } else {
            $this->SetBuffer('NamesFallback', '1');
            $names = array();
            for ($i = 1; $i <= (int)$this->GetBuffer('CueCount'); $i++) $names[] = 'Cue ' . $i;
        }
        $prev = json_decode($this->GetBuffer('CueNames'), true);
        if ($prev === $names) return;
        $p = $this->CueProfile();
        foreach (IPS_GetVariableProfile($p)['Associations'] as $as) {
            @IPS_SetVariableProfileAssociation($p, $as['Value'], '', '', -1);
        }
        @IPS_SetVariableProfileAssociation($p, 0, '—', '', -1);
        foreach ($names as $i => $n) {
            @IPS_SetVariableProfileAssociation($p, $i + 1, ($i + 1) . ' · ' . $n, '', -1);
        }
        $this->SetBuffer('CueNames', json_encode($names));
    }

    // ---- Profile / Helfer ----
    private function CueProfile() { return 'LHC.Cue.' . $this->InstanceID; }

    private function EnsureProfiles()
    {
        if (!IPS_VariableProfileExists('ANP.Percent')) {
            IPS_CreateVariableProfile('ANP.Percent', 1);
            IPS_SetVariableProfileIcon('ANP.Percent', 'Intensity');
        }
        IPS_SetVariableProfileValues('ANP.Percent', 0, 100, 1);
        IPS_SetVariableProfileText('ANP.Percent', '', ' %');
        if (!IPS_VariableProfileExists('LHC.Action')) {
            IPS_CreateVariableProfile('LHC.Action', 1);
            IPS_SetVariableProfileIcon('LHC.Action', 'Script');
        }
        IPS_SetVariableProfileAssociation('LHC.Action', 1, 'Go', '', -1);
        IPS_SetVariableProfileAssociation('LHC.Action', 2, 'Zurück', '', -1);
        IPS_SetVariableProfileAssociation('LHC.Action', 3, 'Release', '', -1);
        IPS_SetVariableProfileAssociation('LHC.Action', 4, 'Stopp', '', -1);
        $p = $this->CueProfile();
        if (!IPS_VariableProfileExists($p)) {
            IPS_CreateVariableProfile($p, 1);
            IPS_SetVariableProfileAssociation($p, 0, '—', '', -1);
        }
    }

    private function Out($prop, $val)
    {
        $vid = (int)$this->ReadPropertyInteger($prop);
        if ($vid > 0 && IPS_VariableExists($vid) && GetValue($vid) != $val) @RequestAction($vid, $val);
    }

    private function SetValueSafe($ident, $val)
    {
        $vid = @$this->GetIDForIdent($ident);
        if ($vid && GetValue($vid) !== $val) $this->SetValue($ident, $val);
    }

    // Playback-Befehl an LightHUB; Fehlermeldungen (z. B. "hat keine Cues") ins Debug
    private function Send($action, $extra = array())
    {
        $r = json_decode((string)$this->SendToParent('cue', array_merge(array('action' => $action), $extra)), true);
        if (empty($r['ok'])) {
            $this->SendDebug('Cue-Liste', $action . ' fehlgeschlagen: ' . ($r['error'] ?? 'LightHUB nicht erreichbar'), 0);
        }
        return $r;
    }

    private function SendToParent($cmd, $arg)
    {
        $arg['list'] = (int)$this->ReadPropertyInteger('CuelistID');
        return @$this->SendDataToParent(json_encode(array(
            'DataID' => $this->DataID, 'cmd' => $cmd, 'arg' => $arg)));
    }
}
