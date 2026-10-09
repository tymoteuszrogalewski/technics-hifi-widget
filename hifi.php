<?php
/**
 * hifi.php — a small widget for the Technics SC-C70MK2 (and other Technics units with the same
 * network module): volume, what is playing (cover, title, artist), previous / stop-play / next.
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
// POST ?api=set — op=vol (delta), op=ctl (previous | next | pause | play), op=resume (last Spotify session)
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
.hifi{box-sizing:border-box;background:#1e1e1e;border:1px solid #2c2c2c;border-radius:12px;padding:10px 14px;max-width:440px}
.row{display:flex;align-items:center;min-height:44px;gap:8px}
.lbl{flex:1;font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#7fb0ff}
.lbl .ic{font-size:14px;margin-right:6px}
.src{font-size:13px;color:#999;margin-left:8px;font-weight:400;letter-spacing:0;text-transform:none}
button{background:#2a2a2a;border:none;border-radius:6px;color:#9cc0ff;cursor:pointer;width:44px;height:44px;display:flex;align-items:center;justify-content:center;font-size:22px;-webkit-tap-highlight-color:transparent;touch-action:manipulation}
button:active{background:#333;transform:scale(0.93)}
button svg{width:20px;height:20px;fill:currentColor}
.vol{font-size:24px;font-weight:300;min-width:44px;text-align:center}
.vol.mute{color:#666;text-decoration:line-through}
.now{display:none;align-items:center;gap:10px;padding:4px 0 6px}
.now img{width:48px;height:48px;border-radius:6px;object-fit:cover;background:#262626;flex:0 0 48px}
.now .tx{min-width:0;flex:1}
.now .t{font-size:15px;color:#ddd;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.now .a{font-size:12px;color:#888;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.now.idle img,.now.idle .tx{opacity:.45}
.ctl{display:flex;align-items:center;gap:6px}
.msg{font-size:11px;color:#a08040;min-height:14px}
</style>
</head>
<body>
<div class="hifi">
  <div class="row"><div class="lbl"><span class="ic">🎵</span>HiFi<span class="src" id="src">…</span></div></div>
  <div class="row">
    <div class="lbl">Volume</div>
    <div class="ctl">
      <button data-d="-1" title="Volume down"><svg viewBox="0 0 24 24"><path d="M5 8h14l-7 9z"/></svg></button>
      <span class="vol" id="vol">–</span>
      <button data-d="1" title="Volume up"><svg viewBox="0 0 24 24"><path d="M5 16h14l-7-9z"/></svg></button>
    </div>
  </div>
  <div class="now" id="now">
    <img id="cover" alt="">
    <div class="tx"><div class="t" id="title"></div><div class="a" id="artist"></div></div>
    <div class="ctl">
      <button data-ctl="previous" title="Previous"><svg viewBox="0 0 24 24"><path d="M6 5h2v14H6zM20 5v14L9 12z"/></svg></button>
      <button data-ctl="pp" id="pp" title="Stop / play"><svg viewBox="0 0 24 24"><path id="pp-ic" d="M6 6h12v12H6z"/></svg></button>
      <button data-ctl="next" title="Next"><svg viewBox="0 0 24 24"><path d="M16 5h2v14h-2zM4 5v14l11-7z"/></svg></button>
    </div>
  </div>
  <div class="msg" id="msg"></div>
</div>

<script>
var $ = function(id){ return document.getElementById(id); };
var last = null, state = '';

function post(data){
  return fetch('?api=set', {method: 'POST', body: new URLSearchParams(data)}).then(function(r){ return r.json(); });
}

function show(d){
  if (!d || !d.ok) {
    $('vol').textContent = '–';
    $('src').textContent = d && d.offline ? 'off / no connection' : '';
    return;
  }
  $('vol').textContent = d.volume;
  $('vol').classList.toggle('mute', !!d.mute);
  if (d.title) last = {title: d.title, artist: [d.artist, d.album].filter(Boolean).join(' · '), cover: d.cover, src: d.src};
  else if (!last && d.last) last = {title: d.last.title, artist: [d.last.artist, d.last.album].filter(Boolean).join(' · '), cover: d.last.cover, src: d.last.src};
  // When nothing streams, the unit cannot tell "stopped" from "CD / OPT is playing" — so just "not playing".
  $('src').textContent = d.state === 'playing' ? (d.srcName || '—') : (d.state === 'paused' ? d.srcName + ' (paused)' : 'Spotify not playing');
  // Pause in Spotify Connect stops the session anyway (state "stopped"), so the middle button is STOP.
  $('pp-ic').setAttribute('d', d.state === 'playing' ? 'M6 6h12v12H6z' : 'M7 5v14l12-7z');
  state = d.state;
  if (last) {
    $('title').textContent = last.title;
    $('artist').textContent = last.artist;
    if (last.cover && $('cover').getAttribute('src') !== last.cover) $('cover').setAttribute('src', last.cover);
    $('cover').style.display = last.cover ? '' : 'none';
    $('now').classList.toggle('idle', d.state !== 'playing');
    $('now').style.display = 'flex';
  }
}

function poll(){ fetch('?api=state').then(function(r){ return r.json(); }).then(show).catch(function(){ show(null); }); }

document.querySelectorAll('[data-d]').forEach(function(b){
  b.onclick = function(){
    var d = parseInt(b.dataset.d, 10), cur = parseInt($('vol').textContent, 10);
    if (!isNaN(cur)) $('vol').textContent = Math.max(0, Math.min(100, cur + d));   // optimistic, poll confirms
    post({op: 'vol', delta: d}).then(function(r){ if (r.ok) $('vol').textContent = r.volume; else poll(); });
  };
});

document.querySelectorAll('[data-ctl]').forEach(function(b){
  b.onclick = function(){
    var c = b.dataset.ctl;
    if (c === 'pp') {
      if (state === 'playing') c = 'pause';
      else if (state !== 'paused' && last && last.src === 'spotify') {
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
