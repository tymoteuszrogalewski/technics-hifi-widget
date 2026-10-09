#!/usr/bin/env php
<?php
/**
 * start-volume.php — the unit always starts with the same volume. Run from cron every minute:
 *   * * * * * php /path/to/start-volume.php
 *
 * Why: someone listens loud, the unit goes to standby, in the evening the TV turns on (sound through OPT)
 * and suddenly it is way too loud.
 *
 * How:
 *   1. In STANDBY (settings:/system/hostPower = false) the volume is set to START_VOL in advance —
 *      the unit wakes up already with that volume.
 *   2. On the change standby -> on it is set once more, in case the unit restores its own old volume.
 * No answer from the unit (e.g. unplugged): nothing is done and the remembered state is not changed.
 */

$HIFI_IP = '192.168.1.229';
const START_VOL = 16;                                          // start volume (scale 0-100)
$STATE_FILE = sys_get_temp_dir() . '/technics-hifi-power';     // last power state: '1' / '0'

function hifi_req($endpoint, $query) {
    global $HIFI_IP;
    $ch = curl_init('http://' . $HIFI_IP . '/api/' . $endpoint . '?' . http_build_query($query));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 3]);
    $raw = curl_exec($ch);
    curl_close($ch);
    if ($raw === false) return null;
    return json_decode($raw, true) ?? true;
}
function hifi_get($path) {
    $d = hifi_req('getData', ['path' => $path, 'roles' => 'value']);
    return (is_array($d) && isset($d[0]) && is_array($d[0])) ? $d[0] : null;
}
function hifi_set($path, $value) {
    $d = hifi_req('setData', ['path' => $path, 'role' => 'value', 'value' => json_encode($value)]);
    return $d !== null && !(is_array($d) && isset($d['error']));
}
function logMsg($s) { echo date('Y-m-d H:i:s') . " | {$s}\n"; }

$pw  = hifi_get('settings:/system/hostPower');
$vol = hifi_get('player:volume');
if ($pw === null || $vol === null) { logMsg('unit does not answer - nothing to do'); exit(0); }

$on   = (bool)($pw['bool_'] ?? true);
$v    = (int)($vol['i32_'] ?? -1);
$prev = @file_get_contents($STATE_FILE);       // false on the first run
@file_put_contents($STATE_FILE, $on ? '1' : '0');

$why = null;
if (!$on && $v !== START_VOL)                      $why = 'standby';
elseif ($on && $prev === '0' && $v !== START_VOL)  $why = 'power on';

if ($why === null) { logMsg(($on ? 'on' : 'standby') . ", volume {$v} - no change"); exit(0); }

$ok = hifi_set('player:volume', ['type' => 'i32_', 'i32_' => START_VOL]);
logMsg("{$why}: volume {$v} -> " . START_VOL . ($ok ? '' : ' (WRITE FAILED)'));
