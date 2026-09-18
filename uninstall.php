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

$symlinks_to_remove = [
    "/var/www/html/tftpboot",
    "/var/www/html/tftp",
    "/tftpboot/PhoneSettings",
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

// ============================================================================
// 3. REMOVE CUSTOM HTACCESS OVERRIDES
// ============================================================================
$htaccess_files = [
    "/var/www/html/PhoneSettings/.htaccess",
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