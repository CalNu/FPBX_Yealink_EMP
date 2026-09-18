<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

global $db;
global $amp_conf;

out("Starting Yealink Endpoint Manager (yealink_epm) Uninstallation...");

// ============================================================================
// 1. CLEANUP ASTERISK NOTIFY CUSTOM STANZAS
// ============================================================================
function removeSipAndPjsipNotifyCustom() {
    $files_to_clean = [
        '/etc/asterisk/sip_notify_custom.conf',
        '/etc/asterisk/pjsip_notify_custom.conf'
    ];

    foreach ($files_to_clean as $file_path) {
        if (file_exists($file_path)) {
            $existing_content = file_get_contents($file_path);

            $pattern = '/; --- Added by Yealink Endpoint Manager Module ---.*?; --- End Yealink EPM Custom Notify Events ---/s';
            $cleaned_content = preg_replace($pattern, '', $existing_content);

            $stanzas_to_strip = [
                '/; --- Added by Yealink Endpoint Manager Module ---/i',
                '/\[yealink-check-cfg\]\s*Event=>check-sync;reboot=false/i',
                '/\[reboot-yealink\]\s*Event=>check-sync;reboot=true/i',
                '/; --- End Yealink EPM Custom Notify Events ---/i'
            ];

            foreach ($stanzas_to_strip as $stanza_pattern) {
                $cleaned_content = preg_replace($stanza_pattern, '', $cleaned_content);
            }

            @file_put_contents($file_path, trim($cleaned_content) . "\n");
            @chown($file_path, 'asterisk');
            @chgrp($file_path, 'asterisk');
        }
    }

    exec("asterisk -rx 'module reload res_sip_notify.so' 2>&1");
    exec("asterisk -rx 'module reload res_pjsip_notify.so' 2>&1");
    out("Removed custom Yealink NOTIFY handlers from Asterisk configuration.");
}

removeSipAndPjsipNotifyCustom();

// ============================================================================
// 2. REMOVE MODULE SYMLINKS
// ============================================================================
$module_name = 'yealink_epm';
$module_root = $amp_conf['AMPWEBROOT'] . '/admin/modules/' . $module_name;
$phone_settings_dir = $amp_conf['AMPWEBROOT'] . '/PhoneSettings';

// Plain symlinks it's always safe to remove outright: removing a symlink
// never touches whatever it points to, it just deletes the pointer itself.
$symlinks_to_remove = [
    $amp_conf['AMPWEBROOT'] . '/tftpboot',   // alias created by install
    $amp_conf['AMPWEBROOT'] . '/tftp',       // alias created by install
    '/tftpboot/' . $module_name,             // /tftpboot/yealink_epm -> module dir
    $module_root . '/tftpboot',
    $module_root . '/PhoneSettings',
    $module_root . '/ovpn_mgr'
];

foreach ($symlinks_to_remove as $link) {
    if (is_link($link)) {
        @unlink($link);
    }
}
out("Cleaned up module symlinks.");

// PhoneSettings gets special handling: it's the live TFTP root for every
// phone's config, so we must never delete real content out from under it.
if (is_link($phone_settings_dir)) {
    // Safe: this is just the pointer this module created; the actual files
    // it points to (in /tftpboot) are left completely untouched.
    @unlink($phone_settings_dir);
    out("Removed PhoneSettings symlink (contents preserved in /tftpboot).");
} elseif (is_dir($phone_settings_dir)) {
    // A real directory here (e.g. left over from a broken older install).
    // Only remove it if it's completely empty - otherwise leave it alone
    // so we never destroy phone configs, logos, ringtones, or vpn keys.
    $contents = array_diff(scandir($phone_settings_dir), ['.', '..']);
    if (empty($contents)) {
        @rmdir($phone_settings_dir);
        out("Removed empty PhoneSettings directory.");
    } else {
        out("PhoneSettings exists as a real directory containing files - leaving it in place (not removed) to avoid data loss.");
    }
}

// ============================================================================
// 3. REMOVE CUSTOM HTACCESS OVERRIDES
// ============================================================================
$htaccess_files = [
    "/tftpboot/.htaccess"
];

foreach ($htaccess_files as $htaccess_path) {
    if (file_exists($htaccess_path)) {
        $content = file_get_contents($htaccess_path);
        if (strpos($content, 'DirectoryIndex disabled') !== false) {
            @unlink($htaccess_path);
        }
    }
}

// ============================================================================
// 4. DROP DATABASE TABLES
// ============================================================================
try {
    $db->query("DROP TABLE IF EXISTS yealink_epm_devices;");
    out("Dropped database table [yealink_epm_devices].");
} catch (\Exception $e) {
    out("Error dropping database table: " . $e->getMessage());
}

out("Yealink EPM uninstallation completed.");
