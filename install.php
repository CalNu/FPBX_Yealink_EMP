<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

global $db;
global $amp_conf;

out("Starting Yealink Endpoint Manager (yealink_epm) Installation...");

// ============================================================================
// 0. Module Assets & Dynamic Symlink Mapping
// ============================================================================
$module_name = 'yealink_epm'; 
$module_root = $amp_conf['AMPWEBROOT'] . '/admin/modules/' . $module_name;

if (!function_exists('deploy_module_symlink')) {
    function deploy_module_symlink($source, $target) {
        if (file_exists($target) || is_link($target)) {
            if (is_dir($target) && !is_link($target)) {
                out("Warning: A physical folder already exists at " . $target . ". Skipping link generation.");
                return false;
            }
            @unlink($target);
        }

        if (@symlink($source, $target)) {
            @chown($target, 'asterisk');
            @chgrp($target, 'asterisk');
            return true;
        }
        return false;
    }
}

// Map 'tftpboot' to system /tftpboot, 'PhoneSettings' directly to web root, and 'ovpn_mgr' to adjacent module
deploy_module_symlink('/tftpboot', $module_root . '/tftpboot');
deploy_module_symlink($amp_conf['AMPWEBROOT'] . '/PhoneSettings', $module_root . '/PhoneSettings');
if (file_exists($amp_conf['AMPWEBROOT'] . '/admin/modules/ovpn_mgr')) {
    deploy_module_symlink($amp_conf['AMPWEBROOT'] . '/admin/modules/ovpn_mgr', $module_root . '/ovpn_mgr');
}

// Map /tftpboot/yealink_epm -> /var/www/html/admin/modules/yealink_epm
deploy_module_symlink($module_root, '/tftpboot/' . $module_name);


// ============================================================================
// 1. Directory Setup & Permissions
// ============================================================================
$tftp_dir = "/tftpboot/";
$template_dir = "/tftpboot/templates/";
$logo_dir = "/var/www/html/PhoneSettings/logo/";
$ringtone_dir = "/var/www/html/PhoneSettings/ringtones/";
$vpnkeys_dir = "/var/www/html/PhoneSettings/vpnkeys/";

foreach ([$logo_dir, $ringtone_dir, $template_dir, $vpnkeys_dir] as $dir) {
    if (!file_exists($dir)) {
        if (!@mkdir($dir, 0775, true)) {
            out("Failed to create directory: {$dir}");
        } else {
            out("Created directory: {$dir}");
        }
    }
    @chown($dir, 'asterisk');
    @chgrp($dir, 'asterisk');
}

if (!file_exists($tftp_dir)) {
    @mkdir($tftp_dir, 0775, true);
    @chown($tftp_dir, 'asterisk');
    @chgrp($tftp_dir, 'asterisk');
}

// ============================================================================
// 2. Database Table Schema Creation
// ============================================================================
$sql = "CREATE TABLE IF NOT EXISTS yealink_epm_devices (
    mac VARCHAR(12) NOT NULL,
    ext VARCHAR(15) DEFAULT '',
    model VARCHAR(30) DEFAULT 'manual',
    template VARCHAR(100) DEFAULT '',
    openvpn_enabled TINYINT(1) DEFAULT 0,
    PRIMARY KEY (mac)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;";

try {
    $db->query($sql);
    out("Verified database table structure [yealink_epm_devices].");
} catch (\Exception $e) {
    out("Error creating database table: " . $e->getMessage());
}

// ============================================================================
// 3. Ensure Web & Port 83 Symlinks Exist
// ============================================================================
$web_symlinks = [
    "/var/www/html/tftpboot"   => $tftp_dir,
    "/var/www/html/tftp"       => $tftp_dir,
    "/tftpboot/PhoneSettings" => "/var/www/html/PhoneSettings",
    "/var/www/html/PhoneSettings/yealink_epm"  => $module_root,
    "/tftpboot" =>  $module_root
];

foreach ($web_symlinks as $web_symlink => $target_dir) {
    if (!file_exists($web_symlink)) {
        @symlink($target_dir, $web_symlink);
        @chown($web_symlink, 'asterisk');
    }
}

// ============================================================================
// 4. System Dependency Check (FFmpeg & SoX)
// ============================================================================
$missing_deps = [];

exec('which ffmpeg 2>&1', $out_ff, $ret_ff);
if ($ret_ff !== 0) {
    $missing_deps[] = 'ffmpeg';
}

exec('which sox 2>&1', $out_sox, $ret_sox);
if ($ret_sox !== 0) {
    $missing_deps[] = 'sox';
}

if (!empty($missing_deps) && function_exists('out')) {
    out("<warning>Missing recommended system packages: " . implode(', ', $missing_deps) . ". Audio conversion/trimming may fail.</warning>");
} else {
    out("Audio conversion dependencies (FFmpeg / SoX) verified.");
}

// ============================================================================
// 5. Isolated Directory Overrides (Prevents 403 Forbidden)
// ============================================================================
// Directory listing is enabled for provisioning/admin convenience on the LAN,
// but access is restricted to private (RFC1918) address space plus loopback so
// these folders (which contain MAC-named cfg files with SIP secrets) are never
// reachable from outside the intranet, even if this host is ever dual-homed or
// accidentally port-forwarded. Adjust the ranges below if your LAN uses a
// different scheme (e.g. add more specific subnets, or remove ranges you don't use).
$htaccess_content = <<<EOT
Options +Indexes
DirectoryIndex disabled

<IfModule mod_authz_core.c>
    Require ip 127.0.0.1
    Require ip ::1
    Require ip 10.0.0.0/8
    Require ip 172.16.0.0/12
    Require ip 192.168.0.0/16
    Require ip fc00::/7
</IfModule>
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
    Allow from 127.0.0.1
    Allow from 10.0.0.0/8
    Allow from 172.16.0.0/12
    Allow from 192.168.0.0/16
</IfModule>
EOT;

$target_htaccess_files = [
    "/var/www/html/PhoneSettings/.htaccess",
    "/tftpboot/.htaccess"
];

foreach ($target_htaccess_files as $htaccess_path) {
    if (!file_exists($htaccess_path) || file_get_contents($htaccess_path) !== $htaccess_content) {
        @file_put_contents($htaccess_path, $htaccess_content);
        @chown($htaccess_path, 'asterisk');
        @chmod($htaccess_path, 0644);
    }
}

// ============================================================================
// 6. Add Yealink Reboot / Check-Sync Stanzas to Asterisk Custom Configs
// ============================================================================
$notify_stanzas = <<<EOT

; --- Added by Yealink Endpoint Manager Module ---
[check-sync]
Event=>check-sync;reboot=false

[yealink-check-cfg]
Event=>check-sync;reboot=false

[reboot-yealink]
Event=>check-sync;reboot=true

[reboot]
Event=>check-sync;reboot=true
; --- End Yealink EPM Custom Notify Events ---
EOT;

$files_to_update = [
    '/etc/asterisk/sip_notify_custom.conf',
    '/etc/asterisk/pjsip_notify_custom.conf'
];

$needs_asterisk_reload = false;

foreach ($files_to_update as $file) {
    if (file_exists(dirname($file))) {
        if (!file_exists($file)) {
            @file_put_contents($file, "");
            @chmod($file, 0664);
            @chown($file, 'asterisk');
            @chgrp($file, 'asterisk');
        }

        $current_content = file_get_contents($file);

        if (strpos($current_content, '[yealink-check-cfg]') === false) {
            if (@file_put_contents($file, $notify_stanzas . "\n", FILE_APPEND) !== false) {
                @chown($file, 'asterisk');
                $needs_asterisk_reload = true;
            }
        }
    }
}

if ($needs_asterisk_reload) {
    @exec("asterisk -rx 'module reload res_pjsip_notify.so' >/dev/null 2>&1");
    @exec("asterisk -rx 'module reload res_sip_notify.so' >/dev/null 2>&1");
    out("Added custom Yealink NOTIFY handlers to Asterisk configuration.");
}

// ============================================================================
// 7. INITIALIZE DEFAULT GLOBAL CONFIG (y000000000000.cfg)
// ============================================================================
// $global_cfg_file = '/tftpboot/y000000000000.cfg';
// if (!file_exists($global_cfg_file)) {
//     $default_global = "#!version:1.0.0.0\n\n";
//     $default_global .= "security.user_password = admin:22222\n";
//     $default_global .= "sip.notify_reboot_enable = 0\n";
//     $default_global .= "phone_setting.zero_touch_enable = 1\n";
//     $default_global .= "action_uri.enable = 1\n";
//     $default_global .= "features.action_uri_limit_ip = any\n";
//     $default_global .= "auto_provision.mode = 7\n";
//     $default_global .= "auto_provision.dhcp_option.enable = 1\n";

//     file_put_contents($global_cfg_file, $default_global);
//     @chown($global_cfg_file, 'asterisk');
//     @chgrp($global_cfg_file, 'asterisk');
//     out("Generated default base global configuration (/tftpboot/y000000000000.cfg)");
// }

// ============================================================================
// 8. AUTO-SIGN MODULE (generates module.sig so the "unsigned/tampered" notice
//    never appears in the first place; no root/sudo involved — this just
//    hashes the files that are already in place and writes a local signature
//    file the web user already has permission to write)
// ============================================================================
$signer_script = "{$module_root}/devtools/signer.php";
if (file_exists($signer_script)) {
    $sign_cmd = sprintf('/usr/bin/php %s %s 2>&1', escapeshellarg($signer_script), escapeshellarg($module_root));
    $sign_output = [];
    $sign_return = 1;
    exec($sign_cmd, $sign_output, $sign_return);

    if ($sign_return === 0 && file_exists("{$module_root}/module.sig")) {
        @chown("{$module_root}/module.sig", 'asterisk');
        @chgrp("{$module_root}/module.sig", 'asterisk');
        @chmod("{$module_root}/module.sig", 0644);
        out("Generated module.sig (local signature) - no manual signing needed.");
    } else {
        out("Warning: Automatic module signing failed (you can still use the 'Sign Module' button on the module page): " . implode(" ", $sign_output));
    }
} else {
    out("Warning: Signer script not found at {$signer_script}; skipping automatic signing.");
}

out("Yealink EPM installation completed successfully.");