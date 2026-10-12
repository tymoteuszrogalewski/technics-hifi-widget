<?php
/**
 * hifi.php — a small widget for the Technics SC-C70MK2 (and other Technics units with the same
 * network module): previous / pause (mute) / next / stop, volume and optionally what is playing (cover, title, artist).
 *
 * The network module inside is made by StreamUnlimited (StreamSDK). It has a local HTTP API without
 * a password that the official Technics app does not show:
 *   GET /api/getData?path=<path>&roles=value             -> [ {"type":"i32_","i32_":16} ]
 *   GET /api/setData?path=<path>&role=value&value=<json>  -> true
 * Paths used here: player:volume (0-100), settings:/mediaPlayer/mute, player:player/data (what plays),
 * player:player/control (role=activate, commands play / pause / previous / next).
 *
 * One file: without parameters it shows the widget, ?api=state returns JSON, POST ?api=set controls the unit.
 */

$HIFI_IP = '192.168.1.229';                          // IP of your Technics unit (give it a fixed lease in your router)
$STATE_DIR = sys_get_temp_dir() . '/technics-hifi';  // last played track + last Spotify session
$SHOW_TITLE = true;                                 // false = mini version: only the button row, no cover and title
if (isset($_GET['title'])) $SHOW_TITLE = $_GET['title'] === '1';   // ?title=0 / ?title=1 overrides it per page

// ---------------------------------------------------------------------------------------------
// API of the unit
// ---------------------------------------------------------------------------------------------

// Short timeouts: the unit is often in network standby and the widget must not hang on it.
function hifi_req($endpoint, $query) {
    global $HIFI_IP;
    $ch = curl_init('http://' . $HIFI_IP . '/api/' . $endpoint . '?' . http_build_query($query));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT        => 3,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    if ($raw === false) return null;          // no connection to the unit
    // Player commands answer with a literal `null` (HTTP 200) — that is a success too
    return json_decode($raw, true) ?? true;
}

// Value from getData (roles=value) or null when the unit does not answer / the path does not exist.
function hifi_get($path) {
    $d = hifi_req('getData', ['path' => $path, 'roles' => 'value']);
    return (is_array($d) && isset($d[0]) && is_array($d[0])) ? $d[0] : null;
}

// Success = the unit answered and did NOT return an error. Setting a value returns `true`,
// player commands (role=activate) return `null`.
function hifi_set($path, $value, $role = 'value') {
    $d = hifi_req('setData', ['path' => $path, 'role' => $role, 'value' => json_encode($value)]);
    return $d !== null && !(is_array($d) && isset($d['error']));
}

// ---------------------------------------------------------------------------------------------
// ?api=state — volume, mute, source, what plays
// ---------------------------------------------------------------------------------------------

if (($_GET['api'] ?? '') === 'state') {
    header('Content-Type: application/json');
    $vol = hifi_get('player:volume');
    if ($vol === null) { echo json_encode(['ok' => false, 'offline' => true]); exit; }

    $mute = hifi_get('settings:/mediaPlayer/mute');
    $pd   = hifi_get('player:player/data') ?? [];
    $meta = $pd['trackRoles']['mediaData']['metaData'] ?? ($pd['mediaRoles']['mediaData']['metaData'] ?? []);
    $svc  = $meta['serviceID'] ?? '';

    @mkdir($STATE_DIR, 0777, true);
    // The last Spotify session is remembered — "play" resumes it later without a phone.
    if ($svc === 'spotify' && !empty($pd['mediaRoles'])) {
        @file_put_contents("$STATE_DIR/spotify.json", json_encode($pd['mediaRoles']));
    }
    // The last played track stays on screen after stop (Spotify Connect forgets it right away).
    if (($pd['state'] ?? '') === 'playing' && !empty($pd['trackRoles']['title'])) {
        @file_put_contents("$STATE_DIR/last.json", json_encode([
            'title' => $pd['trackRoles']['title'], 'artist' => $meta['artist'] ?? '', 'album' => $meta['album'] ?? '',
            'cover' => $pd['trackRoles']['icon'] ?? '', 'src' => $svc,
        ]));
    }
    $last = json_decode((string)@file_get_contents("$STATE_DIR/last.json"), true) ?: null;

    $names = ['spotify' => 'Spotify', 'AUX' => 'AUX', 'airplay' => 'AirPlay', 'googlecast' => 'Chromecast',
              'bluetooth' => 'Bluetooth', 'tidal' => 'TIDAL', 'qobuz' => 'Qobuz', 'airable' => 'Radio'];

    echo json_encode([
        'ok'      => true,
        'volume'  => (int)($vol['i32_'] ?? 0),
        'mute'    => (bool)($mute['bool_'] ?? false),
        'state'   => $pd['state'] ?? '',                 // playing / paused / stopped
        'src'     => $svc,                               // raw serviceID (spotify, AUX, ...)
        'srcName' => $names[$svc] ?? $svc,
        'title'   => $pd['trackRoles']['title'] ?? '',
        'artist'  => $meta['artist'] ?? '',
        'album'   => $meta['album'] ?? '',
        'cover'   => $pd['trackRoles']['icon'] ?? '',    // cover URL (Spotify: i.scdn.co)
        'last'    => $last,
    ]);
    exit;
}

// ---------------------------------------------------------------------------------------------
// POST ?api=set — op=vol (delta), op=mute (on 0/1), op=ctl (previous | next | pause | play), op=resume (last Spotify session)
// ---------------------------------------------------------------------------------------------

if (($_GET['api'] ?? '') === 'set') {
    header('Content-Type: application/json');
    $op = $_POST['op'] ?? '';

    if ($op === 'vol') {
        $delta = (int)($_POST['delta'] ?? 0);
        if ($delta < -20 || $delta > 20 || $delta === 0) { echo '{"ok":false}'; exit; }
        $cur = hifi_get('player:volume');
        if ($cur === null) { echo json_encode(['ok' => false, 'offline' => true]); exit; }
        $v = max(0, min(100, (int)$cur['i32_'] + $delta));
        echo json_encode(['ok' => hifi_set('player:volume', ['type' => 'i32_', 'i32_' => $v]), 'volume' => $v]);
        exit;
    }

    if ($op === 'mute') {
        // Pause from the widget = MUTE: pause in Spotify Connect ends the session and play starts over.
        // The music keeps playing quietly, the volume stays untouched, unmute returns to the same level.
        $on = !empty($_POST['on']);
        echo json_encode(['ok' => hifi_set('settings:/mediaPlayer/mute', ['type' => 'bool_', 'bool_' => $on]), 'mute' => $on]);
        exit;
    }

    if ($op === 'ctl') {
        $ctl = $_POST['ctl'] ?? '';
        if (!in_array($ctl, ['previous', 'next', 'pause', 'play'], true)) { echo '{"ok":false}'; exit; }
        echo json_encode(['ok' => hifi_set('player:player/control', ['control' => $ctl], 'activate')]);
        exit;
    }

    if ($op === 'resume') {
        // Resume the last Spotify session (also switches the unit from another input to Spotify).
        $mr = json_decode((string)@file_get_contents("$STATE_DIR/spotify.json"), true);
        if (!$mr) { echo json_encode(['ok' => false, 'error' => 'Play Spotify once from your phone']); exit; }
        echo json_encode(['ok' => hifi_set('player:player/control', ['control' => 'play', 'mediaRoles' => $mr], 'activate')]);
        exit;
    }

    echo '{"ok":false}';
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>HiFi</title>
<style>
body{margin:0;padding:12px;background:#111;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#ddd}
.hifi{box-sizing:border-box;display:flex;flex-direction:column;gap:8px;max-width:440px}
.now{display:flex;align-items:center;gap:10px;min-height:44px}
.now img{width:44px;height:44px;border-radius:6px;object-fit:cover;background:#262626;flex:0 0 44px}
.now .tx{min-width:0;flex:1;white-space:nowrap}
.now .t{font-size:15px;font-weight:600;color:#eee;overflow:hidden;text-overflow:ellipsis}
.now .a{font-size:12px;color:#999;overflow:hidden;text-overflow:ellipsis}
.now.idle img,.now.idle .tx{opacity:.45}
.row{display:flex;gap:8px}
.grp{display:flex;align-items:center;flex:1;background:rgba(255,255,255,0.06);border-radius:10px}
.grp button{flex:1;height:44px;background:none;border:none;border-radius:10px;color:#ddd;cursor:pointer;padding:0;
  display:flex;align-items:center;justify-content:center;-webkit-tap-highlight-color:transparent;touch-action:manipulation}
.grp button:active{background:rgba(255,255,255,0.08);transform:scale(0.93)}
.grp svg{width:28px;height:28px;fill:currentColor;display:block}
#pp{color:#4a9eff}
.vol{flex:0 0 40px;text-align:center;font:700 18px/1 system-ui,sans-serif;color:#fff}
.vol svg{width:26px;height:26px;fill:#888;display:block;margin:0 auto}
.msg{font-size:11px;color:#a08040;min-height:14px}
</style>
</head>
<body>
<div class="hifi">
  <?php if ($SHOW_TITLE): ?>
  <!-- What plays: the last track stays after stop (greyed out), so there is something to resume -->
  <div class="now idle" id="now">
    <img id="cover" alt="" style="display:none">
    <div class="tx"><div class="t" id="title">HiFi</div><div class="a" id="artist">…</div></div>
  </div>
  <?php endif; ?>
  <div class="row">
    <div class="grp">
      <button data-ctl="previous" title="Previous"><svg viewBox="0 0 24 24"><path d="M6 5h2v14H6zM20 5v14L9 12z"/></svg></button>
      <button data-ctl="pp" id="pp" title="Pause (mute) / play"><svg viewBox="0 0 24 24"><path id="pp-ic" d="M7 5v14l12-7z"/></svg></button>
      <button data-ctl="next" title="Next"><svg viewBox="0 0 24 24"><path d="M16 5h2v14h-2zM4 5v14l11-7z"/></svg></button>
      <!-- Real stop: pause only mutes and the music keeps going, so without stop the unit would never go to standby -->
      <button data-ctl="stop" title="Stop"><svg viewBox="0 0 24 24"><path d="M6 6h12v12H6z"/></svg></button>
    </div>
    <div class="grp">
      <button data-d="-1" title="Volume down"><svg viewBox="0 0 24 24"><path d="M5 8h14l-7 9z"/></svg></button>
      <span class="vol" id="vol">–</span>
      <button data-d="1" title="Volume up"><svg viewBox="0 0 24 24"><path d="M5 16h14l-7-9z"/></svg></button>
    </div>
  </div>
  <div class="msg" id="msg"></div>
</div>

<script>
var $ = function(id){ return document.getElementById(id); };
var last = null, state = '', mute = false, vol = null;
// Muted: a crossed speaker instead of the volume number
var SPK_X = '<svg viewBox="0 0 24 24"><path d="M3 9v6h4l5 5V4L7 9z"/><path d="M16 9l6 6M22 9l-6 6" stroke="#888" stroke-width="2" stroke-linecap="round" fill="none"/></svg>';

function post(data){
  return fetch('?api=set', {method: 'POST', body: new URLSearchParams(data)}).then(function(r){ return r.json(); });
}

function paintVol(){
  if (mute) $('vol').innerHTML = SPK_X;
  else $('vol').textContent = vol === null ? '–' : vol;
}
// Middle button: playing and not muted = PAUSE icon, otherwise PLAY
function paintPp(){
  $('pp-ic').setAttribute('d', state === 'playing' && !mute ? 'M6 5h4v14H6zM14 5h4v14h-4z' : 'M7 5v14l12-7z');
}
function setMute(on){
  mute = on; paintPp(); paintVol();
  post({op: 'mute', on: on ? 1 : 0}).then(function(){ setTimeout(poll, 800); });
}

function show(d){
  if (!d || !d.ok) {
    vol = null; state = ''; paintVol();
    if (!last && $('title')) { $('title').textContent = 'HiFi'; $('artist').textContent = d && d.offline ? 'off / no connection' : ''; }
    return;
  }
  state = d.state; mute = !!d.mute; vol = d.volume;
  paintVol(); paintPp();
  if (d.title) last = {title: d.title, artist: d.artist, cover: d.cover, src: d.src};
  else if (!last && d.last) last = {title: d.last.title, artist: d.last.artist, cover: d.last.cover, src: d.last.src};
  if (!$('now')) return;
  // Second line: artist · source when playing; otherwise artist · paused / not playing.
  // When nothing streams, the unit cannot tell "stopped" from "CD / OPT is playing" — so just "not playing".
  var st = state === 'playing' ? (mute ? 'muted' : (d.srcName || '')) : (state === 'paused' ? 'paused' : 'not playing');
  if (last) {
    $('title').textContent = last.title;
    $('artist').textContent = [last.artist, st].filter(Boolean).join(' · ');
    if (last.cover && $('cover').getAttribute('src') !== last.cover) $('cover').setAttribute('src', last.cover);
    $('cover').style.display = last.cover ? '' : 'none';
  } else {
    $('title').textContent = 'HiFi';
    $('artist').textContent = state === 'playing' ? st : 'Spotify not playing';
  }
  $('now').classList.toggle('idle', state !== 'playing' || mute);
}

function poll(){ fetch('?api=state').then(function(r){ return r.json(); }).then(show).catch(function(){ show(null); }); }

document.querySelectorAll('[data-d]').forEach(function(b){
  b.onclick = function(){
    var d = parseInt(b.dataset.d, 10);
    if (vol !== null) { vol = Math.max(0, Math.min(100, vol + d)); paintVol(); }   // optimistic, poll confirms
    post({op: 'vol', delta: d}).then(function(r){ if (r.ok) { vol = r.volume; paintVol(); } else poll(); });
  };
});

document.querySelectorAll('[data-ctl]').forEach(function(b){
  b.onclick = function(){
    var c = b.dataset.ctl;
    if (c === 'stop') {
      // Real stop of Spotify + unmute, so the unit is not left muted for the next source (phone, TV).
      // After a while without playing the unit goes to standby on its own.
      if (mute) setMute(false);
      c = 'pause';
    }
    else if (c === 'pp') {
      // PAUSE = MUTE: pause in Spotify Connect ends the session and "play" starts the playlist from the top.
      // Muted music keeps playing quietly, unmute brings back the same volume (the unit does not change it).
      // Muted and stopped (e.g. the unit went to standby meanwhile): one click unmutes and resumes.
      if (mute) { setMute(false); if (state === 'playing') return; }
      else if (state === 'playing') { setMute(true); return; }
      if (state === 'paused') c = 'play';
      else if (last && last.src === 'spotify') {
        post({op: 'resume'}).then(function(r){ $('msg').textContent = r.ok ? '' : (r.error || 'Could not resume'); setTimeout(poll, 1500); });
        return;
      }
      else c = 'play';
    }
    post({op: 'ctl', ctl: c}).then(function(){ setTimeout(poll, 800); });
  };
});

poll();
setInterval(poll, 3000);   // a new track shows up within 3 s
</script>
</body>
</html>
