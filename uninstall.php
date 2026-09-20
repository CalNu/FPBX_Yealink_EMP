<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

global $db;
global $amp_conf;

out("Starting Yealink Endpoint Manager (yealink_epm) Uninstallation...");

$module_name = 'yealink_epm';
$module_root = $amp_conf['AMPWEBROOT'] . '/admin/modules/' . $module_name;
$phone_settings_dir = $amp_conf['AMPWEBROOT'] . '/PhoneSettings';

// Progress + PhoneSettings state are written to a log file as well as shown on
// screen, so it is possible to tell afterwards exactly when (or whether) this
// script touched PhoneSettings.
if (!function_exists('yealink_epm_uninstall_log')) {
    function yealink_epm_uninstall_log($msg) {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
        foreach (['/var/log/asterisk/yealink_epm_uninstall.log', sys_get_temp_dir() . '/yealink_epm_uninstall.log'] as $file) {
            if (@file_put_contents($file, $line, FILE_APPEND) !== false) {
                return;
            }
        }
    }
}
if (!function_exists('yealink_epm_describe_dir')) {
    function yealink_epm_describe_dir($path) {
        if (is_link($path)) {
            return 'symlink -> ' . readlink($path);
        }
        if (!file_exists($path)) {
            return 'MISSING';
        }
        $list = @scandir($path);
        if ($list === false) {
            return 'real folder (unreadable)';
        }
        $list = array_values(array_diff($list, ['.', '..']));
        return 'real folder, ' . count($list) . ' item(s): ' . implode(', ', array_slice($list, 0, 20));
    }
}
yealink_epm_uninstall_log('---- uninstall.php started (' . __FILE__ . ')');
yealink_epm_uninstall_log('PhoneSettings at start: ' . yealink_epm_describe_dir($phone_settings_dir));

// ============================================================================
// 1. REMOVE MODULE SYMLINKS (first, so nothing later can block it)
// ============================================================================
// Removing a symlink never touches whatever it points to, it only deletes the
// pointer itself, so these are always safe to remove. is_link() is checked
// first so a real file/folder that happens to sit at one of these paths is
// never removed.
$symlinks_to_remove = [
    $amp_conf['AMPWEBROOT'] . '/tftpboot',        // alias created by install
    $amp_conf['AMPWEBROOT'] . '/tftp',            // alias created by install
    $phone_settings_dir . '/tftpboot',            // PhoneSettings/tftpboot    -> /tftpboot
    $phone_settings_dir . '/' . $module_name,     // PhoneSettings/yealink_epm -> module dir
    '/tftpboot/PhoneSettings',                    // /tftpboot/PhoneSettings   -> PhoneSettings
    '/tftpboot/' . $module_name,                  // /tftpboot/yealink_epm     -> module dir
    $module_root . '/tftpboot',                   // yealink_epm/tftpboot      -> /tftpboot
    $module_root . '/PhoneSettings',              // yealink_epm/PhoneSettings -> PhoneSettings
    $module_root . '/ovpn_mgr'
];

foreach ($symlinks_to_remove as $link) {
    if (is_link($link)) {
        @unlink($link);
    }
}
out("Cleaned up module symlinks.");
yealink_epm_uninstall_log('PhoneSettings after symlink removal: ' . yealink_epm_describe_dir($phone_settings_dir));

// ============================================================================
// 2. CLEANUP ASTERISK NOTIFY CUSTOM STANZAS
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
// 3. REMOVE CUSTOM HTACCESS OVERRIDES
// ============================================================================
// Done before the PhoneSettings check below so our own .htaccess doesn't make
// an otherwise-empty PhoneSettings folder look like it has content in it.
$htaccess_files = [
    $phone_settings_dir . "/.htaccess",
    "/tftpboot/.htaccess"
];

foreach ($htaccess_files as $htaccess_path) {
    if (is_file($htaccess_path)) {
        $content = file_get_contents($htaccess_path);
        if (strpos($content, 'DirectoryIndex disabled') !== false) {
            @unlink($htaccess_path);
        }
    }
}

// ============================================================================
// 4. REMOVE PHONESETTINGS ONLY IF IT IS EMPTY
// ============================================================================
// PhoneSettings holds the user's logos, ringtones and VPN keys, so it is only
// removed when nothing of theirs is in it. Anything else = leave it fully intact.
clearstatcache();   // PHP caches stat() results; we just unlinked paths above
if (!function_exists('yealink_epm_dir_is_empty')) {
    /** True only if $path is a readable directory with nothing in it. Unreadable = "not empty" (the safe answer). */
    function yealink_epm_dir_is_empty($path) {
        $list = @scandir($path);
        return $list !== false && count(array_diff($list, ['.', '..'])) === 0;
    }
}

if (is_link($phone_settings_dir)) {
    // Leftover from v1.0.4, which made this a symlink to /tftpboot. Same rule
    // as a real folder: if there are files behind it, PhoneSettings stays put.
    // Only a dangling link, or one pointing at an empty folder, is removed.
    $link_target = realpath($phone_settings_dir);
    if ($link_target === false || (is_dir($link_target) && yealink_epm_dir_is_empty($link_target))) {
        @unlink($phone_settings_dir);
        out("Removed empty PhoneSettings symlink.");
    } else {
        out("PhoneSettings is a symlink to {$link_target}, which is not empty - leaving it in place (not removed).");
    }
} elseif (is_dir($phone_settings_dir)) {
    $module_subdirs = ['logo', 'ringtones', 'vpnkeys'];   // created by install.php
    $entries = @scandir($phone_settings_dir);
    $removable = ($entries !== false);

    if ($removable) {
        $entries = array_values(array_diff($entries, ['.', '..']));
        foreach ($entries as $entry) {
            $path = $phone_settings_dir . '/' . $entry;
            $is_empty_module_subdir = in_array($entry, $module_subdirs, true)
                && is_dir($path) && !is_link($path)
                && yealink_epm_dir_is_empty($path);
            if (!$is_empty_module_subdir) {
                $removable = false;   // a user file/folder (or unknown item) lives here
                break;
            }
        }
    }

    if ($removable) {
        foreach ($entries as $entry) {
            @rmdir($phone_settings_dir . '/' . $entry);   // rmdir only succeeds on empty dirs
        }
        if (@rmdir($phone_settings_dir)) {
            out("Removed empty PhoneSettings directory.");
        } else {
            out("Could not remove PhoneSettings directory (check permissions); leaving it in place.");
        }
    } else {
        out("PhoneSettings is not empty - leaving it in place (not removed) so your logos, ringtones and other files are preserved.");
    }
}

// ============================================================================
// 5. DROP DATABASE TABLES
// ============================================================================
try {
    $db->query("DROP TABLE IF EXISTS yealink_epm_devices;");
    out("Dropped database table [yealink_epm_devices].");
} catch (\Exception $e) {
    out("Error dropping database table: " . $e->getMessage());
}

$final_state = yealink_epm_describe_dir($phone_settings_dir);
yealink_epm_uninstall_log('PhoneSettings at END of uninstall.php: ' . $final_state);
out("PhoneSettings state when the uninstall script finished: " . $final_state);

out("Yealink EPM uninstallation completed.");
