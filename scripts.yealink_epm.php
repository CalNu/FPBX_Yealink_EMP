<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (php_sapi_name() !== 'cli' && !defined('FREEPBX_IS_AUTH')) { 
    die('No direct script access allowed'); 
}

ini_set('display_errors', 0);
error_reporting(E_ALL);

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
// Older Yealink phones (e.g. T28P) only ever look for y000000000000.cfg as
// their common/global config. Newer generations each look for their OWN
// numbered "y" file instead - a phone will simply never see global settings
// saved only to y000000000000.cfg. Global Settings therefore has to be
// written to (and deleted from) every filename below, not just the
// original one, or newer phones silently get no common config at all.
if (!function_exists('yealinkGlobalCfgMap')) {
    function yealinkGlobalCfgMap() {
        return [
            'y000000000000' => 'Legacy (T28 and other original-generation models)',
            'y000000000028' => 'T4X Legacy - T46G',
            'y000000000029' => 'T4X Legacy - T42G',
            'y000000000066' => 'T4X S-Series - T46S',
            'y000000000067' => 'T4X S-Series - T42S',
            'y000000000095' => 'T5X Series - T53W / T53',
            'y000000000096' => 'T5X Series - T54W',
            'y000000000108' => 'T4X U-Series - T46U',
            'y000000000109' => 'T4X U-Series - T48U',
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

$sysadmin_redirect = false;
if (function_exists('sysadmin_get_storage_settings')) {
    $settings = sysadmin_get_storage_settings();
    if (!empty($settings['https_redirect'])) {
        $sysadmin_redirect = true;
    }
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

function generateYealinkOpenVpnTarFromOvpnMgr($mac, $ext, $server_host, $server_port = '1194', &$debug_log = []) {
    $macClean = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $mac));
    $extClean = preg_replace('/[^0-9]/', '', $ext);

    if (empty($macClean) || empty($extClean)) {
        $debug_log[] = "FAIL: Invalid MAC or Extension provided.";
        return false;
    }

    $pkiDir = '/var/www/html/PhoneSettings/openvpn/legacy_pki';
    $targetVpnDir = '/var/www/html/PhoneSettings/vpnkeys';

    if (!is_dir("{$pkiDir}/issued")) { @mkdir("{$pkiDir}/issued", 0775, true); }
    if (!is_dir("{$pkiDir}/private")) { @mkdir("{$pkiDir}/private", 0775, true); }
    if (!is_dir($targetVpnDir)) { 
        @mkdir($targetVpnDir, 0775, true); 
        @chown($targetVpnDir, 'asterisk');
    }

    $clientCert = "{$pkiDir}/issued/{$extClean}.crt";
    $clientKey  = "{$pkiDir}/private/{$extClean}.key";
    $caCert     = "{$pkiDir}/ca.crt";
    $caKey      = "{$pkiDir}/private/ca.key";

    if (!file_exists($caCert) || !file_exists($caKey)) {
        $debug_log[] = "FAIL: Missing CA cert or CA key in {$pkiDir}.";
        return false;
    }

    if (!file_exists($clientCert) || !file_exists($clientKey)) {
        $debug_log[] = "Certificates missing. Generating via OpenSSL for extension {$extClean}...";
        $csr = "{$pkiDir}/{$extClean}.csr";
        $serial = time();

        $cmd1 = "openssl req -new -nodes -batch -sha1 -newkey rsa:1024 -out " . escapeshellarg($csr) . " -keyout " . escapeshellarg($clientKey) . " -subj '/CN=client-{$extClean}/' 2>&1";
        exec($cmd1, $o1, $r1);
        if ($r1 !== 0) {
            $debug_log[] = "FAIL: CSR creation failed: " . implode(" | ", $o1);
            return false;
        }

        $cmd2 = "openssl x509 -req -days 3650 -sha1 -in " . escapeshellarg($csr) . " -CA " . escapeshellarg($caCert) . " -CAkey " . escapeshellarg($caKey) . " -set_serial {$serial} -out " . escapeshellarg($clientCert) . " 2>&1";
        exec($cmd2, $o2, $r2);
        if ($r2 !== 0) {
            $debug_log[] = "FAIL: Cert signing failed: " . implode(" | ", $o2);
            return false;
        }
        @unlink($csr);
        $debug_log[] = "OpenSSL certificate successfully generated.";
    } else {
        $debug_log[] = "Existing certificates found in {$pkiDir}.";
    }

    $stagingDir = sys_get_temp_dir() . "/vpn_build_{$macClean}";
    if (is_dir($stagingDir)) {
        exec("rm -rf " . escapeshellarg($stagingDir));
    }
    @mkdir("{$stagingDir}/keys", 0775, true);

    @copy($caCert, "{$stagingDir}/ca.crt");
    @copy($clientCert, "{$stagingDir}/client.crt");
    @copy($clientKey, "{$stagingDir}/client.key");

    @copy($caCert, "{$stagingDir}/keys/ca.crt");
    @copy($clientCert, "{$stagingDir}/keys/client.crt");
    @copy($clientKey, "{$stagingDir}/keys/client.key");

    $vpnCnf = "client\n" .
              "dev tun\n" .
              "proto udp\n" .
              "remote {$server_host} {$server_port}\n" .
              "resolv-retry infinite\n" .
              "nobind\n" .
              "persist-key\n" .
              "persist-tun\n" .
              "reneg-sec 0\n" .
              "ca /config/openvpn/keys/ca.crt\n" .
              "cert /config/openvpn/keys/client.crt\n" .
              "key /config/openvpn/keys/client.key\n" .
              "cipher AES-128-CBC\n" .
              "auth SHA1\n" .
              "verb 3\n";

    @file_put_contents("{$stagingDir}/vpn.cnf", $vpnCnf);

    $outputTar = "{$targetVpnDir}/{$macClean}_{$extClean}_ovpn.tar";
    if (file_exists($outputTar)) {
        @unlink($outputTar);
    }

    $cmdTar = "cd " . escapeshellarg($stagingDir) . " && tar -cf " . escapeshellarg($outputTar) . " vpn.cnf ca.crt client.crt client.key keys/ 2>&1";
    exec($cmdTar, $o3, $r3);
    exec("rm -rf " . escapeshellarg($stagingDir));

    if ($r3 === 0 && file_exists($outputTar)) {
        @chmod($outputTar, 0775);
        @chown($outputTar, 'asterisk');
        $debug_log[] = "SUCCESS: Generated {$outputTar}";
        return $outputTar;
    }

    $debug_log[] = "FAIL: Tar execution failed: " . implode(" | ", $o3);
    return false;
}

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

function generateAndSaveGlobalConfig($formData, $cfg_version, $default_server_target, $tftp_dir) {
    $raw_server = !empty($formData['server_ip']) ? $formData['server_ip'] : $default_server_target;
    
    if (strpos($raw_server, '://') === false) {
        $raw_server = 'http://' . $raw_server;
    }
    
    $parsed_host = parse_url($raw_server, PHP_URL_HOST);
    $parsed_port = parse_url($raw_server, PHP_URL_PORT);
    
    if (!empty($parsed_host)) {
        $server_ip_target = $parsed_host . (!empty($parsed_port) ? ':' . $parsed_port : '');
    } else {
        $server_ip_target = $default_server_target;
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

$saved_global_server_ip = $default_server_target;
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
                $saved_global_server_ip = trim($gm[1]);
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
    "manual" => "-- Manual / Custom --",
    "T19P"   => "T19P / T19P E2 (1 Line Key)",
    "T21P"   => "T21P / T21P E2 (2 Line Keys)",
    "T23G"   => "T23G / T23P (3 Line Keys)",
    "T27G"   => "T27G / T27P (21 Line Keys)",
    "T28P"   => "T28P (6 Line Keys, 10 Mem Keys)",
    "T29G"   => "T29G (27 Line Keys)",
    "T30"    => "T30 / T30P (1 Line Key)",
    "T31G"   => "T31G / T31P / T31 (2 Line Keys)",
    "T33G"   => "T33G / T33P (4 Line Keys)",
    "T40P"   => "T40P / T40G (3 Line Keys)",
    "T41S"   => "T41S / T41P / T41U (15 Line Keys)",
    "T42S"   => "T42S / T42G / T42U (15 Line Keys)",
    "T43U"   => "T43U (21 Line Keys)",
    "T46S"   => "T46S / T46U / T46G (27 Line Keys)",
    "T48S"   => "T48S / T48U / T48G (29 Line Keys)",
    "T53W"   => "T53W / T53 (21 Line Keys)",
    "T54W"   => "T54W (27 Line Keys)",
    "T57W"   => "T57W (29 Line Keys)",
    "T58A"   => "T58A / T58V (27 Line Keys)",
    "VP59"   => "VP59 (27 Line Keys)"
];

$expansion_models = [
    "none"  => "-- None --",
    "EXP20" => "EXP20 (20 Keys per Module)",
    "EXP40" => "EXP40 (40 Keys per Module)",
    "EXP50" => "EXP50 (60 Keys per Module)"
];

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

    $mac = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $_REQUEST['mac'] ?? ''));
    $ext = preg_replace('/[^0-9]/', '', $_REQUEST['ext'] ?? '');
    $enable = isset($_REQUEST['enable']) && ($_REQUEST['enable'] === '1' || $_REQUEST['enable'] === 'true');

    if (empty($ext) || empty($mac)) {
        echo json_encode(['status' => 'error', 'message' => 'Missing MAC address or Extension assignment.']);
        exit;
    }

    $ovpn_host = '';
    $ovpn_port = '1194';

    $openvpn_conf = '/var/www/html/PhoneSettings/openvpn/legacy-vpn.conf';
    if (!file_exists($openvpn_conf)) {
        $openvpn_conf = '/etc/openvpn/server/server.conf';
    }

    if (file_exists($openvpn_conf)) {
        $conf_content = (string)@file_get_contents($openvpn_conf);
        if (preg_match('/^\#\s*client-remote-host\s+(.+)$/m', $conf_content, $mHost)) {
            $ovpn_host = trim($mHost[1]);
        }
        if (preg_match('/^port\s+(\d+)/m', $conf_content, $mPort)) {
            $ovpn_port = trim($mPort[1]);
        }
    }

    if (empty($ovpn_host)) {
        $ovpn_host = !empty($saved_global_server_ip) ? $saved_global_server_ip : ($_SERVER['SERVER_ADDR'] ?? '192.168.0.52');
    }

    if (strpos($ovpn_host, '://') !== false) {
        $ovpn_host = parse_url($ovpn_host, PHP_URL_HOST);
    }
    if (strpos($ovpn_host, ':') !== false) {
        $ovpn_host = explode(':', $ovpn_host)[0];
    }

    $admin_pass = !empty($saved_global_admin_pass) ? $saved_global_admin_pass : '22222';
    
    $client_cn = "client-{$ext}";
    $tar_filename = "{$mac}_{$ext}_ovpn.tar";
    $tar_path_tftp = "{$tftp_dir}openvpn_{$ext}.tar";
    $tar_path_vpnkeys = "{$vpnkeys_dir}{$tar_filename}";

    if ($enable) {
        $debug_logs = [];
        $generated_path = false;
        try {
            $gen_script = "/var/www/html/admin/modules/ovpn_mgr/scripts/generate_client.sh";
            if (file_exists($gen_script)) {
                exec("sudo " . escapeshellarg($gen_script) . " " . escapeshellarg($ext) . " " . escapeshellarg($mac) . " 2>&1", $output, $return_var);
                if ($return_var === 0) {
                    $generated_path = $tar_path_vpnkeys;
                } else {
                    $debug_logs[] = "ovpn_mgr generate_client.sh failed (exit {$return_var}): " . implode(" ", $output) . " -- falling back to built-in generator.";
                }
            }

            // Fall back to the built-in generator whenever ovpn_mgr's script is missing,
            // OR present but failed (e.g. no sudo rights, script removed/rewritten,
            // permission model changed). Don't rely on file_exists() alone to decide.
            if (!$generated_path) {
                $generated_path = generateYealinkOpenVpnTarFromOvpnMgr($mac, $ext, $ovpn_host, $ovpn_port, $debug_logs);
            }
        } catch (Throwable $e) {
            $generated_path = false;
            $debug_logs[] = "PHP Exception: " . $e->getMessage();
        }

        if (($generated_path && file_exists($generated_path)) || file_exists($tar_path_vpnkeys)) {
            $cfg_path = $tftp_dir . $mac . ".cfg";
            if (file_exists($cfg_path)) {
                $lines = @file($cfg_path, FILE_IGNORE_NEW_LINES) ?: [];
                $clean_lines = array_filter($lines, function($l) {
                    return !preg_match('/^(openvpn\.|network\.vpn_enable)/i', trim($l));
                });
                $clean_lines[] = "openvpn.url = http://{$saved_global_server_ip}/PhoneSettings/vpnkeys/{$tar_filename}";
                $clean_lines[] = "network.vpn_enable = 1";
                @file_put_contents($cfg_path, implode("\n", $clean_lines) . "\n");
                @chown($cfg_path, 'asterisk');
            }
            sendSipNotify($ext, 'yealink-check-cfg', '', $admin_pass);

            // Check active live connection state in Asterisk / VPN logs
            $is_connected = false;
            if (isset($online_exts[$ext])) {
                $contact_uri = $online_exts[$ext]['via'] ?? '';
                if (strpos($contact_uri, '10.0.6.') !== false) {
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
                'message' => "Failed to generate OpenVPN tarball.\n\nDebug Logs:\n" . implode("\n", $debug_logs)
            ]);
            exit;
        }
    } else {
        $easyrsa_paths = ["/etc/openvpn/easy-rsa", "/etc/openvpn/easy-rsa/3.0", "/usr/share/easy-rsa"];
        foreach ($easyrsa_paths as $er_dir) {
            if (is_dir($er_dir)) {
                $cmd = "cd " . escapeshellarg($er_dir) . " && sudo ./easyrsa --batch revoke " . escapeshellarg($client_cn) . " 2>&1; " .
                       "cd " . escapeshellarg($er_dir) . " && sudo ./easyrsa --batch revoke " . escapeshellarg("client_{$ext}") . " 2>&1; " .
                       "cd " . escapeshellarg($er_dir) . " && sudo ./easyrsa gen-crl 2>&1";
                exec($cmd);
            }
        }

        $legacy_pki = "/var/www/html/PhoneSettings/openvpn/legacy_pki";
        if (file_exists("{$legacy_pki}/issued/{$ext}.crt")) {
            @unlink("{$legacy_pki}/issued/{$ext}.crt");
            @unlink("{$legacy_pki}/private/{$ext}.key");
        }

        exec("sudo /usr/bin/systemctl reload openvpn@server 2>&1");
        exec("sudo /usr/bin/systemctl reload openvpn 2>&1");

        if (file_exists($tar_path_tftp)) { @unlink($tar_path_tftp); }
        if (file_exists($tar_path_vpnkeys)) { @unlink($tar_path_vpnkeys); }

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
    
    $existing_cfg_files = glob($tftp_dir . "*.cfg");
    $existing_macs = [];
    if (is_array($existing_cfg_files)) {
        foreach ($existing_cfg_files as $cfg_file) {
            $mac_name = strtolower(pathinfo($cfg_file, PATHINFO_FILENAME));
            if (!isYealinkGlobalCfgBasename($mac_name)) {
                $existing_macs[] = $mac_name;
            }
        }
    }

    if (preg_match('/^(\d{1,3}\.\d{1,3}\.\d{1,3})/', $subnet_input, $m)) {
        $prefix = $m[1];
    } else {
        $prefix = implode('.', array_slice(explode('.', $detected_host), 0, 3));
    }

    exec("ping -c 2 -b {$prefix}.255 > /dev/null 2>&1 &");
    usleep(200000);

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

    $yealink_ouis = [
        '001565', '0004f2', '805ec0', 'e434d7', 
        '805e0c', '249ab8', '706979', 'b44b36', 
        '108c70', '286b35', '001a4d', '805ec1'
    ];

    $discovered = [];

    foreach ($arp_output as $line) {
        if (preg_match('/^([\d\.]+)\s+.*\s+([0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2})/i', $line, $matches) ||
            preg_match('/\(([\d\.]+)\)\s+at\s+([0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2})/i', $line, $matches)) {
            
            $ip = $matches[1];
            $mac_clean = strtolower(str_replace([':', '-'], '', $matches[2]));

            if (strpos($ip, $prefix . '.') !== 0 || $mac_clean === '000000000000') {
                continue;
            }

            if (strlen($mac_clean) === 12) {
                $oui = substr($mac_clean, 0, 6);
                if (in_array($oui, $yealink_ouis) && !in_array($mac_clean, $existing_macs)) {
                    $discovered[] = [
                        'ip' => $ip,
                        'mac' => $mac_clean,
                        'vendor' => 'Yealink'
                    ];
                }
            }
        }
    }

    echo json_encode(['status' => 'success', 'subnet' => "{$prefix}.0/24", 'devices' => $discovered]);
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
                $vpn_url = "http://{$saved_global_server_ip}/PhoneSettings/vpnkeys/{$scanned_mac}_{$scanned_ext}_ovpn.tar";
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

        @file_put_contents($tftp_dir . "{$scanned_mac}.cfg", $cfg_body);
        @chown($tftp_dir . "{$scanned_mac}.cfg", 'asterisk');
        
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
        // "Delete Global Settings" removes every y-config file, since a
        // save wrote the same content to all of them - leaving newer
        // phones' files behind would leave stale global settings in
        // place for them while the legacy file (and the UI) shows deleted.
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
        $full_path = ""; // already handled above - skip the generic single-file path below
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

    $server_ip_target = $saved_global_server_ip;
    $tpl_name = preg_replace('/[^a-zA-Z0-9_\-]/', '', $formData['template_name']);
    if (empty($tpl_name)) $tpl_name = "default_template";
    $tpl_filename = $tpl_name . ".template.cfg";

    $host_only = explode(':', $server_ip_target)[0];
    $asset_host = "http://{$host_only}:83/PhoneSettings";

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

    $generated_template_cfg .= "account.1.sip_server = {$server_ip_target}\n";
    $generated_template_cfg .= "account.1.sip_server_host = {$server_ip_target}\n";
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

    $has_memkeys = false;
    for ($i = 1; $i <= $max_memkeys; $i++) {
        $val = $formData["memkey_{$i}_value"] ?? '';
        if (!empty($val)) {
            if (!$has_memkeys) {
                $generated_template_cfg .= "################################################\n";
                $generated_template_cfg .= "##         Memory Keys                          ##\n";
                $generated_template_cfg .= "################################################\n\n";
                $has_memkeys = true;
            }
            $pickup = isset($formData["memkey_{$i}_pickup"]) ? $formData["memkey_{$i}_pickup"] : '**';
            if ($pickup === '') { $pickup = '**'; }
            
            $generated_template_cfg .= "memorykey.{$i}.value = {$val}\n";
            if ($pickup !== 'none') {
                $generated_template_cfg .= "memorykey.{$i}.pickup_value = {$pickup}\n";
            }
            $generated_template_cfg .= "memorykey.{$i}.type = 16\n\n";
        }
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
        if ($highest_tpl_memkey > 0) $max_memkeys = $formData['memkey_count'] = $highest_tpl_memkey;

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
                        $vpn_url = "http://{$saved_global_server_ip}/PhoneSettings/vpnkeys/{$clean_mac}_{$new_ext}_ovpn.tar";
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

// Fetch REAL-TIME active connected OpenVPN clients directly from the management interface
$ovpn_connected_exts = [];
$ovpn_connected_macs = [];
$ovpn_connected_ips  = [];

$status_output = '';

// 1. Attempt connection via OpenVPN Management TCP Socket
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

// 2. Fallback: Parse openvpn-status.log if socket connection is unavailable
if (empty($status_output)) {
    $status_file = file_exists('/var/log/openvpn/openvpn-status.log') 
        ? '/var/log/openvpn/openvpn-status.log' 
        : '/var/www/html/PhoneSettings/openvpn/logs/openvpn-status.log';
        
    if (file_exists($status_file)) {
        $status_output = (string)@file_get_contents($status_file);
    }
}

// Parse live CLIENT_LIST lines
if (!empty($status_output)) {
    $lines = explode("\n", $status_output);
    foreach ($lines as $line) {
        if (strpos($line, 'CLIENT_LIST') === 0) {
            $parts = explode(',', $line);
            $cn = trim($parts[1] ?? '');
            $virt_ip = trim($parts[3] ?? '');

            if (!empty($virt_ip)) {
                $ovpn_connected_ips[] = $virt_ip;
            }

            if (!empty($cn)) {
                $clean_cn = strtolower(preg_replace('/[^a-f0-9]/i', '', $cn));
                $ovpn_connected_exts[$clean_cn] = true;
                $ovpn_connected_macs[$clean_cn] = true;
            }
        }
    }
}

if (is_array($existing_files)) {
    foreach ($existing_files as $file_path) {
        $b_name = basename($file_path);
        $file_name_no_ext = strtolower(pathinfo($b_name, PATHINFO_FILENAME));
        
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

        // Verify active OpenVPN connection against real-time socket data & Asterisk PJSIP contact status
        $is_vpn_connected = false;
        if ($has_vpn) {
            $clean_mac = strtolower($file_name_no_ext);
            $clean_ext_cn = strtolower("client-{$ext_num}");
            $pjsip_online = isset($online_exts[$ext_num]);

            // Must match active client list in OpenVPN memory OR have an active PJSIP registration over the VPN
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

    $generated_common_cfg = generateAndSaveGlobalConfig($formData, $cfg_version, $default_server_target, $tftp_dir);
    $status = "Saved Global Settings to " . count(yealinkGlobalCfgMap()) . " y-config file(s) in {$tftp_dir}";
}

$max_dialnow_slots = (int)($formData['dialnow_count'] ?? 1);
$existing_logos = glob($logo_dir . "*.*");
$logo_filenames = array_map('basename', is_array($existing_logos) ? $existing_logos : []);
?>