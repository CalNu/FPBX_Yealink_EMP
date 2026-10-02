<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (php_sapi_name() !== 'cli' && !defined('FREEPBX_IS_AUTH')) { 
    die('No direct script access allowed'); 
}

// Keep PHP notices out of this module's HTML/JSON output. Do NOT call error_reporting(E_ALL) here:
// it stays in effect for the rest of the request, and FreePBX's own config.php runs AFTER this page
// (e.g. it reads $_SERVER['HTTP_REFERER'] unguarded). With E_ALL on, FreePBX's Whoops handler turns that
// harmless notice into a fatal "Undefined index: HTTP_REFERER" whenever the browser sends no Referer.
ini_set('display_errors', 0);

// ============================================================================
// 0. MOD-SIGN: Direct Native Execution & Instant Reload
// ============================================================================
$elevationError = '';
if (isset($_POST['action']) && ($_POST['action'] === 'resign_custom_module_with_pass' || $_POST['action'] === 'resign_yealink_epm_module')) {
    $modulePath = '/var/www/html/admin/modules/yealink_epm';
    $signerScript = file_exists("{$modulePath}/devtools/signer.php") 
        ? "{$modulePath}/devtools/signer.php" 
        : "{$modulePath}/signer.php";

    if (!file_exists($signerScript)) {
        $elevationError = "Signer script not found at {$signerScript}";
    } else {
        $cmd = sprintf('/usr/bin/php %s %s 2>&1', escapeshellarg($signerScript), escapeshellarg($modulePath));
        
        $output = [];
        exec($cmd, $output, $returnVar);
        clearstatcache(true, "{$modulePath}/module.sig");

        if (file_exists("{$modulePath}/module.sig")) {
            if (isset($pdo)) {
                try {
                    $pdo->exec("DELETE FROM notifications WHERE module IN ('core', 'framework', 'freepbx') AND id IN ('RBNUM', 'SIGNATURE_NOT_VALID', 'TAMPERED_FILES')");
                } catch (\Exception $e) {}
            }

            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            echo '<script type="text/javascript">window.location.replace("config.php?display=yealink_epm");</script>';
            exit();
        } else {
            $elevationError = "<b>Signing Failed:</b> " . htmlspecialchars(implode("\n", $output) ?: "Unknown execution error.");
        }
    }
}

// --- CHECK IF SECURITY WARNING EXISTS FOR CONDITIONAL DISPLAY ---
$show_resign_button = false;
if (class_exists('FreePBX')) {
    $notifications = \FreePBX::Notifications();
    if (
        $notifications->exists('core', 'SIGNATURE_NOT_VALID') || 
        $notifications->exists('framework', 'TAMPERED_FILES') ||
        !file_exists('/var/www/html/admin/modules/yealink_epm/module.sig')
    ) {
        $show_resign_button = true;
    }
}

// ============================================================================
// 1. MODULE VERSION & DIRECTORY INITIALIZATION
// ============================================================================

$module_xml_path = __DIR__ . '/module.xml';
$cfg_version = "1.0.0.0"; 
if (file_exists($module_xml_path)) {
    $xml_obj = @simplexml_load_file($module_xml_path);
    if ($xml_obj && !empty($xml_obj->version)) {
        $cfg_version = (string)$xml_obj->version;
    }
}

$generated_common_cfg = "";
$generated_template_cfg = "";
$status = "";
$tftp_dir = "/tftpboot/";
$template_dir = "/tftpboot/templates/";
$logo_dir = "/var/www/html/PhoneSettings/logo/";
$ringtone_dir = "/var/www/html/PhoneSettings/ringtones/";
$vpnkeys_dir = "/var/www/html/PhoneSettings/vpnkeys/";
$ringtone_was_deleted = false;
$just_flushed = false;

foreach ([$logo_dir, $ringtone_dir, $template_dir, $vpnkeys_dir] as $dir) {
    if (!file_exists($dir)) {
        @mkdir($dir, 0775, true);
        @chown($dir, 'asterisk');
    }
}

if (!file_exists($tftp_dir)) {
    @mkdir($tftp_dir, 0775, true);
    @chown($tftp_dir, 'asterisk');
}

// ============================================================================
// 1.5. YEALINK "GLOBAL" (y-config) FILENAMES
// ============================================================================
if (!function_exists('yealinkGlobalCfgMap')) {
	function yealinkGlobalCfgMap() {
		return [
			'y000000000000' => 'Global Legacy Base',
			'y000000000053' => 'SIP-T19 E2 / T19P E2',
			'y000000000052' => 'SIP-T21 E2 / T21P E2',
			'y000000000044' => 'SIP-T23P / SIP-T23G',
			'y000000000069' => 'SIP-T27G',
			'y000000000046' => 'SIP-T29G',
			'y000000000127' => 'SIP-T30P / SIP-T30',
			'y000000000123' => 'SIP-T31P / SIP-T31G / SIP-T31',
			'y000000000172' => 'SIP-T31W',
			'y000000000124' => 'SIP-T33P / SIP-T33G',
			'y000000000171' => 'SIP-T34W',
			'y000000000076' => 'SIP-T40G',
			'y000000000054' => 'SIP-T40P',
			'y000000000036' => 'SIP-T41P',
			'y000000000068' => 'SIP-T41S',
			'y000000000116' => 'SIP-T42U',
			'y000000000107' => 'SIP-T43U',
			'y000000000173' => 'SIP-T44U',
			'y000000000174' => 'SIP-T44W',
			'y000000000035' => 'SIP-T48G',
			'y000000000065' => 'SIP-T48S',
			'y000000000097' => 'SIP-T57W',
			'y000000000058' => 'SIP-T58A',
			'y000000000150' => 'SIP-T58W',
			'y000000000091' => 'VP59',
		];
	}
}
if (!function_exists('isYealinkGlobalCfgBasename')) {
    function isYealinkGlobalCfgBasename($basename) {
        return array_key_exists(strtolower((string)$basename), yealinkGlobalCfgMap());
    }
}

// ============================================================================
// 2. DETECT SERVER TIMEZONE & YEALINK MAPPING
// ============================================================================

function getServerTimezone() {
    if (file_exists('/etc/timezone')) {
        $tz = trim(file_get_contents('/etc/timezone'));
        if (!empty($tz)) return $tz;
    }
    if (is_link('/etc/localtime')) {
        $filename = readlink('/etc/localtime');
        $pos = strpos($filename, 'zoneinfo/');
        if ($pos !== false) {
            return substr($filename, $pos + 9);
        }
    }
    return date_default_timezone_get() ?: 'America/Los_Angeles';
}

$server_tz_identifier = getServerTimezone();

$yealink_tz_mapping = [
    'America/Adak'           => ['offset' => '-10', 'name' => 'United States-Hawaii-Aleutian'],
    'Pacific/Honolulu'       => ['offset' => '-10', 'name' => 'United States-Hawaii-Aleutian'],
    'America/Anchorage'      => ['offset' => '-9',  'name' => 'United States-Alaska Time'],
    'America/Los_Angeles'    => ['offset' => '-8',  'name' => 'United States-Pacific Time'],
    'America/Tijuana'        => ['offset' => '-8',  'name' => 'Mexico(Tijuana,Mexicali)'],
    'America/Vancouver'      => ['offset' => '-8',  'name' => 'Canada(Vancouver,Whitehorse)'],
    'America/Denver'         => ['offset' => '-7',  'name' => 'United States-Mountain Time'],
    'America/Phoenix'        => ['offset' => '-7',  'name' => 'United States-MST no DST'],
    'America/Chicago'        => ['offset' => '-6',  'name' => 'United States-Central Time'],
    'America/New_York'       => ['offset' => '-5',  'name' => 'United States-Eastern Time'],
    'America/Halifax'        => ['offset' => '-4',  'name' => 'Canada(Halifax,Saint John)'],
    'Europe/London'          => ['offset' => '0',   'name' => 'United Kingdom(London)'],
    'Europe/Paris'           => ['offset' => '+1',  'name' => 'France(Paris)'],
    'Europe/Berlin'          => ['offset' => '+1',  'name' => 'Germany(Berlin)'],
    'Asia/Tokyo'             => ['offset' => '+9',  'name' => 'Japan(Tokyo)'],
    'Australia/Sydney'       => ['offset' => '+10', 'name' => 'Australia(Sydney,Melbourne,Canberra)']
];

$detected_tz_info = $yealink_tz_mapping[$server_tz_identifier] ?? ['offset' => '-8', 'name' => 'United States-Pacific Time'];

// ============================================================================
// 3. DETECT GLOBAL HTTPS REDIRECT & DETERMINE PROVISIONING PORT / GUI ADDR
// ============================================================================

// The previous check called sysadmin_get_storage_settings() and looked for an
// 'https_redirect' key. That function is real, but it belongs to Sysadmin's
// storage/backup email-notification settings — it has nothing to do with
// HTTP/HTTPS redirection or Port Management, and never returns an
// 'https_redirect' key. So $sysadmin_redirect was always false, regardless
// of how Port Management was actually configured, and toggling "Force" in
// Port Management never changed anything here.
//
// There also isn't a single reliable "is HTTPS forced" flag we can query:
// FreePBX's Port Management treats "HTTP Provisioning" as its own service
// with its own port (83 by default), separate from whether the admin GUI's
// HTTP is forced to redirect to HTTPS. What actually matters for phones is
// simply whether something is listening on that HTTP provisioning port, so
// we test that directly instead of trusting a config flag.
$sysadmin_redirect = false;   // decided below, once $detected_host is known

// ---------------------------------------------------------------------------
// HTTP provisioning port. When http://<server>/PhoneSettings (or /tftpboot) is being
// forwarded to https, phones can't download from port 80, so every http:// URL the module
// writes (provisioning, vpn.tar, ringtones, logo/wallpaper) is shifted to this port. It is
// user-selectable (Global Settings, next to PBX Server IP) and defaults to 83. It only
// takes effect while a redirect is detected - the detection runs on every page load.
// Stored in a small dotfile so it is known before the global .cfg is parsed.
// ---------------------------------------------------------------------------
function yealink_epm_prov_port($set = null) {
    static $port = 83;
    if ($set !== null) { $port = (int)$set; }
    return $port;
}
function yealink_epm_settings_path() {
    return '/tftpboot/.yealink_epm_settings.json';
}
function yealink_epm_valid_port($p) {
    $p = trim((string)$p);
    return preg_match('/^\d{1,5}$/', $p) === 1 && (int)$p >= 1 && (int)$p <= 65535
        && !in_array((int)$p, [80, 443], true);
}
function yealink_epm_load_prov_port() {
    $f = yealink_epm_settings_path();
    if (is_file($f)) {
        $j = json_decode((string)@file_get_contents($f), true);
        if (is_array($j) && isset($j['prov_http_port']) && yealink_epm_valid_port($j['prov_http_port'])) {
            return (int)$j['prov_http_port'];
        }
    }
    return 83;
}
function yealink_epm_save_prov_port($port) {
    $f = yealink_epm_settings_path();
    $j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : [];
    if (!is_array($j)) { $j = []; }
    $j['prov_http_port'] = (int)$port;
    $ok = @file_put_contents($f, json_encode($j)) !== false;
    if ($ok) {
        @chown($f, 'asterisk');
        @chmod($f, 0664);
    }
    return $ok;
}

yealink_epm_prov_port(yealink_epm_load_prov_port());
$epm_prov_port_notice = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_global']) && isset($_POST['prov_http_port'])) {
    $posted_port = trim((string)$_POST['prov_http_port']);
    if (!yealink_epm_valid_port($posted_port)) {
        $epm_prov_port_notice = "Port '" . htmlspecialchars($posted_port) . "' is not valid (use 1-65535, not 80 or 443); kept port " . yealink_epm_prov_port() . ".";
    } elseif ((int)$posted_port !== yealink_epm_prov_port()) {
        if (yealink_epm_save_prov_port($posted_port)) {
            yealink_epm_prov_port($posted_port);
        } else {
            $epm_prov_port_notice = "Could not save the port (is " . yealink_epm_settings_path() . " writable?); kept port " . yealink_epm_prov_port() . ".";
        }
    }
}

// TCP ports something is currently listening on (any address), from /proc/net/tcp{,6}.
function epm_listening_tcp_ports() {
    $ports = [];
    foreach (['/proc/net/tcp', '/proc/net/tcp6'] as $f) {
        $rows = @file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$rows) { continue; }
        foreach (array_slice($rows, 1) as $r) {
            $c = preg_split('/\s+/', trim($r));
            if (isset($c[1], $c[3]) && $c[3] === '0A' && preg_match('/:([0-9A-Fa-f]{4})$/', $c[1], $m)) {
                $ports[hexdec($m[1])] = true;
            }
        }
    }
    $out = array_keys($ports);
    sort($out);
    return $out;
}

// One raw HTTP GET (no redirects followed). ['connected'=>bool, 'code'=>int|null, 'location'=>string]
function yealink_epm_http_probe($connect_host, $port, $host_header, $path) {
    $res = ['connected' => false, 'code' => null, 'location' => ''];
    $fp = @fsockopen($connect_host, (int)$port, $errno, $errstr, 1.0);
    if (!$fp) {
        return $res;
    }
    $res['connected'] = true;
    stream_set_timeout($fp, 2);
    @fwrite($fp, "GET {$path} HTTP/1.0\r\nHost: {$host_header}\r\nUser-Agent: yealink-epm-probe\r\nConnection: close\r\n\r\n");
    $head = '';
    while (!feof($fp) && strlen($head) < 8192) {
        $line = fgets($fp, 2048);
        if ($line === false) { break; }
        $head .= $line;
        if (trim($line) === '') { break; }
    }
    @fclose($fp);
    if (preg_match('#^HTTP/\d\.\d\s+(\d{3})#', $head, $m)) {
        $res['code'] = (int)$m[1];
    }
    if (preg_match('#^Location:\s*(\S+)#im', $head, $lm)) {
        $res['location'] = $lm[1];
    }
    return $res;
}

// Does a plain-HTTP request for $path on port 80 get answered with a redirect to https://?
// true / false, or null when port 80 could not be reached or did not answer HTTP (inconclusive).
function yealink_epm_http_path_redirects($connect_host, $host_header, $path) {
    $r = yealink_epm_http_probe($connect_host, 80, $host_header, $path);
    if (!$r['connected'] || $r['code'] === null) {
        return null;
    }
    return in_array($r['code'], [301, 302, 303, 307, 308], true)
        && stripos($r['location'], 'https://') === 0;
}

// True when http://<server>/PhoneSettings/ or /tftpboot/ is being forwarded to https.
// Falls back to "is anything listening on the HTTP provisioning port" only when port 80
// can't be probed.
function yealink_epm_detect_https_redirect($lan_host) {
    $any_answer = false;
    foreach (array_unique([$lan_host, '127.0.0.1']) as $connect_host) {
        foreach (['/PhoneSettings/', '/tftpboot/'] as $path) {
            $r = yealink_epm_http_path_redirects($connect_host, $lan_host, $path);
            if ($r === true) { return true; }
            if ($r === false) { $any_answer = true; }
        }
        if ($any_answer) { return false; }   // port 80 answered and did not redirect
    }
    $fp = @fsockopen('127.0.0.1', yealink_epm_prov_port(), $errno, $errstr, 0.5);
    if ($fp) {
        @fclose($fp);
        return true;
    }
    return false;
}

// ---------------------------------------------------------------------------
// Root helper. Apache's listener can only be changed by root, so a ONE-TIME setup-root.sh
// (run once over SSH, like ovpn_mgr's) installs a root-owned helper plus a narrow sudoers
// rule. After that, picking a new port in Global Settings is applied from the page itself.
// ---------------------------------------------------------------------------
if (!defined('EPM_CTL_VERSION')) { define('EPM_CTL_VERSION', '2'); }
function epm_ctl_path() { return '/usr/local/sbin/yealink_epm_ctl'; }

// Runs the helper through sudo -n (fails fast instead of waiting for a password).
function epm_ctl_run(array $args) {
    $bin = epm_ctl_path();
    if (!is_executable($bin)) { return ['ok' => false, 'out' => '', 'missing' => true]; }
    if (!function_exists('exec')) { return ['ok' => false, 'out' => 'exec() is disabled in PHP.', 'missing' => false]; }
    $cmd = 'timeout 60 sudo -n ' . escapeshellarg($bin) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    $lines = [];
    $code = 1;
    @exec($cmd, $lines, $code);
    return ['ok' => ($code === 0), 'out' => trim(implode("\n", $lines)), 'missing' => false];
}

// 'ready' | 'missing' (setup-root.sh never run) | 'nosudo' (helper present, sudo rule missing)
// | 'outdated' (helper older than this module - re-run setup-root.sh). Cached per request.
function epm_ctl_info($set = null) {
    static $i = ['user' => '', 'out' => ''];
    if ($set !== null) { $i = $set; }
    return $i;
}
function epm_ctl_status() {
    static $st = null;
    if ($st !== null) { return $st; }
    $r = epm_ctl_run(['version']);
    $who = '';
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $pw = @posix_getpwuid(posix_geteuid());
        $who = is_array($pw) ? (string)$pw['name'] : '';
    }
    epm_ctl_info(['user' => $who, 'out' => (string)$r['out']]);
    if (!empty($r['missing']))                  { return $st = 'missing'; }
    if (!$r['ok'])                              { return $st = 'nosudo'; }
    if (trim($r['out']) !== EPM_CTL_VERSION)    { return $st = 'outdated'; }
    return $st = 'ready';
}

// Asks the helper to make Apache serve /PhoneSettings and /tftpboot on $port.
function epm_apply_prov_port($port) {
    if (!yealink_epm_valid_port($port)) { return ['ok' => false, 'message' => 'Invalid port.']; }
    if (epm_ctl_status() !== 'ready')   { return ['ok' => false, 'message' => 'The one-time root setup has not been run (or is out of date).']; }
    $r = epm_ctl_run(['setport', (string)(int)$port]);
    if (!$r['ok']) {
        $msg = $r['out'] !== '' ? $r['out'] : 'The helper returned an error.';
        return ['ok' => false, 'message' => mb_substr($msg, 0, 600)];
    }
    $warn = [];
    foreach (preg_split('/\r?\n/', $r['out']) as $l) {
        if (stripos($l, 'WARNING') !== false) { $warn[] = trim(preg_replace('/^\[[^\]]*\]\s*WARNING:\s*/', '', $l)); }
    }
    return ['ok' => true, 'message' => "Apache now serves /PhoneSettings and /tftpboot on port " . (int)$port . '.'
        . ($warn ? ' Note: ' . implode(' ', $warn) : '')];
}

// Port of the listener this module's root helper created, read from its (world-readable) config.
function epm_own_listener_port() {
    foreach (['/etc/apache2/sites-available/yealink_epm_prov.conf', '/etc/httpd/conf.d/yealink_epm_prov.conf'] as $f) {
        $c = @file_get_contents($f);
        if ($c !== false && preg_match('/^Listen\s+(\d{1,5})\s*$/m', $c, $m)) {
            return (int)$m[1];
        }
    }
    return null;
}

// Can phones use $port for http provisioning? state: 'serving' (our /PhoneSettings answers
// there over plain HTTP), 'free' (nothing listening yet), 'in_use' (another service owns it),
// 'redirected' (this module's own listener, but a rule still redirects it to https) or 'invalid'. $redirect = whether an http->https redirect is currently detected.
function yealink_epm_check_prov_port($port, $lan_host, $redirect = false) {
    if (!yealink_epm_valid_port($port)) {
        return ['state' => 'invalid', 'message' => 'Port must be a number from 1 to 65535 (not 80 or 443).'];
    }
    $port = (int)$port;
    $listening = in_array($port, epm_listening_tcp_ports(), true);
    $probe = null;
    foreach (array_unique([$lan_host, '127.0.0.1']) as $h) {
        $r = yealink_epm_http_probe($h, $port, $lan_host, '/PhoneSettings/');
        if ($r['connected']) { $probe = $r; break; }
    }
    if ($probe === null) {
        if ($listening) {
            return ['state' => 'in_use', 'message' => "Port {$port} is already in use by another service on this server. Pick a different port."];
        }
        $msg = "Nothing is listening on port {$port} yet.";
        if ($redirect) {
            $msg .= (epm_ctl_status() === 'ready')
                ? ' Saving (or the button in the banner) sets it up in Apache automatically.'
                : ' Run the one-time setup command shown at the top of this page; after that, port changes are applied from here.';
        }
        return ['state' => 'free', 'message' => $msg];
    }
    if (in_array($probe['code'], [200, 403], true)) {
        return ['state' => 'serving', 'message' => "Port {$port} is serving /PhoneSettings over plain HTTP."];
    }
    $is_redirect = in_array($probe['code'], [301, 302, 303, 307, 308], true);
    $loc = $probe['location'] !== '' ? " to {$probe['location']}" : '';
    if ($is_redirect && epm_own_listener_port() === $port) {
        return ['state' => 'redirected', 'message' => "Apache is listening on port {$port} for this module, but requests are still being redirected{$loc}. Another redirect rule is catching this port too."];
    }
    $what = $probe['code'] === null ? 'it did not answer HTTP' : "it answered HTTP {$probe['code']}{$loc} for /PhoneSettings/";
    return ['state' => 'in_use', 'message' => "Port {$port} is already in use by another service ({$what}). Pick a different port."];
}

// Re-derives host:port for the provisioning/asset target from the CURRENT
// sysadmin_redirect state, rather than trusting a port baked into a
// previously-saved value. Only the hostname is preserved from $host_string;
// the port is always recomputed so toggling the global HTTPS redirect
// setting takes effect immediately, without a stale (or missing) port
// values persisting from before the setting was changed.
function yealink_epm_apply_redirect_port($host_string, $sysadmin_redirect) {
    if (empty($host_string)) {
        return $host_string;
    }
    $bare = $host_string;
    if (strpos($bare, '://') !== false) {
        $parsed = parse_url($bare, PHP_URL_HOST);
        if (!empty($parsed)) {
            $bare = $parsed;
        }
    }
    $bare = explode(':', $bare)[0];
    return $sysadmin_redirect ? "{$bare}:" . yealink_epm_prov_port() : $bare;
}

// URL of an empty tarball the phone is pointed at when its VPN is turned off. The running
// phone re-reads openvpn.url from its next config, downloads the empty archive and stops
// trying to reach the VPN - no reboot needed, and no more connection attempts in the log.
function yealink_epm_fake_vpn_url($server_ip, $sysadmin_redirect) {
    return "http://" . yealink_epm_apply_redirect_port($server_ip, $sysadmin_redirect) . "/PhoneSettings/fakekeys/null.tar";
}

// Is TFTP available on this box? 'running' (something listens on UDP 69), 'installed'
// (server files exist but nothing is listening) or 'missing'.
function epm_tftp_status() {
    $readable = false;
    foreach (['/proc/net/udp', '/proc/net/udp6'] as $f) {
        $rows = @file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$rows) { continue; }
        $readable = true;
        foreach (array_slice($rows, 1) as $r) {
            $cols = preg_split('/\s+/', trim($r));
            if (isset($cols[1]) && preg_match('/:0045$/i', $cols[1])) { return 'running'; }
        }
    }
    if (!$readable && function_exists('shell_exec')) {
        $ss = @shell_exec('ss -lun 2>/dev/null');
        if (is_string($ss) && preg_match('/[:.]69\s/', $ss)) { return 'running'; }
    }
    foreach (['/usr/sbin/in.tftpd', '/usr/libexec/tftpd', '/usr/sbin/tftpd', '/etc/default/tftpd-hpa',
              '/lib/systemd/system/tftpd-hpa.service', '/usr/lib/systemd/system/tftp.socket',
              '/usr/lib/systemd/system/tftp.service', '/etc/xinetd.d/tftp'] as $p) {
        if (file_exists($p)) { return 'installed'; }
    }
    return 'missing';
}

$raw_host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_ADDR'] ?? '';
if (strpos($raw_host, ':') !== false) {
    $raw_host = explode(':', $raw_host)[0];
}

$get_lan_ip = function() use ($raw_host) {
    if (!empty($raw_host) && filter_var($raw_host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && strpos($raw_host, '127.') !== 0) {
        return $raw_host;
    }
    if (!empty($_SERVER['SERVER_ADDR']) && filter_var($_SERVER['SERVER_ADDR'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && strpos($_SERVER['SERVER_ADDR'], '127.') !== 0) {
        return $_SERVER['SERVER_ADDR'];
    }
    $sock = @fsockopen('8.8.8.8', 53, $errno, $errstr, 1);
    if ($sock) {
        $sockname = @getsockname($sock, $local_ip, $local_port);
        @fclose($sock);
        if ($sockname && filter_var($local_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && strpos($local_ip, '127.') !== 0) {
            return $local_ip;
        }
    }
    return '192.168.1.1';
};

$detected_host = $get_lan_ip();
$sysadmin_redirect = yealink_epm_detect_https_redirect($detected_host);
$epm_prov_port_state = $sysadmin_redirect ? yealink_epm_check_prov_port(yealink_epm_prov_port(), $detected_host, true) : null;

// Saving Global Settings while a redirect is active and the chosen port isn't served yet:
// set it up through the root helper (no SSH needed once setup-root.sh has been run).
if ($sysadmin_redirect && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_global'])
    && is_array($epm_prov_port_state) && $epm_prov_port_state['state'] !== 'serving') {
    if (in_array($epm_prov_port_state['state'], ['free', 'redirected'], true) && epm_ctl_status() === 'ready') {
        $ap = epm_apply_prov_port(yealink_epm_prov_port());
        $epm_prov_port_notice .= ($epm_prov_port_notice !== '' ? ' ' : '') . htmlspecialchars($ap['message']);
        $epm_prov_port_state = yealink_epm_check_prov_port(yealink_epm_prov_port(), $detected_host, true);
        if ($epm_prov_port_state['state'] === 'redirected') {
            $epm_prov_port_notice .= ' ' . htmlspecialchars($epm_prov_port_state['message']);
        }
    } elseif ($epm_prov_port_state['state'] === 'in_use') {
        $epm_prov_port_notice .= ($epm_prov_port_notice !== '' ? ' ' : '') . htmlspecialchars($epm_prov_port_state['message']);
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'check_prov_port') {
    if (ob_get_length()) { ob_clean(); }
    header('Content-Type: application/json');
    $chk = yealink_epm_check_prov_port($_GET['port'] ?? '', $detected_host, (bool)$sysadmin_redirect);
    $chk['port'] = (string)($_GET['port'] ?? '');
    echo json_encode($chk);
    exit;
}

// "Find the redirect rule" button in the banner (read-only).
if (isset($_GET['action']) && $_GET['action'] === 'diagnose_redirect') {
    if (ob_get_length()) { ob_clean(); }
    header('Content-Type: application/json');
    if (epm_ctl_status() !== 'ready') {
        echo json_encode(['ok' => false, 'out' => 'The one-time root setup has not been run (or is out of date).']);
        exit;
    }
    $dg = epm_ctl_run(['diagnose']);
    echo json_encode(['ok' => $dg['ok'], 'out' => $dg['out']]);
    exit;
}

// "Set up port now" button in the banner.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_GET['action']) && $_GET['action'] === 'apply_prov_port') {
    if (ob_get_length()) { ob_clean(); }
    header('Content-Type: application/json');
    $want = $_POST['port'] ?? '';
    if (!yealink_epm_valid_port($want)) {
        echo json_encode(['ok' => false, 'message' => 'Invalid port.']);
        exit;
    }
    $chk = yealink_epm_check_prov_port($want, $detected_host, true);
    if ($chk['state'] === 'in_use') {
        echo json_encode(['ok' => false, 'message' => $chk['message']]);
        exit;
    }
    if ($chk['state'] === 'serving') {
        echo json_encode(['ok' => true, 'message' => $chk['message']]);
        exit;
    }
    echo json_encode(epm_apply_prov_port($want));
    exit;
}

if ($sysadmin_redirect) {
    $epm_prov_port_now = yealink_epm_prov_port();
    $default_provision_url = "http://{$detected_host}:{$epm_prov_port_now}/PhoneSettings/";
    $default_server_target = "{$detected_host}:{$epm_prov_port_now}";
    $ringtone_http_base = "http://{$detected_host}:{$epm_prov_port_now}/PhoneSettings/ringtones/";
} else {
    $default_provision_url = "http://{$detected_host}/PhoneSettings/";
    $default_server_target = $detected_host;
    $ringtone_http_base = "http://{$detected_host}/PhoneSettings/ringtones/";
}

$builtin_ringtones = [
    'Common'     => 'Common (Use Default Phone Setting)',
    'Ring1.wav'  => 'Ring1.wav',
    'Ring2.wav'  => 'Ring2.wav',
    'Ring3.wav'  => 'Ring3.wav',
    'Ring4.wav'  => 'Ring4.wav',
    'Ring5.wav'  => 'Ring5.wav',
    'Ring6.wav'  => 'Ring6.wav',
    'Ring7.wav'  => 'Ring7.wav',
    'Ring8.wav'  => 'Ring8.wav',
    'Silent.wav' => 'Silent.wav',
    'Splash.wav' => 'Splash.wav'
];

// ============================================================================
// 4. DOWNLOAD TEMPLATE, RINGTONE STREAM, & VIEW MAC CFG ACTIONS
// ============================================================================

if (isset($_GET['action']) && $_GET['action'] === 'view_mac_cfg' && !empty($_GET['mac'])) {
    if (ob_get_length()) { ob_clean(); }
    header('Content-Type: text/plain');
    
    $mac = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $_GET['mac']));
    $cfg_path = $tftp_dir . $mac . '.cfg';

    if (file_exists($cfg_path) && is_file($cfg_path)) {
        echo file_get_contents($cfg_path);
    } else {
        http_response_code(404);
        echo "Configuration file [{$mac}.cfg] not found in /tftpboot/";
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'download_template' && !empty($_GET['file'])) {
    $dl_file = basename($_GET['file']);
    $dl_path = $template_dir . $dl_file;

    if (file_exists($dl_path) && is_file($dl_path)) {
        if (ob_get_length()) { ob_clean(); }
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $dl_file . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($dl_path));
        readfile($dl_path);
        exit;
    }
}

if (isset($_GET['action']) && ($_GET['action'] === 'download_ringtone' || $_GET['action'] === 'stream_ringtone') && !empty($_GET['file'])) {
    $r_file = basename($_GET['file']);
    $r_path = $ringtone_dir . $r_file;

    if (file_exists($r_path) && is_file($r_path)) {
        if (ob_get_length()) { ob_clean(); }
        
        $mime_type = (pathinfo($r_file, PATHINFO_EXTENSION) === 'mp3') ? 'audio/mpeg' : 'audio/wav';
        
        header('Content-Type: ' . $mime_type);
        header('Content-Length: ' . filesize($r_path));
        
        if ($_GET['action'] === 'download_ringtone') {
            header('Content-Disposition: attachment; filename="' . $r_file . '"');
        } else {
            header('Content-Disposition: inline; filename="' . $r_file . '"');
        }
        
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        
        readfile($r_path);
        exit;
    } else {
        http_response_code(404);
        die('File not found');
    }
}

// ============================================================================
// 5. HELPER FUNCTIONS & OVPN_MGR INTEGRATION
// ============================================================================

function getArpTableMap() {
    $arp_map = [];
    $arp_output = [];
    if (file_exists('/proc/net/arp')) {
        $arp_lines = @file('/proc/net/arp', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($arp_lines) {
            array_shift($arp_lines);
            $arp_output = $arp_lines;
        }
    }
    if (empty($arp_output)) {
        exec("ip neighbor show 2>/dev/null || arp -an 2>/dev/null", $arp_output);
    }
    foreach ($arp_output as $line) {
        if (preg_match('/^([\d\.]+)\s+.*\s+([0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2})/i', $line, $m) ||
            preg_match('/\(([\d\.]+)\)\s+at\s+([0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2})/i', $line, $m)) {
            $ip = $m[1];
            $mac_clean = strtolower(str_replace([':', '-'], '', $m[2]));
            if (strlen($mac_clean) === 12) {
                $arp_map[$mac_clean] = $ip;
            }
        }
    }
    return $arp_map;
}

// ============================================================================
// SCAN HELPERS (1.0.8): Yealink OUI list, CIDR handling, OpenVPN client list,
// and provisioning-log MAC discovery for phones that sit behind a routed tunnel.
// ============================================================================

// Yealink's IEEE-registered MAC blocks (MA-L). 00:15:65 is the legacy block; the
// others are the newer registrations. Add new ones here and every part of the
// module (scanner, device list, manual-add warning) picks them up.
if (!function_exists('getYealinkOuis')) {
    function getYealinkOuis() {
        return [
            '001565',                       // legacy Yealink block
            '805ec0', '805e0c',             // 80:5E:C0, 80:5E:0C
            '249ad8', '44dbd2', 'c4fc22', 'ec1da9',
            '644f56', '3497d7', 'b061a9', 'f01653',
        ];
    }
}

if (!function_exists('isYealinkMac')) {
    function isYealinkMac($mac) {
        $mac = strtolower(preg_replace('/[^a-f0-9]/i', '', (string)$mac));
        return strlen($mac) === 12 && in_array(substr($mac, 0, 6), getYealinkOuis(), true);
    }
}

// Accepts "192.168.1.0/24", "10.8.0.1", "10.8.0", or a hostname. Defaults to a /24.
if (!function_exists('epmParseScanTarget')) {
    function epmParseScanTarget($input, $fallback_ip) {
        $input = trim((string)$input);
        $base = '';
        $bits = 24;

        if (preg_match('#^(\d{1,3})\.(\d{1,3})\.(\d{1,3})(?:\.(\d{1,3}))?(?:/(\d{1,2}))?#', $input, $m)) {
            $base = $m[1] . '.' . $m[2] . '.' . $m[3] . '.' . ((isset($m[4]) && $m[4] !== '') ? $m[4] : '0');
            if (isset($m[5]) && $m[5] !== '') {
                $bits = (int)$m[5];
            }
        } elseif ($input !== '' && preg_match('/^[a-z0-9][a-z0-9.\-]*$/i', $input)) {
            $resolved = gethostbyname($input);
            if (filter_var($resolved, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $base = $resolved;
            }
        }

        if ($base === '' || ip2long($base) === false) {
            $parts = explode('.', (string)$fallback_ip);
            $base = implode('.', array_slice($parts, 0, 3)) . '.0';
            $bits = 24;
        }
        if ($bits < 8 || $bits > 32) {
            $bits = 24;
        }

        $mask  = (-1 << (32 - $bits)) & 0xFFFFFFFF;
        $net   = ip2long($base) & $mask;
        $bcast = $net | (~$mask & 0xFFFFFFFF);

        return [
            'net'       => $net,
            'mask'      => $mask,
            'bits'      => $bits,
            'network'   => long2ip($net),
            'broadcast' => long2ip($bcast),
        ];
    }
}

if (!function_exists('epmIpInTarget')) {
    function epmIpInTarget($ip, $target) {
        $l = ip2long($ip);
        return $l !== false && (($l & $target['mask']) === $target['net']);
    }
}

// Small helper so callers don't need an is_readable() check before every read.
// Read access to the OpenVPN status log and the web server's access log (both
// used below) is granted once, outside of any web request, by the ovpn_mgr
// module's setup-root.sh -- see that script for how. No sudo call happens here.
if (!function_exists('epmReadProtectedFile')) {
    function epmReadProtectedFile($path) {
        if (!is_readable($path)) { return ''; }
        return (string)(@file_get_contents($path) ?: '');
    }
}

// Locates ovpn_mgr's root helper script. Resolved once per request; cached
// in a static so repeated calls (device list + debug endpoint, say) don't
// re-stat the filesystem every time.
if (!function_exists('epmFindOvpnCtl')) {
    function epmFindOvpnCtl() {
        static $path = null;
        if ($path !== null) { return $path ?: false; }

        global $amp_conf;
        $amp_web_root = rtrim(($amp_conf['AMPWEBROOT'] ?? null) ?: '/var/www/html', '/');
        $candidate = "{$amp_web_root}/admin/modules/ovpn_mgr/scripts/ovpnctl";
        $path = is_executable($candidate) ? $candidate : '';
        return $path ?: false;
    }
}

// Runs `sudo ovpnctl <subcommand>` (the asterisk user's sudoers entry covers
// the whole script, no per-subcommand restriction). -n so a broken/missing
// sudo rule fails fast with an error instead of hanging the page on a
// password prompt that will never come. Returns ['ok' => bool, 'out' => string].
if (!function_exists('epmRunOvpnCtl')) {
    function epmRunOvpnCtl($subcommand) {
        $bin = epmFindOvpnCtl();
        if ($bin === false) { return ['ok' => false, 'out' => '']; }

        $cmd = implode(' ', array_map('escapeshellarg', ['sudo', '-n', $bin, $subcommand]));
        $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) { return ['ok' => false, 'out' => '']; }

        stream_set_timeout($pipes[1], 3);
        $out = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        return ['ok' => ($code === 0), 'out' => $out];
    }
}

// Given [virtual_ip => common_name] pairs, derives the three lookup tables
// the device list matches phones against: by extension/CN, by MAC-style CN,
// and by virtual tunnel IP. Shared by the device list and (previously
// duplicated) inline logic so both match connections the same way.
if (!function_exists('epmDeriveOvpnLookups')) {
    function epmDeriveOvpnLookups(array $vpn_clients) {
        $exts = []; $macs = []; $ips = [];
        foreach ($vpn_clients as $vip => $cn) {
            $vip = trim((string)$vip);
            $cn = strtolower(trim((string)$cn));
            if ($vip !== '' && !in_array($vip, $ips, true)) { $ips[] = $vip; }
            if ($cn === '') { continue; }
            $exts[$cn] = true;
            if (preg_match('/^client[-_]?(\d+)$/', $cn, $m)) { $exts[$m[1]] = true; }
            $clean_cn = preg_replace('/[^a-f0-9]/', '', $cn);
            if ($clean_cn !== '') {
                $exts[$clean_cn] = true;
                $macs[$clean_cn] = true;
            }
        }
        return [$exts, $macs, $ips];
    }
}

// Returns [virtual_ip => common_name] for every client currently connected to
// the built-in OpenVPN server. Tries, in order: ovpn_mgr's root helper
// (reads the status file as root - the sanctioned path, works regardless of
// its 0600 permissions), a direct read of the same file (in case it's ever
// made world/group-readable another way), then the management port (kept
// as a last resort for setups that do pass --management; this module's own
// start case currently does not).
if (!function_exists('epmGetOpenVpnClientMap')) {
    function epmGetOpenVpnClientMap() {
        $clients = [];
        $status_output = '';

        $ovpnctl_result = epmRunOvpnCtl('status');
        if ($ovpnctl_result['ok'] && trim($ovpnctl_result['out']) !== '') {
            $status_output = $ovpnctl_result['out'];
        }

        if (trim($status_output) === '') {
            foreach (['/var/log/openvpn/openvpn-status.log',
                      '/var/www/html/PhoneSettings/openvpn/logs/openvpn-status.log',
                      '/var/log/openvpn/status.log'] as $status_file) {
                if (file_exists($status_file)) {
                    $status_output = epmReadProtectedFile($status_file);
                    if (trim($status_output) !== '') { break; }
                }
            }
        }

        if (trim($status_output) === '') {
            $fp = @fsockopen('127.0.0.1', 7505, $errno, $errstr, 1);
            if ($fp) {
                stream_set_timeout($fp, 2);
                fputs($fp, "status\n");
                while (!feof($fp)) {
                    $line = fgets($fp, 1024);
                    if ($line === false) { break; }
                    $status_output .= $line;
                    if (strpos($line, 'END') === 0) { break; }
                }
                fclose($fp);
            }
        }

        $section = '';
        foreach (preg_split('/\r?\n/', $status_output) as $line) {
            if ($line === '') { continue; }
            if (strpos($line, 'CLIENT_LIST') === 0) {                    // status v2 (csv) / v3 (tab)
                $p = preg_split('/[,\t]/', $line);
                $vip = trim($p[3] ?? '');
                if (filter_var($vip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $clients[$vip] = preg_replace('/[^\x20-\x7E]/', '?', trim($p[1] ?? ''));
                }
            } elseif (strpos($line, 'OpenVPN CLIENT LIST') === 0) {      // status v1
                $section = 'clients';
            } elseif (strpos($line, 'ROUTING TABLE') === 0) {
                $section = 'routes';
            } elseif (strpos($line, 'GLOBAL STATS') === 0 || $line === 'END') {
                $section = '';
            } elseif ($section === 'routes' && strpos($line, 'Virtual Address,') !== 0) {
                $p = explode(',', $line);                                // virtual,cn,real,lastref
                $vip = trim($p[0] ?? '');
                if (filter_var($vip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $clients[$vip] = preg_replace('/[^\x20-\x7E]/', '?', trim($p[1] ?? ''));
                }
            }
        }
        return $clients;
    }
}

// A routed tunnel (OpenVPN "dev tun", or any router between the PBX and the phone)
// never puts the phone's MAC in the PBX's ARP table. What the PBX *can* see is the
// phone asking for its config: the request line carries "<mac>.cfg" and Yealink's
// User-Agent. Scan the web-server access logs for that and return [ip => mac]
// (most recent request wins) plus how many log files were readable.
if (!function_exists('epmGetProvisioningLogMacMap')) {
    function epmGetProvisioningLogMacMap($max_bytes = 4194304) {
        $map = [];
        $files = [];

        foreach (['/var/log/httpd', '/var/log/apache2', '/var/log/nginx'] as $dir) {
            $found = @glob($dir . '/*access*');
            if (!is_array($found)) { continue; }
            foreach ($found as $f) {
                if (preg_match('/\.(gz|bz2|xz|zip)$/i', $f) || !is_file($f) || !is_readable($f)) { continue; }
                $files[$f] = (int)@filemtime($f);
            }
        }
        arsort($files);
        $files = array_reverse(array_slice(array_keys($files), 0, 4));   // oldest first, newest overwrites

        $read = 0;
        foreach ($files as $f) {
            $content = '';
            if (is_readable($f)) {
                $fh = @fopen($f, 'rb');
                if ($fh) {
                    $size = (int)@filesize($f);
                    if ($size > $max_bytes) {
                        fseek($fh, -$max_bytes, SEEK_END);
                        fgets($fh);                                      // drop the partial first line
                    }
                    $content = stream_get_contents($fh);
                    fclose($fh);
                }
            } else {
                $content = epmReadProtectedFile($f);                     // '' if still unreadable (see setup-root.sh)
                if (strlen($content) > $max_bytes) {
                    $content = substr($content, -$max_bytes);
                }
            }
            if ($content === '') { continue; }
            $read++;

            foreach (explode("\n", $content) as $line) {
                if (stripos($line, '.cfg') === false && stripos($line, 'Yealink') === false) { continue; }
                if (!preg_match('/^(?:\S+:\d+\s+)?(\d{1,3}(?:\.\d{1,3}){3})\s/', $line, $mi)) { continue; }

                $mac = '';
                if (preg_match('#/([0-9a-f]{12})\.(?:cfg|boot)#i', $line, $mm)) {
                    $mac = $mm[1];
                } elseif (preg_match('/Yealink[^"]*?\s([0-9a-f]{2}(?::[0-9a-f]{2}){5}|[0-9a-f]{12})\b/i', $line, $mm)) {
                    $mac = $mm[1];
                }
                if ($mac === '') { continue; }

                $mac = strtolower(preg_replace('/[^a-f0-9]/i', '', $mac));
                if (isYealinkMac($mac)) {
                    $map[$mi[1]] = $mac;
                }
            }
        }

        return ['map' => $map, 'files' => $read];
    }
}

if (!function_exists('epmHostResponds')) {
    function epmHostResponds($ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) { return false; }
        $o = [];
        $rc = 1;
        exec('ping -c 1 -W 1 ' . escapeshellarg($ip) . ' > /dev/null 2>&1', $o, $rc);
        return $rc === 0;
    }
}

function sendSipNotify($ext_or_mac, $event_type = 'check-sync', $phone_ip = '', $admin_pass = '22222') {
    $ext = preg_replace('/[^0-9]/', '', $ext_or_mac);
    if (empty($ext)) return false;

    if ($event_type === 'reboot') {
        exec("asterisk -rx 'pjsip send notify reboot-yealink endpoint {$ext}' 2>&1 &");
    } else {
        exec("asterisk -rx 'pjsip send notify yealink-check-cfg endpoint {$ext}' 2>&1 &");
        exec("asterisk -rx 'pjsip send notify check-sync\;reboot=false endpoint {$ext}' 2>&1 &");
    }

    $contacts_output = [];
    exec("asterisk -rx 'pjsip show contacts' 2>&1", $contacts_output);
    if (is_array($contacts_output)) {
        foreach ($contacts_output as $line) {
            if (preg_match('/Contact:\s*(' . preg_quote($ext, '/') . '\/sip:[^\s]+)/i', $line, $cm)) {
                $contact_uri = trim($cm[1]);
                $notify_type = ($event_type === 'reboot') ? 'reboot-yealink' : 'yealink-check-cfg';
                exec("asterisk -rx 'pjsip send notify {$notify_type} contact {$contact_uri}' 2>&1 &");
            }
        }
    }

    return true;
}

// First ringer index available to custom (uploaded) ringtones. Custom ringtones are numbered
// after the phone's factory ringtones (RingN files + Silent + Splash), so the starting index
// is (factory count + 1) and depends on the model.
//   VERIFIED on hardware (web UI ring dropdown): T28P = Ring1-5 + Silent + Splash = 7,
//                                                 T48S = Ring1-13 + Silent + Splash = 15.
//   NOT YET VERIFIED: T19/T21/T23/T27/T29 use 10 (Ring1-8 + Silent + Splash, per Yealink's
//   admin guide). These are only used if a model is added to $epm_ringer_index_models in
//   epm_ringer_by_name(); today only the T28P is numbered, everything else uses the filename.
// Count on a phone: in the web UI ring dropdown, factory entries are the ones whose value
// starts with "Resource:" (RingN.wav, Silent.wav, Splash.wav).
if (!function_exists('epm_builtin_ringer_count')) {
    function epm_builtin_ringer_count($model) {
        $m = preg_replace('/^SIP-/', '', strtoupper(trim((string)$model)));
        $counts = [
            'T28P' => 7,
            'T19P' => 10, 'T21P' => 10, 'T23G' => 10, 'T27G' => 10, 'T29G' => 10,
            'T48S' => 15,
        ];
        if ($m === '' || $m === 'MANUAL' || $m === 'YEALINK') { return 7; }
        return $counts[$m] ?? 15;    // every other model: assume the T48S layout
    }
}
if (!function_exists('epm_first_custom_ringer_index')) {
    function epm_first_custom_ringer_index($model) {
        return epm_builtin_ringer_count($model) + 1;
    }
}

// The ringtone block's marker comments are generated, never user content. Drop them so they
// can't leak into (and accumulate in) Template Custom Key / Value Additions.
if (!function_exists('epm_is_ringtone_marker_line')) {
    function epm_is_ringtone_marker_line($line) {
        return strpos($line, '######## DISTINCTIVE RINGTONE & ALERT INFO SETUP ########') !== false
            || strpos($line, '######## END DISTINCTIVE RINGTONE SETUP ########') !== false;
    }
}

// Write the ringer as the custom ringtone's filename (e.g. ring_att3.wav) instead of a numeric
// index. Set EPM_RINGER_BY_NAME to false to go back to numeric indexes for every model, or list
// any model that does not honour filenames in $epm_ringer_index_models to keep it on indexes.
if (!defined('EPM_RINGER_BY_NAME')) { define('EPM_RINGER_BY_NAME', true); }
if (!function_exists('epm_ringer_by_name')) {
    function epm_ringer_by_name($model) {
        if (!EPM_RINGER_BY_NAME) { return false; }
        $m = strtoupper(trim((string)$model));
        $m = preg_replace('/^SIP-/', '', $m);
        // Only the T28P is known for sure (7 built-in ringtones -> numeric index starting at 8).
        // Its legacy firmware ignores filenames, so it stays on numbers. Every other model,
        // including the rest of the T2x series and the T19, writes the ringtone filename, which
        // does not depend on knowing each model's factory ringtone count.
        $epm_ringer_index_models = ['T28P'];
        return !in_array($m, $epm_ringer_index_models, true);
    }
}

function buildDistinctiveRingtoneConfigBlock($active_ringtones = [], $model = '') {
    if (empty($active_ringtones) || !is_array($active_ringtones)) {
        return "";
    }

    $ring_files = array_values(array_unique($active_ringtones));
    sort($ring_files, SORT_STRING | SORT_FLAG_CASE);

    $cfg = "######## DISTINCTIVE RINGTONE & ALERT INFO SETUP ########\n";
    $cfg .= "features.alert_info_tone = 1\n";
    $cfg .= "account.1.alert_info_tone = 1\n";
    $cfg .= "account.1.alert_info_url_enable = 1\n";
    $cfg .= "distinctive_ring_tones.alert_info.enable = 1\n\n";

    $ringer_index = epm_first_custom_ringer_index($model);
    $max_slots = min(count($ring_files), 10);

    for ($r_idx = 1; $r_idx <= $max_slots; $r_idx++) {
        $r_file = $ring_files[$r_idx - 1];
        $text_name = pathinfo($r_file, PATHINFO_FILENAME);

        // By filename (default) the phone resolves the custom ringtone itself, so the result does
        // not depend on the model's factory ringtone count or on how it orders custom files.
        $ringer_val = epm_ringer_by_name($model) ? $r_file : $ringer_index;

        $cfg .= "distinctive_ring_tones.alert_info.{$r_idx}.text = {$text_name}\n";
        $cfg .= "distinctive_ring_tones.alert_info.{$r_idx}.ringer = {$ringer_val}\n";

        $cfg .= "account.1.alert_info_text.{$r_idx} = {$text_name}\n";
        $cfg .= "account.1.alert_info_ringer.{$r_idx} = {$ringer_val}\n";

        $ringer_index++;
    }
    $cfg .= "######## END DISTINCTIVE RINGTONE SETUP ########\n\n";
    return $cfg;
}

function rebuildDevicesForTemplate($tpl_filename, $tftp_dir, $template_dir, $saved_global_admin_pass, $append_flush = false) {
    $tpl_path = $template_dir . $tpl_filename;
    if (empty($tpl_filename) || !file_exists($tpl_path)) {
        return 0;
    }

    $arp_table = getArpTableMap();
    $all_cfg_files = glob($tftp_dir . "*.cfg");
    $updated_count = 0;

    if (is_array($all_cfg_files)) {
        foreach ($all_cfg_files as $cf) {
            $mname = strtolower(pathinfo($cf, PATHINFO_FILENAME));
            if (isYealinkGlobalCfgBasename($mname) || strpos(strtolower($cf), 'template') !== false) {
                continue;
            }

            $c_lines = @file($cf, FILE_IGNORE_NEW_LINES);
            $uses_tpl = false;
            $assigned_ext = '';

            if ($c_lines) {
                foreach ($c_lines as $cl) {
                    if (preg_match('/^#\s*Template\s*:\s*(.+)$/i', $cl, $tm)) {
                        if (strcasecmp(trim($tm[1]), $tpl_filename) === 0) {
                            $uses_tpl = true;
                        }
                    }
                    if (preg_match('/^account\.1\.(auth_name|user_name)\s*=\s*(.+)$/i', $cl, $em)) {
                        $assigned_ext = trim($em[2]);
                    }
                }
            }

            if ($uses_tpl) {
                $file_content = file_get_contents($cf);
                
                if (($pos = strpos($file_content, '##### INHERITED TEMPLATE SETTINGS')) !== false) {
                    $base_content = substr($file_content, 0, $pos);
                } else {
                    $base_content = $file_content;
                }
                $dev_overrides_text = epm_extract_device_overrides($file_content);
                $base_content = epm_strip_device_overrides($base_content);

                if (($pos_flush = strpos($base_content, '######## ONE-TIME RINGTONE FLASH CLEAR ########')) !== false) {
                    $base_content = substr($base_content, 0, $pos_flush);
                }

                $tpl_content = file_get_contents($tpl_path);
                $tpl_content = preg_replace('/^account\.1\.sip_server.*$/m', '', $tpl_content);
                $tpl_content = preg_replace('/^#!version:.*$/m', '', $tpl_content);

                // Re-emit the device's SIP server lines in the naming the template's phone model needs
                // (so a template/model change also switches legacy <-> current parameter names).
                if (preg_match('/^account\.1\.(?:sip_server_host|sip_server\.1\.address|sip_server)\s*=\s*(\S+)/mi', $base_content, $hm)) {
                    $rb_host = $hm[1];
                    $rb_port = '5060';
                    if (preg_match('/^account\.1\.(?:sip_server_port|sip_server\.1\.port)\s*=\s*(\d+)/mi', $base_content, $pm2)) { $rb_port = $pm2[1]; }
                    $rb_lines = implode("\n", epm_sip_server_lines(epm_template_phone_model($tpl_path), $rb_host, $rb_port));
                    $first = true;
                    $base_content = preg_replace_callback('/^account\.1\.sip_server[^\n]*\n?/mi', function ($mm) use (&$first, $rb_lines) {
                        if ($first) { $first = false; return $rb_lines . "\n"; }
                        return '';
                    }, $base_content);
                }

                if ($append_flush) {
                    $tpl_content = preg_replace('/######## DISTINCTIVE RINGTONE & ALERT INFO SETUP ########.*?######## END DISTINCTIVE RINGTONE SETUP ########/s', '', $tpl_content);
                    $tpl_content = preg_replace('/^ringtone\.url\s*=.*$/m', '', $tpl_content);

                    $flush_block = "######## ONE-TIME RINGTONE FLASH CLEAR ########\n";
                    $flush_block .= "account.1.ringtone.ring_type = Common\n";
                    $flush_block .= "features.alert_info_tone = 0\n";
                    $flush_block .= "account.1.alert_info_url_enable = 0\n";
                    $flush_block .= "distinctive_ring_tones.alert_info.enable = 0\n";
                    $flush_block .= "ringtone.delete = http://localhost/all\n";

                    for ($clear_i = 1; $clear_i <= 10; $clear_i++) {
                        $flush_block .= "distinctive_ring_tones.alert_info.{$clear_i}.text = %NULL%\n";
                        $flush_block .= "distinctive_ring_tones.alert_info.{$clear_i}.ringer = %NULL%\n";
                        $flush_block .= "account.1.alert_info_text.{$clear_i} = %NULL%\n";
                        $flush_block .= "account.1.alert_info_ringer.{$clear_i} = %NULL%\n";
                    }
                    $flush_block .= "######## END ONE-TIME FLASH CLEAR ########\n\n";

                    $tpl_content = $flush_block . $tpl_content;
                }
                
                $final_cfg = rtrim($base_content) . "\n\n##### INHERITED TEMPLATE SETTINGS ({$tpl_filename}) #####\n" . $tpl_content;
                $final_cfg .= epm_wrap_device_overrides($dev_overrides_text);

                @file_put_contents($cf, $final_cfg);
                @chown($cf, 'asterisk');

                if (!empty($assigned_ext)) {
                    $target_ip = $arp_table[$mname] ?? '';
                    sendSipNotify($assigned_ext, 'yealink-check-cfg', $target_ip, $saved_global_admin_pass);
                }
                $updated_count++;
            }
        }
    }
    return $updated_count;
}

// SIP server parameter names differ between firmware generations.
//   legacy (SIP-T28P, V7x): account.1.sip_server_host / account.1.sip_server_port
//   current (V81+):         account.1.sip_server.1.address / account.1.sip_server.1.port
// An unknown / generic model gets both sets (phones ignore parameters they don't know).
if (!function_exists('epm_sip_generation')) {
    function epm_sip_generation($model) {
        $m = strtoupper(trim((string)$model));
        $m = preg_replace('/^SIP-/', '', $m);
        if ($m === '' || $m === 'MANUAL' || $m === 'YEALINK') { return 'both'; }
        return in_array($m, ['T28P'], true) ? 'legacy' : 'modern';
    }
}
if (!function_exists('epm_sip_server_lines')) {
    function epm_sip_server_lines($model, $host, $port) {
        $gen = epm_sip_generation($model);
        $lines = [];
        if ($gen === 'legacy' || $gen === 'both') {
            $lines[] = "account.1.sip_server = {$host}";
            $lines[] = "account.1.sip_server_host = {$host}";
            $lines[] = "account.1.sip_server_port = {$port}";
        }
        if ($gen === 'modern' || $gen === 'both') {
            $lines[] = "account.1.sip_server.1.address = {$host}";
            $lines[] = "account.1.sip_server.1.port = {$port}";
        }
        return $lines;
    }
}
if (!function_exists('epm_template_phone_model')) {
    function epm_template_phone_model($path) {
        if (empty($path) || !is_file($path)) { return ''; }
        $fh = @fopen($path, 'r');
        if (!$fh) { return ''; }
        $model = '';
        for ($i = 0; $i < 12 && ($ln = fgets($fh)) !== false; $i++) {
            if (preg_match('/^#\s*Phone\s*Model\s*:\s*(.+)$/i', trim($ln), $m)) { $model = trim($m[1]); break; }
        }
        fclose($fh);
        return $model;
    }
}

// Firmware generations differ in how the auto-provisioning parameters are named.
// Legacy (V7x/V8x era, e.g. SIP-T28P and the "Global Legacy Base" y000000000000.cfg):
//   auto_provision.mode / auto_provision.weekly.* / auto_provision.server.*
// Current firmware: static.auto_provision.* with separate power_on / repeat / weekly switches.
if (!function_exists('epm_autop_is_legacy_basename')) {
    function epm_autop_is_legacy_basename($basename) {
        return in_array(strtolower((string)$basename), ['y000000000000'], true);
    }
}
if (!function_exists('epm_build_autop_block')) {
    function epm_build_autop_block($legacy, $p) {
        $mode = (string)$p['mode'];
        $b = '';
        if ($legacy) {
            $b .= "auto_provision.mode = {$mode}\n";
            $b .= "auto_provision.reboot_force.enable = 0\n";
            $b .= "auto_provision.weekly.enable = {$p['weekly']}\n";
            $b .= "auto_provision.weekly.begin_time = {$p['begin']}\n";
            $b .= "auto_provision.weekly.end_time = {$p['end']}\n";
            $b .= "auto_provision.weekly.dayofweek = {$p['dow']}\n";
            $b .= "auto_provision.server.url = {$p['url']}\n";
            $b .= "auto_provision.server.username = {$p['user']}\n";
            $b .= "auto_provision.server.password = {$p['pass']}\n";
            $b .= "auto_provision.dhcp_option.enable = {$p['dhcp']}\n\n";
            return $b;
        }
        // Mode labels used by the UI: 1 Power on, 4 Repeatedly, 5 Weekly,
        // 6 Power on + Repeatedly, 7 Power on + Weekly, 0 Disabled.
        $power_on = in_array($mode, ['1', '6', '7'], true) ? '1' : '0';
        $repeat   = in_array($mode, ['4', '6'], true) ? '1' : '0';
        $weekly   = (in_array($mode, ['5', '7'], true) && (string)$p['weekly'] === '1') ? '1' : '0';
        $b .= "static.auto_provision.power_on = {$power_on}\n";
        $b .= "static.auto_provision.repeat.enable = {$repeat}\n";
        if ($repeat === '1') {
            $b .= "static.auto_provision.repeat.minutes = 1440\n";
        }
        $b .= "static.auto_provision.weekly.enable = {$weekly}\n";
        $b .= "static.auto_provision.weekly.begin_time = {$p['begin']}\n";
        $b .= "static.auto_provision.weekly.end_time = {$p['end']}\n";
        $b .= "static.auto_provision.weekly.dayofweek = {$p['dow']}\n";
        $b .= "static.auto_provision.reboot_force.enable = 0\n";
        $b .= "static.auto_provision.server.url = {$p['url']}\n";
        $b .= "static.auto_provision.server.username = {$p['user']}\n";
        $b .= "static.auto_provision.server.password = {$p['pass']}\n";
        $b .= "static.auto_provision.dhcp_option.enable = {$p['dhcp']}\n\n";
        return $b;
    }
}

function generateAndSaveGlobalConfig($formData, $cfg_version, $default_server_target, $tftp_dir, $sysadmin_redirect) {
    $raw_server = !empty($formData['server_ip']) ? $formData['server_ip'] : $default_server_target;
    
    if (strpos($raw_server, '://') === false) {
        $raw_server = 'http://' . $raw_server;
    }
    
    $parsed_host = parse_url($raw_server, PHP_URL_HOST);

    if (!empty($parsed_host)) {
        $server_ip_target = yealink_epm_apply_redirect_port($parsed_host, $sysadmin_redirect);
    } else {
        $server_ip_target = yealink_epm_apply_redirect_port($default_server_target, $sysadmin_redirect);
    }

    $admin_pass = $formData['admin_password'] ?? '22222';
    $auto_prov_mode = $formData['auto_provision_mode'] ?? '7';
    $auto_prov_weekly = $formData['auto_provision_weekly_enable'] ?? '1';
    $auto_prov_begin = $formData['auto_provision_weekly_begin_time'] ?? '23:00';
    $auto_prov_end = $formData['auto_provision_weekly_end_time'] ?? '23:59';
    $auto_prov_dow = $formData['auto_provision_weekly_dayofweek'] ?? '0';
    $auto_prov_user = $formData['auto_provision_username'] ?? '';
    $auto_prov_pass = $formData['auto_provision_password'] ?? '';
    $auto_prov_dhcp = $formData['auto_provision_dhcp_option_enable'] ?? '1';
    $sip_outbound = $formData['sip_use_out_bound_in_dialog'] ?? '1';
    $transfer_blind = $formData['transfer_blind_tran_on_hook_enable'] ?? '1';
    $transfer_onhook = $formData['transfer_on_hook_trans_enable'] ?? '1';
    $transfer_dss = $formData['transfer_dsskey_deal_type'] ?? '2';
    $tz_val = $formData['timezone'] ?? '-8';
    $tz_name = $formData['timezone_name'] ?? '';
    $time_fmt = $formData['time_format'] ?? '0';
    $dial_timeout = $formData['dialnow_timeout'] ?? '4';

    $ntp1_target = !empty($formData['ntp_server1']) ? $formData['ntp_server1'] : explode(':', $server_ip_target)[0];
    $ntp2_target = !empty($formData['ntp_server2']) ? $formData['ntp_server2'] : 'pool.ntp.org';

    $cfg = "#!version:{$cfg_version}\n\n";
    $cfg .= "##File header \"#!version:{$cfg_version}\" can not be edited or deleted.##\n\n";
    $cfg .= "security.user_password = admin:{$admin_pass}\n\n";
    $cfg .= "sip.notify_reboot_enable = 0\n";
    $cfg .= "phone_setting.zero_touch_enable = 1\n";
    $cfg .= "action_uri.enable = 1\n";
    $cfg .= "features.action_uri_limit_ip = any\n\n";
    $cfg .= "@@EPM_AUTOP_BLOCK@@\n";
    $cfg .= "sip.use_out_bound_in_dialog = {$sip_outbound}\n";
    $cfg .= "transfer.blind_tran_on_hook_enable = {$transfer_blind}\n";
    $cfg .= "transfer.on_hook_trans_enable = {$transfer_onhook}\n";
    $cfg .= "transfer.dsskey_deal_type = {$transfer_dss}\n\n";
    $cfg .= "local_time.time_zone = {$tz_val}\n";
    if (!empty($tz_name)) {
        $cfg .= "local_time.time_zone_name = {$tz_name}\n";
    }
    $cfg .= "local_time.time_format = {$time_fmt}\n";
    $cfg .= "local_time.ntp_server1 = {$ntp1_target}\n";
    $cfg .= "local_time.ntp_server2 = {$ntp2_target}\n";
    $cfg .= "phone_setting.dialnow_delay = {$dial_timeout}\n\n";

    $cfg .= "######## My DIALPLAN ########\n\n";
    $item_idx = 1;
    for ($d = 1; $d <= 50; $d++) {
        if (!empty($formData["dialnow_{$d}"])) {
            $cfg .= "dialplan.dialnow.rule.{$item_idx} = {$formData["dialnow_{$d}"]}\n";
            $cfg .= "dialplan.dialnow.line_id.{$item_idx} = 0\n";
            $item_idx++;
        }
    }
    $cfg .= "######## End My DIALPLAN ########\n\n";

    if (!empty($formData['custom_inputs_global'])) {
        $cfg .= "##### Global Custom Key-Value Additions #####\n";
        $cfg .= trim($formData['custom_inputs_global']) . "\n\n";
    }

    $autop_params = [
        'mode' => $auto_prov_mode, 'weekly' => $auto_prov_weekly, 'begin' => $auto_prov_begin,
        'end' => $auto_prov_end, 'dow' => $auto_prov_dow, 'url' => "http://{$server_ip_target}",
        'user' => $auto_prov_user, 'pass' => $auto_prov_pass, 'dhcp' => $auto_prov_dhcp,
    ];
    $cfg_modern = str_replace("@@EPM_AUTOP_BLOCK@@\n", epm_build_autop_block(false, $autop_params), $cfg);
    $cfg_legacy = str_replace("@@EPM_AUTOP_BLOCK@@\n", epm_build_autop_block(true, $autop_params), $cfg);
    foreach (array_keys(yealinkGlobalCfgMap()) as $global_basename) {
        $out = epm_autop_is_legacy_basename($global_basename) ? $cfg_legacy : $cfg_modern;
        @file_put_contents($tftp_dir . $global_basename . ".cfg", $out);
        @chown($tftp_dir . $global_basename . ".cfg", 'asterisk');
    }
    return $cfg_modern;
}

// ============================================================================
// 6. READ GLOBAL CONFIGURATION (y-configs) & DATABASE DATA
// ============================================================================

$saved_global_server_ip = $detected_host;
$saved_global_admin_pass = "22222";
$saved_global_time_format = "0"; 
$saved_global_timezone = $detected_tz_info['offset'];
$saved_global_timezone_name = $detected_tz_info['name'];
$saved_global_ntp_server1 = $detected_host;
$saved_global_ntp_server2 = "pool.ntp.org";
$saved_global_dialnow_timeout = "4";
$file_dialnow_patterns = [];
$global_cfg_file = $tftp_dir . "y000000000000.cfg";

if (file_exists($global_cfg_file)) {
    $g_content = @file($global_cfg_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($g_content) {
        $temp_dialnow_file = [];
        foreach ($g_content as $g_line) {
            $g_line = trim($g_line);
            if (preg_match('/^(?:dialplan\.dialnow\.rule|dialnow\.item)\.(\d+)\s*=\s*(.+)$/i', $g_line, $gm)) {
                $idx = (int)$gm[1];
                $val = trim($gm[2]);
                if ($val !== '') {
                    $temp_dialnow_file[$idx] = $val;
                }
            }
            if (preg_match('/^(?:static\.)?auto_provision\.server\.url\s*=\s*http:\/\/(.+)$/i', $g_line, $gm)) {
                // account.1.sip_server / sip_server_host are also derived from
                // this value elsewhere, and SIP registration already has its
                // own port field (account.1.sip_server_port) — it must never
                // carry the HTTP provisioning port. Keep this bare (host
                // only); the port shift is applied separately, only where an
                // http:// URL is actually being built (provisioning, ringtone,
                // logo, VPN key downloads).
                $saved_global_server_ip = yealink_epm_apply_redirect_port(trim($gm[1]), false);
            }
            if (preg_match('/^security\.user_password\s*=\s*admin:(.+)$/i', $g_line, $gm)) {
                $saved_global_admin_pass = trim($gm[1]);
            }
            if (preg_match('/^local_time\.time_zone\s*=\s*([+\-]?\d+)/i', $g_line, $gm)) {
                $saved_global_timezone = trim($gm[1]);
            }
            if (preg_match('/^local_time\.time_zone_name\s*=\s*(.+)$/i', $g_line, $gm)) {
                $saved_global_timezone_name = trim($gm[1]);
            }
            if (preg_match('/^local_time\.time_format\s*=\s*([01])$/i', $g_line, $gm)) {
                $saved_global_time_format = trim($gm[1]);
            }
            if (preg_match('/^local_time\.ntp_server1\s*=\s*(.+)$/i', $g_line, $gm)) {
                $saved_global_ntp_server1 = trim($gm[1]);
            }
            if (preg_match('/^local_time\.ntp_server2\s*=\s*(.+)$/i', $g_line, $gm)) {
                $saved_global_ntp_server2 = trim($gm[1]);
            }
            if (preg_match('/^phone_setting\.(?:dialnow_delay|inter_digit_time)\s*=\s*(\d+)$/i', $g_line, $gm)) {
                $saved_global_dialnow_timeout = trim($gm[1]);
            }
        }
        if (!empty($temp_dialnow_file)) {
            ksort($temp_dialnow_file);
            $file_dialnow_patterns = array_values($temp_dialnow_file);
        }
    }
}

if (empty($saved_global_ntp_server1)) {
    $saved_global_ntp_server1 = $detected_host;
}

$all_extensions = [];
$online_exts = [];
$default_sip_port = "5060";
$default_voicemail_ext = "*97";
$outbound_patterns = [];

$dss_key_types = [
    "15" => "Line (15)",
    "16" => "BLF (16)",
    "13" => "Speed Dial (13)",
    "0"  => "Disabled (0)",
    "20" => "Direct Pickup (20)",
    "39" => "Park (39)"
];

$yealink_models = [
    'manual' => 'Manual / Generic',
    'T19P'   => 'SIP-T19P E2',
    'T21P'   => 'SIP-T21P E2',
    'T23G'   => 'SIP-T23G / T23P',
    'T27G'   => 'SIP-T27G',
    'T28P'   => 'SIP-T28P',
    'T29G'   => 'SIP-T29G',
    'T30'    => 'SIP-T30 / T30P',
    'T31G'   => 'SIP-T31G / T31P / T31 / T31W',
    'T33G'   => 'SIP-T33G / T33P',
    'T34W'   => 'SIP-T34W',
    'T40P'   => 'SIP-T40P / T40G',
    'T41S'   => 'SIP-T41S / T41P',
    'T42S'   => 'SIP-T42S / T42U',
    'T43U'   => 'SIP-T43U',
    'T44U'   => 'SIP-T44U / T44W',
    'T46S'   => 'SIP-T46S / T46U',
    'T48S'   => 'SIP-T48S / T48G',
    'T53W'   => 'SIP-T53W',
    'T54W'   => 'SIP-T54W',
    'T57W'   => 'SIP-T57W',
    'T58A'   => 'SIP-T58A / T58W',
    'VP59'   => 'VP59',
];

$expansion_models = [
    "none"  => "-- None --",
    "EXP20" => "EXP20 (20 Keys per Module)",
    "EXP40" => "EXP40 (40 Keys per Module)",
    "EXP43" => "EXP43 (60 Keys per Module, Color LCD)",
    "EXP50" => "EXP50 (60 Keys per Module, Color LCD)"
];

// Keys per expansion module (matches the labels above).
$expansion_key_sizes = ['none' => 0, 'EXP20' => 20, 'EXP40' => 40, 'EXP43' => 60, 'EXP50' => 60];

// Expansion modules with a color LCD that supports a custom wallpaper/background image
// (wallpaper_upload.url / expansion_module.backgrounds). EXP20/EXP40 have no screen.
$expansion_wallpaper_models = ['EXP43', 'EXP50'];

// ----------------------------------------------------------------------------
// Per-model key layout. This is the single source of truth for the page JS and
// for template generation/parsing.
//   linekeys : linekey.X slots on the phone itself
//   lines    : SIP accounts the phone can register
//   memkeys  : built-in physical memory keys (memorykey.X). Only the legacy T2x
//              side-button phones (T28P here) have these; every other model puts
//              all of its DSS keys under linekey.X.
// Expansion module keys are NOT memorykey.X on any model: they are written as
// expansion_module.<module>.key.<key>.* (see epm_build_memory_keys_block()).
// ----------------------------------------------------------------------------
$yealink_model_keys = [
    'manual' => ['linekeys' => 1,  'lines' => 16, 'memkeys' => 0],
    'T19P'   => ['linekeys' => 1,  'lines' => 1,  'memkeys' => 0],
    'T21P'   => ['linekeys' => 2,  'lines' => 2,  'memkeys' => 0],
    'T23G'   => ['linekeys' => 3,  'lines' => 3,  'memkeys' => 0],
    'T27G'   => ['linekeys' => 21, 'lines' => 6,  'memkeys' => 0],
    'T28P'   => ['linekeys' => 6,  'lines' => 6,  'memkeys' => 10],
    'T29G'   => ['linekeys' => 27, 'lines' => 16, 'memkeys' => 0],
    'T30'    => ['linekeys' => 1,  'lines' => 1,  'memkeys' => 0],
    'T31G'   => ['linekeys' => 2,  'lines' => 2,  'memkeys' => 0],
    'T33G'   => ['linekeys' => 4,  'lines' => 4,  'memkeys' => 0],
    'T34W'   => ['linekeys' => 4,  'lines' => 4,  'memkeys' => 0],
    'T40P'   => ['linekeys' => 3,  'lines' => 3,  'memkeys' => 0],
    'T41S'   => ['linekeys' => 15, 'lines' => 6,  'memkeys' => 0],
    'T42S'   => ['linekeys' => 15, 'lines' => 6,  'memkeys' => 0],
    'T43U'   => ['linekeys' => 21, 'lines' => 12, 'memkeys' => 0],
    'T44U'   => ['linekeys' => 21, 'lines' => 12, 'memkeys' => 0],
    'T46S'   => ['linekeys' => 27, 'lines' => 16, 'memkeys' => 0],
    'T48S'   => ['linekeys' => 29, 'lines' => 16, 'memkeys' => 0],
    'T53W'   => ['linekeys' => 21, 'lines' => 12, 'memkeys' => 0],
    'T54W'   => ['linekeys' => 27, 'lines' => 16, 'memkeys' => 0],
    'T57W'   => ['linekeys' => 29, 'lines' => 16, 'memkeys' => 0],
    'T58A'   => ['linekeys' => 27, 'lines' => 16, 'memkeys' => 0],
    'VP59'   => ['linekeys' => 27, 'lines' => 16, 'memkeys' => 0],
];

// ----------------------------------------------------------------------------
// Programmable keys (programablekey.X.*)
// Source: Yealink admin guide, "DSS Keys > Programmable Keys". IDs are the
// physical/soft key positions; which IDs exist depends on the phone family.
// NOTE: everything the popout needs is passed around as arrays (no globals),
// because FreePBX includes this file from inside a function scope.
// ----------------------------------------------------------------------------
$prog_key_names = [
    1 => 'SoftKey 1', 2 => 'SoftKey 2', 3 => 'SoftKey 3', 4 => 'SoftKey 4',
    5 => 'Up', 6 => 'Down', 7 => 'Left', 8 => 'Right', 9 => 'OK', 10 => 'Cancel',
    11 => 'CONF', 12 => 'Hold', 13 => 'Mute', 14 => 'TRAN',
    17 => 'Redial', 18 => 'Message'
];

// Factory function of each key (Yealink default values). 0 = N/A.
$prog_key_defaults = [
    1 => 28, 2 => 61, 3 => 5, 4 => 30, 5 => 28, 6 => 61, 7 => 51, 8 => 52, 9 => 33,
    10 => 0, 11 => 0, 12 => 0, 13 => 0, 14 => 2, 17 => 0, 18 => 0
];

// Models where a key's factory function differs from the table above.
// T30 / T19 E2 do not support Switch Account Up/Down, so Left/Right are N/A.
$prog_key_default_override = [
    'T19P' => [7 => 0, 8 => 0],
    'T30'  => [7 => 0, 8 => 0]
];

$prog_key_types = [
    0 => 'N/A', 2 => 'Forward', 5 => 'DND', 7 => 'Recall', 8 => 'SMS', 9 => 'Pickup',
    13 => 'Speed Dial', 14 => 'Intercom', 23 => 'Group Pickup', 24 => 'Multicast Paging',
    27 => 'XML Browser', 28 => 'History', 30 => 'Menu', 32 => 'New SMS', 33 => 'Status',
    34 => 'Hot Desking', 40 => 'Prefix', 41 => 'Zero Touch', 43 => 'Local Directory',
    50 => 'Phone Lock', 51 => 'Switch Account Up', 52 => 'Switch Account Down',
    61 => 'Directory', 66 => 'Paging List'
];

// Which extra fields a key type actually uses (line, value, ext, hist).
// Label is handled separately: only SoftKey 1-4 have an on-screen label.
$prog_key_type_fields = [
    2  => ['line'],
    9  => ['line', 'value'],
    13 => ['line', 'value'],
    14 => ['line', 'value'],
    23 => ['line', 'value'],
    24 => ['value'],
    27 => ['value'],
    28 => ['hist'],
    40 => ['value']
];

$prog_grp_t3  = [1, 2, 3, 4, 5, 6, 7, 8, 9, 13, 14, 17, 18];          // T31 / T30 / T19 E2
$prog_grp_t2  = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 14, 17, 18];         // T23 / T21 E2
$prog_grp_t27 = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 17, 18]; // T27G / T29G
$prog_grp_t4  = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 13, 17, 18];         // T33 / T40 / T41 / T42 / T43 / T53
$prog_grp_t5  = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 12, 13, 14, 17, 18]; // T46 / T48 / T54
$prog_grp_t57 = [1, 2, 3, 4, 12, 13, 14, 17, 18];                     // T57 / T58 / VP59

$prog_key_models = [
    'manual' => array_keys($prog_key_names),
    'T19P' => $prog_grp_t3,  'T21P' => $prog_grp_t2,  'T23G' => $prog_grp_t2,
    'T27G' => $prog_grp_t27, 'T28P' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14],
    'T29G' => $prog_grp_t27, 'T30'  => $prog_grp_t3,  'T31G' => $prog_grp_t3,
    'T33G' => $prog_grp_t4,  'T34W' => $prog_grp_t4,  'T40P' => $prog_grp_t4,
    'T41S' => $prog_grp_t4,  'T42S' => $prog_grp_t4,  'T43U' => $prog_grp_t4,
    'T44U' => $prog_grp_t4,  'T46S' => $prog_grp_t5,  'T48S' => $prog_grp_t5,
    'T53W' => $prog_grp_t4,  'T54W' => $prog_grp_t5,  'T57W' => $prog_grp_t57,
    'T58A' => $prog_grp_t57, 'VP59' => $prog_grp_t57
];

$prog_meta = [
    'names'     => $prog_key_names,
    'defaults'  => $prog_key_defaults,
    'overrides' => $prog_key_default_override,
    'fields'    => $prog_key_type_fields,
    'models'    => $prog_key_models
];

function epm_prog_key_default($model, $id, array $meta) {
    if (isset($meta['overrides'][$model][$id])) {
        return (int)$meta['overrides'][$model][$id];
    }
    return (int)($meta['defaults'][$id] ?? 0);
}

// True if the key's *currently loaded* form values (i.e. what's already saved in
// the template / already been pushed to phones) differ from the factory default -
// same test the popout's JS runs client-side (progKeyIsCustom). Used at render time
// to snapshot "was this non-default before the admin touches anything in this edit",
// so a later save that reverts the key back to default still knows to explicitly
// write the default (instead of silently omitting the key) and actually overwrite
// whatever non-default value is already sitting on the phone.
function epm_prog_key_is_custom($id, $model, array $formData, array $meta) {
    $type_raw = trim((string)($formData["progkey_{$id}_type"] ?? ''));
    if ($type_raw === '' || !ctype_digit($type_raw)) { return false; }
    $type = (int)$type_raw;
    $default = epm_prog_key_default($model, $id, $meta);
    if ($type !== $default) { return true; }

    $fields = $meta['fields'][$type] ?? [];
    if (in_array('value', $fields, true) && trim((string)($formData["progkey_{$id}_value"] ?? '')) !== '') { return true; }
    if ($id <= 4 && $type !== 0 && trim((string)($formData["progkey_{$id}_label"] ?? '')) !== '') { return true; }
    if (in_array('line', $fields, true)) {
        $line = trim((string)($formData["progkey_{$id}_line"] ?? '1'));
        if ($line !== '' && $line !== '1') { return true; }
    }
    if (in_array('hist', $fields, true)) {
        $hist = trim((string)($formData["progkey_{$id}_hist"] ?? '0'));
        if ($hist !== '' && $hist !== '0') { return true; }
    }
    return false;
}

// Which of this key's fields (line/value/hist/label) were meaningfully in effect
// under the PREVIOUS type (the one already saved/pushed to the phone when this
// edit started) but are no longer used by the type being saved now. Those need
// an explicit %NULL% written for them - see the note in epm_build_prog_keys_block()
// on why simply omitting a no-longer-used field isn't enough (Yealink templates
// are overrides, not full state, so a stale value keeps overriding the phone
// forever otherwise).
function epm_prog_key_null_fields($id, $new_type, array $formData, array $meta) {
    $prev_type_raw = trim((string)($formData["progkey_{$id}_prevtype"] ?? ''));
    if ($prev_type_raw === '' || !ctype_digit($prev_type_raw)) { return []; }
    $prev_type = (int)$prev_type_raw;
    if ($prev_type === $new_type) { return []; }

    $prev_fields = $meta['fields'][$prev_type] ?? [];
    $new_fields  = $meta['fields'][$new_type] ?? [];
    $null_fields = [];

    // Line is always written whenever a type uses it (even at its default of "1"),
    // so it always needs clearing when the new type drops it.
    if (in_array('line', $prev_fields, true) && !in_array('line', $new_fields, true)) {
        $null_fields[] = 'line';
    }
    if (in_array('hist', $prev_fields, true) && !in_array('hist', $new_fields, true)) {
        $prev_hist = trim((string)($formData["progkey_{$id}_prevhist"] ?? '0'));
        if ($prev_hist !== '' && $prev_hist !== '0') { $null_fields[] = 'hist'; }
    }
    if (in_array('value', $prev_fields, true) && !in_array('value', $new_fields, true)) {
        $prev_value = trim((string)($formData["progkey_{$id}_prevvalue"] ?? ''));
        if ($prev_value !== '') { $null_fields[] = 'value'; }
    }
    $prev_label_active = ($id <= 4 && $prev_type !== 0);
    $new_label_active  = ($id <= 4 && $new_type !== 0);
    if ($prev_label_active && !$new_label_active) {
        $prev_label = trim((string)($formData["progkey_{$id}_prevlabel"] ?? ''));
        if ($prev_label !== '') { $null_fields[] = 'label'; }
    }
    return $null_fields;
}

function epm_prog_clean($s) {
    return trim(preg_replace('/[\r\n]+/', ' ', (string)$s));
}

// Maps a flat memory-key index (1..N, as shown in the Memory Keys box) to the
// config prefix the phone expects:
//   1..$base_mem                 -> memorykey.<i>                       (built-in keys)
//   $base_mem+1.. (with module)  -> expansion_module.<m>.key.<k>        (m = module, k = key on it)
// With no expansion module selected, anything past the built-in keys falls back
// to memorykey.<i> so nothing the user typed is silently dropped.
function epm_memkey_prefix($i, $base_mem, $exp_size) {
    if ($i <= $base_mem || $exp_size <= 0) {
        return ["memorykey.{$i}", false];
    }
    $j = $i - $base_mem;
    $module = intdiv($j - 1, $exp_size) + 1;
    $key = (($j - 1) % $exp_size) + 1;
    return ["expansion_module.{$module}.key.{$key}", true];
}

function epm_build_memory_keys_block(array $formData, $count, $base_mem, $exp_size) {
    $out = '';
    $has = false;
    for ($i = 1; $i <= $count; $i++) {
        $val = trim((string)($formData["memkey_{$i}_value"] ?? ''));
        $lbl = trim((string)($formData["memkey_{$i}_label"] ?? ''));
        $pickup = isset($formData["memkey_{$i}_pickup"]) ? $formData["memkey_{$i}_pickup"] : '**';
        if ($pickup === '') { $pickup = '**'; }
        if ($val === '' && $lbl === '') { continue; }

        if (!$has) {
            $out .= "################################################\n";
            $out .= "##         Memory / Expansion Keys              ##\n";
            $out .= "################################################\n\n";
            $has = true;
        }

        $type = !empty($formData["memkey_{$i}_type"]) ? $formData["memkey_{$i}_type"] : '16';
        $line_num = !empty($formData["memkey_{$i}_line"]) ? $formData["memkey_{$i}_line"] : '1';

        list($prefix, $is_exp) = epm_memkey_prefix($i, $base_mem, $exp_size);
        $out .= "{$prefix}.line = {$line_num}\n";
        if (!empty($val)) $out .= "{$prefix}.value = {$val}\n";
        if ($pickup !== 'none') {
            $out .= "{$prefix}.pickup_value = {$pickup}\n";
        }
        $out .= "{$prefix}.type = {$type}\n";
        if (!empty($lbl)) $out .= "{$prefix}.label = {$lbl}\n";
        $out .= "\n";
    }
    return $out;
}

// Builds the programablekey.* block for a template. Keys left at their factory
// function (and with nothing filled in) are not written, so the phone keeps
// its own defaults. Only keys that exist on the selected model are written.
function epm_build_prog_keys_block(array $formData, array $meta) {
    $model = $formData['phone_model'] ?? 'manual';
    $ids = $meta['models'][$model] ?? $meta['models']['manual'];
    $out = '';

    foreach ($ids as $id) {
        $type_raw = trim((string)($formData["progkey_{$id}_type"] ?? ''));
        if ($type_raw === '' || !ctype_digit($type_raw)) { continue; }
        $type = (int)$type_raw;
        $default = epm_prog_key_default($model, $id, $meta);
        $fields = $meta['fields'][$type] ?? [];

        $line = '';
        if (in_array('line', $fields, true)) {
            $line = epm_prog_clean($formData["progkey_{$id}_line"] ?? '1');
            if ($line === '' || !ctype_digit($line)) { $line = '1'; }
        }
        $value = in_array('value', $fields, true) ? epm_prog_clean($formData["progkey_{$id}_value"] ?? '') : '';
        $hist  = in_array('hist', $fields, true)  ? epm_prog_clean($formData["progkey_{$id}_hist"] ?? '0') : '';
        $label = ($id <= 4 && $type !== 0) ? epm_prog_clean($formData["progkey_{$id}_label"] ?? '') : '';

        $has_extra = ($value !== '' || $label !== '' || ($hist !== '' && $hist !== '0') || ($line !== '' && $line !== '1'));

        // Fields that were in use under the previously-saved type but are dropped
        // by the type being saved now (e.g. a Speed Dial's line/value when the key
        // is put back to N/A, or switched to a type that doesn't use them) need an
        // explicit %NULL% - see epm_prog_key_null_fields().
        $null_fields = epm_prog_key_null_fields($id, $type, $formData, $meta);

        // If this key was already non-default when the form was loaded (i.e. a prior
        // save already pushed a custom value to the phone) and the admin has now put
        // it back to the factory function, we still have to write the default
        // explicitly - Yealink templates are overrides, not full state, so a phone
        // that already has a custom value keeps it forever if the parameter is simply
        // left out of the next config. Once written back to default once, it drops
        // out of the "was custom" snapshot on the next load and goes back to being
        // omitted normally.
        $was_custom = (($formData["progkey_{$id}_wascustom"] ?? '') === '1');
        if ($type === $default && !$has_extra && !$was_custom && empty($null_fields)) { continue; }

        if ($out === '') {
            $out .= "################################################\n";
            $out .= "##" . str_pad("         Programmable Keys", 44) . "##\n";
            $out .= "################################################\n\n";
        }
        $out .= "programablekey.{$id}.type = {$type}\n";
        if ($line !== '') {
            $out .= "programablekey.{$id}.line = {$line}\n";
        } elseif (in_array('line', $null_fields, true)) {
            $out .= "programablekey.{$id}.line = %NULL%\n";
        }
        if ($value !== '') {
            $out .= "programablekey.{$id}.value = {$value}\n";
        } elseif (in_array('value', $null_fields, true)) {
            $out .= "programablekey.{$id}.value = %NULL%\n";
        }
        if ($hist !== '' && $hist !== '0') {
            $out .= "programablekey.{$id}.history_type = {$hist}\n";
        } elseif (in_array('hist', $null_fields, true)) {
            $out .= "programablekey.{$id}.history_type = %NULL%\n";
        }
        if ($label !== '') {
            $out .= "programablekey.{$id}.label = {$label}\n";
        } elseif (in_array('label', $null_fields, true)) {
            $out .= "programablekey.{$id}.label = %NULL%\n";
        }
        $out .= "\n";
    }
    return $out;
}

// Per-model factory functions for every key ID (handed to the popout's JS).
$prog_key_model_defaults = [];
foreach (array_keys($prog_key_models) as $pm_name) {
    foreach (array_keys($prog_key_names) as $pm_id) {
        $prog_key_model_defaults[$pm_name][$pm_id] = epm_prog_key_default($pm_name, $pm_id, $prog_meta);
    }
}

// ============================================================================
// PER-DEVICE OVERRIDES
// A device .cfg is: device lines + inherited template + (optional) override block.
// The block is written LAST so its values win over the template, and every rebuild
// copies it across untouched. It is always regenerated as a diff against the
// template, so putting a setting back to the template's value removes the override.
// ============================================================================
function epm_ov_begin_marker() { return '##### DEVICE OVERRIDES #####'; }
function epm_ov_end_marker()   { return '##### END DEVICE OVERRIDES #####'; }

function epm_split_device_overrides($content) {
    $content = (string)$content;
    $b = strpos($content, epm_ov_begin_marker());
    if ($b === false) { return [$content, '']; }
    $baseline = substr($content, 0, $b);
    $rest = substr($content, $b + strlen(epm_ov_begin_marker()));
    $e = strpos($rest, epm_ov_end_marker());
    $ov = ($e === false) ? $rest : substr($rest, 0, $e);
    return [$baseline, trim($ov, "\r\n")];
}
function epm_extract_device_overrides($content) { $parts = epm_split_device_overrides($content); return $parts[1]; }
function epm_strip_device_overrides($content)   { $parts = epm_split_device_overrides($content); return $parts[0]; }
function epm_wrap_device_overrides($text) {
    $text = trim((string)$text);
    if ($text === '') { return ''; }
    return "\n\n" . epm_ov_begin_marker() . "\n" . $text . "\n" . epm_ov_end_marker() . "\n";
}

function epm_parse_cfg_map($text) {
    $map = [];
    foreach (preg_split('/\R/', (string)$text) as $l) {
        $l = trim($l);
        if ($l === '' || $l[0] === '#') { continue; }
        if (preg_match('/^([A-Za-z0-9_.\-]+)\s*=\s*(.*)$/', $l, $m)) {
            $v = trim($m[2]);
            $map[strtolower($m[1])] = ($v === '%NULL%') ? '' : $v;
        }
    }
    return $map;
}

function epm_ov_clean($s, $max = 120) {
    $s = preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string)$s);
    return trim(substr($s, 0, $max));
}

// Keys the Edit window manages itself; anything else in the override block goes to the custom box.
function epm_ov_is_managed_key($k) {
    return (bool)preg_match('/^(features\.(voice_mail|missed_call|forward_call|text_message)_popup\.enable|account\.1\.ringtone\.ring_type|ringtone\.url|linekey\.\d+\.(line|value|pickup_value|type|label)|memorykey\.\d+\.(line|value|pickup_value|type|label)|expansion_module\.\d+\.key\.\d+\.(line|value|pickup_value|type|label)|programablekey\.\d+\.(type|line|value|label|history_type))$/i', $k);
}

function epm_ov_model_key($content, array $model_keys) {
    // A device .cfg has its own "# Phone Model: Yealink" line first and the inherited template's
    // real model after it, so walk the matches from the last one back.
    $matches = [];
    preg_match_all('/^#\s*Phone\s*Model\s*:\s*(.+)$/im', (string)$content, $matches);
    $best = 'manual';
    foreach (array_reverse($matches[1] ?? []) as $cand) {
        $raw = strtoupper(trim($cand));
        $best_len = 0;
        $found = 'manual';
        foreach (array_keys($model_keys) as $k) {
            if ($k === 'manual') { continue; }
            if (strpos($raw, strtoupper($k)) !== false && strlen($k) > $best_len) { $found = $k; $best_len = strlen($k); }
        }
        if ($found !== 'manual') { $best = $found; break; }
    }
    return $best;
}

// Key counts for the Edit window: the model's own counts, or - for the custom/manual model and
// for anything the template uses beyond that - the highest key the template actually defines.
function epm_ov_key_counts($model, array $spec, $baseline_text, $exp_size, $exp_count) {
    $hl = 0; $hm = 0; $mm = [];
    if (preg_match_all('/^\s*linekey\.(\d+)\./im', (string)$baseline_text, $mm)) { $hl = max(array_map('intval', $mm[1])); }
    $mm = [];
    if (preg_match_all('/^\s*memorykey\.(\d+)\./im', (string)$baseline_text, $mm)) { $hm = max(array_map('intval', $mm[1])); }
    $linekeys = max((int)($spec['linekeys'] ?? 1), $hl, 1);
    $base_mem = ($model === 'manual') ? max((int)($spec['memkeys'] ?? 0), $hm) : (int)($spec['memkeys'] ?? 0);
    return [$linekeys, $base_mem, $base_mem + ($exp_size * $exp_count)];
}

// One line key / memory key: write only the fields that differ from the template.
function epm_ov_key_diff($prefix, array $new, array $base_map, array $defaults) {
    $out = '';
    $fields = ['line' => 'line', 'value' => 'value', 'pickup' => 'pickup_value', 'type' => 'type', 'label' => 'label'];
    foreach ($fields as $f => $param) {
        if (!array_key_exists($f, $new)) { continue; }
        $n = epm_ov_clean($new[$f]);
        $o = $base_map["{$prefix}.{$param}"] ?? ($defaults[$f] ?? '');
        if ($n === $o) { continue; }
        if (($f === 'type' || $f === 'line') && ($n === '' || !ctype_digit($n))) { continue; }
        $out .= "{$prefix}.{$param} = " . ($n === '' ? '%NULL%' : $n) . "\n";
    }
    // A key the template never configured has no type/line lines to inherit, so when the
    // override gives it content, write them explicitly instead of relying on assumed defaults.
    if ($out !== '' && (epm_ov_clean($new['value'] ?? '') !== '' || epm_ov_clean($new['label'] ?? '') !== '')) {
        foreach (['type', 'line'] as $f) {
            $n = epm_ov_clean($new[$f] ?? '');
            if ($n !== '' && ctype_digit($n) && !isset($base_map["{$prefix}.{$f}"]) && strpos($out, "{$prefix}.{$f} =") === false) {
                $out .= "{$prefix}.{$f} = {$n}\n";
            }
        }
    }
    return $out;
}

function epm_ov_prog_norm($id, $type, $line, $value, $label, $hist, array $meta) {
    $fields = $meta['fields'][(int)$type] ?? [];
    return [
        (int)$type,
        in_array('line', $fields, true)  ? (string)$line  : '',
        in_array('value', $fields, true) ? (string)$value : '',
        ($id <= 4 && (int)$type !== 0)   ? (string)$label : '',
        in_array('hist', $fields, true)  ? (string)$hist  : ''
    ];
}

if (isset($_GET['action']) && $_GET['action'] === 'get_device_overrides' && !empty($_GET['mac'])) {
    if (ob_get_length()) { ob_clean(); }
    header('Content-Type: application/json');
    $ov_mac = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $_GET['mac']));
    $ov_path = $tftp_dir . $ov_mac . '.cfg';
    if (!is_file($ov_path)) {
        http_response_code(404);
        echo json_encode(['error' => "Configuration file [{$ov_mac}.cfg] not found."]);
        exit;
    }
    $ov_content = file_get_contents($ov_path);
    list($ov_base_text, $ov_text) = epm_split_device_overrides($ov_content);
    $ov_base = epm_parse_cfg_map($ov_base_text);
    $ov_eff  = array_merge($ov_base, epm_parse_cfg_map($ov_text));
    $ov_model = epm_ov_model_key($ov_content, $yealink_model_keys);
    $ov_spec  = $yealink_model_keys[$ov_model];

    $ov_popups = [];
    foreach (['voice_mail', 'missed_call', 'forward_call', 'text_message'] as $pp) {
        $ov_popups[$pp] = (($ov_eff["features.{$pp}_popup.enable"] ?? '1') === '0') ? '0' : '1';
    }

    $ov_custom_files = array_values(array_filter(array_map('basename', glob($ringtone_dir . '*.*') ?: [])));
    usort($ov_custom_files, 'strcasecmp');
    $ov_builtin = [];
    foreach ($builtin_ringtones as $rv => $rl) { $ov_builtin[] = [$rv, $rl]; }

    $ov_keys = function ($prefix, $count) use ($ov_eff) {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'type'   => $ov_eff["{$prefix}.{$i}.type"] ?? ($i === 1 && $prefix === 'linekey' ? '15' : '16'),
                'value'  => $ov_eff["{$prefix}.{$i}.value"] ?? '',
                'label'  => $ov_eff["{$prefix}.{$i}.label"] ?? '',
                'pickup' => $ov_eff["{$prefix}.{$i}.pickup_value"] ?? '',
                'line'   => $ov_eff["{$prefix}.{$i}.line"] ?? '1',
            ];
        }
        return $rows;
    };

    // Memory keys = the model's built-in ones plus the keys of the template's expansion module(s).
    $ov_exp_model = 'none'; $ov_exp_count = 0;
    if (preg_match('/^#\s*Expansion\s*Model\s*:\s*(.+)$/im', $ov_base_text, $em)) { $ov_exp_model = trim($em[1]); }
    if (preg_match('/^#\s*Expansion\s*Count\s*:\s*(\d+)/im', $ov_base_text, $ec)) { $ov_exp_count = (int)$ec[1]; }
    $ov_exp_size  = (int)($expansion_key_sizes[$ov_exp_model] ?? 0);
    list($ov_linekey_count, $ov_base_mem, $ov_mem_total) = epm_ov_key_counts($ov_model, $ov_spec, $ov_base_text, $ov_exp_size, $ov_exp_count);
    $ov_mem_rows = [];
    for ($mi = 1; $mi <= $ov_mem_total; $mi++) {
        list($mp) = epm_memkey_prefix($mi, $ov_base_mem, $ov_exp_size);
        $ov_mem_rows[] = [
            'type'   => $ov_eff["{$mp}.type"] ?? '16',
            'value'  => $ov_eff["{$mp}.value"] ?? '',
            'label'  => $ov_eff["{$mp}.label"] ?? '',
            'pickup' => $ov_eff["{$mp}.pickup_value"] ?? '',
            'line'   => $ov_eff["{$mp}.line"] ?? '1',
        ];
    }

    $ov_prog = [];
    $ov_prog_ids = $prog_key_models[$ov_model] ?? $prog_key_models['manual'];
    foreach ($ov_prog_ids as $pid) {
        $def = epm_prog_key_default($ov_model, $pid, $prog_meta);
        $t = $ov_eff["programablekey.{$pid}.type"] ?? '';
        $ov_prog[] = [
            'id'    => $pid,
            'name'  => $prog_key_names[$pid] ?? ("Key {$pid}"),
            'type'  => ($t !== '' && ctype_digit($t)) ? $t : (string)$def,
            'line'  => $ov_eff["programablekey.{$pid}.line"] ?? '1',
            'value' => $ov_eff["programablekey.{$pid}.value"] ?? '',
            'label' => $ov_eff["programablekey.{$pid}.label"] ?? '',
            'hist'  => $ov_eff["programablekey.{$pid}.history_type"] ?? '0',
        ];
    }

    $ov_custom_lines = [];
    foreach (preg_split('/\R/', $ov_text) as $cl) {
        $cl = trim($cl);
        if ($cl === '' || $cl[0] === '#') { continue; }
        if (preg_match('/^([A-Za-z0-9_.\-]+)\s*=/', $cl, $cm) && !epm_ov_is_managed_key($cm[1])) { $ov_custom_lines[] = $cl; }
    }

    echo json_encode([
        'model'      => $ov_model,
        'ext'        => $ov_eff['account.1.auth_name'] ?? ($ov_eff['account.1.user_name'] ?? ''),
        'popups'     => $ov_popups,
        'ringtone'   => $ov_eff['account.1.ringtone.ring_type'] ?? 'Common',
        'ringtones'  => ['builtin' => $ov_builtin, 'custom' => $ov_custom_files],
        'dss'        => $dss_key_types,
        'maxLines'   => max(16, (int)($ov_spec['lines'] ?? 16)),
        'linekeys'   => $ov_keys('linekey', $ov_linekey_count),
        'memkeys'    => $ov_mem_rows,
        'prog'       => $ov_prog,
        'progTypes'  => $prog_key_types,
        'progFields' => $prog_key_type_fields,
        'custom'     => implode("\n", $ov_custom_lines),
        'hasOverrides' => trim($ov_text) !== '',
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_device_overrides']) && !empty($_POST['mac'])) {
    if (ob_get_length()) { ob_clean(); }
    header('Content-Type: application/json');
    $ov_mac = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $_POST['mac']));
    $ov_path = $tftp_dir . $ov_mac . '.cfg';
    $ov_in = json_decode((string)($_POST['payload'] ?? ''), true);
    if (!is_file($ov_path) || !is_array($ov_in)) {
        http_response_code(400);
        echo json_encode(['error' => 'Config file or payload missing.']);
        exit;
    }
    $ov_content = file_get_contents($ov_path);
    list($ov_base_text, $ov_old_text) = epm_split_device_overrides($ov_content);
    $ov_base  = epm_parse_cfg_map($ov_base_text);
    $ov_model = epm_ov_model_key($ov_content, $yealink_model_keys);
    $ov_spec  = $yealink_model_keys[$ov_model];
    $ov_lines = '';

    // Notifications
    foreach (['voice_mail', 'missed_call', 'forward_call', 'text_message'] as $pp) {
        if (!isset($ov_in['popups'][$pp])) { continue; }
        $new = ($ov_in['popups'][$pp] === '0' || $ov_in['popups'][$pp] === 0) ? '0' : '1';
        $old = (($ov_base["features.{$pp}_popup.enable"] ?? '1') === '0') ? '0' : '1';
        if ($new !== $old) { $ov_lines .= "features.{$pp}_popup.enable = {$new}\n"; }
    }

    // Default account ringtone
    if (isset($ov_in['ringtone'])) {
        $rv = epm_ov_clean($ov_in['ringtone']);
        $is_builtin = array_key_exists($rv, $builtin_ringtones);
        $is_custom  = !$is_builtin && $rv === basename($rv) && $rv !== '' && is_file($ringtone_dir . $rv);
        $old_rv = $ov_base['account.1.ringtone.ring_type'] ?? 'Common';
        if (($is_builtin || $is_custom) && $rv !== $old_rv) {
            $ov_lines .= "account.1.ringtone.ring_type = {$rv}\n";
            if ($is_custom && !preg_match('#^ringtone\.url\s*=.*/ringtones/' . preg_quote($rv, '#') . '\s*$#mi', $ov_base_text)) {
                if (preg_match('#^ringtone\.url\s*=\s*(\S+)/ringtones/#mi', $ov_base_text, $um)) {
                    $ov_assets = $um[1];
                } else {
                    $ov_assets = "http://{$saved_global_server_ip}" . ((isset($sysadmin_redirect) && $sysadmin_redirect) ? ':' . yealink_epm_prov_port() : '') . '/PhoneSettings';
                }
                $ov_lines .= "ringtone.url = {$ov_assets}/ringtones/{$rv}\n";
            }
        }
    }

    $p_exp_model = 'none'; $p_exp_count = 0;
    if (preg_match('/^#\s*Expansion\s*Model\s*:\s*(.+)$/im', $ov_base_text, $pem)) { $p_exp_model = trim($pem[1]); }
    if (preg_match('/^#\s*Expansion\s*Count\s*:\s*(\d+)/im', $ov_base_text, $pec)) { $p_exp_count = (int)$pec[1]; }
    $p_exp_size = (int)($expansion_key_sizes[$p_exp_model] ?? 0);
    list($p_linekey_count, $p_base_mem, $p_mem_total) = epm_ov_key_counts($ov_model, $ov_spec, $ov_base_text, $p_exp_size, $p_exp_count);

    // Line keys (key 1 is the account line and is never overridden)
    if (!empty($ov_in['linekeys']) && is_array($ov_in['linekeys'])) {
        foreach ($ov_in['linekeys'] as $row) {
            $i = isset($row['idx']) ? (int)$row['idx'] : 0;
            if ($i < 2 || $i > $p_linekey_count) { continue; }
            if (isset($row['type']) && !array_key_exists((string)$row['type'], $dss_key_types)) { unset($row['type']); }
            if (isset($row['line']) && (!ctype_digit((string)$row['line']) || (int)$row['line'] < 1 || (int)$row['line'] > 16)) { unset($row['line']); }
            $ov_lines .= epm_ov_key_diff("linekey.{$i}", $row, $ov_base, ['type' => '16', 'line' => '1', 'value' => '', 'label' => '', 'pickup' => '']);
        }
    }

    // Memory keys: built-in ones plus the template's expansion module keys
    if (!empty($ov_in['memkeys']) && is_array($ov_in['memkeys'])) {
        $m_exp_size = $p_exp_size; $m_base_mem = $p_base_mem; $m_total = $p_mem_total;
        foreach ($ov_in['memkeys'] as $row) {
            $i = isset($row['idx']) ? (int)$row['idx'] : 0;
            if ($i < 1 || $i > $m_total) { continue; }
            if (isset($row['type']) && !array_key_exists((string)$row['type'], $dss_key_types)) { unset($row['type']); }
            if (isset($row['line']) && (!ctype_digit((string)$row['line']) || (int)$row['line'] < 1 || (int)$row['line'] > 16)) { unset($row['line']); }
            list($m_prefix) = epm_memkey_prefix($i, $m_base_mem, $m_exp_size);
            $ov_lines .= epm_ov_key_diff($m_prefix, $row, $ov_base, ['type' => '16', 'line' => '1', 'value' => '', 'label' => '', 'pickup' => '']);
        }
    }

    // Programmable keys: reuse the template builder (incl. %NULL% handling) for the keys that changed
    if (!empty($ov_in['progkeys']) && is_array($ov_in['progkeys'])) {
        $allowed_ids = $prog_key_models[$ov_model] ?? $prog_key_models['manual'];
        $fd = ['phone_model' => $ov_model];
        foreach ($ov_in['progkeys'] as $row) {
            $id = isset($row['idx']) ? (int)$row['idx'] : 0;
            if (!in_array($id, $allowed_ids, true)) { continue; }
            $nt = epm_prog_clean($row['type'] ?? '');
            if ($nt === '' || !ctype_digit($nt) || !isset($prog_key_types[(int)$nt])) { continue; }
            $def = epm_prog_key_default($ov_model, $id, $prog_meta);
            $bt_raw = $ov_base["programablekey.{$id}.type"] ?? '';
            $bt = ($bt_raw !== '' && ctype_digit($bt_raw)) ? (int)$bt_raw : $def;
            $bl = $ov_base["programablekey.{$id}.line"] ?? '1';   if ($bl === '' || !ctype_digit($bl)) { $bl = '1'; }
            $bv = $ov_base["programablekey.{$id}.value"] ?? '';
            $bb = $ov_base["programablekey.{$id}.label"] ?? '';
            $bh = $ov_base["programablekey.{$id}.history_type"] ?? '0'; if ($bh === '') { $bh = '0'; }
            $nl = epm_prog_clean($row['line'] ?? '1');  if ($nl === '' || !ctype_digit($nl)) { $nl = '1'; }
            $nv = epm_prog_clean($row['value'] ?? '');
            $nb = epm_prog_clean($row['label'] ?? '');
            $nh = (($row['hist'] ?? '0') === '1') ? '1' : '0';
            if (epm_ov_prog_norm($id, $bt, $bl, $bv, $bb, $bh, $prog_meta) === epm_ov_prog_norm($id, (int)$nt, $nl, $nv, $nb, $nh, $prog_meta)) { continue; }
            $fd["progkey_{$id}_type"] = $nt;
            $fd["progkey_{$id}_line"] = $nl;
            $fd["progkey_{$id}_value"] = $nv;
            $fd["progkey_{$id}_label"] = $nb;
            $fd["progkey_{$id}_hist"] = $nh;
            $fd["progkey_{$id}_prevtype"] = (string)$bt;
            $fd["progkey_{$id}_prevvalue"] = $bv;
            $fd["progkey_{$id}_prevlabel"] = $bb;
            $fd["progkey_{$id}_prevhist"] = $bh;
            $fd["progkey_{$id}_wascustom"] = ($bt !== $def || $bv !== '' || $bb !== '' || $bl !== '1' || $bh !== '0') ? '1' : '0';
        }
        $ov_lines .= epm_build_prog_keys_block($fd, $prog_meta);
    }

    // Custom key / value additions
    $ov_dropped = 0;
    $ov_custom_out = '';
    foreach (preg_split('/\R/', (string)($ov_in['custom'] ?? '')) as $cl) {
        $cl = epm_ov_clean($cl, 400);
        if ($cl === '' || $cl[0] === '#') { continue; }
        if (preg_match('/^[A-Za-z0-9_.\-]+\s*=/', $cl)) { $ov_custom_out .= $cl . "\n"; } else { $ov_dropped++; }
    }
    $ov_lines .= $ov_custom_out;

    $new_cfg = rtrim($ov_base_text) . "\n" . epm_wrap_device_overrides($ov_lines);
    @file_put_contents($ov_path, $new_cfg);
    @chown($ov_path, 'asterisk');

    if (!empty($_POST['sync'])) {
        $ov_ext = $ov_base['account.1.auth_name'] ?? ($ov_base['account.1.user_name'] ?? '');
        if ($ov_ext !== '') { sendSipNotify($ov_ext, 'yealink-check-cfg', '', $saved_global_admin_pass); }
    }

    echo json_encode(['ok' => true, 'lines' => substr_count(trim($ov_lines), "\n") + ($ov_lines === '' ? 0 : 1), 'dropped' => $ov_dropped, 'synced' => !empty($_POST['sync'])]);
    exit;
}

if (isset($db) && $db instanceof PDO) {
    $pdo = $db;
} else {
    if (file_exists('/etc/freepbx.conf')) {
        include_once '/etc/freepbx.conf';
        $pdo = \FreePBX::Database();
    }
}

if (isset($pdo)) {
    try {
        $stmt = $pdo->query("SELECT val FROM kvstore_Sipsettings WHERE `key` = 'udpport-0.0.0.0' AND val != '' LIMIT 1");
        if ($res = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $raw_val = trim($res['val']);
            if (ctype_digit($raw_val)) {
                $default_sip_port = $raw_val;
            } elseif (preg_match('/:(\d+)$/', $raw_val, $pm)) {
                $default_sip_port = $pm[1];
            }
        }
    } catch (Exception $e) {}

    if (empty($file_dialnow_patterns)) {
        try {
            $stmt = $pdo->prepare("
                SELECT p.match_pattern_prefix, p.match_pattern_pass 
                FROM outbound_routes r 
                INNER JOIN outbound_route_patterns p ON r.route_id = p.route_id 
                WHERE LOWER(TRIM(r.name)) = 'outbound'
            ");
            $stmt->execute();
            $route_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($route_rows as $r_row) {
                $prefix = trim($r_row['match_pattern_prefix'] ?? '');
                $pattern = trim($r_row['match_pattern_pass'] ?? '');
                $full_pattern = $prefix . $pattern;
                if ($full_pattern !== '') {
                    $full_pattern = ltrim($full_pattern, '_');
                    $full_pattern = str_replace('.', 'x', $full_pattern);
                    $full_pattern = strtr($full_pattern, 'NnXxZz', 'xxxxxx');
                    if (!in_array($full_pattern, $outbound_patterns)) {
                        $outbound_patterns[] = $full_pattern;
                    }
                }
            }
        } catch (Exception $e) {}
    } else {
        $outbound_patterns = $file_dialnow_patterns;
    }

    try {
        // Only SIP / PJSIP extensions can register with a secret. Custom, DAHDI,
        // IAX2 and virtual extensions have no row in `sip`, so exclude them.
        $stmt = $pdo->query("SELECT u.extension AS id, u.name AS display_name, s.data AS secret 
                            FROM users u 
                            INNER JOIN devices d ON d.id = u.extension AND LOWER(d.tech) IN ('sip', 'pjsip') 
                            LEFT JOIN sip s ON u.extension = s.id AND s.keyword = 'secret' 
                            ORDER BY CAST(u.extension AS UNSIGNED) ASC");
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($results)) {
            $stmt = $pdo->query("SELECT d.id, d.description AS display_name, s.data AS secret 
                                FROM devices d 
                                LEFT JOIN sip s ON d.id = s.id AND s.keyword = 'secret' 
                                WHERE LOWER(d.tech) IN ('sip', 'pjsip') 
                                ORDER BY CAST(d.id AS UNSIGNED) ASC");
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        foreach ($results as $row) {
            $ext_id = (string)$row['id'];
            $name = !empty($row['display_name']) ? $row['display_name'] : "Extension {$ext_id}";
            $all_extensions[$ext_id] = [
                'id' => $ext_id,
                'secret' => $row['secret'] ?? '',
                'display_name' => $name
            ];
        }
    } catch (Exception $e) {}

    exec("asterisk -rx 'pjsip show contacts' 2>&1", $pjsip_contacts);
    if (is_array($pjsip_contacts)) {
        foreach ($pjsip_contacts as $c_line) {
            if (preg_match('/Contact:\s*(\d+)\/sip:[^@]+@([\d\.\:]+).*(Avail|Reachable|OK)/i', $c_line, $cm)) {
                $online_exts[$cm[1]] = [
                    'via' => $cm[2]
                ];
            }
        }
    }

    exec("asterisk -rx 'sip show peers' 2>&1", $sip_out);
    if (is_array($sip_out)) {
        foreach ($sip_out as $s_line) {
            if (preg_match('/^(\d+)\/(\d+)\s+([\d\.]+)\s+.*\s+OK\b/i', $s_line, $sm)) {
                $online_exts[$sm[1]] = [
                    'via' => $sm[3]
                ];
            }
        }
    }
}

ksort($all_extensions);

// ============================================================================
// 7. AJAX ENDPOINTS (INCLUDES OVPN TOGGLE, AUDIO TRIMMING & SCANNING)
// ============================================================================

if (isset($_REQUEST['action']) && $_REQUEST['action'] === 'rename_device_mac') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    // Changes only the MAC the phone is provisioned under. The cfg's content (template, extension,
    // keys, overrides, VPN settings) is carried over untouched except for the VPN tar filename.
    $old_mac = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $_REQUEST['old'] ?? ''));
    $new_mac = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $_REQUEST['new'] ?? ''));

    if (strlen($old_mac) !== 12 || strlen($new_mac) !== 12) {
        echo json_encode(['status' => 'error', 'message' => 'A MAC address must be exactly 12 hexadecimal characters.']);
        exit;
    }
    if ($old_mac === $new_mac) {
        echo json_encode(['status' => 'success', 'unchanged' => true]);
        exit;
    }

    $old_cfg = $tftp_dir . $old_mac . '.cfg';
    $new_cfg = $tftp_dir . $new_mac . '.cfg';
    if (!file_exists($old_cfg)) {
        echo json_encode(['status' => 'error', 'message' => "Configuration file {$old_mac}.cfg was not found."]);
        exit;
    }
    if (file_exists($new_cfg)) {
        echo json_encode(['status' => 'error', 'message' => "A configuration file for {$new_mac} already exists. Delete it first or choose a different MAC."]);
        exit;
    }

    // VPN tars are named <mac>_<ext>_ovpn.tar. Rename them first so the cfg never points at a missing file.
    $renamed_tars = [];
    $tar_failed = false;
    foreach (glob($vpnkeys_dir . $old_mac . '_*_ovpn.tar') ?: [] as $old_tar) {
        $new_tar = $vpnkeys_dir . $new_mac . substr(basename($old_tar), 12);
        if (file_exists($new_tar) || !@rename($old_tar, $new_tar)) {
            $tar_failed = true;
            break;
        }
        @chown($new_tar, 'asterisk');
        $renamed_tars[$old_tar] = $new_tar;
    }
    if ($tar_failed) {
        foreach ($renamed_tars as $o => $n) { @rename($n, $o); }
        echo json_encode(['status' => 'error', 'message' => 'Could not rename the VPN key package. Nothing was changed.']);
        exit;
    }

    // Point openvpn.url at the renamed tar (only lines that reference this MAC's package).
    $cfg_content = (string)@file_get_contents($old_cfg);
    if (!empty($renamed_tars)) {
        $cfg_content = preg_replace(
            '#^(\s*openvpn\.url\s*=.*/vpnkeys/)' . preg_quote($old_mac, '#') . '(_[^/\s]*_ovpn\.tar.*)$#mi',
            '${1}' . $new_mac . '${2}',
            $cfg_content
        );
    }

    if (@file_put_contents($new_cfg, $cfg_content) === false) {
        foreach ($renamed_tars as $o => $n) { @rename($n, $o); }
        echo json_encode(['status' => 'error', 'message' => "Could not write {$new_mac}.cfg in {$tftp_dir}."]);
        exit;
    }
    @chown($new_cfg, 'asterisk');
    @unlink($old_cfg);

    // Keep the registered-device record (used for the non-Yealink-OUI allow list) in step.
    if (isset($pdo) && $pdo instanceof PDO) {
        try {
            $upd = $pdo->prepare("UPDATE yealink_epm_devices SET mac = ? WHERE LOWER(mac) = ?");
            $upd->execute([$new_mac, $old_mac]);
        } catch (Exception $e) {
            error_log('Yealink EPM: unable to update registered MAC: ' . $e->getMessage());
        }
    }

    echo json_encode(['status' => 'success', 'old' => $old_mac, 'new' => $new_mac, 'vpn_renamed' => count($renamed_tars)]);
    exit;
}

if (isset($_REQUEST['action']) && $_REQUEST['action'] === 'toggle_ovpn_state') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    // The VPN toggle has no logic or PKI of its own - it only exists
    // because ovpn_mgr owns the OpenVPN CA/daemon. Mirror the same
    // installed+enabled check page.yealink_epm.php uses to decide
    // whether to render the toggle at all, so a direct/replayed request
    // can't do anything if ovpn_mgr isn't there (e.g. was uninstalled
    // after the page was loaded).
    global $amp_conf;
    $amp_web_root = rtrim(($amp_conf['AMPWEBROOT'] ?? null) ?: '/var/www/html', '/');

    $ovpn_mgr_available = false;
    if (class_exists('FreePBX') && \FreePBX::Modules()->checkStatus('ovpn_mgr')) {
        $module_info = \FreePBX::Modules()->getInfo('ovpn_mgr');
        if (!empty($module_info['ovpn_mgr']) && $module_info['ovpn_mgr']['status'] === MODULE_STATUS_ENABLED) {
            $ovpn_mgr_available = true;
        }
    }

    // Path constructed directly (not via a helper from the lib file
    // itself) since we haven't required that file yet at this point -
    // whether it exists is exactly what we're checking.
    $ovpn_client_ops_path = "{$amp_web_root}/admin/modules/ovpn_mgr/lib/client_ops.php";
    if (!$ovpn_mgr_available || !file_exists($ovpn_client_ops_path)) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'The OpenVPN Manager (ovpn_mgr) module is not installed and enabled, so per-device VPN cannot be toggled.'
        ]);
        exit;
    }
    require_once $ovpn_client_ops_path;

    $mac = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $_REQUEST['mac'] ?? ''));
    $ext = preg_replace('/[^0-9]/', '', $_REQUEST['ext'] ?? '');
    $enable = isset($_REQUEST['enable']) && ($_REQUEST['enable'] === '1' || $_REQUEST['enable'] === 'true');

    if (empty($ext) || empty($mac)) {
        echo json_encode(['status' => 'error', 'message' => 'Missing MAC address or Extension assignment.']);
        exit;
    }

    // Every path below comes from ovpn_mgr's own resolver (AMPWEBROOT-based,
    // wherever PhoneSettings currently resolves to) rather than being
    // hardcoded here, so this always points at the same PKI/data ovpn_mgr's
    // own UI and daemon use.
    $ovpn_paths = ovpn_mgr_resolve_paths($amp_web_root);
    $settings = getActiveServerSettings($ovpn_paths['serverConf']);
    $ovpn_host = $settings['ip'];
    $ovpn_port = $settings['port'];

    // vpn_prefix is yealink_epm's own "is this phone's SIP registration
    // coming in over the VPN subnet" check, derived from the same
    // "server a.b.c.0 255.255.255.0" line ovpn_mgr writes into its conf -
    // not something ovpn_mgr's shared settings helper exposes, so it's
    // parsed here.
    $vpn_prefix = '10.8.0.';
    if (file_exists($ovpn_paths['serverConf'])) {
        $conf_content = (string)@file_get_contents($ovpn_paths['serverConf']);
        if (preg_match('/^server\s+(\d+\.\d+\.\d+)\.\d+\s+/m', $conf_content, $mSrv)) {
            $vpn_prefix = $mSrv[1] . '.';
        }
    }

    // IMPORTANT: $settings['ip'] comes from ovpn_mgr's saved
    // "# client-remote-host ..." setting in its server configuration.
    // Do not overwrite it with Yealink EPM's global provisioning/SIP host:
    // that value is often the PBX's private LAN address and is not
    // necessarily the public VPN endpoint entered in ovpn_mgr.
    if (strpos($ovpn_host, '://') !== false) {
        $ovpn_host = parse_url($ovpn_host, PHP_URL_HOST);
    }
    if (strpos($ovpn_host, ':') !== false) {
        $ovpn_host = explode(':', $ovpn_host)[0];
    }

    $admin_pass = !empty($saved_global_admin_pass) ? $saved_global_admin_pass : '22222';

    $tar_filename = "{$mac}_{$ext}_ovpn.tar";
    $tar_path_vpnkeys = "{$ovpn_paths['pkgDir']}/{$tar_filename}";

    if ($enable) {
        // buildClientPackage() is ovpn_mgr's own generator: it issues the
        // client cert (if one doesn't already exist for this extension)
        // against ovpn_mgr's CA and writes the tarball to $tar_path_vpnkeys
        // itself, so there is nothing left to do here but check the result.
        $generated_path = buildClientPackage($ovpn_paths['pkiDir'], $ovpn_paths['pkgDir'], $ovpn_paths['baseDir'], $ext, $mac, $ovpn_host, $ovpn_port);

        if ($generated_path && file_exists($generated_path)) {
            $cfg_path = $tftp_dir . $mac . ".cfg";
            if (file_exists($cfg_path)) {
                $lines = @file($cfg_path, FILE_IGNORE_NEW_LINES) ?: [];
                $clean_lines = array_filter($lines, function($l) {
                    return !preg_match('/^(openvpn\.|network\.vpn_enable)/i', trim($l));
                });
                $clean_lines[] = "openvpn.url = http://" . yealink_epm_apply_redirect_port($saved_global_server_ip, $sysadmin_redirect) . "/PhoneSettings/vpnkeys/{$tar_filename}";
                $clean_lines[] = "network.vpn_enable = 1";
                @file_put_contents($cfg_path, implode("\n", $clean_lines) . "\n");
                @chown($cfg_path, 'asterisk');
            }
            sendSipNotify($ext, 'yealink-check-cfg', '', $admin_pass);

            $is_connected = false;
            if (isset($online_exts[$ext])) {
                $contact_uri = $online_exts[$ext]['via'] ?? '';
                if (strpos($contact_uri, $vpn_prefix) !== false) {
                    $is_connected = true;
                }
            }

            echo json_encode([
                'status'    => 'success', 
                'enabled'   => true, 
                'connected' => $is_connected
            ]);
            exit;
        } else {
            echo json_encode([
                'status' => 'error',
                'message' => "ovpn_mgr failed to generate the OpenVPN package for extension {$ext}. Check the ovpn_mgr module's own log for details."
            ]);
            exit;
        }
    } else {
        // revokeExtensionAndRestart() is ovpn_mgr's own revocation: it
        // revokes the cert against ovpn_mgr's CA, regenerates crl.pem,
        // deletes the cert/key and any built packages for this extension,
        // and restarts the daemon (via the same scoped ovpnctl helper
        // ovpn_mgr's own "Revoke" button uses) so the daemon actually
        // starts rejecting this client - a plain file delete or service
        // reload does not do that on its own.
        revokeExtensionAndRestart($ovpn_paths, $ext);

        $cfg_path = $tftp_dir . $mac . ".cfg";
        if (file_exists($cfg_path)) {
            $lines = @file($cfg_path, FILE_IGNORE_NEW_LINES) ?: [];
            $clean_lines = array_filter($lines, function($l) {
                return !preg_match('/^(openvpn\.|network\.vpn_enable)/i', trim($l));
            });
            // vpn_enable stays 1: the phone keeps the VPN feature on, fetches the empty tar and goes quiet
            // without a reboot (a 0 here would only take effect after one).
            $clean_lines[] = "openvpn.url = " . yealink_epm_fake_vpn_url($saved_global_server_ip, $sysadmin_redirect);
            $clean_lines[] = "network.vpn_enable = 1";
            @file_put_contents($cfg_path, implode("\n", $clean_lines) . "\n");
            @chown($cfg_path, 'asterisk');
        }

        sendSipNotify($ext, 'yealink-check-cfg', '', $admin_pass);
        echo json_encode(['status' => 'success', 'enabled' => false, 'connected' => false]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['single_ringtone_ajax'])) {
    if (ob_get_length()) { ob_clean(); }
    header('Content-Type: application/json');

    $start_time = filter_input(INPUT_POST, 'start_time', FILTER_VALIDATE_FLOAT) ?: 0.0;
    $duration   = filter_input(INPUT_POST, 'duration', FILTER_VALIDATE_FLOAT) ?: 0.0;

    if (isset($_FILES['ringtone_file']) && $_FILES['ringtone_file']['error'] === UPLOAD_ERR_OK) {
        $tmp_path = $_FILES['ringtone_file']['tmp_name'];
        $orig_name = basename($_FILES['ringtone_file']['name']);
        $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));

        if (in_array($ext, ['mp3', 'wav'])) {
            // Lowercase so the phone's alphabetical slot order matches the order we compute.
            $clean_filename = strtolower(pathinfo($orig_name, PATHINFO_FILENAME)) . '.wav';
            $target_path = $ringtone_dir . $clean_filename;

            exec('which ffmpeg 2>&1', $out_ff, $ret_ff);

            if ($ret_ff === 0) {
                $trim_flags = '';
                if ($duration > 0) {
                    $trim_flags = sprintf('-ss %f -t %f ', $start_time, $duration);
                }

                $cmd = sprintf(
                    'ffmpeg -y %s-i %s -ac 1 -ar 8000 -acodec pcm_mulaw %s 2>&1',
                    $trim_flags,
                    escapeshellarg($tmp_path),
                    escapeshellarg($target_path)
                );
                
                exec($cmd, $output, $return_var);

                if ($return_var === 0 && file_exists($target_path)) {
                    @chown($target_path, 'asterisk');
                    @chgrp($target_path, 'asterisk');
                    clearstatcache(true, $target_path);

                    echo json_encode([
                        'status' => 'success',
                        'filename' => $clean_filename,
                        'size' => filesize($target_path)
                    ]);
                    exit;
                } else {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'FFmpeg audio conversion/trimming failed.'
                    ]);
                    exit;
                }
            } else {
                if (move_uploaded_file($tmp_path, $target_path)) {
                    @chown($target_path, 'asterisk');
                    @chgrp($target_path, 'asterisk');
                    clearstatcache(true, $target_path);

                    echo json_encode([
                        'status' => 'success',
                        'filename' => $clean_filename,
                        'size' => filesize($target_path)
                    ]);
                    exit;
                }
            }
        } else {
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid file format. Only .mp3 and .wav files are allowed.'
            ]);
            exit;
        }
    }

    echo json_encode(['status' => 'error', 'message' => 'Failed to process uploaded ringtone file.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['single_wallpaper_ajax'])) {
    if (ob_get_length()) { ob_clean(); }
    header('Content-Type: application/json');

    if (isset($_FILES['wallpaper_file']) && $_FILES['wallpaper_file']['error'] === UPLOAD_ERR_OK) {
        $orig_wp_name = basename($_FILES['wallpaper_file']['name']);
        $wp_ext = strtolower(pathinfo($orig_wp_name, PATHINFO_EXTENSION));

        if (in_array($wp_ext, ['jpg', 'jpeg', 'png', 'bmp'], true)) {
            $clean_wp_name = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $orig_wp_name);

            if (!file_exists($logo_dir)) {
                @mkdir($logo_dir, 0775, true);
                @chown($logo_dir, 'asterisk');
            }

            $target_wp_path = $logo_dir . $clean_wp_name;

            if (move_uploaded_file($_FILES['wallpaper_file']['tmp_name'], $target_wp_path)) {
                @chown($target_wp_path, 'asterisk');
                @chgrp($target_wp_path, 'asterisk');
                clearstatcache(true, $target_wp_path);

                echo json_encode(['status' => 'success', 'filename' => $clean_wp_name]);
                exit;
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Failed to save uploaded wallpaper image.']);
                exit;
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Invalid file format. Only .jpg, .jpeg, .png and .bmp files are allowed.']);
            exit;
        }
    }

    echo json_encode(['status' => 'error', 'message' => 'Failed to process uploaded wallpaper file.']);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'scan_network') {
    if (ob_get_length()) { ob_clean(); }
    header('Content-Type: application/json');

    $subnet_input = trim($_GET['subnet'] ?? '');

    // MACs that already have a config, plus extension -> MAC so we can tell which
    // OpenVPN clients are phones we've already provisioned.
    $existing_cfg_files = glob($tftp_dir . "*.cfg");
    $existing_macs = [];
    $ext_to_mac = [];
    if (is_array($existing_cfg_files)) {
        foreach ($existing_cfg_files as $cfg_file) {
            $mac_name = strtolower(pathinfo($cfg_file, PATHINFO_FILENAME));
            if (isYealinkGlobalCfgBasename($mac_name)) {
                continue;
            }
            $existing_macs[] = $mac_name;
            if (preg_match('/^[a-f0-9]{12}$/', $mac_name)) {
                $cfg_body = @file_get_contents($cfg_file);
                if ($cfg_body !== false && preg_match('/^account\.1\.user_name\s*=\s*(\d+)/mi', $cfg_body, $em)) {
                    $ext_to_mac[$em[1]] = $mac_name;
                }
            }
        }
    }

    $target = epmParseScanTarget($subnet_input, $detected_host);

    // ---- Method 1: local L2 segment (broadcast ping -> ARP table) ------------
    exec('ping -c 2 -b ' . escapeshellarg($target['broadcast']) . ' > /dev/null 2>&1 &');
    usleep(200000);

    $discovered = [];
    $seen_macs = [];

    foreach (getArpTableMap() as $mac_clean => $ip) {
        if ($mac_clean === '000000000000' || !epmIpInTarget($ip, $target)) {
            continue;
        }
        if (isYealinkMac($mac_clean) && !in_array($mac_clean, $existing_macs, true)) {
            $discovered[] = ['ip' => $ip, 'mac' => $mac_clean, 'vendor' => 'Yealink', 'via' => 'arp'];
            $seen_macs[$mac_clean] = true;
        }
    }

    // ---- Method 2: routed subnets / OpenVPN (no ARP, so use provisioning logs) --
    $notes = [];
    $vpn_clients = epmGetOpenVpnClientMap();
    $vpn_in_scope = [];
    foreach ($vpn_clients as $vip => $vcn) {
        if (epmIpInTarget($vip, $target)) {
            $vpn_in_scope[$vip] = $vcn;
        }
    }

    $log_result = epmGetProvisioningLogMacMap();
    $log_map = $log_result['map'];
    $ping_budget = 20;

    foreach ($log_map as $ip => $mac) {
        if (!epmIpInTarget($ip, $target) || isset($seen_macs[$mac]) || in_array($mac, $existing_macs, true)) {
            continue;
        }
        $via = isset($vpn_in_scope[$ip]) ? 'openvpn' : 'routed';
        if ($via === 'routed') {
            // Not a live OpenVPN client, so make sure the address is actually answering.
            if ($ping_budget-- <= 0 || !epmHostResponds($ip)) {
                continue;
            }
        }
        $discovered[] = ['ip' => $ip, 'mac' => $mac, 'vendor' => 'Yealink', 'via' => $via];
        $seen_macs[$mac] = true;
    }

    // ---- Diagnostics for the UI -------------------------------------------------
    $unresolved = [];
    foreach ($vpn_in_scope as $vip => $vcn) {
        if (isset($log_map[$vip])) {
            continue;   // MAC known: either listed above or already configured
        }
        $cn_ext = '';
        if (preg_match('/^client[-_]?(\d+)$/i', $vcn, $cm) || preg_match('/^(\d+)$/', $vcn, $cm)) {
            $cn_ext = $cm[1];
        }
        if ($cn_ext !== '' && isset($ext_to_mac[$cn_ext])) {
            continue;   // extension already has a provisioned phone
        }
        $unresolved[] = $vip . ' (' . $vcn . ')';
    }

    if (!empty($unresolved)) {
        $notes[] = count($unresolved) . ' connected OpenVPN client(s) in this subnet could not be matched to a Yealink MAC: '
                 . implode(', ', array_slice($unresolved, 0, 10)) . '.';
        if ($log_result['files'] === 0) {
            $notes[] = 'No readable web-server access log was found (checked /var/log/httpd, /var/log/apache2, /var/log/nginx). '
                     . 'MACs behind a routed tunnel are learned from the phone\'s provisioning requests, so the "asterisk" user needs read access to that log - '
                     . 'run (or rerun) scripts/setup-root.sh as root to grant it.';
        } else {
            $notes[] = 'MACs behind a routed tunnel are learned from the phone\'s provisioning request (PhoneSettings/<mac>.cfg). '
                     . 'Reboot the phone or run Auto Provision on it, then scan again.';
        }
    }

    if (empty($vpn_in_scope) && !empty($vpn_clients)) {
        $vpn_nets = [];
        foreach (array_keys($vpn_clients) as $vip) {
            $vpn_nets[implode('.', array_slice(explode('.', $vip), 0, 3)) . '.0/24'] = true;
        }
        $notes[] = 'OpenVPN clients are currently connected on ' . implode(', ', array_keys($vpn_nets))
                 . ' - enter that subnet to scan them.';
    }

    echo json_encode([
        'status'  => 'success',
        'subnet'  => $target['network'] . '/' . $target['bits'],
        'devices' => $discovered,
        'notes'   => $notes,
    ]);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'scan_debug') {
    if (ob_get_length()) { ob_clean(); }
    header('Content-Type: application/json');

    $info = ['ouis' => getYealinkOuis()];

    $proc_user = 'unknown';
    if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $pw = @posix_getpwuid(posix_geteuid());
        if ($pw && !empty($pw['name'])) { $proc_user = $pw['name']; }
    } elseif (function_exists('get_current_user')) {
        $proc_user = get_current_user();
    }
    $info['php_process_user'] = $proc_user;
    $info['note'] = "If status/log files below show readable=false, this account ({$proc_user}) needs "
        . "read access to them. That's granted by the ovpn_mgr module's one-time setup-root.sh (run as "
        . "root, outside of any web request) -- re-run it if it hasn't been run since this account was "
        . "created, or if these permissions have regressed.";

    // ---- OpenVPN management port -------------------------------------------
    $mgmt_ok = false;
    $mgmt_err = '';
    $fp = @fsockopen('127.0.0.1', 7505, $errno, $errstr, 1);
    if ($fp) {
        $mgmt_ok = true;
        fclose($fp);
    } else {
        $mgmt_err = "{$errno}: {$errstr}";
    }
    $info['openvpn_mgmt_port_7505'] = $mgmt_ok ? 'reachable' : "not reachable ({$mgmt_err})";

    // ---- ovpnctl (primary source as of 1.0.14) ------------------------------
    $ovpnctl_path = epmFindOvpnCtl();
    $info['ovpnctl_path'] = $ovpnctl_path ?: 'not found';
    if ($ovpnctl_path) {
        $ovpnctl_status = epmRunOvpnCtl('status');
        $info['ovpnctl_status_ok'] = $ovpnctl_status['ok'];
        $info['ovpnctl_status_output_preview'] = implode("\n", array_slice(preg_split('/\r?\n/', trim($ovpnctl_status['out'])), 0, 15));
    } else {
        $info['ovpnctl_status_ok'] = false;
        $info['ovpnctl_status_output_preview'] = '';
    }

    // ---- OpenVPN status file candidates ------------------------------------
    $status_candidates = [
        '/var/log/openvpn/openvpn-status.log',
        '/var/www/html/PhoneSettings/openvpn/logs/openvpn-status.log',
        '/var/log/openvpn/status.log',
    ];
    $info['openvpn_status_files'] = [];
    foreach ($status_candidates as $sf) {
        $info['openvpn_status_files'][] = [
            'path'     => $sf,
            'exists'   => file_exists($sf),
            'readable' => is_readable($sf),
            'size'     => file_exists($sf) ? filesize($sf) : null,
            'mtime'    => file_exists($sf) ? date('Y-m-d H:i:s', filemtime($sf)) : null,
        ];
    }

    // ---- Parsed OpenVPN clients ---------------------------------------------
    $vpn_clients = epmGetOpenVpnClientMap();
    $info['openvpn_clients_parsed'] = $vpn_clients;
    $info['openvpn_client_count'] = count($vpn_clients);

    // ---- ARP table ------------------------------------------------------------
    $arp = getArpTableMap();
    $info['arp_table_entries'] = count($arp);
    $info['arp_table_sample'] = array_slice($arp, 0, 10, true);

    // ---- Access log discovery --------------------------------------------------
    $log_dirs = ['/var/log/httpd', '/var/log/apache2', '/var/log/nginx'];
    $info['log_dirs'] = [];
    foreach ($log_dirs as $dir) {
        $entry = ['dir' => $dir, 'exists' => is_dir($dir), 'readable' => is_dir($dir) && is_readable($dir), 'files' => []];
        if ($entry['exists'] && $entry['readable']) {
            $found = @glob($dir . '/*access*');
            if (is_array($found)) {
                foreach ($found as $f) {
                    $entry['files'][] = [
                        'name'       => basename($f),
                        'readable'   => is_readable($f),
                        'size'       => @filesize($f),
                        'mtime'      => @filemtime($f) ? date('Y-m-d H:i:s', filemtime($f)) : null,
                        'compressed' => (bool)preg_match('/\.(gz|bz2|xz|zip)$/i', $f),
                    ];
                }
            }
        }
        $info['log_dirs'][] = $entry;
    }

    // ---- Sample lines from the newest readable, uncompressed access log ------
    $info['log_sample'] = null;
    $newest = null;
    $newest_mtime = -1;
    foreach ($info['log_dirs'] as $entry) {
        if (!$entry['readable']) { continue; }
        foreach ($entry['files'] as $f) {
            if (!$f['readable'] || $f['compressed']) { continue; }
            if ($f['mtime'] !== null && strtotime($f['mtime']) > $newest_mtime) {
                $newest_mtime = strtotime($f['mtime']);
                $newest = $entry['dir'] . '/' . $f['name'];
            }
        }
    }
    if ($newest !== null) {
        $lines = [];
        $content = epmReadProtectedFile($newest);
        if (strlen($content) > 1048576) {
            $content = substr($content, -1048576);
        }
        foreach (explode("\n", $content) as $l) {
            if (stripos($l, '.cfg') !== false || stripos($l, 'yealink') !== false) {
                $lines[] = $l;
            }
        }
        $info['log_sample'] = [
            'file'            => $newest,
            'matching_lines'  => count($lines),
            'last_5_matches'  => array_slice($lines, -5),
        ];
        $log_result = epmGetProvisioningLogMacMap();
        $info['log_sample']['macs_extracted'] = $log_result['map'];
    } else {
        $info['log_sample'] = 'No readable, uncompressed access log found in any checked directory.';
    }

    echo json_encode($info, JSON_PRETTY_PRINT);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'add_scanned_device') {
    if (ob_get_length()) { ob_clean(); }
    header('Content-Type: application/json');

    $scanned_mac = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $_POST['scanned_mac'] ?? ''));
    $scanned_ext = trim($_POST['scanned_ext'] ?? '');
    $scanned_tpl = trim($_POST['scanned_template'] ?? '');
    $should_notify = isset($_POST['auto_provision']) && $_POST['auto_provision'] === '1';

    if (!empty($scanned_mac)) {
        if (!empty($scanned_ext)) {
            $existing_cfgs = glob($tftp_dir . "*.cfg");
            if (is_array($existing_cfgs)) {
                foreach ($existing_cfgs as $ecfg) {
                    $emac = strtolower(pathinfo($ecfg, PATHINFO_FILENAME));
                    if (isYealinkGlobalCfgBasename($emac) || strpos($emac, 'template') !== false || $emac === $scanned_mac) {
                        continue;
                    }
                    $elines = @file($ecfg, FILE_IGNORE_NEW_LINES);
                    if ($elines) {
                        foreach ($elines as $el) {
                            if (preg_match('/^account\.1\.(auth_name|user_name)\s*=\s*' . preg_quote($scanned_ext, '/') . '$/i', trim($el))) {
                                echo json_encode(['status' => 'error', 'message' => "Extension {$scanned_ext} is already assigned to MAC {$emac}."]);
                                exit;
                            }
                        }
                    }
                }
            }
        }

        $ext_name = $all_extensions[$scanned_ext]['display_name'] ?? "Extension {$scanned_ext}";
        $ext_secret = $all_extensions[$scanned_ext]['secret'] ?? '';
        $tpl_to_write = !empty($scanned_tpl) ? $scanned_tpl : 'none';

        $cfg_body = "#!version:{$cfg_version}\n\n";
        $cfg_body .= "# Phone Model: Yealink\n";
        $cfg_body .= "# Template: {$tpl_to_write}\n\n";
        if (!empty($scanned_ext)) {
            $cfg_body .= "account.1.enable = 1\n";
            $cfg_body .= "account.1.label = {$ext_name}\n";
            $cfg_body .= "account.1.display_name = {$ext_name}\n";
            $cfg_body .= "account.1.auth_name = {$scanned_ext}\n";
            $cfg_body .= "account.1.user_name = {$scanned_ext}\n";
            $cfg_body .= "account.1.password = {$ext_secret}\n";
            $scan_model = !empty($scanned_tpl) ? epm_template_phone_model($template_dir . $scanned_tpl) : '';
            foreach (epm_sip_server_lines($scan_model, $saved_global_server_ip, $default_sip_port) as $sl) {
                $cfg_body .= $sl . "\n";
            }
            $cfg_body .= "account.1.port = {$default_sip_port}\n";
            $cfg_body .= "linekey.1.type = 15\n";
            $cfg_body .= "linekey.1.line = 1\n";
            $cfg_body .= "linekey.1.value = {$scanned_ext}\n";
            $cfg_body .= "linekey.1.label = {$ext_name}\n\n";

            $tar_file = "/var/www/html/PhoneSettings/vpnkeys/{$scanned_mac}_{$scanned_ext}_ovpn.tar";
            if (file_exists($tar_file)) {
                $vpn_url = "http://" . yealink_epm_apply_redirect_port($saved_global_server_ip, $sysadmin_redirect) . "/PhoneSettings/vpnkeys/{$scanned_mac}_{$scanned_ext}_ovpn.tar";
                $cfg_body .= "openvpn.url = {$vpn_url}\n";
                $cfg_body .= "network.vpn_enable = 1\n\n";
            }
        }

        if (!empty($scanned_tpl) && file_exists($template_dir . $scanned_tpl)) {
            $tpl_content = file_get_contents($template_dir . $scanned_tpl);
            $tpl_content = preg_replace('/^account\.1\.sip_server.*$/m', '', $tpl_content);
            $tpl_content = preg_replace('/^#!version:.*$/m', '', $tpl_content);
            $cfg_body .= "##### INHERITED TEMPLATE SETTINGS ({$scanned_tpl}) #####\n";
            $cfg_body .= $tpl_content;
        }

        $cfg_path = $tftp_dir . "{$scanned_mac}.cfg";
        $cfg_written = @file_put_contents($cfg_path, $cfg_body);
        if ($cfg_written === false) {
            echo json_encode(['status' => 'error', 'message' => 'Could not write device config file.']);
            exit;
        }
        @chown($cfg_path, 'asterisk');

        // Persist administrator-added devices independently of their OUI.
        if (!isset($pdo) || !($pdo instanceof PDO)) {
            echo json_encode(['status' => 'error', 'message' => 'Database connection unavailable; device was not registered.']);
            exit;
        }
        try {
            $register = $pdo->prepare(
                "INSERT INTO yealink_epm_devices (mac, ext, model, template)
                 VALUES (:mac, :ext, :model, :template)
                 ON DUPLICATE KEY UPDATE ext = VALUES(ext), model = VALUES(model), template = VALUES(template)"
            );
            $register->execute([
                ':mac' => $scanned_mac,
                ':ext' => $scanned_ext,
                ':model' => 'manual',
                ':template' => $tpl_to_write
            ]);
        } catch (Exception $e) {
            error_log('Yealink EPM: unable to register manual device: ' . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Config was written, but database registration failed. Check the FreePBX database and module log.']);
            exit;
        }
        
        if ($should_notify && !empty($scanned_ext)) {
            $arp_table = getArpTableMap();
            $scanned_ip = $arp_table[$scanned_mac] ?? '';
            sendSipNotify($scanned_ext, 'yealink-check-cfg', $scanned_ip, $saved_global_admin_pass);
        }

        echo json_encode(['status' => 'success', 'mac' => $scanned_mac]);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Invalid MAC address']);
    exit;
}

// ============================================================================
// 8. POST ACTIONS (DELETE HANDLER, UPLOAD TEMPLATE, TEMPLATE FLUSH HANDLER)
// ============================================================================

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['upload_template_file'])) {
    $_POST['active_tab'] = 'tab_template';
    if (isset($_FILES['template_upload']) && $_FILES['template_upload']['error'] === UPLOAD_ERR_OK) {
        $orig_name = basename($_FILES['template_upload']['name']);
        $ext_check = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));

        if ($ext_check === 'cfg' || $ext_check === 'template') {
            $clean_basename = preg_replace('/(\.template)?\.cfg$/i', '', $orig_name);
            $clean_basename = preg_replace('/(\.template|\.cfg|_template|-template|template)$/i', '', $clean_basename);
            
            $clean_basename = preg_replace('/[^a-zA-Z0-9_\-]/', '', $clean_basename);
            if (empty($clean_basename)) { $clean_basename = "uploaded_template"; }

            $target_filename = $clean_basename . ".template.cfg";
            $destination_path = $template_dir . $target_filename;

            if (move_uploaded_file($_FILES['template_upload']['tmp_name'], $destination_path)) {
                @chown($destination_path, 'asterisk');
                $status = "Successfully uploaded template '{$target_filename}' into /tftpboot/templates/";
                $_POST['template_to_load'] = $target_filename;
            } else {
                $status = "<span style='color:#dc3545;'><b>Error:</b> Failed to move uploaded template to {$template_dir}</span>";
            }
        } else {
            $status = "<span style='color:#dc3545;'><b>Error:</b> Invalid template file extension. Must be a .cfg or .template.cfg file.</span>";
        }
    } else {
        $status = "<span style='color:#dc3545;'><b>Error:</b> No valid template file selected for upload.</span>";
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_target_file']) && !empty($_POST['target_filename'])) {
    $target_file = basename($_POST['target_filename']);
    $file_type = $_POST['target_file_type'] ?? '';

    if (in_array($file_type, ['logo', 'ringtone', 'template'])) {
        $_POST['active_tab'] = 'tab_template';
    }

    if ($file_type === 'global') {
        $deleted_any_global = false;
        foreach (array_keys(yealinkGlobalCfgMap()) as $global_basename) {
            $global_path = $tftp_dir . $global_basename . ".cfg";
            if (file_exists($global_path) && is_file($global_path)) {
                if (@unlink($global_path)) {
                    $deleted_any_global = true;
                }
            }
        }
        if ($deleted_any_global) {
            $status = "Successfully deleted all Global Settings y-config files.";
        }
        $full_path = "";
    } elseif ($file_type === 'template') {
        $full_path = $template_dir . $target_file;
    } elseif ($file_type === 'cfg') {
        $full_path = $tftp_dir . $target_file;
    } elseif ($file_type === 'logo') {
        $full_path = $logo_dir . $target_file;
    } elseif ($file_type === 'ringtone') {
        $full_path = $ringtone_dir . $target_file;
    } else {
        $full_path = "";
    }

    if (!empty($full_path) && file_exists($full_path) && is_file($full_path)) {
        if (@unlink($full_path)) {
            $status = "Successfully deleted file: " . htmlspecialchars($target_file);
            if ($file_type === 'ringtone') {
                $ringtone_was_deleted = true;
            }
        }
    }

    if (!empty($_POST['current_loaded_template'])) {
        $curr_tpl = trim($_POST['current_loaded_template']);
        if (strpos($curr_tpl, '.template.cfg') === false && strpos($curr_tpl, '.cfg') === false) {
            $curr_tpl .= '.template.cfg';
        }
        $_POST['template_to_load'] = $curr_tpl;
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['flush_template_ringtones'])) {
    $target_tpl = trim($_POST['template_name'] ?? '');
    if (empty($target_tpl) && !empty($_POST['current_loaded_template'])) {
        $target_tpl = trim($_POST['current_loaded_template']);
    }

    if (!empty($target_tpl)) {
        if (strpos($target_tpl, '.template.cfg') === false && strpos($target_tpl, '.cfg') === false) {
            $tpl_filename = $target_tpl . '.template.cfg';
        } else {
            $tpl_filename = $target_tpl;
        }

        $tpl_path = $template_dir . $tpl_filename;

        $posted_ringtones = $_POST['uploaded_ringtones'] ?? [];
        if (!is_array($posted_ringtones)) {
            $posted_ringtones = [];
        }

        if (file_exists($tpl_path)) {
            $tpl_lines = file($tpl_path, FILE_IGNORE_NEW_LINES);
            $clean_tpl_lines = [];
            $in_distinctive_block = false;

            foreach ($tpl_lines as $t_line) {
                $trimmed_line = trim($t_line);

                if (strpos($trimmed_line, '######## DISTINCTIVE RINGTONE & ALERT INFO SETUP ########') !== false) {
                    $in_distinctive_block = true;
                    continue;
                }
                if (strpos($trimmed_line, '######## END DISTINCTIVE RINGTONE SETUP ########') !== false) {
                    $in_distinctive_block = false;
                    continue;
                }
                if ($in_distinctive_block) {
                    continue;
                }

                if (preg_match('/^ringtone\.url\s*=\s*http:\/\/[^\/]+\/PhoneSettings\/ringtones\/([^\s]+)/i', $trimmed_line, $rm)) {
                    if (!in_array($rm[1], $posted_ringtones)) {
                        continue;
                    }
                }

                $clean_tpl_lines[] = $t_line;
            }

            $updated_tpl_str = implode("\n", $clean_tpl_lines);
            if (!empty($posted_ringtones)) {
                $updated_tpl_str = rtrim($updated_tpl_str) . "\n\n" . buildDistinctiveRingtoneConfigBlock($posted_ringtones, epm_template_phone_model($tpl_path));
            }

            @file_put_contents($tpl_path, $updated_tpl_str);
            @chown($tpl_path, 'asterisk');
        }

        $flushed_count = rebuildDevicesForTemplate($tpl_filename, $tftp_dir, $template_dir, $saved_global_admin_pass, true);

        $_SESSION['pending_ringtone_flush'][$tpl_filename] = true;

        $status = "Flushed deselected ringtones (%NULL%) across {$flushed_count} device(s) and saved changes to '{$tpl_filename}'.";
        $_POST['template_to_load'] = $tpl_filename;
        $just_flushed = true;
    }
}

// ============================================================================
// 9. FORM DATA INITIALIZATION & SAVE TEMPLATE HANDLER
// ============================================================================

$max_linekeys = isset($_POST['linekey_count']) ? (int)$_POST['linekey_count'] : 1;
$max_memkeys = isset($_POST['memkey_count']) ? (int)$_POST['memkey_count'] : 0;

$formData = [
    'template_name' => '',
    'phone_model' => 'manual',
    'exp_model' => 'none',
    'exp_count' => '0',
    'server_ip' => $saved_global_server_ip,
    'prov_http_port' => yealink_epm_prov_port(),
    'sip_port' => $default_sip_port,
    'sip_listen_port' => '5062',
    'voicemail_number' => $default_voicemail_ext,
    'timezone' => $saved_global_timezone,
    'timezone_name' => $saved_global_timezone_name,
    'time_format' => $saved_global_time_format,
    'ntp_server1' => $saved_global_ntp_server1,
    'ntp_server2' => $saved_global_ntp_server2,
    'admin_password' => $saved_global_admin_pass,
    'account_ringtone' => 'Common',
    'uploaded_ringtones' => [],
    'logo_file' => '',
    'exp_wallpaper_file' => '',
    'dialnow_timeout' => $saved_global_dialnow_timeout,
    'dialnow_count' => count($outbound_patterns) ?: 1,
    'linekey_count' => $max_linekeys,
    'memkey_count' => $max_memkeys,
    'popup_voice_mail' => '1',
    'popup_missed_call' => '1',
    'popup_forward_call' => '1',
    'popup_text_message' => '1',
    'custom_inputs_global' => '',
    'custom_inputs' => '',
    'sip_use_out_bound_in_dialog' => '1',
    'transfer_blind_tran_on_hook_enable' => '1',
    'transfer_on_hook_trans_enable' => '1',
    'transfer_dsskey_deal_type' => '2',
    'auto_provision_mode' => '7',
    'auto_provision_weekly_enable' => '1',
    'auto_provision_weekly_begin_time' => '23:00',
    'auto_provision_weekly_end_time' => '23:59',
    'auto_provision_weekly_dayofweek' => '0',
    'auto_provision_dhcp_option_enable' => '1',
    'auto_provision_username' => '',
    'auto_provision_password' => '',
    'active_tab' => $_POST['active_tab']
        ?? ((isset($_GET['tab']) && in_array($_GET['tab'], ['tab_global', 'tab_template', 'tab_devices'], true)) ? $_GET['tab'] : 'tab_global')
];

$formData["linekey_1_type"] = "15";
$formData["linekey_1_line"] = "1";
$formData["linekey_1_value"] = "";
$formData["linekey_1_label"] = "";
$formData["linekey_1_pickup"] = "";

for ($i = 2; $i <= 29; $i++) {
    $formData["linekey_{$i}_type"] = "16"; 
    $formData["linekey_{$i}_line"] = "1";
    $formData["linekey_{$i}_value"] = "";
    $formData["linekey_{$i}_label"] = "";
    $formData["linekey_{$i}_pickup"] = "";
}

for ($i = 1; $i <= 180; $i++) {
    $formData["memkey_{$i}_type"] = "16";
    $formData["memkey_{$i}_value"] = "";
    $formData["memkey_{$i}_label"] = "";
    $formData["memkey_{$i}_pickup"] = "";
    $formData["memkey_{$i}_line"] = "1";
}

foreach (array_keys($prog_key_names) as $pid) {
    $formData["progkey_{$pid}_type"] = (string)$prog_key_defaults[$pid];
    $formData["progkey_{$pid}_line"] = "1";
    $formData["progkey_{$pid}_value"] = "";
    $formData["progkey_{$pid}_label"] = "";
    $formData["progkey_{$pid}_hist"] = "0";
    // Must be seeded here (even though it's not a real Yealink parameter) so the
    // save handler's "foreach ($formData as $k => $v) { if (isset($_POST[$k])) }"
    // whitelist below actually picks up progkey_{id}_wascustom from the submitted
    // form - see epm_build_prog_keys_block() for what it's used for.
    $formData["progkey_{$pid}_wascustom"] = "";
    // Snapshot of the type/line/value/label/hist that were already in effect when
    // the popout was rendered (before the admin touches anything). Also just
    // seeded here so the whitelist loop below picks it up as a hidden field - see
    // epm_prog_key_null_fields() for what it's used for.
    $formData["progkey_{$pid}_prevtype"] = (string)$prog_key_defaults[$pid];
    $formData["progkey_{$pid}_prevline"] = "1";
    $formData["progkey_{$pid}_prevvalue"] = "";
    $formData["progkey_{$pid}_prevlabel"] = "";
    $formData["progkey_{$pid}_prevhist"] = "0";
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_template'])) {
    $formData['active_tab'] = 'tab_template';

    foreach ($formData as $k => $v) {
        if (isset($_POST[$k])) {
            if ($k === 'uploaded_ringtones' && is_array($_POST[$k])) {
                $formData[$k] = array_map('trim', $_POST[$k]);
            } else {
                $formData[$k] = trim($_POST[$k]);
            }
        }
    }

    if (!isset($_POST['uploaded_ringtones'])) {
        $formData['uploaded_ringtones'] = [];
    } else {
        sort($formData['uploaded_ringtones'], SORT_STRING | SORT_FLAG_CASE);
        $formData['uploaded_ringtones'] = array_values(array_unique($formData['uploaded_ringtones']));
    }

    // Process new logo uploads directly during template save
    if (isset($_FILES['logo_upload']) && $_FILES['logo_upload']['error'] === UPLOAD_ERR_OK) {
        $orig_logo_name = basename($_FILES['logo_upload']['name']);
        $clean_logo_name = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $orig_logo_name);
        
        if (!file_exists($logo_dir)) {
            @mkdir($logo_dir, 0775, true);
            @chown($logo_dir, 'asterisk');
        }

        $target_logo_path = $logo_dir . $clean_logo_name;

        if (move_uploaded_file($_FILES['logo_upload']['tmp_name'], $target_logo_path)) {
            @chown($target_logo_path, 'asterisk');
            $formData['logo_file'] = $clean_logo_name;
        }
    }

    // Process new expansion module wallpaper uploads (EXP43 / EXP50 only have a screen for this).
    if (isset($_FILES['exp_wallpaper_upload']) && $_FILES['exp_wallpaper_upload']['error'] === UPLOAD_ERR_OK) {
        $orig_wp_name = basename($_FILES['exp_wallpaper_upload']['name']);
        $clean_wp_name = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $orig_wp_name);

        if (!file_exists($logo_dir)) {
            @mkdir($logo_dir, 0775, true);
            @chown($logo_dir, 'asterisk');
        }

        $target_wp_path = $logo_dir . $clean_wp_name;

        if (move_uploaded_file($_FILES['exp_wallpaper_upload']['tmp_name'], $target_wp_path)) {
            @chown($target_wp_path, 'asterisk');
            $formData['exp_wallpaper_file'] = $clean_wp_name;
        }
    }

    $formData["linekey_1_type"] = "15";
    $formData["linekey_1_line"] = trim($_POST["linekey_1_line"] ?? '1');
    $formData["linekey_1_value"] = trim($_POST["linekey_1_value"] ?? '');
    $formData["linekey_1_label"] = trim($_POST["linekey_1_label"] ?? '');
    $formData["linekey_1_pickup"] = trim($_POST["linekey_1_pickup"] ?? '');

    for ($i = 2; $i <= 29; $i++) {
        if (isset($_POST["linekey_{$i}_type"])) $formData["linekey_{$i}_type"] = trim($_POST["linekey_{$i}_type"]);
        if (isset($_POST["linekey_{$i}_line"])) $formData["linekey_{$i}_line"] = trim($_POST["linekey_{$i}_line"]);
        if (isset($_POST["linekey_{$i}_value"])) $formData["linekey_{$i}_value"] = trim($_POST["linekey_{$i}_value"]);
        if (isset($_POST["linekey_{$i}_label"])) $formData["linekey_{$i}_label"] = trim($_POST["linekey_{$i}_label"]);
        if (isset($_POST["linekey_{$i}_pickup"])) $formData["linekey_{$i}_pickup"] = trim($_POST["linekey_{$i}_pickup"]);
    }

    for ($i = 1; $i <= 180; $i++) {
        if (isset($_POST["memkey_{$i}_type"])) $formData["memkey_{$i}_type"] = trim($_POST["memkey_{$i}_type"]);
        if (isset($_POST["memkey_{$i}_value"])) $formData["memkey_{$i}_value"] = trim($_POST["memkey_{$i}_value"]);
        if (isset($_POST["memkey_{$i}_label"])) $formData["memkey_{$i}_label"] = trim($_POST["memkey_{$i}_label"]);
        if (isset($_POST["memkey_{$i}_pickup"])) $formData["memkey_{$i}_pickup"] = trim($_POST["memkey_{$i}_pickup"]);
        if (isset($_POST["memkey_{$i}_line"])) $formData["memkey_{$i}_line"] = trim($_POST["memkey_{$i}_line"]);
    }

    foreach (array_keys($prog_key_names) as $pid) {
        foreach (['type', 'line', 'value', 'label', 'hist'] as $pf) {
            if (isset($_POST["progkey_{$pid}_{$pf}"])) {
                $formData["progkey_{$pid}_{$pf}"] = trim($_POST["progkey_{$pid}_{$pf}"]);
            }
        }
    }

    $tpl_name = preg_replace('/[^a-zA-Z0-9_\-]/', '', $formData['template_name']);
    if (empty($tpl_name)) $tpl_name = "default_template";
    $tpl_filename = $tpl_name . ".template.cfg";

    // $saved_global_server_ip is always the bare host (no port). SIP
    // registration uses its own port field (account.1.sip_server_port,
    // account.1.port below) and must never carry the HTTP provisioning
    // port — only HTTP asset URLs (logo/ringtone) shift to the provisioning port.
    $host_only = $saved_global_server_ip;
    $asset_host = $sysadmin_redirect
        ? "http://{$host_only}:" . yealink_epm_prov_port() . "/PhoneSettings"
        : "http://{$host_only}/PhoneSettings";

    $logo_path_prefix = "{$asset_host}/logo/";
    $logo_url = "";
    $lcd_logo_mode = "0";
    $use_lcd_logo_url = false;
    $is_logo_disabled = false;

    if ($formData['logo_file'] === 'system') {
        $lcd_logo_mode = "1";
        $logo_url = "Config:default";
    } elseif (!empty($formData['logo_file'])) {
        $lcd_logo_mode = "2";
        $logo_url = $logo_path_prefix . $formData['logo_file'];
        
        $ext = strtolower(pathinfo($formData['logo_file'], PATHINFO_EXTENSION));
        if ($formData['phone_model'] === 'T28P' || $ext === 'dob') {
            $use_lcd_logo_url = true;
        }
    } else {
        $lcd_logo_mode = "0";
        $is_logo_disabled = true;
    }

    $generated_template_cfg = "## Yealink Template Configuration File ##\n";
    $generated_template_cfg .= "# Phone Model: {$formData['phone_model']}\n";
    $generated_template_cfg .= "# Expansion Model: {$formData['exp_model']}\n";
    $generated_template_cfg .= "# Expansion Count: {$formData['exp_count']}\n\n";

    foreach (epm_sip_server_lines($formData['phone_model'], $saved_global_server_ip, $formData['sip_port']) as $sl) {
        $generated_template_cfg .= $sl . "\n";
    }
    $generated_template_cfg .= "account.1.port = {$formData['sip_port']}\n";
    $generated_template_cfg .= "account.1.sip_listen_port = {$formData['sip_listen_port']}\n";
    $generated_template_cfg .= "voice_mail.number.1 = {$formData['voicemail_number']}\n";
    foreach (['voice_mail', 'missed_call', 'forward_call', 'text_message'] as $pp) {
        $pv = (($formData["popup_{$pp}"] ?? '1') === '0') ? '0' : '1';
        $generated_template_cfg .= "features.{$pp}_popup.enable = {$pv}\n";
    }
    $generated_template_cfg .= "\n";

    $acct_ring = $formData['account_ringtone'] ?? 'Common';
    $generated_template_cfg .= "account.1.ringtone.ring_type = {$acct_ring}\n";
    
    if (!empty($formData['uploaded_ringtones']) && is_array($formData['uploaded_ringtones'])) {
        $existing_ringtone_files = array_map('basename', glob($ringtone_dir . "*.*") ?: []);
        $valid_ringtones = [];

        foreach ($formData['uploaded_ringtones'] as $r_file) {
            if (in_array($r_file, $existing_ringtone_files)) {
                $valid_ringtones[] = $r_file;
            }
        }

        $formData['uploaded_ringtones'] = $valid_ringtones;

        if (!empty($formData['uploaded_ringtones'])) {
            sort($formData['uploaded_ringtones'], SORT_STRING | SORT_FLAG_CASE);
            $formData['uploaded_ringtones'] = array_values(array_unique($formData['uploaded_ringtones']));

            $generated_template_cfg .= "account.1.alert_info_url_enable = 1\n\n";
            $generated_template_cfg .= "################################################\n";
            $generated_template_cfg .= "##         Uploaded Sound Files / Provisioning  ##\n";
            $generated_template_cfg .= "################################################\n";
            foreach ($formData['uploaded_ringtones'] as $r_file) {
                $r_url = "{$asset_host}/ringtones/" . $r_file;
                $generated_template_cfg .= "ringtone.url = {$r_url}\n";
            }
            $generated_template_cfg .= "\n";
        } else {
            $generated_template_cfg .= "\n";
        }
    } else {
        $generated_template_cfg .= "\n";
    }

    $generated_template_cfg .= buildDistinctiveRingtoneConfigBlock($formData['uploaded_ringtones'], $formData['phone_model'] ?? '');

    $gen_base_mem = (int)($yealink_model_keys[$formData['phone_model']]['memkeys'] ?? 0);
    $gen_exp_size = (int)($expansion_key_sizes[$formData['exp_model']] ?? 0);
    $generated_template_cfg .= epm_build_memory_keys_block($formData, $max_memkeys, $gen_base_mem, $gen_exp_size);

    // Expansion module wallpaper: only EXP43 / EXP50 have a color LCD that supports this.
    if (in_array($formData['exp_model'], $expansion_wallpaper_models, true) && !empty($formData['exp_wallpaper_file'])) {
        $exp_wp_url = $logo_path_prefix . $formData['exp_wallpaper_file'];
        $generated_template_cfg .= "################################################\n";
        $generated_template_cfg .= "##         Expansion Module Wallpaper           ##\n";
        $generated_template_cfg .= "################################################\n\n";
        // The phone screen and the expansion module choose their picture independently
        // (phone_setting.backgrounds / expansion_module.backgrounds), so they may differ.
        // wallpaper_upload.url is a single download URL, so:
        //  - same file as the phone wallpaper: the phone block below downloads it for both;
        //  - different file: this URL is written first and the phone's URL follows it.
        $phone_uses_wp_url = !$is_logo_disabled && !$use_lcd_logo_url && $formData['logo_file'] !== 'system';
        $same_as_phone = $phone_uses_wp_url && basename($logo_url) === $formData['exp_wallpaper_file'];
        if (!$same_as_phone) {
            $generated_template_cfg .= "wallpaper_upload.url = {$exp_wp_url}\n";
        }
        $generated_template_cfg .= "expansion_module.backgrounds = Config:{$formData['exp_wallpaper_file']}\n\n";
    }

    $has_linekeys = false;
    for ($i = 1; $i <= $max_linekeys; $i++) {
        $type = $formData["linekey_{$i}_type"] ?? '16';
        $line_num = !empty($formData["linekey_{$i}_line"]) ? $formData["linekey_{$i}_line"] : '1';
        $val = $formData["linekey_{$i}_value"] ?? '';
        $lbl = $formData["linekey_{$i}_label"] ?? '';
        $pickup = isset($formData["linekey_{$i}_pickup"]) ? $formData["linekey_{$i}_pickup"] : '**';
        if ($pickup === '') { $pickup = '**'; }

        if (!empty($val) || !empty($lbl) || ($pickup !== 'none' && !empty($pickup))) {
            if (!$has_linekeys) {
                $generated_template_cfg .= "################################################\n";
                $generated_template_cfg .= "##         Line Keys                            ##\n";
                $generated_template_cfg .= "################################################\n\n";
                $has_linekeys = true;
            }
            $generated_template_cfg .= "linekey.{$i}.line = {$line_num}\n";
            if (!empty($val)) $generated_template_cfg .= "linekey.{$i}.value = {$val}\n";
            if ($pickup !== 'none') {
                $generated_template_cfg .= "linekey.{$i}.pickup_value = {$pickup}\n";
            }
            $generated_template_cfg .= "linekey.{$i}.type = {$type}\n";
            if (!empty($lbl)) $generated_template_cfg .= "linekey.{$i}.label = {$lbl}\n\n";
        }
    }

    $generated_template_cfg .= epm_build_prog_keys_block($formData, $prog_meta);

    $generated_template_cfg .= "phone_setting.lcd_logo.mode = {$lcd_logo_mode}\n";
    if ($is_logo_disabled) {
        // Nothing to push; blank lcd_logo.url is stripped by the cleanup pass below.
        $generated_template_cfg .= "lcd_logo.url = \n\n";
    } elseif ($use_lcd_logo_url) {
        // .dob logo (T28P etc.)
        $generated_template_cfg .= "lcd_logo.url = {$logo_url}\n\n";
    } elseif ($formData['logo_file'] === 'system') {
        $generated_template_cfg .= "phone_setting.backgrounds = Default:1.png\n\n";
    } else {
        // Color-screen wallpaper (jpg/png/bmp): wallpaper_upload.url tells the phone
        // where to download the image; phone_setting.backgrounds selects it by filename.
        $generated_template_cfg .= "wallpaper_upload.url = {$logo_url}\n";
        $generated_template_cfg .= "phone_setting.backgrounds = Config:" . basename($logo_url) . "\n\n";
    }

    if (!empty($formData['custom_inputs'])) {
        $formData['custom_inputs'] = implode("\n", array_filter(
            preg_split('/\R/', $formData['custom_inputs']),
            function ($l) { return !epm_is_ringtone_marker_line($l); }
        ));
    }
    if (!empty(trim($formData['custom_inputs']))) {
        $generated_template_cfg .= "##### Template Custom Additions #####\n";
        $generated_template_cfg .= trim($formData['custom_inputs']) . "\n\n";
    }

    $cleaned_lines = [];
    foreach (explode("\n", $generated_template_cfg) as $line) {
        $trimmed = trim($line);
        if (preg_match('/^[^=]+=\s*$/i', $trimmed)) {
            continue;
        }
        $cleaned_lines[] = $line;
    }
    $generated_template_cfg = implode("\n", $cleaned_lines);

    @file_put_contents($template_dir . $tpl_filename, $generated_template_cfg);
    @chown($template_dir . $tpl_filename, 'asterisk');

    if (!empty($_SESSION['pending_ringtone_flush'][$tpl_filename])) {
        $rebuilt_count = rebuildDevicesForTemplate($tpl_filename, $tftp_dir, $template_dir, $saved_global_admin_pass, false);
        unset($_SESSION['pending_ringtone_flush'][$tpl_filename]);
        $status = "Saved Template: {$tpl_filename}. Rebuilt configurations and removed temporary flush directives from {$rebuilt_count} device(s).";
    } else {
        $status = "Saved Template: {$tpl_filename}. Updated template configuration file in /tftpboot/templates/.";
    }

    $_POST['template_to_load'] = $tpl_filename;
}

// ============================================================================
// 10. DEVICE MANAGER ACTIONS & TEMPLATE FILE LOADERS
// ============================================================================

if (isset($_POST['load_template']) || !empty($_POST['template_to_load'])) {
    $tpl_filename = basename($_POST['template_to_load'] ?? '');
    $tpl_path = $template_dir . $tpl_filename;

    if (!empty($tpl_filename) && file_exists($tpl_path)) {
        $formData['active_tab'] = 'tab_template';
        $formData['template_name'] = str_replace(['.template.cfg', '.cfg'], '', $tpl_filename);
        
        $raw_tpl_content = file_get_contents($tpl_path);
        $generated_template_cfg = $raw_tpl_content;

        $tpl_lines = file($tpl_path, FILE_IGNORE_NEW_LINES);
        $unparsed_tpl = [];
        $is_custom_section = false;

        $highest_tpl_linekey = 0;
        $highest_tpl_memkey = 0;
        $prog_seen = [];
        foreach (array_keys($prog_key_names) as $pid) {
            $formData["progkey_{$pid}_line"] = "1";
            $formData["progkey_{$pid}_value"] = "";
            $formData["progkey_{$pid}_label"] = "";
            $formData["progkey_{$pid}_hist"] = "0";
        }
        $formData['uploaded_ringtones'] = [];

        foreach ($tpl_lines as $t_line) {
            $t_line = trim($t_line);

            if (strpos($t_line, '##### Template Custom Additions #####') !== false) {
                $is_custom_section = true;
                continue;
            }

            if ($is_custom_section) {
                if ($t_line !== '' && !epm_is_ringtone_marker_line($t_line)) {
                    if (
                        strpos($t_line, 'distinctive_ring_tones.') !== 0 && 
                        strpos($t_line, 'account.1.alert_info_') !== 0 && 
                        $t_line !== 'features.alert_info_tone = 1'
                    ) {
                        $unparsed_tpl[] = $t_line;
                    }
                }
                continue;
            }

            if (empty($t_line)) continue;

            if (preg_match('/^#\s*Phone\s*Model\s*:\s*(.+)$/i', $t_line, $m)) { $formData['phone_model'] = trim($m[1]); continue; }
            if (preg_match('/^#\s*Expansion\s*Model\s*:\s*(.+)$/i', $t_line, $m)) { $formData['exp_model'] = trim($m[1]); continue; }
            if (preg_match('/^#\s*Expansion\s*Count\s*:\s*(.+)$/i', $t_line, $m)) { $formData['exp_count'] = trim($m[1]); continue; }

            if (strpos($t_line, '=') === false || strpos($t_line, '#') === 0) continue;
            list($k, $v) = array_map('trim', explode('=', $t_line, 2));

            if ($k === 'auto_provision.server.url' || $k === 'static.auto_provision.server.url' || $k === 'security.user_password' || strpos($k, 'account.1.sip_server') === 0) {
                continue;
            }

            $is_parsed_tpl = false;

            if (preg_match('/^account\.1\.(sip_server_port|sip_server\.1\.port|port)$/i', $k)) { $formData['sip_port'] = $v; $is_parsed_tpl = true; }
            if (preg_match('/^account\.1\.sip_listen_port$/i', $k)) { $formData['sip_listen_port'] = $v; $is_parsed_tpl = true; }
            if (preg_match('/^voice_mail\.number\.1$/i', $k)) { $formData['voicemail_number'] = $v; $is_parsed_tpl = true; }
            if (preg_match('/^features\.(voice_mail|missed_call|forward_call|text_message)_popup\.enable$/i', $k, $pm_popup)) {
                $formData['popup_' . strtolower($pm_popup[1])] = ($v === '0') ? '0' : '1';
                $is_parsed_tpl = true;
            }
            if (preg_match('/^account\.1\.ringtone\.ring_type$/i', $k)) { $formData['account_ringtone'] = $v; $is_parsed_tpl = true; }
            if (preg_match('/^account\.1\.alert_info_url_enable$/i', $k)) { $is_parsed_tpl = true; }
            if (preg_match('/^distinctive_ring_tones\.alert_info\./i', $k)) { $is_parsed_tpl = true; }
            if (preg_match('/^features\.alert_info_tone$/i', $k)) { $is_parsed_tpl = true; }
            if (preg_match('/^account\.1\.alert_info_(text|ringer|tone)(\.\d+)?$/i', $k)) { $is_parsed_tpl = true; }

            if (preg_match('/^ringtone\.url(\.\d+)?$/i', $k)) { 
                $r_name = basename($v);
                if (!in_array($r_name, $formData['uploaded_ringtones'])) {
                    $formData['uploaded_ringtones'][] = $r_name;
                }
                $is_parsed_tpl = true; 
            }
            if (preg_match('/^phone_setting\.lcd_logo\.mode$/i', $k)) { $is_parsed_tpl = true; }
            // lcd_logo.url (.dob logos) and phone_setting.backgrounds (color wallpapers).
            // phone_setting.background_image is the legacy (invalid) key written by EPM <= 1.1.5;
            // still read so older saved templates load, then re-save with the correct keys.
            if (preg_match('/^(lcd_logo\.url|phone_setting\.background_image|phone_setting\.backgrounds)$/i', $k)) {
                if (empty($v)) {
                    $formData['logo_file'] = '';
                } elseif (preg_match('/^Default:/i', $v) || $v === 'Config:default') {
                    $formData['logo_file'] = 'system';
                } else {
                    $formData['logo_file'] = basename(preg_replace('/^Config:/i', '', $v));
                }
                $is_parsed_tpl = true;
            }

            if (preg_match('/^linekey\.(\d+)\.(value|label|type|pickup_value|line)$/i', $k, $m)) {
                $f_name = (strtolower($m[2]) === 'pickup_value') ? 'pickup' : strtolower($m[2]);
                $formData["linekey_{$m[1]}_{$f_name}"] = $v;
                if ((int)$m[1] > $highest_tpl_linekey) $highest_tpl_linekey = (int)$m[1];
                $is_parsed_tpl = true;
            }

            if (preg_match('/^memorykey\.(\d+)\.(value|label|type|pickup_value|line)$/i', $k, $m)) {
                $f_name = (strtolower($m[2]) === 'pickup_value') ? 'pickup' : strtolower($m[2]);
                $formData["memkey_{$m[1]}_{$f_name}"] = $v;
                if ((int)$m[1] > $highest_tpl_memkey) $highest_tpl_memkey = (int)$m[1];
                $is_parsed_tpl = true;
            }

            // expansion_module.<module>.key.<key>.* -> flat memory-key index (built-in keys first, then module 1, 2, ...)
            if (preg_match('/^expansion_module\.(\d+)\.key\.(\d+)\.(value|label|type|pickup_value|line)$/i', $k, $m)) {
                $tpl_base_mem = (int)($yealink_model_keys[$formData['phone_model']]['memkeys'] ?? 0);
                $tpl_exp_size = (int)($expansion_key_sizes[$formData['exp_model']] ?? 0);
                if ($tpl_exp_size <= 0) { $tpl_exp_size = 40; }
                $flat = $tpl_base_mem + (((int)$m[1] - 1) * $tpl_exp_size) + (int)$m[2];
                $f_name = (strtolower($m[3]) === 'pickup_value') ? 'pickup' : strtolower($m[3]);
                if (in_array($f_name, ['value', 'pickup', 'type', 'label', 'line'], true)) {
                    $formData["memkey_{$flat}_{$f_name}"] = $v;
                }
                if ($flat > $highest_tpl_memkey) $highest_tpl_memkey = $flat;
                $is_parsed_tpl = true;
            }

            if (preg_match('/^expansion_module\.backgrounds$/i', $k)) {
                $formData['exp_wallpaper_file'] = basename(preg_replace('/^Config:/i', '', $v));
                $is_parsed_tpl = true;
            }
            if (preg_match('/^wallpaper_upload\.url$/i', $k)) { $is_parsed_tpl = true; }

            if (preg_match('/^programablekey\.(\d+)\.(type|line|value|label|history_type)$/i', $k, $m) && isset($prog_key_names[(int)$m[1]])) {
                $pk_id = (int)$m[1];
                $pk_field = strtolower($m[2]);
                if ($pk_field === 'history_type') { $pk_field = 'hist'; }
                // A prior save may have written %NULL% to clear a field that the
                // key's type no longer uses (see epm_prog_key_null_fields()) -
                // read it back as the field's normal "unset" value rather than
                // the literal string, so it doesn't resurface if the admin picks
                // a type that uses this field again.
                if (strcasecmp($v, '%NULL%') === 0) {
                    $v = ($pk_field === 'line') ? '1' : (($pk_field === 'hist') ? '0' : '');
                }
                $formData["progkey_{$pk_id}_{$pk_field}"] = $v;
                if ($pk_field === 'type') { $prog_seen[$pk_id] = true; }
                $is_parsed_tpl = true;
            }

            if (!$is_parsed_tpl) {
                $unparsed_tpl[] = "{$k} = {$v}";
            }
        }

        if (!empty($formData['uploaded_ringtones'])) {
            $existing_ringtone_files = array_map('basename', glob($ringtone_dir . "*.*") ?: []);
            $filtered_ringtones = [];

            foreach ($formData['uploaded_ringtones'] as $r_check) {
                if (in_array($r_check, $existing_ringtone_files)) {
                    $filtered_ringtones[] = $r_check;
                }
            }

            sort($filtered_ringtones, SORT_STRING | SORT_FLAG_CASE);
            $formData['uploaded_ringtones'] = array_values(array_unique($filtered_ringtones));
        }

        if ($highest_tpl_linekey > 0) $max_linekeys = $formData['linekey_count'] = $highest_tpl_linekey;
        // Slots = built-in keys for this model + every expansion module selected, or the highest key the file uses.
        $tpl_want_mem = max(
            $highest_tpl_memkey,
            (int)($yealink_model_keys[$formData['phone_model']]['memkeys'] ?? 0)
                + ((int)($expansion_key_sizes[$formData['exp_model']] ?? 0) * (int)$formData['exp_count'])
        );
        if ($tpl_want_mem > 0) $max_memkeys = $formData['memkey_count'] = $tpl_want_mem;

        // Keys the file does not mention fall back to this model's factory function.
        foreach (array_keys($prog_key_names) as $pid) {
            if (empty($prog_seen[$pid])) {
                $formData["progkey_{$pid}_type"] = (string)epm_prog_key_default($formData['phone_model'], $pid, $prog_meta);
            }
            // Snapshot what's actually in effect now (just loaded from this template
            // file) as the "previous" state for the next save - see
            // epm_prog_key_null_fields().
            $formData["progkey_{$pid}_prevtype"]  = $formData["progkey_{$pid}_type"];
            $formData["progkey_{$pid}_prevline"]  = $formData["progkey_{$pid}_line"] ?? '1';
            $formData["progkey_{$pid}_prevvalue"] = $formData["progkey_{$pid}_value"] ?? '';
            $formData["progkey_{$pid}_prevlabel"] = $formData["progkey_{$pid}_label"] ?? '';
            $formData["progkey_{$pid}_prevhist"]  = $formData["progkey_{$pid}_hist"] ?? '0';
        }

        $formData['custom_inputs'] = implode("\n", $unparsed_tpl);
        $formData['server_ip'] = $saved_global_server_ip;
        $formData['admin_password'] = $saved_global_admin_pass;

        if (empty($status)) {
            $status = "Successfully Loaded Template: " . htmlspecialchars($tpl_filename);
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['device_action']) && !isset($_POST['save_template'])) {
    $action = $_POST['device_action'];
    $selected_macs = $_POST['selected_phones'] ?? [];
    $assigned_tpls = $_POST['phone_template'] ?? [];
    $assigned_exts = $_POST['phone_extension'] ?? [];
    $edited_macs = $_POST['edited_mac'] ?? [];
    $bulk_override_tpl = trim($_POST['bulk_selected_template'] ?? '');

    if (in_array($action, ['rebuild_selected', 'rebuild_all', 'rebuild_filtered'])) {
        $filtered_exts = array_filter($assigned_exts);
        if (count($filtered_exts) !== count(array_unique($filtered_exts))) {
            $status = "<span style='color:#dc3545;'><b>Error:</b> Duplicate extensions detected in submission! Each phone must have a unique extension assigned.</span>";
            goto skip_device_rebuild;
        }
    }

    if ($action === 'delete_selected') {
        $deleted_count = 0;
        foreach ($selected_macs as $smac) {
            $clean_mac = strtolower(trim($smac));
            $f_path = $tftp_dir . $clean_mac . ".cfg";
            if (file_exists($f_path)) {
                @unlink($f_path);
                $deleted_count++;
            }
        }
        $status = "Deleted {$deleted_count} selected configuration file(s).";
    } elseif ($action === 'rebuild_selected' || $action === 'rebuild_all' || $action === 'rebuild_filtered' || $action === 'single_rebuild') {
        $all_cfg_files = glob($tftp_dir . "*.cfg");
        $all_macs = [];
        if (is_array($all_cfg_files)) {
            foreach ($all_cfg_files as $cf) {
                $mname = strtolower(pathinfo($cf, PATHINFO_FILENAME));
                if (!isYealinkGlobalCfgBasename($mname) && strpos(strtolower($cf), 'template') === false) {
                    $all_macs[] = $mname;
                }
            }
        }

        $filter_model = ($action === 'rebuild_filtered') ? trim($_POST['global_filter_model'] ?? '') : '';
        $filter_template = ($action === 'rebuild_filtered') ? trim($_POST['global_filter_template'] ?? '') : '';

        if ($action === 'single_rebuild') {
            $target_mac = strtolower(trim($_POST['single_mac'] ?? ''));
            $targets = !empty($target_mac) ? [$target_mac] : [];
            $override_model = trim($_POST['single_model'] ?? '');
            $do_autoprovision = isset($_POST['single_provision']);
            $do_reboot = isset($_POST['single_reboot_check']);
        } elseif ($action === 'rebuild_filtered') {
            $targets = $all_macs;
            $do_autoprovision = isset($_POST['auto_provision_filtered']);
            $do_reboot = isset($_POST['reboot_filtered']);
            $override_model = '';
        } else {
            $targets = ($action === 'rebuild_selected') ? array_map('strtolower', $selected_macs) : $all_macs;
            $do_autoprovision = ($action === 'rebuild_selected') ? isset($_POST['auto_provision_selected']) : isset($_POST['auto_provision_all']);
            $do_reboot = ($action === 'rebuild_selected') ? isset($_POST['reboot_selected']) : isset($_POST['reboot_phones']);
            $override_model = '';
        }

        $notify_event = 'none';
        if ($do_reboot) {
            $notify_event = 'reboot';
        } elseif ($do_autoprovision) {
            $notify_event = 'check-sync';
        }

        $rebuilt = 0;

        foreach ($targets as $smac) {
            $clean_mac = strtolower(trim($smac));
            $f_path = $tftp_dir . $clean_mac . ".cfg";
            
            if ($action === 'rebuild_selected' && !empty($bulk_override_tpl)) {
                $new_tpl = $bulk_override_tpl;
            } else {
                $new_tpl = $assigned_tpls[$clean_mac] ?? ($assigned_tpls[strtoupper($clean_mac)] ?? '');
            }

            $new_ext = $assigned_exts[$clean_mac] ?? ($assigned_exts[strtoupper($clean_mac)] ?? '');

            if (file_exists($f_path)) {
                $file_content = file_get_contents($f_path);
                
                if (($pos = strpos($file_content, '##### INHERITED TEMPLATE SETTINGS')) !== false) {
                    $base_content = substr($file_content, 0, $pos);
                } else {
                    $base_content = $file_content;
                }
                $dev_overrides_text = epm_extract_device_overrides($file_content);
                $base_content = epm_strip_device_overrides($base_content);

                if (($pos_ring = strpos($base_content, '-------- DISTINCTIVE RINGTONE')) !== false) {
                    $base_content = substr($base_content, 0, $pos_ring);
                }

                $file_lines = explode("\n", $file_content);
                $curr_model = '';
                $curr_tpl = '';

                foreach ($file_lines as $f_line) {
                    if (preg_match('/^#\s*Phone\s*Model\s*:\s*(.+)$/i', $f_line, $m)) {
                        $found_m = trim($m[1]);
                        if (empty($curr_model) || strcasecmp($curr_model, 'Yealink') === 0) {
                            $curr_model = $found_m;
                        }
                    }
                    if (preg_match('/^#\s*Template\s*:\s*(.+)$/i', $f_line, $m)) {
                        $curr_tpl = trim($m[1]);
                    }
                }

                if ($action === 'rebuild_filtered') {
                    if (!empty($filter_model) && stripos($curr_model, $filter_model) === false) {
                        continue;
                    }
                    if (!empty($filter_template) && strcasecmp($curr_tpl, $filter_template) !== 0) {
                        continue;
                    }
                }

                $updated_lines = [];
                $has_tpl_comment = false;

                $base_lines = explode("\n", $base_content);

                $new_ext_name = $all_extensions[$new_ext]['display_name'] ?? "Extension {$new_ext}";
                $new_ext_secret = $all_extensions[$new_ext]['secret'] ?? '';
                $tpl_to_write = !empty($new_tpl) ? $new_tpl : 'none';

                foreach ($base_lines as $f_line) {
                    if (preg_match('/^#!version:/i', $f_line)) {
                        $updated_lines[] = "#!version:{$cfg_version}";
                    } elseif (preg_match('/^account\.1\./i', $f_line)) {
                        continue;
                    } elseif (preg_match('/^linekey\.1\./i', $f_line)) {
                        continue;
                    } elseif (preg_match('/^openvpn\./i', $f_line) || preg_match('/^network\.vpn_enable/i', $f_line)) {
                        continue;
                    } elseif (preg_match('/^#\s*Phone\s*Model\s*:\s*(.+)$/i', $f_line, $m)) {
                        $model_val = !empty($override_model) ? $override_model : trim($m[1]);
                        $updated_lines[] = "# Phone Model: {$model_val}";
                    } elseif (preg_match('/^#\s*Template\s*:/i', $f_line)) {
                        $updated_lines[] = "# Template: {$tpl_to_write}";
                        $has_tpl_comment = true;
                    } else {
                        $updated_lines[] = $f_line;
                    }
                }

                if (!$has_tpl_comment) {
                    array_splice($updated_lines, 2, 0, "# Template: {$tpl_to_write}");
                }

                if (!empty($new_ext)) {
                    $account_block = [
                        "account.1.enable = 1",
                        "account.1.label = {$new_ext_name}",
                        "account.1.display_name = {$new_ext_name}",
                        "account.1.auth_name = {$new_ext}",
                        "account.1.user_name = {$new_ext}",
                        "account.1.password = {$new_ext_secret}",
                        "@@EPM_SIP_SERVER@@",
                        "account.1.port = {$default_sip_port}",
                        "linekey.1.type = 15",
                        "linekey.1.line = 1",
                        "linekey.1.value = {$new_ext}",
                        "linekey.1.label = {$new_ext_name}"
                    ];

                    $tar_file = "/var/www/html/PhoneSettings/vpnkeys/{$clean_mac}_{$new_ext}_ovpn.tar";
                    if (file_exists($tar_file)) {
                        $vpn_url = "http://" . yealink_epm_apply_redirect_port($saved_global_server_ip, $sysadmin_redirect) . "/PhoneSettings/vpnkeys/{$clean_mac}_{$new_ext}_ovpn.tar";
                        $account_block[] = "openvpn.url = {$vpn_url}";
                        $account_block[] = "network.vpn_enable = 1";
                    } elseif (strpos($base_content, '/PhoneSettings/fakekeys/null.tar') !== false) {
                        // VPN was turned off earlier: keep pointing the phone at the empty tar.
                        $account_block[] = "openvpn.url = " . yealink_epm_fake_vpn_url($saved_global_server_ip, $sysadmin_redirect);
                        $account_block[] = "network.vpn_enable = 1";
                    }

                    $blk_model = !empty($new_tpl) ? epm_template_phone_model($template_dir . $new_tpl) : '';
                    if ($blk_model === '' && !empty($override_model)) { $blk_model = $override_model; }
                    $sip_idx = array_search("@@EPM_SIP_SERVER@@", $account_block, true);
                    array_splice($account_block, $sip_idx, 1, epm_sip_server_lines($blk_model, $saved_global_server_ip, $default_sip_port));
                    array_splice($updated_lines, 3, 0, $account_block);
                }

                $final_cfg = implode("\n", $updated_lines);

                if (!empty($new_tpl) && file_exists($template_dir . $new_tpl)) {
                    $tpl_content = file_get_contents($template_dir . $new_tpl);
                    $tpl_content = preg_replace('/^account\.1\.sip_server.*$/m', '', $tpl_content);
                    $tpl_content = preg_replace('/^#!version:.*$/m', '', $tpl_content);
                    $final_cfg = rtrim($final_cfg) . "\n\n##### INHERITED TEMPLATE SETTINGS ({$new_tpl}) #####\n" . $tpl_content;
                }

                $final_cfg = rtrim($final_cfg) . "\n" . epm_wrap_device_overrides($dev_overrides_text);
                @file_put_contents($f_path, $final_cfg);
                @chown($f_path, 'asterisk');

                if ($notify_event !== 'none' && !empty($new_ext)) {
                    $target_ip = $arp_table[$clean_mac] ?? '';
                    sendSipNotify($new_ext, $notify_event, $target_ip, $saved_global_admin_pass);
                }
                $rebuilt++;
            }
        }
        $status = "Rebuilt and updated configurations for {$rebuilt} device(s).";
    } elseif ($action === 'single_reboot') {
        $single_ext = $_POST['single_ext'] ?? '';
        if (!empty($single_ext)) {
            sendSipNotify($single_ext, 'reboot');
            $status = "Sent reboot NOTIFY to Extension {$single_ext}.";
        }
    }

    skip_device_rebuild:;
}

// ============================================================================
// RE-EVALUATE RINGTONES & FILE SIZES BEFORE RENDERING VIEW
// ============================================================================
$existing_ringtones_init = glob($ringtone_dir . "*.*");
$ringtone_filenames = array_map('basename', is_array($existing_ringtones_init) ? $existing_ringtones_init : []);
sort($ringtone_filenames, SORT_STRING | SORT_FLAG_CASE);

$ringtone_file_sizes = [];
foreach ($ringtone_filenames as $rf) {
    $r_path = $ringtone_dir . $rf;
    if (file_exists($r_path)) {
        clearstatcache(true, $r_path);
        $ringtone_file_sizes[$rf] = filesize($r_path);
    } else {
        $ringtone_file_sizes[$rf] = 0;
    }
}

if (!isset($_POST['uploaded_ringtones']) && $_SERVER["REQUEST_METHOD"] !== "POST") {
    $formData['uploaded_ringtones'] = $ringtone_filenames;
}

$mac_files = glob($tftp_dir . "*.cfg");
$assigned_ringtone_references = [];
$missing_referenced_ringtones = [];

if (is_array($mac_files)) {
    foreach ($mac_files as $mf) {
        $m_base = strtolower(pathinfo($mf, PATHINFO_FILENAME));
        if (isYealinkGlobalCfgBasename($m_base) || strpos(strtolower($mf), 'template') !== false) {
            continue;
        }

        $m_content = file_get_contents($mf);
        if (preg_match_all('/ringtone\.url\s*=\s*http:\/\/[^\/]+\/PhoneSettings\/ringtones\/([^\s]+)/i', $m_content, $rmatches)) {
            foreach ($rmatches[1] as $referenced_ring) {
                $assigned_ringtone_references[$referenced_ring] = true;
                if (!in_array($referenced_ring, $ringtone_filenames)) {
                    $missing_referenced_ringtones[$referenced_ring] = true;
                }
            }
        }
    }
}

$show_flush_ringtone_btn = (!empty($missing_referenced_ringtones) || $ringtone_was_deleted) && !$just_flushed;

if ($just_flushed) {
    $show_flush_ringtone_btn = false;
}

$existing_files = glob($tftp_dir . "*.cfg");
$existing_templates = glob($template_dir . "*.cfg");
$available_templates = [];
$managed_devices = [];
$assigned_extensions_map = [];
$arp_table = getArpTableMap();

if (is_array($existing_templates)) {
    foreach ($existing_templates as $tpl_path) {
        $tb_name = basename($tpl_path);
        $available_templates[$tb_name] = $tb_name;
    }
}

// Same source and matching rules as the device list's individual VPN
// dots below (epmGetOpenVpnClientMap -> epmDeriveOvpnLookups): ovpnctl
// status as root, falling back to a direct file read or the management
// port if that's ever unavailable.
[$ovpn_connected_exts, $ovpn_connected_macs, $ovpn_connected_ips] = epmDeriveOvpnLookups(epmGetOpenVpnClientMap());

// Load administrator-registered MACs. These are allowed regardless of OUI.
$registered_manual_macs = [];
if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $manual_stmt = $pdo->query("SELECT mac FROM yealink_epm_devices");
        if ($manual_stmt) {
            while ($manual_row = $manual_stmt->fetch(PDO::FETCH_ASSOC)) {
                if (!empty($manual_row['mac'])) {
                    $registered_manual_macs[strtolower(trim($manual_row['mac']))] = true;
                }
            }
        }
    } catch (Exception $e) {
        error_log('Yealink EPM: unable to load registered devices: ' . $e->getMessage());
    }
}

if (is_array($existing_files)) {
    foreach ($existing_files as $file_path) {
        $b_name = basename($file_path);
        $file_name_no_ext = strtolower(pathinfo($b_name, PATHINFO_FILENAME));
		
        // Show known Yealink OUIs automatically, plus admin-registered MACs.
        $is_known_yealink_oui = isYealinkMac($file_name_no_ext) || preg_match('/^(0015|805e)[a-f0-9]{8}$/i', $file_name_no_ext) === 1;
        $is_registered_manual = isset($registered_manual_macs[$file_name_no_ext]);
        if (!$is_known_yealink_oui && !$is_registered_manual) {
            continue;
        }
        
        if (isYealinkGlobalCfgBasename($file_name_no_ext) || strpos(strtolower($b_name), 'template') !== false) continue;

        $ext_num = "";
        $ext_label = "";
        $phone_model_read = "Yealink";
        $dev_has_overrides = false;
        $template_used = "";

        $lines = @file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines) {
            foreach ($lines as $l) {
                $l = trim($l);
                if ($l === '##### DEVICE OVERRIDES #####') { $dev_has_overrides = true; }
                if (preg_match('/^#\s*Phone\s*Model\s*:\s*(.+)$/i', $l, $m)) {
                    $found_m = trim($m[1]);
                    if ($phone_model_read === 'Yealink' || empty($phone_model_read)) {
                        $phone_model_read = $found_m;
                    }
                }
                if (preg_match('/^#\s*Template\s*:\s*(.+)$/i', $l, $m)) {
                    $tpl = trim($m[1]);
                    $template_used = ($tpl === 'none' || $tpl === 'default') ? '' : $tpl;
                }
                if (preg_match('/^account\.1\.(auth_name|user_name)\s*=\s*(.+)$/i', $l, $m)) {
                    $ext_num = trim($m[2]);
                }
                if (preg_match('/^account\.1\.(display_name|label)\s*=\s*(.+)$/i', $l, $m)) {
                    if (empty($ext_label)) $ext_label = trim($m[2]);
                }
            }
        }

        if (!empty($ext_num)) {
            $assigned_extensions_map[(string)$ext_num] = true;
        }

        $ip_addr = $arp_table[$file_name_no_ext] ?? 'Unknown / Offline';

        $vpn_tar_file = "/var/www/html/PhoneSettings/vpnkeys/{$file_name_no_ext}_{$ext_num}_ovpn.tar";
        $has_vpn = file_exists($vpn_tar_file);

        $is_vpn_connected = false;
        if ($has_vpn) {
            $clean_mac = strtolower($file_name_no_ext);
            $clean_ext_cn = strtolower("client-{$ext_num}");
            $pjsip_online = isset($online_exts[$ext_num]);

            if (
                isset($ovpn_connected_macs[$clean_mac]) || 
                isset($ovpn_connected_exts[$clean_ext_cn]) || 
                (!empty($ext_num) && isset($ovpn_connected_exts[$ext_num])) ||
                (!empty($ext_num) && $pjsip_online && in_array($online_exts[$ext_num]['via'] ?? '', $ovpn_connected_ips))
            ) {
                $is_vpn_connected = true;
            }
        }

        $managed_devices[] = [
            'mac'               => $file_name_no_ext,
            'ip'                => $ip_addr,
            'file'              => $b_name,
            'model'             => $phone_model_read,
            'has_overrides'     => $dev_has_overrides,
            'template'          => $template_used,
            'ext'               => $ext_num,
            'label'             => $ext_label,
            'openvpn_enabled'   => $has_vpn,
            'openvpn_connected' => $is_vpn_connected
        ];
    }
}

$available_extensions = [];
foreach ($all_extensions as $e_id => $e_data) {
    if (!isset($assigned_extensions_map[(string)$e_id])) {
        $available_extensions[$e_id] = $e_data;
    }
}

$active_dialnow_items = array_values(array_filter($outbound_patterns));

$timezones = [
    "-10" => "United States - Hawaii (UTC-10)",
    "-9"  => "United States - Alaska (UTC-9)",
    "-8"  => "United States - Pacific Time (UTC-8)",
    "-7"  => "United States - Mountain Time (UTC-7)",
    "-6"  => "United States - Central Time (UTC-6)",
    "-5"  => "United States - Eastern Time (UTC-5)",
    "-4"  => "United States - Atlantic Time (UTC-4)",
    "-11" => "Samoa / Midway (UTC-11)",
    "-3"  => "Brazil - Brasilia / Argentina (UTC-3)",
    "-2"  => "Mid-Atlantic (UTC-2)",
    "-1"  => "Azores / Cape Verde (UTC-1)",
    "0"   => "United Kingdom - London / Dublin (UTC+0)",
    "+1"  => "Europe - Paris / Berlin / Rome (UTC+1)",
    "+2"  => "Europe - Athens / Cairo / Jerusalem (UTC+2)",
    "+3"  => "Russia - Moscow / Saudi Arabia (UTC+3)",
    "+3.5"=> "Iran - Tehran (UTC+3:30)",
    "+4"  => "UAE - Dubai (UTC+4)",
    "+4.5"=> "Afghanistan - Kabul (UTC+4:30)",
    "+5"  => "Pakistan - Islamabad (UTC+5)",
    "+5.5"=> "India - New Delhi (UTC+5:30)",
    "+6"  => "Bangladesh - Dhaka (UTC+6)",
    "+7"  => "Thailand - Bangkok / Vietnam (UTC+7)",
    "+8"  => "China - Beijing / Singapore / Perth (UTC+8)",
    "+9"  => "Japan - Tokyo / Korea - Seoul (UTC+9)",
    "+9.5"=> "Australia - Adelaide / Darwin (UTC+9:30)",
    "+10" => "Australia - Sydney / Guam (UTC+10)",
    "+11" => "Solomon Islands (UTC+11)",
    "+12" => "New Zealand - Auckland (UTC+12)"
];

for ($d = 1; $d <= 50; $d++) {
    $formData["dialnow_{$d}"] = $active_dialnow_items[$d - 1] ?? '';
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['save_global'])) {
    $formData['active_tab'] = 'tab_global';
    
    for ($d = 1; $d <= 50; $d++) {
        if (isset($_POST["dialnow_{$d}"])) $formData["dialnow_{$d}"] = trim($_POST["dialnow_{$d}"]);
    }
    
    foreach ($formData as $k => $v) {
        if (isset($_POST[$k])) $formData[$k] = trim($_POST[$k]);
    }

    $formData['prov_http_port'] = yealink_epm_prov_port();   // the validated/effective port, not raw POST text
    $generated_common_cfg = generateAndSaveGlobalConfig($formData, $cfg_version, $default_server_target, $tftp_dir, $sysadmin_redirect);
    $status = "Saved Global Settings to " . count(yealinkGlobalCfgMap()) . " y-config file(s) in {$tftp_dir}"
        . ($epm_prov_port_notice !== '' ? ' ' . $epm_prov_port_notice : '');
}

$max_dialnow_slots = (int)($formData['dialnow_count'] ?? 1);
$existing_logos = glob($logo_dir . "*.*");
$logo_filenames = array_map('basename', is_array($existing_logos) ? $existing_logos : []);
?>