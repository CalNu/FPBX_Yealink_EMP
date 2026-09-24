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
$sysadmin_redirect = false;
$http_prov_probe = @fsockopen('127.0.0.1', 83, $errno, $errstr, 0.5);
if ($http_prov_probe) {
    @fclose($http_prov_probe);
    $sysadmin_redirect = true;
}

// Re-derives host:port for the provisioning/asset target from the CURRENT
// sysadmin_redirect state, rather than trusting a port baked into a
// previously-saved value. Only the hostname is preserved from $host_string;
// the port is always recomputed so toggling the global HTTPS redirect
// setting takes effect immediately, without stale :83 (or missing :83)
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
    return $sysadmin_redirect ? "{$bare}:83" : $bare;
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

if ($sysadmin_redirect) {
    $default_provision_url = "http://{$detected_host}:83/PhoneSettings/";
    $default_server_target = "{$detected_host}:83";
    $ringtone_http_base = "http://{$detected_host}:83/PhoneSettings/ringtones/";
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

// Returns [virtual_ip => common_name] for every client currently connected to the
// built-in OpenVPN server (management port first, status file as fallback).
if (!function_exists('epmGetOpenVpnClientMap')) {
    function epmGetOpenVpnClientMap() {
        $clients = [];
        $status_output = '';

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
                $content = epmReadProtectedFile($f);                     // sudo -n cat fallback
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

function buildDistinctiveRingtoneConfigBlock($active_ringtones = []) {
    if (empty($active_ringtones) || !is_array($active_ringtones)) {
        return "";
    }

    $ring_files = array_values(array_unique($active_ringtones));
    sort($ring_files, SORT_STRING);

    $cfg = "######## DISTINCTIVE RINGTONE & ALERT INFO SETUP ########\n";
    $cfg .= "features.alert_info_tone = 1\n";
    $cfg .= "account.1.alert_info_tone = 1\n";
    $cfg .= "account.1.alert_info_url_enable = 1\n";
    $cfg .= "distinctive_ring_tones.alert_info.enable = 1\n\n";

    $ringer_index = 8;
    $max_slots = min(count($ring_files), 10);

    for ($r_idx = 1; $r_idx <= $max_slots; $r_idx++) {
        $r_file = $ring_files[$r_idx - 1];
        $text_name = pathinfo($r_file, PATHINFO_FILENAME);

        $cfg .= "distinctive_ring_tones.alert_info.{$r_idx}.text = {$text_name}\n";
        $cfg .= "distinctive_ring_tones.alert_info.{$r_idx}.ringer = {$ringer_index}\n";

        $cfg .= "account.1.alert_info_text.{$r_idx} = {$text_name}\n";
        $cfg .= "account.1.alert_info_ringer.{$r_idx} = {$ringer_index}\n";

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

                if (($pos_flush = strpos($base_content, '######## ONE-TIME RINGTONE FLASH CLEAR ########')) !== false) {
                    $base_content = substr($base_content, 0, $pos_flush);
                }

                $tpl_content = file_get_contents($tpl_path);
                $tpl_content = preg_replace('/^account\.1\.sip_server.*$/m', '', $tpl_content);
                $tpl_content = preg_replace('/^#!version:.*$/m', '', $tpl_content);

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
    $cfg .= "auto_provision.mode = {$auto_prov_mode}\n";
    $cfg .= "auto_provision.reboot_force.enable = 0\n";
    $cfg .= "auto_provision.weekly.enable = {$auto_prov_weekly}\n";
    $cfg .= "auto_provision.weekly.begin_time = {$auto_prov_begin}\n";
    $cfg .= "auto_provision.weekly.end_time = {$auto_prov_end}\n";
    $cfg .= "auto_provision.weekly.dayofweek = {$auto_prov_dow}\n";
    $cfg .= "auto_provision.server.url = http://{$server_ip_target}\n";
    $cfg .= "auto_provision.server.username = {$auto_prov_user}\n";
    $cfg .= "auto_provision.server.password = {$auto_prov_pass}\n";
    $cfg .= "auto_provision.dhcp_option.enable = {$auto_prov_dhcp}\n\n";
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
    $cfg .= "phone_setting.inter_digit_time = {$dial_timeout}\n\n";

    $cfg .= "######## My DIALPLAN ########\n\n";
    $item_idx = 1;
    for ($d = 1; $d <= 50; $d++) {
        if (!empty($formData["dialnow_{$d}"])) {
            $cfg .= "dialnow.item.{$item_idx} = {$formData["dialnow_{$d}"]}\n";
            $item_idx++;
        }
    }
    $cfg .= "######## End My DIALPLAN ########\n\n";

    if (!empty($formData['custom_inputs_global'])) {
        $cfg .= "##### Global Custom Key-Value Additions #####\n";
        $cfg .= trim($formData['custom_inputs_global']) . "\n\n";
    }

    foreach (array_keys(yealinkGlobalCfgMap()) as $global_basename) {
        @file_put_contents($tftp_dir . $global_basename . ".cfg", $cfg);
        @chown($tftp_dir . $global_basename . ".cfg", 'asterisk');
    }
    return $cfg;
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
            if (preg_match('/^dialnow\.item\.(\d+)\s*=\s*(.+)$/i', $g_line, $gm)) {
                $idx = (int)$gm[1];
                $val = trim($gm[2]);
                if ($val !== '') {
                    $temp_dialnow_file[$idx] = $val;
                }
            }
            if (preg_match('/^auto_provision\.server\.url\s*=\s*http:\/\/(.+)$/i', $g_line, $gm)) {
                // account.1.sip_server / sip_server_host are also derived from
                // this value elsewhere, and SIP registration already has its
                // own port field (account.1.sip_server_port) — it must never
                // carry the HTTP provisioning port. Keep this bare (host
                // only); the :83 shift is applied separately, only where an
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
            if (preg_match('/^phone_setting\.inter_digit_time\s*=\s*(\d+)$/i', $g_line, $gm)) {
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
    "EXP50" => "EXP50 (60 Keys per Module)"
];

// Keys per expansion module (matches the labels above).
$expansion_key_sizes = ['none' => 0, 'EXP20' => 20, 'EXP40' => 40, 'EXP50' => 60];

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
    2  => ['line', 'value'],
    9  => ['line', 'value'],
    13 => ['line', 'value'],
    14 => ['line', 'value', 'ext'],
    23 => ['line', 'value'],
    24 => ['value', 'ext'],
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
        if ($val === '') { continue; }
        if (!$has) {
            $out .= "################################################\n";
            $out .= "##         Memory / Expansion Keys              ##\n";
            $out .= "################################################\n\n";
            $has = true;
        }
        $pickup = isset($formData["memkey_{$i}_pickup"]) ? $formData["memkey_{$i}_pickup"] : '**';
        if ($pickup === '') { $pickup = '**'; }

        list($prefix, $is_exp) = epm_memkey_prefix($i, $base_mem, $exp_size);
        $out .= "{$prefix}.value = {$val}\n";
        if ($pickup !== 'none') {
            $out .= "{$prefix}.pickup_value = {$pickup}\n";
        }
        if ($is_exp) {
            $out .= "{$prefix}.line = 1\n";
        }
        $out .= "{$prefix}.type = 16\n\n";
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
        $ext   = in_array('ext', $fields, true)   ? epm_prog_clean($formData["progkey_{$id}_ext"] ?? '')   : '';
        $hist  = in_array('hist', $fields, true)  ? epm_prog_clean($formData["progkey_{$id}_hist"] ?? '0') : '';
        $label = ($id <= 4 && $type !== 0) ? epm_prog_clean($formData["progkey_{$id}_label"] ?? '') : '';

        $has_extra = ($value !== '' || $ext !== '' || $label !== '' || ($hist !== '' && $hist !== '0') || ($line !== '' && $line !== '1'));
        if ($type === $default && !$has_extra) { continue; }

        if ($out === '') {
            $out .= "################################################\n";
            $out .= "##" . str_pad("         Programmable Keys", 44) . "##\n";
            $out .= "################################################\n\n";
        }
        $out .= "programablekey.{$id}.type = {$type}\n";
        if ($line !== '')  { $out .= "programablekey.{$id}.line = {$line}\n"; }
        if ($value !== '') { $out .= "programablekey.{$id}.value = {$value}\n"; }
        if ($ext !== '')   { $out .= "programablekey.{$id}.extension = {$ext}\n"; }
        if ($hist !== '' && $hist !== '0') { $out .= "programablekey.{$id}.history_type = {$hist}\n"; }
        if ($label !== '') { $out .= "programablekey.{$id}.label = {$label}\n"; }
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
        $stmt = $pdo->query("SELECT u.extension AS id, u.name AS display_name, s.data AS secret 
                            FROM users u 
                            LEFT JOIN sip s ON u.extension = s.id AND s.keyword = 'secret' 
                            ORDER BY CAST(u.extension AS UNSIGNED) ASC");
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($results)) {
            $stmt = $pdo->query("SELECT d.id, d.description AS display_name, s.data AS secret 
                                FROM devices d 
                                LEFT JOIN sip s ON d.id = s.id AND s.keyword = 'secret' 
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
            $clean_lines[] = "openvpn.url = ";
            $clean_lines[] = "network.vpn_enable = 0";
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
            $clean_filename = pathinfo($orig_name, PATHINFO_FILENAME) . '.wav';
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
                     . 'MACs behind a routed tunnel are learned from the phone\'s provisioning requests, so the "asterisk" user needs read access to that log.';
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
            $cfg_body .= "account.1.sip_server = {$saved_global_server_ip}\n";
            $cfg_body .= "account.1.sip_server_host = {$saved_global_server_ip}\n";
            $cfg_body .= "account.1.sip_server_port = {$default_sip_port}\n";
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
                $updated_tpl_str = rtrim($updated_tpl_str) . "\n\n" . buildDistinctiveRingtoneConfigBlock($posted_ringtones);
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
    'dialnow_timeout' => $saved_global_dialnow_timeout,
    'dialnow_count' => count($outbound_patterns) ?: 1,
    'linekey_count' => $max_linekeys,
    'memkey_count' => $max_memkeys,
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
    'active_tab' => $_POST['active_tab'] ?? 'tab_global'
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
    $formData["memkey_{$i}_value"] = "";
    $formData["memkey_{$i}_pickup"] = "";
}

foreach (array_keys($prog_key_names) as $pid) {
    $formData["progkey_{$pid}_type"] = (string)$prog_key_defaults[$pid];
    $formData["progkey_{$pid}_line"] = "1";
    $formData["progkey_{$pid}_value"] = "";
    $formData["progkey_{$pid}_label"] = "";
    $formData["progkey_{$pid}_ext"] = "";
    $formData["progkey_{$pid}_hist"] = "0";
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
        sort($formData['uploaded_ringtones'], SORT_STRING);
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
        if (isset($_POST["memkey_{$i}_value"])) $formData["memkey_{$i}_value"] = trim($_POST["memkey_{$i}_value"]);
        if (isset($_POST["memkey_{$i}_pickup"])) $formData["memkey_{$i}_pickup"] = trim($_POST["memkey_{$i}_pickup"]);
    }

    foreach (array_keys($prog_key_names) as $pid) {
        foreach (['type', 'line', 'value', 'label', 'ext', 'hist'] as $pf) {
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
    // port — only HTTP asset URLs (logo/ringtone) shift to :83.
    $host_only = $saved_global_server_ip;
    $asset_host = $sysadmin_redirect
        ? "http://{$host_only}:83/PhoneSettings"
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

    $generated_template_cfg .= "account.1.sip_server = {$saved_global_server_ip}\n";
    $generated_template_cfg .= "account.1.sip_server_host = {$saved_global_server_ip}\n";
    $generated_template_cfg .= "account.1.sip_server_port = {$formData['sip_port']}\n";
    $generated_template_cfg .= "account.1.port = {$formData['sip_port']}\n";
    $generated_template_cfg .= "account.1.sip_listen_port = {$formData['sip_listen_port']}\n";
    $generated_template_cfg .= "voice_mail.number.1 = {$formData['voicemail_number']}\n\n";

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
            sort($formData['uploaded_ringtones'], SORT_STRING);
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

    $generated_template_cfg .= buildDistinctiveRingtoneConfigBlock($formData['uploaded_ringtones']);

    $gen_base_mem = (int)($yealink_model_keys[$formData['phone_model']]['memkeys'] ?? 0);
    $gen_exp_size = (int)($expansion_key_sizes[$formData['exp_model']] ?? 0);
    $generated_template_cfg .= epm_build_memory_keys_block($formData, $max_memkeys, $gen_base_mem, $gen_exp_size);

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
        $generated_template_cfg .= "lcd_logo.url = \n";
        $generated_template_cfg .= "phone_setting.background_image = \n\n";
    } elseif ($use_lcd_logo_url) {
        $generated_template_cfg .= "lcd_logo.url = {$logo_url}\n\n";
    } else {
        $generated_template_cfg .= "phone_setting.background_image = {$logo_url}\n\n";
    }

    if (!empty($formData['custom_inputs'])) {
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
            $formData["progkey_{$pid}_ext"] = "";
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
                if ($t_line !== '') {
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

            if ($k === 'auto_provision.server.url' || $k === 'security.user_password' || strpos($k, 'account.1.sip_server') === 0) {
                continue;
            }

            $is_parsed_tpl = false;

            if (preg_match('/^account\.1\.(sip_server_port|port)$/i', $k)) { $formData['sip_port'] = $v; $is_parsed_tpl = true; }
            if (preg_match('/^account\.1\.sip_listen_port$/i', $k)) { $formData['sip_listen_port'] = $v; $is_parsed_tpl = true; }
            if (preg_match('/^voice_mail\.number\.1$/i', $k)) { $formData['voicemail_number'] = $v; $is_parsed_tpl = true; }
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
            if (preg_match('/^(lcd_logo\.url|phone_setting\.background_image)$/i', $k)) { 
                if (empty($v) || $v === 'Config:default') {
                    $formData['logo_file'] = '';
                } else {
                    $formData['logo_file'] = basename($v);
                }
                $is_parsed_tpl = true; 
            }

            if (preg_match('/^linekey\.(\d+)\.(value|label|type|pickup_value|line)$/i', $k, $m)) {
                $f_name = (strtolower($m[2]) === 'pickup_value') ? 'pickup' : strtolower($m[2]);
                $formData["linekey_{$m[1]}_{$f_name}"] = $v;
                if ((int)$m[1] > $highest_tpl_linekey) $highest_tpl_linekey = (int)$m[1];
                $is_parsed_tpl = true;
            }

            if (preg_match('/^memorykey\.(\d+)\.(value|label|type|pickup_value)$/i', $k, $m)) {
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
                if ($f_name === 'value' || $f_name === 'pickup') {
                    $formData["memkey_{$flat}_{$f_name}"] = $v;
                }
                if ($flat > $highest_tpl_memkey) $highest_tpl_memkey = $flat;
                $is_parsed_tpl = true;
            }

            if (preg_match('/^programablekey\.(\d+)\.(type|line|value|label|extension|history_type)$/i', $k, $m) && isset($prog_key_names[(int)$m[1]])) {
                $pk_id = (int)$m[1];
                $pk_field = strtolower($m[2]);
                if ($pk_field === 'extension') { $pk_field = 'ext'; }
                elseif ($pk_field === 'history_type') { $pk_field = 'hist'; }
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

            sort($filtered_ringtones, SORT_STRING);
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

    foreach ($edited_macs as $orig_mac => $new_mac) {
        $clean_orig = strtolower(trim($orig_mac));
        $clean_new = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $new_mac));
        if (!empty($clean_new) && strlen($clean_new) === 12 && $clean_orig !== $clean_new) {
            $orig_path = $tftp_dir . $clean_orig . ".cfg";
            $new_path = $tftp_dir . $clean_new . ".cfg";
            if (file_exists($orig_path) && !file_exists($new_path)) {
                @rename($orig_path, $new_path);
                @chown($new_path, 'asterisk');
            }
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
                        "account.1.sip_server = {$saved_global_server_ip}",
                        "account.1.sip_server_host = {$saved_global_server_ip}",
                        "account.1.sip_server_port = {$default_sip_port}",
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
                    }

                    array_splice($updated_lines, 3, 0, $account_block);
                }

                $final_cfg = implode("\n", $updated_lines);

                if (!empty($new_tpl) && file_exists($template_dir . $new_tpl)) {
                    $tpl_content = file_get_contents($template_dir . $new_tpl);
                    $tpl_content = preg_replace('/^account\.1\.sip_server.*$/m', '', $tpl_content);
                    $tpl_content = preg_replace('/^#!version:.*$/m', '', $tpl_content);
                    $final_cfg = rtrim($final_cfg) . "\n\n##### INHERITED TEMPLATE SETTINGS ({$new_tpl}) #####\n" . $tpl_content;
                }

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
sort($ringtone_filenames, SORT_STRING);

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

$show_flush_ringtone_btn = !empty($missing_referenced_ringtones) && !$just_flushed;

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

$ovpn_connected_exts = [];
$ovpn_connected_macs = [];
$ovpn_connected_ips  = [];

$status_output = '';

$fp = @fsockopen('127.0.0.1', 7505, $errno, $errstr, 1);
if ($fp) {
    fputs($fp, "status\n");
    while (!feof($fp)) {
        $line = fgets($fp, 1024);
        $status_output .= $line;
        if (strpos($line, 'END') === 0) break;
    }
    fclose($fp);
}

if (empty($status_output)) {
    $status_file = file_exists('/var/log/openvpn/openvpn-status.log') 
        ? '/var/log/openvpn/openvpn-status.log' 
        : '/var/www/html/PhoneSettings/openvpn/logs/openvpn-status.log';
        
    if (file_exists($status_file)) {
        $status_output = (string)@file_get_contents($status_file);
    }
}

if (!empty($status_output)) {
    // Handles all three OpenVPN status formats:
    //   v2/v3 -> "CLIENT_LIST,<cn>,<real>,<virtual>,..." rows
    //   v1    -> "Common Name,Real Address,..." table + "ROUTING TABLE" (this is what
    //            ovpn_mgr writes: its server.conf has "status <file> 1" and no status-version)
    $mark_ovpn_client = function ($cn, $virt_ip) use (&$ovpn_connected_exts, &$ovpn_connected_macs, &$ovpn_connected_ips) {
        $cn = strtolower(trim($cn));
        if ($virt_ip !== '' && !in_array($virt_ip, $ovpn_connected_ips, true)) {
            $ovpn_connected_ips[] = $virt_ip;
        }
        if ($cn === '') {
            return;
        }
        // Exact CN, e.g. "1001" (ovpn_mgr certs) or "client-1001" (EPM-generated certs)
        $ovpn_connected_exts[$cn] = true;
        // "client-1001" / "client_1001" -> also register the bare extension "1001"
        if (preg_match('/^client[-_]?(\d+)$/', $cn, $mExt)) {
            $ovpn_connected_exts[$mExt[1]] = true;
        }
        // MAC-style CNs (legacy behaviour): keep only hex characters
        $clean_cn = preg_replace('/[^a-f0-9]/', '', $cn);
        if ($clean_cn !== '') {
            $ovpn_connected_exts[$clean_cn] = true;
            $ovpn_connected_macs[$clean_cn] = true;
        }
    };

    $section = '';
    foreach (explode("\n", $status_output) as $line) {
        $line = rtrim($line, "\r");
        if (strpos($line, 'CLIENT_LIST') === 0) {                       // v2 / v3
            $parts = explode(',', $line);
            $mark_ovpn_client($parts[1] ?? '', trim($parts[3] ?? ''));
        } elseif (strpos($line, 'OpenVPN CLIENT LIST') === 0) {         // v1 section markers
            $section = 'clients';
        } elseif (strpos($line, 'ROUTING TABLE') === 0) {
            $section = 'routes';
        } elseif (strpos($line, 'GLOBAL STATS') === 0 || $line === 'END') {
            $section = '';
        } elseif ($section === 'clients' && strpos($line, 'Updated,') !== 0 && strpos($line, 'Common Name,') !== 0 && $line !== '') {
            $parts = explode(',', $line);                                // cn,real,rx,tx,since
            $mark_ovpn_client($parts[0] ?? '', '');
        } elseif ($section === 'routes' && strpos($line, 'Virtual Address,') !== 0 && $line !== '') {
            $parts = explode(',', $line);                                // virtual,cn,real,lastref
            $mark_ovpn_client($parts[1] ?? '', trim($parts[0] ?? ''));
        }
    }
}

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
        $template_used = "";

        $lines = @file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines) {
            foreach ($lines as $l) {
                $l = trim($l);
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

    $generated_common_cfg = generateAndSaveGlobalConfig($formData, $cfg_version, $default_server_target, $tftp_dir, $sysadmin_redirect);
    $status = "Saved Global Settings to " . count(yealinkGlobalCfgMap()) . " y-config file(s) in {$tftp_dir}";
}

$max_dialnow_slots = (int)($formData['dialnow_count'] ?? 1);
$existing_logos = glob($logo_dir . "*.*");
$logo_filenames = array_map('basename', is_array($existing_logos) ? $existing_logos : []);
?>