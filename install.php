<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

global $db;
global $amp_conf;

out("Starting Yealink Endpoint Manager (yealink_epm) Installation...");

// ============================================================================
// 0. Directory Setup & Permissions
// ============================================================================
// This runs BEFORE the symlinks (section 1) because four of the six links live
// *inside* /tftpboot or PhoneSettings and need those folders to exist first.
$module_name = 'yealink_epm';
$module_root = $amp_conf['AMPWEBROOT'] . '/admin/modules/' . $module_name;
$phone_settings_dir = $amp_conf['AMPWEBROOT'] . '/PhoneSettings';
$tftp_dir = "/tftpboot/";
$template_dir = "/tftpboot/templates/";

if (!file_exists($tftp_dir)) {
    @mkdir($tftp_dir, 0775, true);
    @chown($tftp_dir, 'asterisk');
    @chgrp($tftp_dir, 'asterisk');
}

if (!file_exists($template_dir)) {
    if (!@mkdir($template_dir, 0775, true)) {
        out("Failed to create directory: {$template_dir}");
    } else {
        out("Created directory: {$template_dir}");
    }
}
@chown($template_dir, 'asterisk');
@chgrp($template_dir, 'asterisk');

// PhoneSettings must be a REAL directory: it holds the logos, ringtones and VPN
// keys. v1.0.4 wrongly turned it into a symlink to /tftpboot. If we find that
// leftover, remove the symlink (unlink() only removes the pointer, never the
// files it points at) and remember where it pointed, so any logo/ringtones/
// vpnkeys folders that 1.0.4 created inside /tftpboot can be moved back below.
$legacy_source = null;
if (is_link($phone_settings_dir)) {
    $resolved = realpath($phone_settings_dir);
    if ($resolved !== false && is_dir($resolved)) {
        $legacy_source = $resolved;
    }
    @unlink($phone_settings_dir);
    out("Removed legacy PhoneSettings symlink at " . $phone_settings_dir . " (replacing it with a real folder).");
}

if (!file_exists($phone_settings_dir)) {
    if (!@mkdir($phone_settings_dir, 0775, true)) {
        out("Failed to create directory: {$phone_settings_dir}");
    } else {
        out("Created directory: {$phone_settings_dir}");
    }
}
clearstatcache();   // PHP caches stat() results; we just unlinked/created paths above
$phone_settings_ok = is_dir($phone_settings_dir) && !is_link($phone_settings_dir);

if ($phone_settings_ok) {
    @chown($phone_settings_dir, 'asterisk');
    @chgrp($phone_settings_dir, 'asterisk');

    $asset_subdirs = ['logo', 'ringtones', 'vpnkeys'];

    // Recover assets left in the old symlink's target (normally /tftpboot).
    // Never overwrites anything that already exists at the destination.
    if ($legacy_source !== null) {
        foreach ($asset_subdirs as $sub) {
            $from = rtrim($legacy_source, '/') . '/' . $sub;
            $to   = $phone_settings_dir . '/' . $sub;

            if (!is_dir($from) || is_link($from)) {
                continue;
            }
            if (file_exists($to) || is_link($to)) {
                out("Warning: {$to} already exists; leaving {$from} where it is. Merge them manually.");
                continue;
            }

            $moved = @rename($from, $to);
            if (!$moved) {
                // rename() cannot move a directory across filesystems; mv can.
                $mv_out = [];
                $mv_ret = 1;
                @exec('mv ' . escapeshellarg($from) . ' ' . escapeshellarg($to) . ' 2>&1', $mv_out, $mv_ret);
                $moved = ($mv_ret === 0);
            }
            if ($moved) {
                out("Moved existing {$sub}/ from {$legacy_source} into {$phone_settings_dir}");
            } else {
                out("Warning: could not move {$from} to {$to}; please move it manually.");
            }
        }
    } else {
        // No legacy symlink was found, so nothing is moved automatically (we
        // won't guess at what belongs to us). But if v1.0.4 left assets in
        // /tftpboot, say so rather than silently orphaning them.
        foreach ($asset_subdirs as $sub) {
            $stranded = '/tftpboot/' . $sub;
            if (is_dir($stranded) && !is_link($stranded)) {
                out("Note: found /tftpboot/{$sub}/ - if it holds your {$sub} from v1.0.4, move it with: mv /tftpboot/{$sub}/* {$phone_settings_dir}/{$sub}/");
            }
        }
    }

    foreach ($asset_subdirs as $sub) {
        $dir = $phone_settings_dir . '/' . $sub . '/';
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
} else {
    out("ERROR: {$phone_settings_dir} could not be set up as a real folder - skipping logo/ringtones/vpnkeys creation and PhoneSettings symlinks. Resolve the error above and re-run the install.");
}

// ============================================================================
// 1. Symlink Mapping
// ============================================================================
if (!function_exists('deploy_module_symlink')) {
    /**
     * Create a symlink at $target pointing to $source.
     * Never deletes a real folder: if one already occupies $target, it is left
     * alone with a warning. Only an existing file/symlink at $target is replaced.
     */
    function deploy_module_symlink($source, $target) {
        // Already correct - nothing to do.
        if (is_link($target) && readlink($target) === $source) {
            return true;
        }

        if (is_dir($target) && !is_link($target)) {
            out("Warning: A physical folder already exists at " . $target . ". Skipping link generation.");
            return false;
        }

        if (file_exists($target) || is_link($target)) {
            @unlink($target);
        }

        if (@symlink($source, $target)) {
            @chown($target, 'asterisk');
            @chgrp($target, 'asterisk');
            return true;
        }

        out("ERROR: Failed to create symlink " . $target . " -> " . $source . " (check filesystem permissions for the web server user).");
        return false;
    }
}

$tftp_root = '/tftpboot';

if ($phone_settings_ok) {
    // Inside /var/www/html/PhoneSettings
    deploy_module_symlink($tftp_root,   $phone_settings_dir . '/tftpboot');       // PhoneSettings/tftpboot     -> /tftpboot
    deploy_module_symlink($module_root, $phone_settings_dir . '/' . $module_name); // PhoneSettings/yealink_epm  -> module dir

    // Inside /tftpboot
    deploy_module_symlink($phone_settings_dir, $tftp_root . '/PhoneSettings');     // /tftpboot/PhoneSettings    -> /var/www/html/PhoneSettings

    // Inside the module dir
    deploy_module_symlink($phone_settings_dir, $module_root . '/PhoneSettings');   // yealink_epm/PhoneSettings  -> /var/www/html/PhoneSettings
}

// Inside /tftpboot
deploy_module_symlink($module_root, $tftp_root . '/' . $module_name);              // /tftpboot/yealink_epm      -> module dir

// Inside the module dir
deploy_module_symlink($tftp_root, $module_root . '/tftpboot');                     // yealink_epm/tftpboot       -> /tftpboot

if (file_exists($amp_conf['AMPWEBROOT'] . '/admin/modules/ovpn_mgr')) {
    deploy_module_symlink($amp_conf['AMPWEBROOT'] . '/admin/modules/ovpn_mgr', $module_root . '/ovpn_mgr');
}

// Convenience aliases some Yealink firmwares/tools expect at these paths.
// (If something real already lives here, it is left alone with a warning.)
deploy_module_symlink($tftp_root, $amp_conf['AMPWEBROOT'] . '/tftpboot');
deploy_module_symlink($tftp_root, $amp_conf['AMPWEBROOT'] . '/tftp');


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
// 3. System Dependency Check (FFmpeg & SoX)
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
// 4. Isolated Directory Overrides (Prevents 403 Forbidden)
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

IndexIgnore openvpn ovpn_mgr vpnkeys yealink_epm

EOT;

// PhoneSettings and /tftpboot are two separate real folders, so each gets its
// own copy.
$target_htaccess_files = ["/tftpboot/.htaccess"];
if ($phone_settings_ok) {
    $target_htaccess_files[] = $phone_settings_dir . "/.htaccess";
}

foreach ($target_htaccess_files as $htaccess_path) {
    if (!file_exists($htaccess_path) || file_get_contents($htaccess_path) !== $htaccess_content) {
        @file_put_contents($htaccess_path, $htaccess_content);
        @chown($htaccess_path, 'asterisk');
        @chmod($htaccess_path, 0644);
    }
}

// ============================================================================
// 5. Add Yealink Reboot / Check-Sync Stanzas to Asterisk Custom Configs
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
// 6. INITIALIZE DEFAULT GLOBAL CONFIG (y000000000000.cfg)
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
// 7. AUTO-SIGN MODULE (generates module.sig so the "unsigned/tampered" notice
//    never appears in the first place; no root/sudo involved - this just
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
