<?php
if (!defined('FREEPBX_IS_AUTH')) { 
    die('No direct script access allowed'); 
}
?>

<!-- ============================================================================ -->
<!-- HTML VIEW & STYLES                                                           -->
<!-- ============================================================================ -->

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/noUiSlider/15.7.1/nouislider.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/noUiSlider/15.7.1/nouislider.min.js"></script>

<style>
    .gen-container { background: #fff; padding: 20px; border-radius: 6px; overflow: visible; }
    .gen-container label { font-weight: bold; display: block; margin-top: 10px; }
    .gen-container input[type="text"], .gen-container select, .gen-container input[type="file"] { padding: 8px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
    .gen-full-width { width: 100%; }
    .gen-key-row { display: flex; gap: 10px; margin-top: 5px; }
    .gen-key-row input, .gen-key-row div, .gen-key-row select { flex: 1; }
    .gen-btn { margin-top: 10px; padding: 10px 18px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; }
    .gen-btn-danger { background: #dc3545; color: white; border: none; border-radius: 4px; padding: 8px 14px; cursor: pointer; }
    .gen-textarea { width: 100%; height: 220px; font-family: monospace; margin-top: 5px; box-sizing: border-box; }
    .gen-alert { background: #d4edda; color: #155724; padding: 10px; border-radius: 4px; margin-bottom: 15px; }
    
    .gen-tab-bar { 
        display: flex; 
        border-bottom: 2px solid #007bff; 
        margin-bottom: 15px; 
        position: sticky; 
        top: 40px; 
        z-index: 100; 
        background: #fff; 
        padding-top: 10px; 
        padding-bottom: 5px; 
    }

    .gen-load-box { 
        background: #f8f9fa; 
        padding: 10px 14px; 
        border-radius: 6px; 
        margin-bottom: 20px;
        position: sticky;
        top: 92px; 
        z-index: 99;
        border: 1px solid #ced4da;
        display: block;
        width: 820px;
        box-sizing: border-box;
    }

    .gen-load-box label {
        font-size: 13px;
        font-weight: bold;
        margin-top: 0 !important;
        margin-bottom: 6px;
        color: #333;
    }

    .gen-load-box select {
        padding: 8px !important;
        height: 36px !important;
        font-size: 14px !important;
        font-weight: bold !important;
        font-family: Arial, Helvetica, sans-serif !important;
        color: #4d4d4d !important;
        border: 1px solid #ccc !important;
        border-radius: 4px !important;
        background-color: #fff !important;
        flex: 1 1 auto;
    }

    .gen-load-box select option,
    .gen-load-box select optgroup,
    #select_template_file option {
        font-family: Arial, Helvetica, sans-serif !important;
        font-size: 14px !important;
        font-weight: normal !important;
        color: #333 !important;
        background-color: #fff !important;
    }

    .gen-load-box .gen-btn, 
    .gen-load-box .gen-btn-danger {
        padding: 8px 14px !important;
        height: auto !important;
        font-size: 13px !important;
        font-weight: bold !important;
        margin-top: 0 !important;
    }

    #ringtone_section {
        scroll-margin-top: 120px;
    }

    .gen-section-title { border-bottom: 2px solid #007bff; padding-bottom: 5px; margin-top: 20px; color: #333; }
    .gen-tab-btn { padding: 10px 20px; cursor: pointer; background: #e9ecef; border: 1px solid #ccc; border-bottom: none; border-top-left-radius: 4px; border-top-right-radius: 4px; margin-right: 5px; font-weight: bold; }
    .gen-tab-btn.active { background: #007bff; color: white; border-color: #007bff; }
    .gen-tab-content { display: none; }
    .gen-tab-content.active { display: block; }

    .gen-modal { display: none; position: fixed; z-index: 999; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); }
    .gen-modal-content { background: #fff; margin: 8% auto; padding: 20px; width: 65%; border-radius: 8px; }
    .scan-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    .scan-table th, .scan-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    .scan-table th { background: #f2f2f2; }

    .oss-table { width: 100%; border-collapse: collapse; margin-top: 15px; background: #fff; }
    .oss-table th, .oss-table td { border: 1px solid #d4d4d4; padding: 8px; text-align: left; }
    .oss-table th { background: #e9ecef; font-weight: bold; }
    .oss-action-card { background: #f8f9fa; border: 1px solid #ddd; border-radius: 6px; padding: 15px; margin-top: 15px; }
    .oss-action-line { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
    .oss-btn-icon { background: none; border: none; cursor: pointer; font-size: 18px; }
    .oss-btn-icon.online { color: #28a745; }
    .oss-btn-icon.offline { color: #dc3545; }

    .ringtone-card { border: 1px solid #ccc; border-radius: 6px; padding: 12px; background: #fafafa; margin-top: 10px; }
    .ringtone-list-container { border: 1px solid #e0e0e0; background: #fff; border-radius: 4px; padding: 4px 10px; margin-top: 8px; }
    
    .ringtone-grid-item { 
        display: grid; 
        grid-template-columns: 180px 80px 210px 60px 85px; 
        align-items: center; 
        padding: 6px 0; 
        border-bottom: 1px dashed #e0e0e0; 
        gap: 8px;
    }
    .ringtone-grid-item:last-child { border-bottom: none; }
    
    .ringtone-player-controls {
        display: flex;
        align-items: center;
    }
    .ringtone-player-controls audio {
        height: 28px;
        max-width: 200px;
    }

    .ringtone-size-badge { 
        font-size: 12px; 
        font-weight: 600;
        color: #495057; 
        font-family: inherit; 
        background: #e9ecef; 
        padding: 3px 8px; 
        border-radius: 4px; 
        display: inline-block;
    }
    
    .action-icon-btn {
        background: none;
        border: none;
        color: #6c757d;
        cursor: pointer;
        padding: 4px;
        line-height: 0;
        border-radius: 4px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: background-color 0.2s, color 0.2s;
        text-decoration: none;
    }
    .action-icon-btn:hover { 
        background-color: #e9ecef; 
        color: #007bff;
    }
    .action-icon-btn.delete-icon:hover {
        background-color: #f8d7da;
        color: #dc3545;
    }
    .action-icon-btn svg {
        width: 16px;
        height: 16px;
        stroke: currentColor;
    }
    
    .upload-controls-col {
        display: flex !important;
        flex-direction: column !important;
        align-items: flex-start !important;
        gap: 8px !important;
        margin-top: 5px !important;
    }

    .custom-file-btn, 
    .upload-btn-aligned {
        height: 38px !important;
        line-height: 38px !important;
        margin: 0 !important;
        padding: 0 16px !important;
        vertical-align: top !important;
        box-sizing: border-box !important;
        font-size: 13px !important;
        font-weight: 600 !important;
        font-family: inherit !important;
        border-radius: 4px !important;
        display: inline-block !important;
        text-align: center !important;
    }

    .custom-file-btn { 
        background: #6c757d !important; 
        color: white !important; 
        cursor: pointer !important; 
    }
    .custom-file-btn:hover { background: #5a6268 !important; }

    .upload-btn-aligned {
        background: #17a2b8 !important; 
        white-space: nowrap !important; 
        border: none !important;
        color: #fff !important;
        cursor: pointer !important;
    }

    #selected_files_textarea {
        width: 320px; 
        display: none; 
        background: #e9ecef; 
        font-size: 14px; 
        font-weight: 500;
        font-family: inherit; 
        resize: none; 
        padding: 8px 10px; 
        border: 1px solid #ccc; 
        border-radius: 4px; 
        box-sizing: border-box;
        color: #333;
        line-height: 1.3;
        margin: 0 !important;
        vertical-align: top !important;
    }
    
    /* Toggle Switch Styling */
    .switch { position: relative; display: inline-block; width: 34px; height: 20px; margin: 0; }
    .switch input { opacity: 0; width: 0; height: 0; }
    .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .3s; border-radius: 20px; }
    .slider:before { position: absolute; content: ""; height: 14px; width: 14px; left: 3px; bottom: 3px; background-color: white; transition: .3s; border-radius: 50%; }
    input:checked + .slider { background-color: #28a745; }
    input:checked + .slider:before { transform: translateX(14px); }
    input:disabled + .slider { background-color: #e9ecef; cursor: not-allowed; }

    /* Connection Status Light Indicator */
    .status-light {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        display: inline-block;
        margin-left: 6px;
        vertical-align: middle;
    }
    .status-light.connected { background-color: #28a745; box-shadow: 0 0 6px #28a745; }
    .status-light.disconnected { background-color: #dc3545; box-shadow: 0 0 6px #dc3545; }
    .status-light.disabled { background-color: #6c757d; opacity: 0.3; }

    .spec-note { background: #e7f3fe; border-left: 4px solid #2196F3; padding: 8px 12px; font-size: 12px; margin-top: 5px; border-radius: 2px; color: #0c5460; }
    .warning-box { background: #f8d7da; border-left: 4px solid #dc3545; padding: 10px 14px; font-size: 13px; margin-top: 10px; border-radius: 2px; color: #721c24; font-weight: bold; }
    .flush-banner { background: #fff3cd; border: 1px solid #ffeeba; border-left: 4px solid #ffc107; padding: 12px; margin-bottom: 15px; border-radius: 4px; color: #856404; }

    .noUi-connect { background: #007bff; }
    .noUi-horizontal { height: 12px; }
    .noUi-handle { height: 22px !important; width: 22px !important; top: -6px !important; border-radius: 50%; }
    .noUi-handle:after, .noUi-handle:before { display: none; }
</style>

<!-- ============================================================================ -->
<!-- JAVASCRIPT CONTROLLERS                                                       -->
<!-- ============================================================================ -->

<script>
function toggleOvpnState(ext, mac, enable) {
    if (!ext) {
        alert("Please assign an extension to this device first.");
        document.getElementById('vpn_toggle_' + mac).checked = !enable;
        return;
    }

    var toggleElem = document.getElementById('vpn_toggle_' + mac);
    var statusLight = document.getElementById('vpn_status_light_' + mac);
    toggleElem.disabled = true;

    var formData = new FormData();
    formData.append('ext', ext);
    formData.append('mac', mac);
    formData.append('enable', enable ? '1' : '0');

    fetch('?display=yealink_epm&action=toggle_ovpn_state', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        toggleElem.disabled = false;
        if (data.status !== 'success') {
            alert(data.message || 'Error updating VPN state.');
            toggleElem.checked = !enable;
        } else {
            // Update the status light state in the DOM without reloading
            if (statusLight) {
                if (enable) {
                    // Check if backend returned active status, fallback to disconnected until daemon handshakes
                    var isConnected = data.connected || false;
                    statusLight.className = 'status-light ' + (isConnected ? 'connected' : 'disconnected');
                    statusLight.title = isConnected ? 'VPN Connected' : 'VPN Disconnected';
                } else {
                    statusLight.className = 'status-light disabled';
                    statusLight.title = 'VPN Disabled';
                }
            }
        }
    })
    .catch(() => {
        toggleElem.disabled = false;
        toggleElem.checked = !enable;
        alert('Communication error with FreePBX backend.');
    });
}
</script>

<script>
    var scannedDeviceMacs = [];
    var ringtoneFileSizes = <?= json_encode($ringtone_file_sizes) ?>;
    var initialRingtoneStates = {};
    var assignedRingtoneReferences = <?= json_encode($assigned_ringtone_references) ?>;
    var initialShowFlushBtn = <?= json_encode($show_flush_ringtone_btn) ?>;
    var activeTrimFile = null;
    var sliderInstance = null;

    var yealinkModelSpecs = {
        "manual": { ringFormats: ".wav, .mp3", ringSize: "100KB - 2MB", maxRingtone: "10+", totalLimit: 10485760, logoFormat: ".dob, .bmp, .jpg, .png", logoRes: "Variable", logoSize: "Max 2MB" },
        "T19P":   { ringFormats: ".wav, .mp3", ringSize: "Max 100KB", maxRingtone: "5", totalLimit: 102400, logoFormat: "Monochrome BMP", logoRes: "132 x 64", logoSize: "Max 20KB" },
        "T21P":   { ringFormats: ".wav, .mp3", ringSize: "Max 100KB", maxRingtone: "5", totalLimit: 102400, logoFormat: "Monochrome BMP", logoRes: "132 x 64", logoSize: "Max 20KB" },
        "T23G":   { ringFormats: ".wav, .mp3", ringSize: "Max 100KB", maxRingtone: "5", totalLimit: 102400, logoFormat: "Monochrome BMP", logoRes: "132 x 64", logoSize: "Max 20KB" },
        "T27G":   { ringFormats: ".wav, .mp3", ringSize: "Max 100KB", maxRingtone: "10", totalLimit: 102400, logoFormat: "Monochrome BMP", logoRes: "240 x 120", logoSize: "Max 30KB" },
        "T28P":   { ringFormats: ".wav, .mp3", ringSize: "Max 100KB", maxRingtone: "10", totalLimit: 102400, logoFormat: ".dob / Monochromic BMP", logoRes: "320 x 160", logoSize: "Max 30KB" },
        "T29G":   { ringFormats: ".wav, .mp3", ringSize: "Max 2MB", maxRingtone: "10", totalLimit: 20971520, logoFormat: ".jpg, .png, .bmp", logoRes: "480 x 272", logoSize: "Max 2MB" },
        "T30":    { ringFormats: ".wav, .mp3", ringSize: "Max 100KB", maxRingtone: "5", totalLimit: 102400, logoFormat: "Monochrome BMP", logoRes: "132 x 64", logoSize: "Max 20KB" },
        "T31G":   { ringFormats: ".wav, .mp3", ringSize: "Max 100KB", maxRingtone: "5", totalLimit: 102400, logoFormat: "Monochrome BMP", logoRes: "132 x 64", logoSize: "Max 20KB" },
        "T33G":   { ringFormats: ".wav, .mp3", ringSize: "Max 2MB", maxRingtone: "10", totalLimit: 10485760, logoFormat: ".jpg, .png, .bmp", logoRes: "320 x 240", logoSize: "Max 2MB" },
        "T40P":   { ringFormats: ".wav, .mp3", ringSize: "Max 100KB", maxRingtone: "5", totalLimit: 102400, logoFormat: "Monochrome BMP", logoRes: "132 x 64", logoSize: "Max 20KB" },
        "T41S":   { ringFormats: ".wav, .mp3", ringSize: "Max 100KB", maxRingtone: "10", totalLimit: 102400, logoFormat: "Monochrome BMP", logoRes: "192 x 64", logoSize: "Max 30KB" },
        "T42S":   { ringFormats: ".wav, .mp3", ringSize: "Max 100KB", maxRingtone: "10", totalLimit: 102400, logoFormat: "Monochrome BMP", logoRes: "192 x 64", logoSize: "Max 30KB" },
        "T43U":   { ringFormats: ".wav, .mp3", ringSize: "Max 300KB", maxRingtone: "10", totalLimit: 307200, logoFormat: "Monochrome BMP", logoRes: "370 x 160", logoSize: "Max 50KB" },
        "T46S":   { ringFormats: ".wav, .mp3", ringSize: "Max 2MB", maxRingtone: "10", totalLimit: 20971520, logoFormat: ".jpg, .png, .bmp", logoRes: "480 x 272", logoSize: "Max 2MB" },
        "T48S":   { ringFormats: ".wav, .mp3", ringSize: "Max 2MB", maxRingtone: "10", totalLimit: 20971520, logoFormat: ".jpg, .png, .bmp", logoRes: "800 x 480", logoSize: "Max 2MB" },
        "T53W":   { ringFormats: ".wav, .mp3", ringSize: "Max 300KB", maxRingtone: "10", totalLimit: 307200, logoFormat: "Monochrome BMP", logoRes: "370 x 160", logoSize: "Max 50KB" },
        "T54W":   { ringFormats: ".wav, .mp3", ringSize: "Max 2MB", maxRingtone: "10", totalLimit: 20971520, logoFormat: ".jpg, .png, .bmp", logoRes: "480 x 272", logoSize: "Max 2MB" },
        "T57W":   { ringFormats: ".wav, .mp3", ringSize: "Max 2MB", maxRingtone: "10", totalLimit: 20971520, logoFormat: ".jpg, .png, .bmp", logoRes: "800 x 480", logoSize: "Max 2MB" },
        "T58A":   { ringFormats: ".wav, .mp3", ringSize: "Max 5MB", maxRingtone: "15", totalLimit: 20971520, logoFormat: ".jpg, .png, .bmp", logoRes: "1024 x 600", logoSize: "Max 5MB" },
        "VP59":   { ringFormats: ".wav, .mp3", ringSize: "Max 5MB", maxRingtone: "15", totalLimit: 20971520, logoFormat: ".jpg, .png, .bmp", logoRes: "1280 x 800", logoSize: "Max 5MB" }
    };

    function openViewConfigModal(mac) {
        var titleElem = document.getElementById('view_cfg_mac_title');
        var contentElem = document.getElementById('view_cfg_content');
        
        titleElem.innerText = mac.toLowerCase() + '.cfg';
        contentElem.value = 'Loading configuration file...';
        document.getElementById('viewConfigModal').style.display = 'block';

        fetch('?display=yealink_epm&action=view_mac_cfg&mac=' + encodeURIComponent(mac))
            .then(function(response) { return response.text(); })
            .then(function(text) {
                contentElem.value = text;
            })
            .catch(function(err) {
                contentElem.value = 'Error loading configuration file.';
            });
    }

    function closeViewConfigModal() {
        document.getElementById('viewConfigModal').style.display = 'none';
    }

    function downloadSelectedTemplate() {
        var sel = document.getElementById('select_template_file');
        var val = sel ? sel.value : '';
        if (!val) {
            alert("Please select a template to download first.");
            return;
        }
        window.location.href = window.location.pathname + '?display=yealink_epm&action=download_template&file=' + encodeURIComponent(val);
    }

    function enforceUniqueExtensionSelections() {
        var selects = document.querySelectorAll('select[name^="phone_extension"], select[id^="scan_ext_"], #manual_ext');
        var selectedValues = [];

        selects.forEach(function(sel) {
            if (sel.value && sel.value !== '') {
                selectedValues.push(sel.value);
            }
        });

        selects.forEach(function(sel) {
            var currentVal = sel.value;
            Array.from(sel.options).forEach(function(opt) {
                if (!opt.value) return;
                if (opt.value !== currentVal && selectedValues.includes(opt.value)) {
                    opt.disabled = true;
                    opt.style.color = '#bbb';
                } else {
                    opt.disabled = false;
                    opt.style.color = '';
                }
            });
        });
    }

    function formatBytes(bytes) {
        if (bytes === 0) return '0 KB';
        var k = 1024;
        var sizes = ['Bytes', 'KB', 'MB'];
        var i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }

    function toggleAudioLoop(playerId, shouldLoop) {
        var player = document.getElementById(playerId);
        if (player) {
            player.loop = shouldLoop;
        }
    }

    function editUploadedRingtone(filename) {
        var streamUrl = '?display=yealink_epm&action=stream_ringtone&file=' + encodeURIComponent(filename);
        fetch(streamUrl)
            .then(function(res) {
                if (!res.ok) throw new Error("HTTP error " + res.status);
                return res.blob();
            })
            .then(function(blob) {
                var fileObj = new File([blob], filename, { type: 'audio/wav' });
                openAudioTrimmerModal(fileObj);
            })
            .catch(function(err) {
                alert("Failed to fetch ringtone for editing.");
            });
    }

    function addRingtoneToDOM(filename, filesize) {
        var container = document.querySelector('.ringtone-list-container');
        if (!container) return;

        var emptyText = container.querySelector('p');
        if (emptyText && emptyText.innerText.indexOf('No custom ringtones') !== -1) {
            container.innerHTML = '';
        }

        var existingBox = container.querySelector('input[value="' + filename + '"]');
        if (existingBox) {
            existingBox.checked = true;
            return;
        }

        var sizeFormatted = (filesize > 0) ? (filesize / 1024).toFixed(1) + ' KB' : '0 KB';
        var downloadUrl = '?display=yealink_epm&action=download_ringtone&file=' + encodeURIComponent(filename);
        var streamUrl   = '?display=yealink_epm&action=stream_ringtone&file=' + encodeURIComponent(filename);
        var cleanId     = filename.replace(/[^a-zA-Z0-9]/g, '_');
        
        var gridItem = document.createElement('div');
        gridItem.className = 'ringtone-grid-item';
        gridItem.innerHTML = `
            <label style="font-weight:normal; margin:0; display:flex; align-items:center;">
                <input type="checkbox" name="uploaded_ringtones[]" value="${filename}" checked onchange="syncRingtoneOptions(this, '${filename}')">
                <span style="margin-left:8px; font-weight:500; text-overflow:ellipsis; overflow:hidden; white-space:nowrap;">${filename}</span>
            </label>
            <div>
                <span class="ringtone-size-badge">(${sizeFormatted})</span>
            </div>
            <div class="ringtone-player-controls">
                <audio id="audio_player_${cleanId}" controls preload="none" controlsList="nodownload">
                    <source src="${streamUrl}" type="audio/wav">
                    <source src="${streamUrl}" type="audio/mpeg">
                </audio>
            </div>
            <div style="display:flex; align-items:center; gap:4px;">
                <label style="font-size:11px; font-weight:600; color:#555; cursor:pointer; margin:0; display:inline-flex; align-items:center; gap:3px;">
                    <input type="checkbox" onchange="toggleAudioLoop('audio_player_${cleanId}', this.checked)"> Loop
                </label>
            </div>
            <div style="display:flex; align-items:center; gap:2px;">
                <button type="button" class="action-icon-btn" title="Trim / Edit ${filename}" onclick="editUploadedRingtone('${filename}')">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="6" cy="6" r="3"></circle>
                        <circle cx="6" cy="18" r="3"></circle>
                        <line x1="20" y1="4" x2="8.12" y2="15.88"></line>
                        <line x1="14.47" y1="14.47" x2="20" y2="20"></line>
                        <line x1="8.12" y1="8.12" x2="12" y2="12"></line>
                    </svg>
                </button>
                <a href="${downloadUrl}" class="action-icon-btn" title="Download ${filename}">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="7 10 12 15 17 10"></polyline>
                        <line x1="12" y1="15" x2="12" y2="3"></line>
                    </svg>
                </a>
                <button type="button" class="action-icon-btn delete-icon" title="Delete ${filename}" onclick="confirmDeleteFile('${filename}', 'ringtone')">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="3 6 5 6 21 6"></polyline>
                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                        <line x1="10" y1="11" x2="10" y2="17"></line>
                        <line x1="14" y1="11" x2="14" y2="17"></line>
                    </svg>
                </button>
            </div>
        `;

        container.appendChild(gridItem);

        var selectElem = document.getElementById('account_ringtone_select');
        if (selectElem) {
            var optGroup = selectElem.querySelector('optgroup[label="Uploaded Custom Ringtones"]');
            if (!optGroup) {
                optGroup = document.createElement('optgroup');
                optGroup.label = 'Uploaded Custom Ringtones';
                selectElem.appendChild(optGroup);
            }
            var optId = 'opt_custom_' + cleanId;
            if (!document.getElementById(optId)) {
                var opt = document.createElement('option');
                opt.id = optId;
                opt.value = filename;
                opt.innerText = 'Custom: ' + filename;
                optGroup.appendChild(opt);
            }
        }
    }

    function openAudioTrimmerModal(file) {
        activeTrimFile = file;
        document.getElementById('trimmer_filename').innerText = file.name;

        var audioPlayer = document.getElementById('trimmer_audio_player');
        audioPlayer.src = URL.createObjectURL(file);

        audioPlayer.onloadedmetadata = function() {
            var totalDuration = audioPlayer.duration;
            var sliderElem = document.getElementById('audio_trim_slider');

            if (sliderInstance) {
                sliderInstance.destroy();
            }

            sliderInstance = noUiSlider.create(sliderElem, {
                start: [0, totalDuration],
                connect: true,
                range: {
                    'min': 0,
                    'max': totalDuration
                },
                step: 0.1
            });

            sliderInstance.on('update', function(values) {
                var start = parseFloat(values[0]);
                var end = parseFloat(values[1]);
                var dur = end - start;

                document.getElementById('trim_start_val').innerText = start.toFixed(1);
                document.getElementById('trim_end_val').innerText = end.toFixed(1);
                document.getElementById('trim_duration_val').innerText = dur.toFixed(1);
            });

            document.getElementById('trimmerModal').style.display = 'block';
        };
    }

    function closeTrimmerModal() {
        document.getElementById('trimmerModal').style.display = 'none';
        var audioPlayer = document.getElementById('trimmer_audio_player');
        audioPlayer.pause();
        audioPlayer.src = '';
    }

    function previewCroppedAudio() {
        var audioPlayer = document.getElementById('trimmer_audio_player');
        var sliderValues = sliderInstance.get();
        var startTime = parseFloat(sliderValues[0]);
        var endTime = parseFloat(sliderValues[1]);

        audioPlayer.currentTime = startTime;
        audioPlayer.play();

        var checkTime = function() {
            if (audioPlayer.currentTime >= endTime) {
                audioPlayer.pause();
                audioPlayer.removeEventListener('timeupdate', checkTime);
            }
        };
        audioPlayer.addEventListener('timeupdate', checkTime);
    }

    function submitAudioCrop() {
        var sliderValues = sliderInstance.get();
        var startTime = parseFloat(sliderValues[0]);
        var duration = parseFloat(sliderValues[1]) - startTime;

        var formData = new FormData();
        formData.append('single_ringtone_ajax', '1');
        formData.append('ringtone_file', activeTrimFile);
        formData.append('start_time', startTime);
        formData.append('duration', duration);

        var uploadBtn = document.getElementById('async_upload_btn');
        uploadBtn.disabled = true;
        uploadBtn.innerText = 'Processing Crop...';

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                ringtoneFileSizes[data.filename] = data.size;
                addRingtoneToDOM(data.filename, data.size);
                calculateTotalRingtonePayloadSize();
                closeTrimmerModal();
                
                uploadBtn.disabled = false;
                uploadBtn.innerText = 'Upload Ringtones';

                var fileInput = document.getElementById('ringtone_file_input');
                fileInput.value = '';
                var textarea = document.getElementById('selected_files_textarea');
                if (textarea) {
                    textarea.value = '';
                    textarea.style.display = 'none';
                }
            } else {
                alert('Error cropping audio: ' + data.message);
                uploadBtn.disabled = false;
                uploadBtn.innerText = 'Upload Ringtones';
            }
        })
        .catch(err => {
            alert('Error processing audio request.');
            uploadBtn.disabled = false;
            uploadBtn.innerText = 'Upload Ringtones';
        });
    }

    function uploadRingtonesAsync(event) {
        if (event) event.preventDefault();
        
        var fileInput = document.getElementById('ringtone_file_input');
        var files = fileInput.files;
        
        if (!files || files.length === 0) {
            alert('Please select files first using the Browse button.');
            return;
        }

        if (files.length === 1) {
            openAudioTrimmerModal(files[0]);
            return;
        }

        var uploadBtn = document.getElementById('async_upload_btn');
        uploadBtn.disabled = true;
        uploadBtn.innerText = 'Uploading & Converting (0/' + files.length + ')...';

        var uploadQueue = Array.from(files);
        var totalFiles = files.length;
        var completed = 0;

        function processNext() {
            if (uploadQueue.length === 0) {
                uploadBtn.innerText = 'Upload Complete!';
                
                fileInput.value = '';
                var textarea = document.getElementById('selected_files_textarea');
                if (textarea) {
                    textarea.value = '';
                    textarea.style.display = 'none';
                }

                setTimeout(function() {
                    uploadBtn.disabled = false;
                    uploadBtn.innerText = 'Upload Ringtones';
                }, 1500);
                return;
            }

            var file = uploadQueue.shift();
            var formData = new FormData();
            formData.append('single_ringtone_ajax', '1');
            formData.append('ringtone_file', file);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.status === 'success') {
                    completed++;
                    uploadBtn.innerText = 'Uploading & Converting (' + completed + '/' + totalFiles + ')...';
                    ringtoneFileSizes[data.filename] = data.size;
                    
                    addRingtoneToDOM(data.filename, data.size);
                    calculateTotalRingtonePayloadSize();
                    
                    processNext();
                } else {
                    alert('Upload failed for ' + file.name + ': ' + data.message);
                    uploadBtn.disabled = false;
                    uploadBtn.innerText = 'Upload Ringtones';
                }
            })
            .catch(function(err) {
                alert('Error uploading ' + file.name + '.');
                uploadBtn.disabled = false;
                uploadBtn.innerText = 'Upload Ringtones';
            });
        }

        processNext();
    }

    function confirmDeleteFile(filename, fileType) {
        if (!filename || filename === 'system') {
            alert("Please select a valid file to delete.");
            return false;
        }
        if (confirm("Are you sure you want to permanently delete '" + filename + "' from the server?")) {
            var targetForm = document.getElementById('delete_file_form');
            document.getElementById('target_filename').value = filename;
            document.getElementById('target_file_type').value = fileType;
            
            if (['ringtone', 'logo', 'template'].includes(fileType)) {
                document.getElementById('delete_active_tab').value = 'tab_template';
                targetForm.action = window.location.pathname + '?display=yealink_epm#ringtone_section';
            }
            
            targetForm.submit();
        }
    }

    function calculateTotalRingtonePayloadSize() {
        var model = document.getElementById('select_phone_model').value;
        var spec = yealinkModelSpecs[model] || yealinkModelSpecs["manual"];
        
        var totalBytes = 0;
        var checkedBoxes = document.querySelectorAll('input[name="uploaded_ringtones[]"]:checked');
        
        checkedBoxes.forEach(function(cb) {
            var fileName = cb.value;
            if (ringtoneFileSizes[fileName]) {
                totalBytes += ringtoneFileSizes[fileName];
            }
        });

        var displayTotal = formatBytes(totalBytes);
        var displayLimit = formatBytes(spec.totalLimit);

        var sizeSummaryElem = document.getElementById('ringtone_payload_summary');
        if (sizeSummaryElem) {
            sizeSummaryElem.innerHTML = `Total Selected Payload: <b>${displayTotal}</b> / Allowed: <b>${displayLimit}</b>`;
        }

        var warnElem = document.getElementById('ringtone_overlimit_warning');
        var submitBtn = document.getElementById('save_template_btn');

        if (totalBytes > spec.totalLimit) {
            if (warnElem) {
                warnElem.style.display = 'block';
                warnElem.innerHTML = `&#9888; Warning: The combined size of checked ringtones (${displayTotal}) exceeds the maximum total memory limit for model ${model} (${displayLimit}). Uncheck some files before saving.`;
            }
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.5';
                submitBtn.style.cursor = 'not-allowed';
            }
        } else {
            if (warnElem) {
                warnElem.style.display = 'none';
            }
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.style.opacity = '1';
                submitBtn.style.cursor = 'pointer';
            }
        }
    }

    function updateModelSpecsInfo(model) {
        var spec = yealinkModelSpecs[model] || yealinkModelSpecs["manual"];
        
        var rNote = document.getElementById('ringtone_spec_note');
        if (rNote) {
            rNote.innerHTML = `<strong>Model Specs (${model}):</strong> Formats: <b>.wav, .mp3 (Auto-converted to 8kHz pcmu)</b> | Max File Size: <b>${spec.ringSize}</b> | Max Slots: <b>${spec.maxRingtone}</b> | Total Allowance: <b>${formatBytes(spec.totalLimit)}</b>`;
        }

        var lNote = document.getElementById('logo_spec_note');
        if (lNote) {
            lNote.innerHTML = `<strong>Model Specs (${model}):</strong> Formats: <b>${spec.logoFormat}</b> | Resolution: <b>${spec.logoRes}</b> | Max Size: <b>${spec.logoSize}</b>`;
        }

        var ringInput = document.getElementById('ringtone_file_input');
        if (ringInput) {
            ringInput.setAttribute('accept', '.wav,.mp3');
        }

        calculateTotalRingtonePayloadSize();
    }

    function updateVerticalFileList(input) {
        var displayBox = document.getElementById('selected_files_textarea');
        if (!input.files || input.files.length === 0) {
            displayBox.value = '';
            displayBox.style.display = 'none';
            return;
        }
        var names = [];
        for (var i = 0; i < input.files.length; i++) {
            var file = input.files[i];
            names.push(file.name);
            ringtoneFileSizes[file.name] = file.size;
        }
        displayBox.value = names.join('\n');
        displayBox.style.display = 'inline-block';
        displayBox.rows = Math.min(names.length, 6);
        calculateTotalRingtonePayloadSize();
    }

    function switchTab(tabId) {
        document.querySelectorAll('.gen-tab-content').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.gen-tab-btn').forEach(el => el.classList.remove('active'));
        document.getElementById(tabId).classList.add('active');
        document.getElementById('btn_' + tabId).classList.add('active');
        document.getElementById('active_tab_field').value = tabId;
        document.getElementById('device_active_tab_field').value = tabId;
        var loadTplTab = document.getElementById('load_tpl_active_tab_field');
        if (loadTplTab) { loadTplTab.value = tabId; }
    }

    function toggleSelectAllPhones(master) {
        var checkboxes = document.querySelectorAll('.phone_checkbox');
        checkboxes.forEach(cb => cb.checked = master.checked);
    }

    function enableMacEdit(mac) {
        var inputElem = document.getElementById('mac_input_' + mac);
        if (inputElem) {
            inputElem.readOnly = false;
            inputElem.style.backgroundColor = '#ffffff';
            inputElem.style.borderColor = '#007bff';
            inputElem.focus();
        }
    }

    function applyBulkTemplateToScanned(selectedTpl) {
        if (!selectedTpl) return;
        scannedDeviceMacs.forEach(mac => {
            var selectElem = document.getElementById('scan_tpl_' + mac);
            if (selectElem) {
                selectElem.value = selectedTpl;
            }
        });
    }

    function triggerDeviceAction(actionName) {
        document.getElementById('device_action_input').value = actionName;
        document.getElementById('device_manager_form').submit();
    }

    function openSingleRebuildModal(mac, model, template) {
        document.getElementById('single_mac_input').value = mac.toLowerCase();
        document.getElementById('single_rebuild_mac_title').innerText = mac.toLowerCase();
        
        var modelSelect = document.getElementById('single_model_select');
        if (modelSelect) modelSelect.value = model || 'manual';

        var tplSelect = document.getElementById('single_template_select');
        if (tplSelect) tplSelect.value = template || '';

        document.getElementById('singleRebuildModal').style.display = 'block';
    }

    function closeSingleRebuildModal() {
        document.getElementById('singleRebuildModal').style.display = 'none';
    }

    function submitSingleRebuildModal() {
        var mac = document.getElementById('single_mac_input').value.toLowerCase();
        var selectedTpl = document.getElementById('single_template_select').value;
        
        var phoneTplElem = document.getElementById('phone_tpl_' + mac);
        if (phoneTplElem) phoneTplElem.value = selectedTpl;

        document.getElementById('device_action_input').value = 'single_rebuild';
        document.getElementById('device_manager_form').submit();
    }

    function confirmDeleteGlobalConfig() {
        if (confirm("Are you sure you want to permanently delete y000000000000.cfg from /tftpboot/?")) {
            document.getElementById('target_filename').value = "y000000000000.cfg";
            document.getElementById('target_file_type').value = "global";
            document.getElementById('delete_file_form').submit();
        }
    }

    function updateDialnowVisibility(count) {
        var num = parseInt(count, 10) || 1;
        for (var i = 1; i <= 20; i++) {
            var slot = document.getElementById('dialnow_slot_' + i);
            if (slot) {
                slot.style.display = (i <= num) ? 'block' : 'none';
            }
        }
    }

    function updateLinekeyVisibility(count) {
        var num = parseInt(count, 10) || 1;
        for (var i = 1; i <= 29; i++) {
            var row = document.getElementById('linekey_row_' + i);
            if (row) {
                row.style.display = (i <= num) ? 'flex' : 'none';
            }
        }
    }

    function updateMemkeyVisibility(count) {
        var num = parseInt(count, 10) || 0;
        for (var i = 1; i <= 180; i++) {
            var row = document.getElementById('memkey_row_' + i);
            if (row) {
                row.style.display = (i <= num) ? 'flex' : 'none';
            }
        }
    }

    function calculateTotalMemoryKeys() {
        var baseMem = parseInt(document.getElementById('field_base_mem_keys').value) || 0;
        var expModel = document.getElementById('select_exp_model').value;
        var expQty = parseInt(document.getElementById('select_exp_count').value) || 0;
        
        var expKeysPerUnit = 0;
        if (expModel === 'EXP20') expKeysPerUnit = 20;
        if (expModel === 'EXP40') expKeysPerUnit = 40;
        if (expModel === 'EXP50') expKeysPerUnit = 60;

        var totalMemKeys = baseMem + (expKeysPerUnit * expQty);
        var memElem = document.getElementById('select_memkey_count');
        if (memElem) {
            memElem.value = totalMemKeys;
            updateMemkeyVisibility(totalMemKeys);
        }
    }

    function handleModelSelect(model) {
        var lineElem = document.getElementById('select_linekey_count');
        var baseMemElem = document.getElementById('field_base_mem_keys');
        
        var modelSpecs = {
            "T19P":  { lines: 1,  mem: 0 },
            "T21P":  { lines: 2,  mem: 0 },
            "T23G":  { lines: 3,  mem: 0 },
            "T27G":  { lines: 21, mem: 0 },
            "T28P":  { lines: 6,  mem: 10 },
            "T29G":  { lines: 27, mem: 0 },
            "T30":   { lines: 1,  mem: 0 },
            "T31G":  { lines: 2,  mem: 0 },
            "T33G":  { lines: 4,  mem: 0 },
            "T40P":  { lines: 3,  mem: 0 },
            "T41S":  { lines: 15, mem: 0 },
            "T42S":  { lines: 15, mem: 0 },
            "T43U":  { lines: 21, mem: 0 },
            "T46S":  { lines: 27, mem: 0 },
            "T48S":  { lines: 29, mem: 0 },
            "T53W":  { lines: 21, mem: 0 },
            "T54W":  { lines: 27, mem: 0 },
            "T57W":  { lines: 29, mem: 0 },
            "T58A":  { lines: 27, mem: 0 },
            "VP59":  { lines: 27, mem: 0 }
        };

        if (modelSpecs[model]) {
            if (lineElem && modelSpecs[model].lines >= 0) {
                lineElem.value = modelSpecs[model].lines;
                updateLinekeyVisibility(modelSpecs[model].lines);
            }
            if (baseMemElem && modelSpecs[model].mem >= 0) {
                baseMemElem.value = modelSpecs[model].mem;
            }
            calculateTotalMemoryKeys();
        }
        updateModelSpecsInfo(model);
    }

    function handleExpSelect() {
        calculateTotalMemoryKeys();
    }

    function syncRingtoneOptions(cb, filename) {
        var optElem = document.getElementById('opt_custom_' + filename.replace(/[^a-zA-Z0-9]/g, '_'));
        var selectElem = document.getElementById('account_ringtone_select');
        
        if (optElem && selectElem) {
            if (cb.checked) {
                optElem.disabled = false;
                optElem.style.display = 'block';
            } else {
                if (selectElem.value === filename) {
                    selectElem.value = 'Common';
                }
                optElem.disabled = true;
                optElem.style.display = 'none';
            }
        }
        
        checkUncheckedRingtonesState();
        calculateTotalRingtonePayloadSize();
    }

    function checkUncheckedRingtonesState() {
        var requiresFlush = false;

        document.querySelectorAll('input[name="uploaded_ringtones[]"]').forEach(function(cb) {
            if (!cb.checked && initialRingtoneStates[cb.value] === true) {
                requiresFlush = true;
            }
        });

        var flushBannerContainer = document.getElementById('flush_banner_container');
        if (flushBannerContainer) {
            flushBannerContainer.style.display = requiresFlush ? "block" : "none";
        }
    }

    function openScanModal() {
        document.getElementById('scanModal').style.display = 'block';
    }

    function closeScanModal() {
        document.getElementById('scanModal').style.display = 'none';
        window.location.href = window.location.pathname + '?display=yealink_epm&_r=' + Date.now() + '#tab_devices';
    }

    function openManualAddModal() {
        document.getElementById('manual_mac').value = '';
        document.getElementById('manual_ext').value = '';
        document.getElementById('manual_tpl').value = '';
        document.getElementById('manualAddModal').style.display = 'block';
        enforceUniqueExtensionSelections();
    }

    function closeManualAddModal() {
        document.getElementById('manualAddModal').style.display = 'none';
        window.location.href = window.location.pathname + '?display=yealink_epm&_r=' + Date.now() + '#tab_devices';
    }

    function submitManualAddDevice() {
        var rawMac = document.getElementById('manual_mac').value.replace(/[^a-fA-F0-9]/g, '').toLowerCase();
        var extVal = document.getElementById('manual_ext').value;
        var tplVal = document.getElementById('manual_tpl').value;
        var autoProvision = document.getElementById('manual_provision').checked ? '1' : '0';
        var btn = document.getElementById('manual_add_btn');

        if (rawMac.length !== 12) {
            alert('Please enter a valid 12-character MAC address.');
            return;
        }

        btn.disabled = true;
        btn.innerText = 'Creating...';

        var formData = new FormData();
        formData.append('scanned_mac', rawMac);
        formData.append('scanned_ext', extVal);
        formData.append('scanned_template', tplVal);
        formData.append('auto_provision', autoProvision);

        fetch('?display=yealink_epm&action=add_scanned_device', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(text => {
            var data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                data = { status: 'success' };
            }

            if (data.status === 'success') {
                btn.innerText = 'Created!';
                btn.style.background = '#6c757d';
                setTimeout(() => { closeManualAddModal(); }, 600);
            } else {
                alert(data.message || 'Error adding device.');
                btn.disabled = false;
                btn.innerText = 'Create Device Config';
                btn.style.background = '#28a745';
            }
        })
        .catch(err => {
            alert('Request failed.');
            btn.disabled = false;
            btn.innerText = 'Create Device Config';
            btn.style.background = '#28a745';
        });
    }

    function runSubnetScan() {
        var subnet = document.getElementById('scan_subnet').value;
        var tbody = document.getElementById('scan_results_body');
        scannedDeviceMacs = [];
        tbody.innerHTML = '<tr><td colspan="6">Scanning subnet asynchronously... Please wait...</td></tr>';
        
        fetch('?display=yealink_epm&action=scan_network&subnet=' + encodeURIComponent(subnet))
            .then(response => response.json())
            .then(data => {
                tbody.innerHTML = '';
                if (!data.devices || data.devices.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="6">No unconfigured Yealink devices found on subnet.</td></tr>';
                    return;
                }
                data.devices.forEach(dev => {
                    scannedDeviceMacs.push(dev.mac);
                    var extOptions = `<option value="">-- Unassigned --</option>`;
                    <?php foreach ($available_extensions as $ext_id => $ext_data): ?>
                        extOptions += `<option value="<?= $ext_id ?>"><?= $ext_id ?> - <?= htmlspecialchars($ext_data['display_name']) ?></option>`;
                    <?php endforeach; ?>

                    var tplOptions = `<option value="">-- None --</option>`;
                    <?php foreach ($available_templates as $tpl_file => $tpl_label): ?>
                        tplOptions += `<option value="<?= htmlspecialchars($tpl_file) ?>"><?= htmlspecialchars($tpl_label) ?></option>`;
                    <?php endforeach; ?>

                    var row = `<tr id="scan_row_${dev.mac}">
                        <td style="vertical-align:middle;">${dev.ip}</td>
                        <td style="vertical-align:middle;"><b>${dev.mac}</b></td>
                        <td>
                            <select id="scan_ext_${dev.mac}" style="padding:4px; max-width:180px;">${extOptions}</select>
                        </td>
                        <td>
                            <select id="scan_tpl_${dev.mac}" style="padding:4px;">${tplOptions}</select>
                        </td>
                        <td style="text-align:center; vertical-align:middle;">
                            <input type="checkbox" id="scan_provision_${dev.mac}" checked title="Push config & auto-provision phone immediately">
                        </td>
                        <td>
                            <button type="button" id="scan_btn_${dev.mac}" class="gen-btn" style="margin-top:0; padding:6px 12px; background:#28a745;" onclick="submitAddScannedDevice('${dev.mac}')">+ Add Device</button>
                        </td>
                    </tr>`;
                    tbody.innerHTML += row;
                });
                enforceUniqueExtensionSelections();
            })
            .catch(err => {
                tbody.innerHTML = '<tr><td colspan="6">Scan failed or timed out.</td></tr>';
            });
    }

    function submitAddScannedDevice(mac) {
        var extVal = document.getElementById('scan_ext_' + mac).value;
        var tplVal = document.getElementById('scan_tpl_' + mac).value;
        var autoProvision = document.getElementById('scan_provision_' + mac).checked ? '1' : '0';
        var btn = document.getElementById('scan_btn_' + mac);
        
        btn.disabled = true;
        btn.innerText = 'Adding...';

        var formData = new FormData();
        formData.append('scanned_mac', mac.toLowerCase());
        formData.append('scanned_ext', extVal);
        formData.append('scanned_template', tplVal);
        formData.append('auto_provision', autoProvision);

        return fetch('?display=yealink_epm&action=add_scanned_device', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(text => {
            var data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                data = { status: 'success' };
            }

            if (data.status === 'success') {
                var row = document.getElementById('scan_row_' + mac);
                if (row) row.style.background = '#d4edda';
                btn.innerHTML = 'Added &#10003;';
                btn.style.background = '#6c757d';
                enforceUniqueExtensionSelections();
            } else {
                alert(data.message || 'Error adding device.');
                btn.disabled = false;
                btn.innerText = 'Error! Try Again';
                btn.style.background = '#dc3545';
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerText = 'Error! Try Again';
            btn.style.background = '#dc3545';
        });
    }

    function submitAddAllScannedDevices() {
        if (scannedDeviceMacs.length === 0) {
            alert('No devices available to add.');
            return;
        }

        var btn = document.getElementById('add_all_btn');
        btn.disabled = true;
        btn.innerText = 'Adding All...';

        var promises = scannedDeviceMacs.map(mac => submitAddScannedDevice(mac));
        
        Promise.all(promises).then(() => {
            btn.innerText = 'Done!';
            setTimeout(() => {
                closeScanModal();
            }, 300);
        });
    }

    document.addEventListener('change', function(e) {
        if (e.target && (e.target.name?.startsWith('phone_extension') || e.target.id?.startsWith('scan_ext_') || e.target.id === 'manual_ext')) {
            enforceUniqueExtensionSelections();
        }
    });

    document.addEventListener("DOMContentLoaded", function() {
        document.querySelectorAll('input[name="uploaded_ringtones[]"]').forEach(function(cb) {
            initialRingtoneStates[cb.value] = cb.checked;
        });

        checkUncheckedRingtonesState();

        if (window.location.hash === '#tab_devices' || '<?= $formData['active_tab'] ?>' === 'tab_devices') {
            switchTab('tab_devices');
        } else if (window.location.hash === '#tab_template' || '<?= $formData['active_tab'] ?>' === 'tab_template' || window.location.hash === '#ringtone_section') {
            switchTab('tab_template');
            if (window.location.hash === '#ringtone_section') {
                var elem = document.getElementById('ringtone_section');
                if (elem) { elem.scrollIntoView({ behavior: 'smooth' }); }
            }
        }
        updateModelSpecsInfo(document.getElementById('select_phone_model').value);
        enforceUniqueExtensionSelections();
    });
</script>

<form id="delete_file_form" method="POST" style="display:none;">
    <input type="hidden" name="delete_target_file" value="1">
    <input type="hidden" id="target_filename" name="target_filename" value="">
    <input type="hidden" id="target_file_type" name="target_file_type" value="">
    <input type="hidden" id="delete_active_tab" name="active_tab" value="tab_global">
    <input type="hidden" name="current_loaded_template" value="<?= htmlspecialchars($formData['template_name']) ?>">
</form>

<!-- MODALS -->
<div id="viewConfigModal" class="gen-modal">
    <div class="gen-modal-content" style="width: 700px;">
        <h3>Device Configuration (<span id="view_cfg_mac_title"></span>)</h3>
        <textarea id="view_cfg_content" readonly class="gen-textarea" style="height: 400px; font-size: 12px; background: #f8f9fa;"></textarea>
        <div style="display: flex; justify-content: flex-end; margin-top: 15px;">
            <button type="button" class="gen-btn-danger" style="margin: 0;" onclick="closeViewConfigModal()">Close</button>
        </div>
    </div>
</div>

<div id="trimmerModal" class="gen-modal">
    <div class="gen-modal-content" style="width: 520px;">
        <h3>Crop & Trim Ringtone Audio</h3>
        
        <div style="text-align: center; margin: 15px 0;">
            <p id="trimmer_filename" style="font-weight: bold; font-size: 14px; margin-bottom: 10px; color: #007bff;"></p>
            <audio id="trimmer_audio_player" controls style="width: 100%; margin-bottom: 20px;"></audio>
            
            <div id="range_slider_container" style="padding: 10px 15px; margin-bottom: 10px;">
                <div id="audio_trim_slider"></div>
            </div>
            
            <div style="display: flex; justify-content: space-between; margin-top: 15px; font-weight: bold; font-size: 13px; color: #495057;">
                <span>Start: <span id="trim_start_val" style="color:#007bff;">0.0</span>s</span>
                <span>Duration: <span id="trim_duration_val" style="color:#28a745;">0.0</span>s</span>
                <span>End: <span id="trim_end_val" style="color:#007bff;">0.0</span>s</span>
            </div>
        </div>

        <div style="display: flex; justify-content: space-between; gap: 10px; margin-top: 20px;">
            <button type="button" class="gen-btn" style="background: #17a2b8; margin: 0;" onclick="previewCroppedAudio()">Preview Selection</button>
            <div style="display: flex; gap: 8px;">
                <button type="button" class="gen-btn" style="background: #28a745; margin: 0;" onclick="submitAudioCrop()">Crop & Save</button>
                <button type="button" class="gen-btn-danger" style="margin: 0;" onclick="closeTrimmerModal()">Cancel</button>
            </div>
        </div>
    </div>
</div>

<div id="scanModal" class="gen-modal">
    <div class="gen-modal-content">
        <h3>Subnet MAC Address Scanner (Yealink)</h3>
        
        <div style="display:flex; justify-content:space-between; align-items:flex-end; gap:10px; margin-bottom:10px;">
            <div style="flex:1;">
                <label style="margin-top:0;">Enter Subnet Base IP / FQDN:</label>
                <div style="display:flex; gap:10px;">
                    <input type="text" id="scan_subnet" class="gen-full-width" value="<?= $detected_host ?>">
                    <button type="button" class="gen-btn" style="margin-top:0;" onclick="runSubnetScan()">Scan Subnet</button>
                </div>
            </div>

            <div style="flex:1;">
                <label style="margin-top:0;">Bulk Assign Template to All Scanned:</label>
                <select id="bulk_scanned_template" class="gen-full-width" onchange="applyBulkTemplateToScanned(this.value)">
                    <option value="">-- Select Template to Apply All --</option>
                    <?php foreach ($available_templates as $tpl_file => $tpl_label): ?>
                        <option value="<?= htmlspecialchars($tpl_file) ?>"><?= htmlspecialchars($tpl_label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <table class="scan-table">
            <thead>
                <tr>
                    <th>IP Address</th>
                    <th>MAC Address</th>
                    <th>Assign Extension</th>
                    <th>Assign Template</th>
                    <th>Auto-Provision</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="scan_results_body">
                <tr><td colspan="6">Click 'Scan Subnet' to discover active phones.</td></tr>
            </tbody>
        </table>
        <br>
        <div style="display:flex; justify-content:space-between; gap:10px;">
            <button type="button" id="add_all_btn" class="gen-btn" style="margin-top:0; background:#28a745;" onclick="submitAddAllScannedDevices()">+ Add All Devices & Close</button>
            <button type="button" class="gen-btn-danger" style="margin-top:0;" onclick="closeScanModal()">Done / Close</button>
        </div>
    </div>
</div>

<div id="manualAddModal" class="gen-modal">
    <div class="gen-modal-content" style="width: 450px;">
        <h3>Manually Add Phone Device</h3>
        
        <label>MAC Address:</label>
        <input type="text" id="manual_mac" class="gen-full-width" placeholder="e.g. 001565123456" maxlength="17">

        <label style="margin-top:10px;">Assign Extension:</label>
        <select id="manual_ext" class="gen-full-width">
            <option value="">-- Unassigned --</option>
            <?php foreach ($available_extensions as $ext_id => $ext_data): ?>
                <option value="<?= $ext_id ?>"><?= $ext_id ?> - <?= htmlspecialchars($ext_data['display_name']) ?></option>
            <?php endforeach; ?>
        </select>

        <label style="margin-top:10px;">Assign Template:</label>
        <select id="manual_tpl" class="gen-full-width">
            <option value="">-- None --</option>
            <?php foreach ($available_templates as $tpl_file => $tpl_label): ?>
                <option value="<?= htmlspecialchars($tpl_file) ?>"><?= htmlspecialchars($tpl_label) ?></option>
            <?php endforeach; ?>
        </select>

        <div style="margin-top:12px;">
            <label style="font-weight:normal; display:inline-flex; align-items:center; gap:5px;">
                <input type="checkbox" id="manual_provision" checked> Push Auto-Provision & SIP NOTIFY
            </label>
        </div>

        <br>
        <div style="display:flex; justify-content:space-between; gap:10px;">
            <button type="button" id="manual_add_btn" class="gen-btn" style="margin-top:0; background:#28a745;" onclick="submitManualAddDevice()">Create Device Config</button>
            <button type="button" class="gen-btn-danger" style="margin-top:0;" onclick="closeManualAddModal()">Cancel / Close</button>
        </div>
    </div>
</div>

<div id="singleRebuildModal" class="gen-modal">
    <div class="gen-modal-content" style="width: 450px;">
        <h3>Rebuild Configuration (<span id="single_rebuild_mac_title"></span>)</h3>
        
        <label>Select Template:</label>
        <select id="single_template_select" class="gen-full-width">
            <option value="">-- None --</option>
            <?php foreach ($available_templates as $tpl_file => $tpl_label): ?>
                <option value="<?= htmlspecialchars($tpl_file) ?>"><?= htmlspecialchars($tpl_label) ?></option>
            <?php endforeach; ?>
        </select>

        <label style="margin-top:10px;">Override Phone Model:</label>
        <select id="single_model_select" name="single_model" class="gen-full-width">
            <?php foreach ($yealink_models as $m_key => $m_label): ?>
                <option value="<?= $m_key ?>"><?= $m_label ?></option>
            <?php endforeach; ?>
        </select>

        <div style="margin-top:12px;">
            <label style="font-weight:normal; display:inline-flex; align-items:center; gap:8px;">
                <input type="checkbox" name="single_provision" checked> Push Auto Provision
            </label>
            <br>
            <label style="font-weight:normal; display:inline-flex; align-items:center; gap:8px; margin-top:5px;">
                <input type="checkbox" name="single_reboot_check"> Reboot Phone
            </label>
        </div>

        <br>
        <div style="display:flex; justify-content:space-between; gap:10px;">
            <button type="button" class="gen-btn" style="margin-top:0; background:#28a745;" onclick="submitSingleRebuildModal()">Rebuild Device Config</button>
            <button type="button" class="gen-btn-danger" style="margin-top:0;" onclick="closeSingleRebuildModal()">Cancel</button>
        </div>
    </div>
</div>

<!-- ============================================================================ -->
<!-- TABBED UI LAYOUT                                                             -->
<!-- ============================================================================ -->

<div class="gen-container">
    <h2>Yealink Endpoint Manager</h2>
    
    <?php if ($sysadmin_redirect): ?>
        <div class="alert alert-warning alert-dismissible" role="alert" style="margin-top: 15px;">
            <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            <strong>Notice for Legacy Yealink Phones:</strong> Global HTTPS Redirect is active in System Admin. 
            Provisioning URLs have been automatically routed to <strong>Port 83</strong> (<code><?= htmlspecialchars($default_provision_url) ?></code>) to ensure legacy V73 firmware can provision over HTTP without SSL errors.
        </div>
    <?php endif; ?>

    <?php if (!empty($status)) echo "<div class='gen-alert'>{$status}</div>"; ?>

    <div class="gen-tab-bar">
        <div id="btn_tab_global" class="gen-tab-btn <?= ($formData['active_tab'] === 'tab_global') ? 'active' : '' ?>" onclick="switchTab('tab_global')">Global Settings (y000000000000.cfg)</div>
        <div id="btn_tab_template" class="gen-tab-btn <?= ($formData['active_tab'] === 'tab_template') ? 'active' : '' ?>" onclick="switchTab('tab_template')">Template Manager (template.cfg)</div>
        <div id="btn_tab_devices" class="gen-tab-btn <?= ($formData['active_tab'] === 'tab_devices') ? 'active' : '' ?>" onclick="switchTab('tab_devices')">Device Manager</div>
    </div>

    <!-- TAB 1: GLOBAL SETTINGS -->
    <div id="tab_global" class="gen-tab-content <?= ($formData['active_tab'] === 'tab_global') ? 'active' : '' ?>">
        <form id="main_cfg_form" method="POST" enctype="multipart/form-data">
            <input type="hidden" id="active_tab_field" name="active_tab" value="<?= htmlspecialchars($formData['active_tab']) ?>">
            <input type="hidden" id="field_base_mem_keys" value="0">

            <div class="gen-key-row">
                <div>
                    <label>PBX Server IP / Domain:</label>
                    <input type="text" class="gen-full-width" name="server_ip" placeholder="<?= $default_server_target ?>" value="<?= htmlspecialchars($formData['server_ip']) ?>">
                </div>
                <div>
                    <label>Phone Web GUI Admin Password:</label>
                    <input type="text" class="gen-full-width" name="admin_password" placeholder="22222" value="<?= htmlspecialchars($formData['admin_password']) ?>">
                </div>
            </div>

            <div class="gen-key-row">
                <div>
                    <label>Time Zone:</label>
                    <select name="timezone" class="gen-full-width">
                        <?php foreach ($timezones as $offset => $tz_name): ?>
                            <option value="<?= $offset ?>" <?= ($formData['timezone'] == $offset) ? 'selected' : '' ?>><?= $tz_name ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Time Format:</label>
                    <select name="time_format" class="gen-full-width">
                        <option value="0" <?= ($formData['time_format'] === '0') ? 'selected' : '' ?>>12-Hour (AM/PM)</option>
                        <option value="1" <?= ($formData['time_format'] === '1') ? 'selected' : '' ?>>24-Hour (Military)</option>
                    </select>
                </div>
            </div>

            <div class="gen-key-row">
                <div>
                    <label>NTP Server 1:</label>
                    <input type="text" class="gen-full-width" name="ntp_server1" placeholder="<?= $detected_host ?>" value="<?= htmlspecialchars($formData['ntp_server1']) ?>">
                </div>
                <div>
                    <label>NTP Server 2:</label>
                    <input type="text" class="gen-full-width" name="ntp_server2" placeholder="pool.ntp.org" value="<?= htmlspecialchars($formData['ntp_server2']) ?>">
                </div>
            </div>

            <h3 class="gen-section-title">Auto Provisioning Settings</h3>
            <div class="gen-key-row">
                <div>
                    <label>Provisioning Mode:</label>
                    <select name="auto_provision_mode" class="gen-full-width">
                        <option value="7" <?= ($formData['auto_provision_mode'] === '7') ? 'selected' : '' ?>>7 - Power on + Weekly</option>
                        <option value="6" <?= ($formData['auto_provision_mode'] === '6') ? 'selected' : '' ?>>6 - Power on + Repeatedly</option>
                        <option value="5" <?= ($formData['auto_provision_mode'] === '5') ? 'selected' : '' ?>>5 - Weekly</option>
                        <option value="4" <?= ($formData['auto_provision_mode'] === '4') ? 'selected' : '' ?>>4 - Repeatedly</option>
                        <option value="1" <?= ($formData['auto_provision_mode'] === '1') ? 'selected' : '' ?>>1 - Power on</option>
                        <option value="0" <?= ($formData['auto_provision_mode'] === '0') ? 'selected' : '' ?>>0 - Disabled</option>
                    </select>
                </div>
                <div>
                    <label>Weekly Provisioning Enable:</label>
                    <select name="auto_provision_weekly_enable" class="gen-full-width">
                        <option value="1" <?= ($formData['auto_provision_weekly_enable'] === '1') ? 'selected' : '' ?>>1 - Enabled</option>
                        <option value="0" <?= ($formData['auto_provision_weekly_enable'] === '0') ? 'selected' : '' ?>>0 - Disabled</option>
                    </select>
                </div>
                <div>
                    <label>DHCP Option Enable:</label>
                    <select name="auto_provision_dhcp_option_enable" class="gen-full-width">
                        <option value="1" <?= ($formData['auto_provision_dhcp_option_enable'] === '1') ? 'selected' : '' ?>>1 - Enabled</option>
                        <option value="0" <?= ($formData['auto_provision_dhcp_option_enable'] === '0') ? 'selected' : '' ?>>0 - Disabled</option>
                    </select>
                </div>
            </div>

            <div class="gen-key-row">
                <div>
                    <label>Weekly Begin Time:</label>
                    <input type="text" class="gen-full-width" name="auto_provision_weekly_begin_time" value="<?= htmlspecialchars($formData['auto_provision_weekly_begin_time']) ?>">
                </div>
                <div>
                    <label>Weekly End Time:</label>
                    <input type="text" class="gen-full-width" name="auto_provision_weekly_end_time" value="<?= htmlspecialchars($formData['auto_provision_weekly_end_time']) ?>">
                </div>
                <div>
                    <label>Day of Week (0=Sun, 6=Sat):</label>
                    <select name="auto_provision_weekly_dayofweek" class="gen-full-width">
                        <option value="0" <?= ($formData['auto_provision_weekly_dayofweek'] === '0') ? 'selected' : '' ?>>0 - Sunday</option>
                        <option value="1" <?= ($formData['auto_provision_weekly_dayofweek'] === '1') ? 'selected' : '' ?>>1 - Monday</option>
                        <option value="2" <?= ($formData['auto_provision_weekly_dayofweek'] === '2') ? 'selected' : '' ?>>2 - Tuesday</option>
                        <option value="3" <?= ($formData['auto_provision_weekly_dayofweek'] === '3') ? 'selected' : '' ?>>3 - Wednesday</option>
                        <option value="4" <?= ($formData['auto_provision_weekly_dayofweek'] === '4') ? 'selected' : '' ?>>4 - Thursday</option>
                        <option value="5" <?= ($formData['auto_provision_weekly_dayofweek'] === '5') ? 'selected' : '' ?>>5 - Friday</option>
                        <option value="6" <?= ($formData['auto_provision_weekly_dayofweek'] === '6') ? 'selected' : '' ?>>6 - Saturday</option>
                    </select>
                </div>
            </div>

            <div class="gen-key-row">
                <div>
                    <label>Server Username (Optional):</label>
                    <input type="text" class="gen-full-width" name="auto_provision_username" value="<?= htmlspecialchars($formData['auto_provision_username']) ?>">
                </div>
                <div>
                    <label>Server Password (Optional):</label>
                    <input type="text" class="gen-full-width" name="auto_provision_password" value="<?= htmlspecialchars($formData['auto_provision_password']) ?>">
                </div>
            </div>

            <h3 class="gen-section-title">SIP & Call Transfer Features</h3>
            <div class="gen-key-row">
                <div>
                    <label>Use Outbound Proxy in Dialog:</label>
                    <select name="sip_use_out_bound_in_dialog" class="gen-full-width">
                        <option value="1" <?= ($formData['sip_use_out_bound_in_dialog'] === '1') ? 'selected' : '' ?>>1 - Enabled</option>
                        <option value="0" <?= ($formData['sip_use_out_bound_in_dialog'] === '0') ? 'selected' : '' ?>>0 - Disabled</option>
                    </select>
                </div>
                <div>
                    <label>DSS Key Transfer Action:</label>
                    <select name="transfer_dsskey_deal_type" class="gen-full-width">
                        <option value="2" <?= ($formData['transfer_dsskey_deal_type'] === '2') ? 'selected' : '' ?>>2 - Attended Transfer</option>
                        <option value="1" <?= ($formData['transfer_dsskey_deal_type'] === '1') ? 'selected' : '' ?>>1 - Blind Transfer</option>
                        <option value="0" <?= ($formData['transfer_dsskey_deal_type'] === '0') ? 'selected' : '' ?>>0 - New Call</option>
                    </select>
                </div>
            </div>

            <div class="gen-key-row">
                <div>
                    <label>Blind Transfer On Hook:</label>
                    <select name="transfer_blind_tran_on_hook_enable" class="gen-full-width">
                        <option value="1" <?= ($formData['transfer_blind_tran_on_hook_enable'] === '1') ? 'selected' : '' ?>>1 - Enabled</option>
                        <option value="0" <?= ($formData['transfer_blind_tran_on_hook_enable'] === '0') ? 'selected' : '' ?>>0 - Disabled</option>
                    </select>
                </div>
                <div>
                    <label>On-Hook Transfer:</label>
                    <select name="transfer_on_hook_trans_enable" class="gen-full-width">
                        <option value="1" <?= ($formData['transfer_on_hook_trans_enable'] === '1') ? 'selected' : '' ?>>1 - Enabled</option>
                        <option value="0" <?= ($formData['transfer_on_hook_trans_enable'] === '0') ? 'selected' : '' ?>>0 - Disabled</option>
                    </select>
                </div>
            </div>

            <h3 class="gen-section-title">Dial Plan (Dial-Now) Rules</h3>
            <label>Inter-Digit Timeout (Seconds):</label>
            <select name="dialnow_timeout" class="gen-full-width">
                <?php for ($sec = 1; $sec <= 14; $sec++): ?>
                    <option value="<?= $sec ?>" <?= ($formData['dialnow_timeout'] == $sec) ? 'selected' : '' ?>><?= $sec ?> Seconds</option>
                <?php endfor; ?>
            </select>

            <label style="margin-top:10px;">Number of DialNow Pattern Slots:</label>
            <select name="dialnow_count" class="gen-full-width" onchange="updateDialnowVisibility(this.value)">
                <?php for ($d_cnt = 1; $d_cnt <= 20; $d_cnt++): ?>
                    <option value="<?= $d_cnt ?>" <?= ($d_cnt == $max_dialnow_slots) ? 'selected' : '' ?>><?= $d_cnt ?> Slots</option>
                <?php endfor; ?>
            </select>

            <label style="margin-top:10px;">Outbound Match Patterns (Pulled from route named "outbound")</label>
            <div style="margin-top:5px;">
            <?php for ($d = 1; $d <= 20; $d += 2): 
                $next_slot = $d + 1;
            ?>
                <div class="gen-key-row">
                    <div id="dialnow_slot_<?= $d ?>" style="display: <?= ($d <= $max_dialnow_slots) ? 'block' : 'none' ?>;">
                        <input type="text" class="gen-full-width" name="dialnow_<?= $d ?>" placeholder="Rule <?= $d ?>" value="<?= htmlspecialchars($formData["dialnow_{$d}"] ?? '') ?>">
                    </div>
                    <?php if ($next_slot <= 20): ?>
                        <div id="dialnow_slot_<?= $next_slot ?>" style="display: <?= ($next_slot <= $max_dialnow_slots) ? 'block' : 'none' ?>;">
                            <input type="text" class="gen-full-width" name="dialnow_<?= $next_slot ?>" placeholder="Rule <?= $next_slot ?>" value="<?= htmlspecialchars($formData["dialnow_{$next_slot}"] ?? '') ?>">
                        </div>
                    <?php endif; ?>
                </div>
            <?php endfor; ?>
            </div>

            <h3 class="gen-section-title">Global Custom Key / Value Additions</h3>
            <label>Add raw global Yealink configuration flags for y000000000000.cfg (One per line):</label>
            <textarea name="custom_inputs_global" class="gen-textarea"><?= htmlspecialchars($formData['custom_inputs_global']) ?></textarea>

            <?php if (!empty($generated_common_cfg)): ?>
                <h3 class="gen-section-title">Generated Common Output (y000000000000.cfg)</h3>
                <textarea readonly class="gen-textarea" style="height:300px;"><?= htmlspecialchars($generated_common_cfg) ?></textarea>
            <?php endif; ?>

            <br>
            <div style="display: flex; gap: 10px; margin-top: 10px;">
                <button type="submit" name="save_global" class="gen-btn" style="background: #28a745; margin-top:0;">Save Global Settings to /tftpboot/</button>
                <?php if (file_exists($tftp_dir . "y000000000000.cfg")): ?>
                    <button type="button" class="gen-btn-danger" style="margin-top:0;" onclick="confirmDeleteGlobalConfig()">Delete y000000000000.cfg</button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- TAB 2: TEMPLATE MANAGER -->
    <div id="tab_template" class="gen-tab-content <?= ($formData['active_tab'] === 'tab_template') ? 'active' : '' ?>">
        <div class="gen-load-box">
            <form method="POST">
                <input type="hidden" id="load_tpl_active_tab_field" name="active_tab" value="tab_template">
                <label>Active / Edit Template:</label>
                <div style="display: flex; gap: 8px; align-items: center;">
                    <?php 
                    $selected_tpl_option = $_POST['template_to_load'] ?? (!empty($formData['template_name']) ? $formData['template_name'] . '.template.cfg' : '');
                    ?>
                    <select id="select_template_file" name="template_to_load" class="gen-full-width">
                        <option value="" <?= empty($selected_tpl_option) ? 'selected' : '' ?>>-- Select a template to edit --</option>
                        <?php foreach ($available_templates as $tpl_file => $tpl_label): ?>
                            <option value="<?= htmlspecialchars($tpl_file) ?>" <?= ($selected_tpl_option === $tpl_file) ? 'selected' : '' ?>><?= htmlspecialchars($tpl_label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" name="load_template" class="gen-btn" style="margin-top:0; background:#6c757d;">Load</button>
                    <button type="button" class="gen-btn" style="margin-top:0; background:#17a2b8;" onclick="downloadSelectedTemplate()">Download</button>
                    <button type="button" class="gen-btn-danger" style="margin-top:0;" onclick="confirmDeleteFile(document.getElementById('select_template_file').value, 'template')">Delete</button>
                </div>
            </form>
            
            <form method="POST" enctype="multipart/form-data" style="margin-top:10px; border-top:1px dashed #ccc; padding-top:8px;">
                <input type="hidden" name="active_tab" value="tab_template">
                <div style="display:flex; align-items:center; gap:10px;">
                    <label style="margin:0; font-weight:bold; white-space:nowrap; font-size:12px;">Upload External Template:</label>
                    <input type="file" name="template_upload" accept=".cfg,.template.cfg" style="padding:4px; font-size:12px;">
                    <button type="submit" name="upload_template_file" class="gen-btn" style="margin:0; padding:6px 12px; font-size:12px; background:#28a745;">Upload Template File</button>
                </div>
            </form>
        </div>

        <form id="template_cfg_form" action="?display=yealink_epm" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="active_tab" value="tab_template">
            <input type="hidden" name="current_loaded_template" value="<?= htmlspecialchars($formData['template_name']) ?>">

            <div class="gen-key-row">
                <div>
                    <label>Template Name:</label>
                    <input type="text" class="gen-full-width" name="template_name" placeholder="e.g., T28_Reception" value="<?= htmlspecialchars($formData['template_name']) ?>">
                </div>
                <div>
                    <label>Phone Model Presets:</label>
                    <select id="select_phone_model" name="phone_model" class="gen-full-width" onchange="handleModelSelect(this.value)">
                        <?php foreach ($yealink_models as $m_key => $m_label): ?>
                            <option value="<?= $m_key ?>" <?= ($formData['phone_model'] === $m_key) ? 'selected' : '' ?>><?= $m_label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="gen-key-row">
                <div>
                    <label>Expansion Module Model:</label>
                    <select id="select_exp_model" name="exp_model" class="gen-full-width" onchange="handleExpSelect()">
                        <?php foreach ($expansion_models as $e_key => $e_label): ?>
                            <option value="<?= $e_key ?>" <?= ($formData['exp_model'] === $e_key) ? 'selected' : '' ?>><?= $e_label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Expansion Quantity:</label>
                    <select id="select_exp_count" name="exp_count" class="gen-full-width" onchange="handleExpSelect()">
                        <option value="0" <?= ($formData['exp_count'] === '0') ? 'selected' : '' ?>>-- None --</option>
                        <option value="1" <?= ($formData['exp_count'] === '1') ? 'selected' : '' ?>>1 Unit</option>
                        <option value="2" <?= ($formData['exp_count'] === '2') ? 'selected' : '' ?>>2 Units</option>
                        <option value="3" <?= ($formData['exp_count'] === '3') ? 'selected' : '' ?>>3 Units</option>
                    </select>
                </div>
            </div>

            <div class="gen-key-row">
                <div>
                    <label>SIP Port:</label>
                    <input type="text" class="gen-full-width" name="sip_port" placeholder="<?= htmlspecialchars($default_sip_port) ?>" value="<?= htmlspecialchars($formData['sip_port']) ?>">
                </div>
                <div>
                    <label>SIP Listen Port:</label>
                    <input type="text" class="gen-full-width" name="sip_listen_port" placeholder="5062" value="<?= htmlspecialchars($formData['sip_listen_port']) ?>">
                </div>
                <div>
                    <label>Voicemail Extension Number:</label>
                    <input type="text" class="gen-full-width" name="voicemail_number" placeholder="*97" value="<?= htmlspecialchars($formData['voicemail_number']) ?>">
                </div>
            </div>

            <h3 class="gen-section-title">Line Keys BLF Settings</h3>
            <label>Number of Line Key Slots:</label>
            <select id="select_linekey_count" name="linekey_count" class="gen-full-width" onchange="updateLinekeyVisibility(this.value)">
                <?php for ($l_cnt = 1; $l_cnt <= 29; $l_cnt++): ?>
                    <option value="<?= $l_cnt ?>" <?= ($l_cnt == $max_linekeys) ? 'selected' : '' ?>><?= $l_cnt ?> Line Keys</option>
                <?php endfor; ?>
            </select>

            <div style="margin-top:10px;">
            <?php for ($i = 1; $i <= 29; $i++): ?>
                <div id="linekey_row_<?= $i ?>" class="gen-key-row" style="display: <?= ($i <= $max_linekeys) ? 'flex' : 'none' ?>;">
                    <?php if ($i === 1): ?>
                        <select name="linekey_1_type" style="background-color: #e9ecef; pointer-events: none;" readonly tabindex="-1">
                            <option value="15" selected>Line (15)</option>
                        </select>
                        <input type="text" name="linekey_1_value" placeholder="Extension Number" value="<?= htmlspecialchars($formData["linekey_1_value"] ?? '') ?>" readonly style="background-color: #e9ecef;">
                        <input type="text" name="linekey_1_label" placeholder="Extension Name" value="<?= htmlspecialchars($formData["linekey_1_label"] ?? '') ?>" readonly style="background-color: #e9ecef;">
                        <input type="text" name="linekey_1_pickup" placeholder="Pickup (**)" value="<?= htmlspecialchars($formData["linekey_1_pickup"] ?? '') ?>" readonly style="background-color: #e9ecef;">
                    <?php else: ?>
                        <select name="linekey_<?= $i ?>_type">
                            <?php 
                            $current_type = $formData["linekey_{$i}_type"] ?? '16';
                            foreach ($dss_key_types as $k_code => $k_label): 
                            ?>
                                <option value="<?= $k_code ?>" <?= ($current_type == $k_code) ? 'selected' : '' ?>><?= $k_label ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="linekey_<?= $i ?>_value" placeholder="Line Key <?= $i ?> Extension" value="<?= htmlspecialchars($formData["linekey_{$i}_value"] ?? '') ?>">
                        <input type="text" name="linekey_<?= $i ?>_label" placeholder="Label" value="<?= htmlspecialchars($formData["linekey_{$i}_label"] ?? '') ?>">
                        <input type="text" name="linekey_<?= $i ?>_pickup" placeholder="Pickup (**)" value="<?= htmlspecialchars($formData["linekey_{$i}_pickup"] ?? '**') ?>">
                    <?php endif; ?>
                </div>
            <?php endfor; ?>
            </div>

            <h3 class="gen-section-title">Memory Keys BLF Settings (Physical/Expansion)</h3>
            <label>Number of Memory Key Slots:</label>
            <select id="select_memkey_count" name="memkey_count" class="gen-full-width" onchange="updateMemkeyVisibility(this.value)">
                <option value="0" <?= (0 == $max_memkeys) ? 'selected' : '' ?>>0 Slots (Disabled)</option>
                <?php for ($k = 1; $k <= 180; $k++): ?>
                    <option value="<?= $k ?>" <?= ($k == $max_memkeys) ? 'selected' : '' ?>><?= $k ?> Slots</option>
                <?php endfor; ?>
            </select>

            <div style="margin-top:10px;">
            <?php for ($i = 1; $i <= 180; $i++): ?>
                <div id="memkey_row_<?= $i ?>" class="gen-key-row" style="display: <?= ($i <= $max_memkeys) ? 'flex' : 'none' ?>;">
                    <input type="text" name="memkey_<?= $i ?>_value" placeholder="Memory Key <?= $i ?> Extension" value="<?= htmlspecialchars($formData["memkey_{$i}_value"] ?? '') ?>">
                    <input type="text" name="memkey_<?= $i ?>_pickup" placeholder="Pickup Value" value="<?= htmlspecialchars($formData["memkey_{$i}_pickup"] ?? '**') ?>">
                </div>
            <?php endfor; ?>
            </div>

            <div id="ringtone_section"></div>
            <h3 class="gen-section-title">Ringtone Management & Provisioning</h3>

            <div id="flush_banner_container" class="flush-banner" style="display: <?= $show_flush_ringtone_btn ? 'block' : 'none' ?>;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <strong>&#9888; Unreferenced / Deleted Ringtone(s) Detected in Phone Configs:</strong> One or more device <code>[mac].cfg</code> files assigned to this template reference ringtones that have been deleted or unchecked. Click below to issue a flush directive and sync all affected phones.
                    </div>
                    <button type="submit" name="flush_template_ringtones" class="gen-btn-danger" style="margin:0; white-space:nowrap; padding:8px 14px; font-weight:bold;" onclick="this.form.action='?display=yealink_epm#ringtone_section';">
                        Flush Ringtones From Phones
                    </button>
                </div>
            </div>

            <div class="ringtone-card">
                <label style="margin-top:0;">1. Provision Uploaded Sound Files to Phone:</label>
                <div id="ringtone_spec_note" class="spec-note">Loading specs...</div>
                
                <p style="font-size:12px; color:#666; margin-top:8px; margin-bottom:6px;">Check ringtones to include them in the template provision file (unchecking removes them from the phone):</p>

                <div class="ringtone-list-container">
                    <?php if (empty($ringtone_filenames)): ?>
                        <p style="color:#888; font-style:italic; padding:6px 0; margin:0;">No custom ringtones found in /PhoneSettings/ringtones/</p>
                    <?php else: ?>
                        <?php foreach ($ringtone_filenames as $r_file): 
                            $is_checked = in_array($r_file, $formData['uploaded_ringtones']);
                            $f_size = $ringtone_file_sizes[$r_file] ?? 0;
                            $size_formatted = ($f_size > 0) ? round($f_size / 1024, 1) . ' KB' : '0 KB';
                            
                            $download_url = "?display=yealink_epm&action=download_ringtone&file=" . rawurlencode($r_file);
                            $stream_url   = "?display=yealink_epm&action=stream_ringtone&file=" . rawurlencode($r_file);
                            $clean_id     = preg_replace('/[^a-zA-Z0-9]/', '_', $r_file);
                        ?>
                            <div class="ringtone-grid-item">
                                <label style="font-weight:normal; margin:0; display:flex; align-items:center;">
                                    <input type="checkbox" name="uploaded_ringtones[]" value="<?= htmlspecialchars($r_file) ?>" <?= $is_checked ? 'checked' : '' ?> onchange="syncRingtoneOptions(this, '<?= htmlspecialchars($r_file) ?>')">
                                    <span style="margin-left:8px; font-weight:500; text-overflow:ellipsis; overflow:hidden; white-space:nowrap;"><?= htmlspecialchars($r_file) ?></span>
                                </label>

                                <div>
                                    <span class="ringtone-size-badge">(<?= $size_formatted ?>)</span>
                                </div>

                                <div class="ringtone-player-controls">
                                    <audio id="audio_player_<?= $clean_id ?>" controls preload="none" controlsList="nodownload">
                                        <source src="<?= $stream_url ?>" type="audio/wav">
                                        <source src="<?= $stream_url ?>" type="audio/mpeg">
                                    </audio>
                                </div>

                                <div style="display:flex; align-items:center; gap:4px;">
                                    <label style="font-size:11px; font-weight:600; color:#555; cursor:pointer; margin:0; display:inline-flex; align-items:center; gap:3px;">
                                        <input type="checkbox" onchange="toggleAudioLoop('audio_player_<?= $clean_id ?>', this.checked)"> Loop
                                    </label>
                                </div>

                                <div style="display:flex; align-items:center; gap:2px;">
                                    <button type="button" class="action-icon-btn" title="Trim / Edit <?= htmlspecialchars($r_file) ?>" onclick="editUploadedRingtone('<?= htmlspecialchars($r_file) ?>')">
                                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="6" cy="6" r="3"></circle>
                                            <circle cx="6" cy="18" r="3"></circle>
                                            <line x1="20" y1="4" x2="8.12" y2="15.88"></line>
                                            <line x1="14.47" y1="14.47" x2="20" y2="20"></line>
                                            <line x1="8.12" y1="8.12" x2="12" y2="12"></line>
                                        </svg>
                                    </button>
                                    <a href="<?= $download_url ?>" class="action-icon-btn" title="Download <?= htmlspecialchars($r_file) ?>">
                                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                            <polyline points="7 10 12 15 17 10"></polyline>
                                            <line x1="12" y1="15" x2="12" y2="3"></line>
                                        </svg>
                                    </a>
                                    <button type="button" class="action-icon-btn delete-icon" title="Delete <?= htmlspecialchars($r_file) ?>" onclick="confirmDeleteFile('<?= htmlspecialchars($r_file) ?>', 'ringtone')">
                                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <polyline points="3 6 5 6 21 6"></polyline>
                                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                            <line x1="10" y1="11" x2="10" y2="17"></line>
                                            <line x1="14" y1="11" x2="14" y2="17"></line>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div id="ringtone_payload_summary" style="font-size:12px; color:#333; margin-top:8px; text-align:left;">Total Selected Payload: 0 KB</div>

                <div id="ringtone_overlimit_warning" class="warning-box" style="display:none;"></div>

                <div style="margin-top:15px; border-top:1px solid #e0e0e0; padding-top:10px;">
                    <label style="margin-top:0; margin-bottom:8px;">Upload New Ringtones:</label>
                    
                    <div class="upload-controls-col">
                        <label for="ringtone_file_input" class="custom-file-btn">Browse Files</label>
                        <input type="file" id="ringtone_file_input" accept=".wav,.mp3" multiple style="display:none;" onchange="updateVerticalFileList(this)">
                        
                        <button type="button" id="async_upload_btn" onclick="uploadRingtonesAsync(event)" class="upload-btn-aligned">
                            Upload Ringtones
                        </button>

                        <textarea id="selected_files_textarea" readonly placeholder="No files selected"></textarea>
                    </div>
                </div>
            </div>

            <div class="ringtone-card" style="margin-top:15px;">
                <label style="margin-top:0;">2. Default Account Ringtone (account.1.ringtone.ring_type):</label>
                <p style="font-size:12px; color:#666; margin-top:2px; margin-bottom:10px;">Select the primary ringtone assigned for incoming calls on Account 1:</p>

                <select id="account_ringtone_select" name="account_ringtone" class="gen-full-width">
                    <optgroup label="Built-in & System Ringtones">
                        <?php foreach ($builtin_ringtones as $r_val => $r_lbl): 
                            $is_selected = ($formData['account_ringtone'] === $r_val);
                        ?>
                            <option value="<?= htmlspecialchars($r_val) ?>" <?= $is_selected ? 'selected' : '' ?>><?= htmlspecialchars($r_lbl) ?></option>
                        <?php endforeach; ?>
                    </optgroup>

                    <?php if (!empty($ringtone_filenames)): ?>
                        <optgroup label="Uploaded Custom Ringtones">
                            <?php foreach ($ringtone_filenames as $r_file): 
                                $is_checked = in_array($r_file, $formData['uploaded_ringtones']);
                                $is_selected = ($formData['account_ringtone'] === $r_file);
                                $opt_id = 'opt_custom_' . preg_replace('/[^a-zA-Z0-9]/', '_', $r_file);
                            ?>
                                <option id="<?= $opt_id ?>" value="<?= htmlspecialchars($r_file) ?>" <?= $is_selected ? 'selected' : '' ?> <?= $is_checked ? '' : 'disabled style="display:none;"' ?>>
                                    Custom: <?= htmlspecialchars($r_file) ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                </select>
            </div>

            <h3 class="gen-section-title">Wallpaper / Logo Customization</h3>
            <div id="logo_spec_note" class="spec-note">Loading specs...</div>
            <div class="gen-key-row" style="margin-top:10px;">
                <div>
                    <label>Select Existing Wallpaper / Logo File:</label>
                    <div style="display: flex; gap: 5px;">
                        <select id="select_logo_file" name="logo_file" class="gen-full-width">
                            <option value="">Disabled (mode = 0)</option>
                            <option value="system" <?= ($formData['logo_file'] === 'system') ? 'selected' : '' ?>>System Logo (mode = 1)</option>
                            <?php foreach ($logo_filenames as $l_file): ?>
                                <option value="<?= $l_file ?>" <?= ($formData['logo_file'] === $l_file) ? 'selected' : '' ?>><?= $l_file ?> (mode = 2)</option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="gen-btn-danger" style="margin-top:0;" onclick="confirmDeleteFile(document.getElementById('select_logo_file').value, 'logo')">Delete</button>
                    </div>
                </div>
                <div>
                    <label>Upload New Wallpaper to /PhoneSettings/logo/:</label>
                    <input type="file" name="logo_upload" class="gen-full-width" accept=".dob,.jpg,.png,.bmp">
                </div>
            </div>

            <h3 class="gen-section-title">Template Custom Key / Value Additions</h3>
            <label>Add raw Yealink configuration flags for this template (One per line):</label>
            <textarea name="custom_inputs" class="gen-textarea"><?= htmlspecialchars($formData['custom_inputs']) ?></textarea>

            <?php if (!empty($generated_template_cfg)): ?>
                <h3 class="gen-section-title">Generated Template Output</h3>
                <textarea readonly class="gen-textarea" style="height:250px;"><?= htmlspecialchars($generated_template_cfg) ?></textarea>
            <?php endif; ?>

            <br>
            <button type="submit" id="save_template_btn" name="save_template" class="gen-btn" style="background: #28a745;">Save Template to /tftpboot/templates/</button>
        </form>
    </div>

     <!-- TAB 3: DEVICE MANAGER -->
<?php
$ovpn_installed = false;
if (class_exists('FreePBX') && \FreePBX::Modules()->checkStatus('ovpn_mgr')) {
    $module_info = \FreePBX::Modules()->getInfo('ovpn_mgr');
    if (!empty($module_info['ovpn_mgr']) && $module_info['ovpn_mgr']['status'] === MODULE_STATUS_ENABLED) {
        $ovpn_installed = true;
    }
}
?>
    <div id="tab_devices" class="gen-tab-content <?= ($formData['active_tab'] === 'tab_devices') ? 'active' : '' ?>">
        <form id="device_manager_form" method="POST">
            <input type="hidden" id="device_active_tab_field" name="active_tab" value="tab_devices">
            <input type="hidden" id="device_action_input" name="device_action" value="">
            <input type="hidden" id="single_ext_input" name="single_ext" value="">
            <input type="hidden" id="single_mac_input" name="single_mac" value="">

            <div style="display:flex; justify-content:space-between; align-items:center;">
                <h3>Registered Extensions & Devices</h3>
                <div style="display:flex; gap:10px; align-items:center;">
                    <button type="button" class="gen-btn" style="margin-top:0; background:#28a745;" onclick="openManualAddModal()">+ Manually Add Device</button>
                    <button type="button" class="gen-btn" style="margin-top:0; background:#17a2b8;" onclick="openScanModal()">Scan Subnet for New Phones</button>
                </div>
            </div>

            <table class="oss-table">
                <thead>
                    <tr>
                        <th style="width: 50px; text-align:center;"><input type="checkbox" onclick="toggleSelectAllPhones(this)"></th>
                        <th>MAC Address</th>
                        <th>IP Address</th>
                        <th>Brand</th>
                        <th>Model</th>
                        <th>Template</th>
                        <th>Assigned Extension</th>
                        <?php if ($ovpn_installed): ?>
                            <th style="text-align:center; width: 100px;">VPN</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($managed_devices)): ?>
                        <tr><td colspan="<?= $ovpn_installed ? '8' : '7' ?>" style="text-align:center; color:#777;">No configured device files found in /tftpboot/</td></tr>
                    <?php else: ?>
                        <?php foreach ($managed_devices as $dev): 
                            $clean_ext = preg_replace('/[^0-9]/', '', $dev['ext']);
                            $is_online = !empty($clean_ext) && isset($online_exts[$clean_ext]);
                            $status_class = $is_online ? 'online' : 'offline';
                            $status_title = $is_online ? "Extension {$dev['ext']} (Online)" : "Extension {$dev['ext']} (Offline / Unregistered)";
                            
                            $vpn_enabled = !empty($dev['openvpn_enabled']);
                            $vpn_connected = !empty($dev['openvpn_connected']);
                        ?>
                            <tr>
                                <td style="text-align:center;">
                                    <div style="display:flex; align-items:center; justify-content:center; gap:8px;">
                                        <button type="button" 
                                                class="oss-btn-icon <?= $status_class ?>" 
                                                title="Rebuild Configuration & Option to Reboot <?= htmlspecialchars($status_title) ?>" 
                                                onclick="openSingleRebuildModal('<?= htmlspecialchars($dev['mac']) ?>', '<?= htmlspecialchars($dev['model']) ?>', '<?= htmlspecialchars($dev['template']) ?>')">&#x23FB;</button>
                                        <input type="checkbox" name="selected_phones[]" class="phone_checkbox" value="<?= htmlspecialchars($dev['mac']) ?>">
                                    </div>
                                </td>
                                <td>
                                    <div style="display:flex; align-items:center; gap:5px;">
                                        <input type="text" 
                                               id="mac_input_<?= htmlspecialchars($dev['mac']) ?>" 
                                               name="edited_mac[<?= htmlspecialchars($dev['mac']) ?>]" 
                                               value="<?= htmlspecialchars($dev['mac']) ?>" 
                                               readonly 
                                               style="padding:4px; font-weight:bold; width:120px; font-family:monospace; text-transform:lowercase; border-radius:4px; border:1px solid #ccc; background-color:#e9ecef;">
                                        
                                        <button type="button" 
                                                title="Edit MAC Address" 
                                                onclick="enableMacEdit('<?= htmlspecialchars($dev['mac']) ?>')" 
                                                style="background:none; border:none; cursor:pointer; padding:2px 4px; display:inline-flex; align-items:center;">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#555" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                            </svg>
                                        </button>

                                        <button type="button" 
                                                title="View Config File" 
                                                onclick="openViewConfigModal('<?= htmlspecialchars($dev['mac']) ?>')" 
                                                style="background:none; border:none; cursor:pointer; padding:2px 4px; display:inline-flex; align-items:center; color:#007bff;">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                                <circle cx="12" cy="12" r="3"></circle>
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($dev['ip'] !== 'Unknown / Offline'): ?>
                                        <a href="http://<?= htmlspecialchars($dev['ip']) ?>" target="_blank" style="text-decoration:none; font-weight:bold; color:#007bff;"><?= htmlspecialchars($dev['ip']) ?></a>
                                    <?php else: ?>
                                        <span style="color:#888; font-style:italic;"><?= htmlspecialchars($dev['ip']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>Yealink</td>
                                <td><?= htmlspecialchars($dev['model']) ?></td>
                                <td>
                                    <select id="phone_tpl_<?= htmlspecialchars($dev['mac']) ?>" name="phone_template[<?= htmlspecialchars($dev['mac']) ?>]" style="padding:4px; border-radius:4px; border:1px solid #ccc;">
                                        <option value="" <?= empty($dev['template']) ? 'selected' : '' ?>>-- None --</option>
                                        <?php foreach ($available_templates as $tpl_file => $tpl_label): ?>
                                            <option value="<?= htmlspecialchars($tpl_file) ?>" <?= ($dev['template'] === $tpl_file) ? 'selected' : '' ?>><?= htmlspecialchars($tpl_label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <select name="phone_extension[<?= htmlspecialchars($dev['mac']) ?>]" style="padding:4px; border-radius:4px; border:1px solid #ccc;">
                                        <option value="">-- Unassigned --</option>
                                        <?php foreach ($all_extensions as $ext_id => $ext_data): ?>
                                            <option value="<?= $ext_id ?>" <?= ($dev['ext'] == $ext_id) ? 'selected' : '' ?>><?= $ext_id ?> - <?= htmlspecialchars($ext_data['display_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>

				<?php if ($ovpn_installed): ?>
                                    <td style="text-align:center;">
                                        <div style="display:inline-flex; align-items:center; justify-content:center;">
                                            <label class="switch" style="margin:0;">
                                                <input type="checkbox" 
                                                       id="vpn_toggle_<?= htmlspecialchars($dev['mac']) ?>" 
                                                       <?= $vpn_enabled ? 'checked' : '' ?> 
                                                       <?= empty($clean_ext) ? 'disabled' : '' ?>
                                                       onchange="toggleOvpnState('<?= $clean_ext ?>', '<?= htmlspecialchars($dev['mac']) ?>', this.checked)">
                                                <span class="slider"></span>
                                            </label>

                                            <span id="vpn_status_light_<?= htmlspecialchars($dev['mac']) ?>" 
                                                  class="status-light <?= $vpn_enabled ? ($vpn_connected ? 'connected' : 'disconnected') : 'disabled' ?>" 
                                                  title="<?= $vpn_enabled ? ($vpn_connected ? 'VPN Connected' : 'VPN Disconnected') : 'VPN Disabled' ?>">
                                            </span>
                                        </div>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="oss-action-card">
                <h4>Selected Phone(s) Options</h4>
                <div class="oss-action-line">
                    <button type="button" class="gen-btn-danger" style="margin:0;" onclick="triggerDeviceAction('delete_selected')">Delete</button>
                    <span>Delete Selected Phones</span>
                </div>
                <div class="oss-action-line" style="flex-wrap: wrap; gap: 10px;">
                    <button type="button" class="gen-btn" style="margin:0; background:#28a745;" onclick="triggerDeviceAction('rebuild_selected')">Rebuild Selected</button>
                    
                    <select name="bulk_selected_template" style="padding: 6px; border-radius: 4px; border: 1px solid #ccc;">
                        <option value="">-- Use Assigned Individual Templates --</option>
                        <?php foreach ($available_templates as $tpl_file => $tpl_label): ?>
                            <option value="<?= htmlspecialchars($tpl_file) ?>"><?= htmlspecialchars($tpl_label) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <span>
                        (<label style="display:inline; font-weight:normal;"><input type="checkbox" name="auto_provision_selected" checked> Push Auto Provision</label>)
                        (<label style="display:inline; font-weight:normal;"><input type="checkbox" name="reboot_selected"> Reboot Phones</label>)
                    </span>
                </div>

                <h4 style="margin-top:20px;">Global Phone Options</h4>
                <div class="oss-action-line">
                    <button type="button" class="gen-btn" style="margin:0; background:#17a2b8;" onclick="triggerDeviceAction('rebuild_all')">Rebuild All</button>
                    <span>
                        Rebuild Configs for All Phones 
                        (<label style="display:inline; font-weight:normal;"><input type="checkbox" name="auto_provision_all" checked> Push Auto Provision</label>)
                        (<label style="display:inline; font-weight:normal;"><input type="checkbox" name="reboot_phones"> Reboot Phones</label>)
                    </span>
                </div>

                <div class="oss-action-line" style="flex-wrap: wrap; gap: 10px; margin-top: 15px;">
                    <button type="button" class="gen-btn" style="margin:0; background:#6c757d;" onclick="triggerDeviceAction('rebuild_filtered')">Rebuild Filtered Group</button>
                    
                    <?php 
                    $registered_models = array_unique(array_filter(array_column($managed_devices, 'model')));
                    sort($registered_models);
                    ?>

                    <select name="global_filter_model" style="padding: 6px; border-radius: 4px; border: 1px solid #ccc;">
                        <option value="">-- Select Model --</option>
                        <?php foreach ($registered_models as $registered_m): ?>
                            <option value="<?= htmlspecialchars($registered_m) ?>"><?= htmlspecialchars($registered_m) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select name="global_filter_template" style="padding: 6px; border-radius: 4px; border: 1px solid #ccc;">
                        <option value="">-- Select Template --</option>
                        <?php foreach ($available_templates as $tpl_file => $tpl_label): ?>
                            <option value="<?= htmlspecialchars($tpl_file) ?>"><?= htmlspecialchars($tpl_label) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <span>
                        (<label style="display:inline; font-weight:normal;"><input type="checkbox" name="auto_provision_filtered" checked> Push Auto Provision</label>)
                        (<label style="display:inline; font-weight:normal;"><input type="checkbox" name="reboot_filtered"> Reboot Phones</label>)
                    </span>
                </div>
            </div>
        </form>
    </div>
</div>