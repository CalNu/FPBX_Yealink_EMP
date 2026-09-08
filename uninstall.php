<?php
// Ensure script is run within FreePBX context
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

function removeSipAndPjsipNotifyCustom() {
    $files_to_clean = [
        '/etc/asterisk/sip_notify_custom.conf',
        '/etc/asterisk/pjsip_notify_custom.conf'
    ];

    foreach ($files_to_clean as $file_path) {
        if (file_exists($file_path)) {
            $existing_content = file_get_contents($file_path);

            // Strip out the custom block added by the module
            $cleaned_content = preg_replace('/; --- Added by Yealink Endpoint Manager Module ---.*?; --- End Yealink EPM Custom Notify Events ---/s', '', $existing_content);

            @file_put_contents($file_path, trim($cleaned_content) . "\n");
            @chown($file_path, 'asterisk');
            @chgrp($file_path, 'asterisk');
        }
    }

    // Reload Asterisk SIP and PJSIP NOTIFY configurations to remove custom event mappings
    exec("asterisk -rx 'sip reload' 2>&1");
    exec("asterisk -rx 'module reload res_pjsip_notify.so' 2>&1");
}

// Run cleanup during module uninstallation
removeSipAndPjsipNotifyCustom();