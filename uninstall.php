<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

global $amp_conf;

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

            // Match the opening comment AND all notify sections added by the installer
            $pattern = '/; --- Added by Yealink Endpoint Manager Module ---\s*\[check-sync\].*?Event=>check-sync;reboot=false\s*\[yealink-check-cfg\].*?Event=>check-sync;reboot=false\s*\[reboot-yealink\].*?Event=>check-sync;reboot=true\s*\[reboot\].*?Event=>check-sync;reboot=true/s';
            
            $cleaned_content = preg_replace($pattern, '', $existing_content);

            // Fallback backup regex: Strips any remaining stanzas created by install.php
            $stanzas_to_strip = [
                '/; --- Added by Yealink Endpoint Manager Module ---/i',
                '/\[yealink-check-cfg\]\s*Event=>check-sync;reboot=false/i',
                '/\[reboot-yealink\]\s*Event=>check-sync;reboot=true/i',
                '/\[check-sync\]\s*Event=>check-sync;reboot=false/i',
                '/\[reboot\]\s*Event=>check-sync;reboot=true/i',
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

    // Reload Asterisk SIP and PJSIP NOTIFY configurations
    exec("asterisk -rx 'module reload res_sip_notify.so' 2>&1");
    exec("asterisk -rx 'module reload res_pjsip_notify.so' 2>&1");
}

// ============================================================================
// 2. REMOVE MODULE SYMLINKS (Safe — does not touch target folder content)
// ============================================================================
$module_name = 'yealink_epm'; 
$module_root = $amp_conf['AMPWEBROOT'] . '/admin/modules/' . $module_name;

$symlinks_to_remove = [
    "/var/www/html/tftpboot",
    "/var/www/html/tftp",
    "/tftpboot/PhoneSettings",
    $module_root . '/tftpboot',
    $module_root . '/PhoneSettings'
];

foreach ($symlinks_to_remove as $link) {
    if (is_link($link)) {
        @unlink($link);
    }
}

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

// Execute system-level cleanup
removeSipAndPjsipNotifyCustom();