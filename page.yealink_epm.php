<?php
// Execute controller script to process logic, actions, and DB queries first
require_once __DIR__ . '/scripts.yealink_epm.php';

if (!defined('FREEPBX_IS_AUTH')) { 
    die('No direct script access allowed'); 
}
?>

<?php
$epm_tftp_state = function_exists('epm_tftp_status') ? epm_tftp_status() : 'running';
$epm_tftp_dir_missing = !is_dir('/tftpboot');
if ($epm_tftp_state !== 'running' || $epm_tftp_dir_missing):
    $epm_tftp_cmd = 'bash ' . rtrim(realpath(__DIR__) ?: __DIR__, '/') . '/install_tftp.sh';
?>
<div style="background:#fff4e5; border:1px solid #f0b35a; border-left:5px solid #e8890c; border-radius:4px; padding:12px 16px; margin:10px 0 14px 0; color:#5a3a00; font-family: Arial, Helvetica, sans-serif;">
    <strong><?php
        if ($epm_tftp_state !== 'running') { echo 'TFTP is ' . ($epm_tftp_state === 'installed' ? 'installed but not running' : 'not installed') . ' on this server.'; }
        if ($epm_tftp_dir_missing) { echo ($epm_tftp_state !== 'running' ? ' ' : '') . 'The /tftpboot folder is missing, so configs cannot be written and the links that live inside it were not created.'; }
    ?></strong>
    <?= $epm_tftp_state !== 'running' ? "Phones that fetch their configuration over TFTP won't be able to provision. " : '' ?>To fix it, run this command as root
    (click it to copy):
    <div id="epm_tftp_cmd" role="button" tabindex="0" title="Click to copy"
         onclick="epmCopyTftpCmd(this)" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();epmCopyTftpCmd(this);}"
         data-cmd="<?= htmlspecialchars($epm_tftp_cmd) ?>"
         style="margin-top:8px; background:#1e1e1e; color:#e6e6e6; font-family:monospace; font-size:13px; padding:9px 12px; border-radius:4px; cursor:pointer; display:flex; justify-content:space-between; align-items:center; gap:12px; word-break:break-all;">
        <span><?= htmlspecialchars($epm_tftp_cmd) ?></span>
        <span class="epm-tftp-copy" style="flex:0 0 auto; font-size:11px; opacity:.75;"><i class="fa fa-clipboard" aria-hidden="true"></i> Click to copy</span>
    </div>
    <div style="margin-top:6px; font-size:12px; opacity:.85;">It creates /tftpboot and the module's links, installs the TFTP server, links /tftpboot into /var/www/html, sets file permissions and opens UDP 69 in the firewall. Reload this page afterwards.</div>
</div>
<script>
function epmCopyTftpCmd(box) {
    var cmd = box.getAttribute('data-cmd'), tag = box.querySelector('.epm-tftp-copy');
    function done(ok) {
        if (!tag) return;
        tag.innerHTML = ok ? '<i class="fa fa-check"></i> Copied!' : 'Press Ctrl+C to copy';
        setTimeout(function () { tag.innerHTML = '<i class="fa fa-clipboard" aria-hidden="true"></i> Click to copy'; }, 1800);
    }
    function fallback() {
        var ta = document.createElement('textarea');
        ta.value = cmd; ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta); ta.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
        done(ok);
    }
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(cmd).then(function () { done(true); }, fallback);
    } else { fallback(); }
}
</script>
<?php endif; ?>

<!-- ============================================================================ -->
<!-- HTML VIEW & STYLES                                                           -->
<!-- ============================================================================ -->

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/noUiSlider/15.7.1/nouislider.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/noUiSlider/15.7.1/nouislider.min.js"></script>

<style>

    .shadow-box { border: none; background: #fff; outline: none; box-shadow: 2px 2px 5px #307847; }
    .sans-font { font-family: Arial, Helvetica, san-sarif; }
    .box-bg { background: #f7f7f7; }

    .gen-container { background: #fff; padding: 20px; border-radius: 6px; overflow: visible; }
    .gen-container label { font-weight: bold; display: block; margin-top: 10px; }
    .gen-container input[type="text"], .gen-container select, .gen-container input[type="file"] { padding: 12px 14px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
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
    .gen-modal-content { background: #fff; margin: 8% auto; padding: 20px; width: 65%; border-radius: 8px; max-height: 80vh; overflow-y: auto; }
    /* Key boxes (line keys / memory keys / programmable keys) */
    .gen-key-btn-reset { background: #6c757d; }
    .gen-key-note { color: #555; font-size: 13px; margin: 0 0 6px; }
    .gen-key-num { flex: none; width: 28px; align-self: center; text-align: right; color: #666; font-size: 12px; }
    .gen-kh { display: flex; gap: 10px; margin-top: 12px; font-size: 12px; font-weight: bold; color: #666; }
    .gen-kh span { flex: 1; }
    .gen-kh .gen-key-num { flex: none; }
    .gen-key-pages { flex-wrap: wrap; gap: 6px; margin-top: 10px; }
    .gen-key-page { padding: 5px 10px; border: 0px solid #ced4da; border-radius: 6px 6px 0 0; background: #3e694c; color: #fff; cursor: pointer; font-size: 13px; }
    .gen-key-page.active { background: #d3e8da; border-color: #237a4b; color: #3e694c; font-weight: 600; }
    .gen-key-sortable { cursor: pointer; user-select: none; }
    .gen-key-sortable:hover { color: #237a4b; }
    .gen-key-sortable i.fa { margin-left: 3px; }
    .gen-key-sortable .sort-caret { margin-left: 3px; display: inline-block; font-size: 11px; }
    .gen-key-draggable { cursor: grab; user-select: none; }
    .gen-key-draggable:active { cursor: grabbing; }
    .gen-key-row.gen-key-dragging { opacity: 0.4; }
    .gen-key-row.gen-key-drop-target { outline: 2px dashed #307847; outline-offset: -2px; }
    .gen-key-empty { color: #666; margin-top: 12px; }
    .gen-pk-name { font-size: 13px; font-weight: bold; }
    .gen-pk-row, .gen-pk-head { display: grid; grid-template-columns: 84px 1.5fr 1fr 1.2fr 1.2fr; gap: 10px; align-items: center; }
    .gen-lk-row, .gen-lk-head { display: grid; grid-template-columns: 28px 1.1fr 1.5fr 1.5fr 1.5fr 1fr; gap: 10px; align-items: center; }
    .gen-mk-row, .gen-mk-head { display: grid; grid-template-columns: 28px 1.1fr 1.5fr 1.5fr 1.5fr 1fr; gap: 10px; align-items: center; }
    .gen-pk-row > *, .gen-pk-head > *, .gen-lk-row > *, .gen-lk-head > *, .gen-mk-row > *, .gen-mk-head > * { min-width: 0; }
    .gen-pk-row input, .gen-pk-row select, .gen-lk-row input, .gen-lk-row select, .gen-mk-row input, .gen-mk-row select { width: 100%; box-sizing: border-box; }
    .pk-na { display: block; padding: 8px; border: 0px solid #dee2e6; border-radius: 4px; background: transparent; color: #888; font-size: 13px; box-sizing: border-box; }

    /* ---- Template tab dashboard (movable boxes) ---- */
    .epm-dash-bar { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: 14px; }
    .epm-dash-hint { color: #777; font-size: 12px; }
    .epm-dash-reset { margin: 0 !important; padding: 4px 10px !important; font-size: 12px; background: #6c757d !important; white-space: nowrap; }
    /* Two explicit, independently-ordered columns. Boxes are dragged directly into
       column 1 or column 2 and stack immediately under whatever is already in that
       column, with no need to line up with the other column. A box toggled to full
       width becomes its own full-bleed band and splits the columns above/below it. */
    .epm-dash { margin-top: 12px; }
    .epm-band { display: flex; align-items: flex-start; gap: 20px; }
    .epm-band + .epm-band { margin-top: 20px; }
    .epm-band.epm-band-full { display: block; }
    .epm-col { flex: 1 1 0; min-width: 0; display: flex; flex-direction: column; gap: 20px; }
    .epm-col.epm-col-drop-target { outline: 2px dashed #007bff; outline-offset: 4px; border-radius: 6px; }
    .epm-box { border: 1px solid #ced4da; border-radius: 6px; background: #fff; min-width: 0; box-shadow: 0 1px 2px rgba(0,0,0,.05); margin-bottom: 0; overflow: hidden; }
    .epm-box.epm-dragging { opacity: .45; outline: 2px dashed #007bff; }
    .epm-box-head { display: flex; align-items: center; gap: 8px; padding: 7px 10px; background: #f1f3f5; border-bottom: 1px solid #ced4da; border-radius: 6px 6px 0 0; min-width: 0; }
    .epm-folded .epm-box-head { border-bottom: none; border-radius: 6px; }
    .epm-grip { cursor: move; color: #888; padding: 2px 4px; }
    .epm-grip:hover { color: #007bff; }
    .epm-box-title { white-space: nowrap; }
    .epm-box-sub { flex: 1; min-width: 0; color: #666; font-size: 12px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .epm-box-tools { display: flex; gap: 2px; margin-left: auto; }
    .epm-tool { background: none; border: none; color: #666; cursor: pointer; padding: 2px 6px; border-radius: 3px; }
    .epm-tool:hover { background: #dee2e6; color: #222; }
    .epm-folded .epm-tool[data-act="fold"] i { transform: rotate(180deg); }
    .epm-folded .epm-box-body { display: none; }
    .epm-box-body { padding: 10px; container-type: inline-size; }
    .epm-toolbar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 6px; }
    .epm-toolbar label { margin: 0 !important; }
    .gen-container .epm-toolbar select { width: auto; }
    .epm-toolbar-note { color: #666; font-size: 12px; flex: 1; min-width: 140px; }
    .epm-toolbar .gen-key-btn-reset { padding: 4px 10px; border: none; border-radius: 4px; color: #fff; cursor: pointer; font-size: 12px; }
    .epm-scroll { max-height: 440px; overflow: auto; padding-right: 4px; }
    .epm-scroll .gen-kh { position: sticky; top: 0; z-index: 1; background: #fff; margin-top: 0; padding: 2px 0; }
    .epm-box .gen-key-row { margin-top: 3px; }
    .epm-box .gen-lk-row, .epm-box .gen-lk-head { gap: 6px; grid-template-columns: 24px minmax(0, 1.05fr) minmax(0, 1.25fr) minmax(0, 1.25fr) minmax(0, .9fr) minmax(0, .9fr); }
    .epm-box .gen-mk-row, .epm-box .gen-mk-head { gap: 6px; grid-template-columns: 24px minmax(0, 1.05fr) minmax(0, 1.25fr) minmax(0, 1.25fr) minmax(0, .9fr) minmax(0, .9fr); }
    .epm-box .gen-pk-row, .epm-box .gen-pk-head { gap: 6px; grid-template-columns: 78px minmax(0, 1.4fr) minmax(0, 1fr) minmax(0, 1.2fr) minmax(0, 1.1fr); }
    .gen-container .epm-box input[type="text"], .gen-container .epm-box select { padding: 10px 12px; height: auto; font-size: 13px; }
    .epm-box .pk-na { padding: 4px 6px; height: 28px; font-size: 12px; }
    .epm-box .gen-pk-name { font-size: 12px; }
    .epm-box .gen-kh { gap: 6px; font-size: 11px; }
    .epm-box .ringtone-card { margin-top: 8px; padding: 10px; }
    .epm-box .ringtone-card:first-child { margin-top: 0; }
    .epm-box .epm-sub-grid > .ringtone-card { margin-top: 0; }
    .epm-template-fields { display: grid; gap: 8px 14px; margin-bottom: 8px; }
    .epm-template-fields:last-child { margin-bottom: 0; }
    .epm-template-two-col { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .epm-template-three-col { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .epm-template-fields > div { min-width: 0; }
    .epm-template-fields label { margin-top: 0 !important; margin-bottom: 4px; }
    .epm-template-fields input, .epm-template-fields select { width: 100%; min-width: 0; box-sizing: border-box; }
    @media (max-width: 760px) {
        .epm-template-two-col, .epm-template-three-col { grid-template-columns: minmax(0, 1fr); }
    }

    .epm-box .gen-full-width { width: 100%; }
    .epm-sub-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 0 10px; margin-top: 0; }
    .epm-stack > div { margin-bottom: 4px; }
    .epm-box .ringtone-player-controls audio { max-width: 100%; }
    .epm-box #selected_files_textarea { max-width: 100%; }

    /* Narrow boxes: reflow instead of overflowing */
    @container (min-width: 700px) { .epm-sub-grid { grid-template-columns: minmax(0, 2fr) minmax(0, 3fr); } }
    @container (max-width: 640px) {
        .ringtone-grid-item { grid-template-columns: minmax(0, 1fr) auto auto; }
        .ringtone-grid-item > :nth-child(1) { grid-column: 1; grid-row: 1; }
        .ringtone-grid-item > :nth-child(2) { grid-column: 2; grid-row: 1; }
        .ringtone-grid-item > :nth-child(5) { grid-column: 3; grid-row: 1; }
        .ringtone-grid-item > :nth-child(3) { grid-column: 1; grid-row: 2; min-width: 0; }
        .ringtone-grid-item > :nth-child(4) { grid-column: 2 / 4; grid-row: 2; }
        .epm-box .gen-pk-head { display: none; }
        .epm-box .gen-pk-row { grid-template-columns: 78px repeat(4, minmax(0, 1fr)); padding-bottom: 6px; border-bottom: 1px solid #eee; margin-top: 6px; }
        .epm-box .gen-pk-row > select[name$="_type"] { grid-column: 2 / 4; }
        .epm-box .gen-pk-row > .gen-pk-slot-linehist { grid-column: 4 / 6; }
        .epm-box .gen-pk-row > .gen-pk-slot-value { grid-column: 2 / 4; }
        .epm-box .gen-pk-row > .gen-pk-slot-label { grid-column: 4 / 6; }
    }
    @media (max-width: 1000px) {
        .epm-band { flex-direction: column; gap: 16px; }
        .epm-band + .epm-band { margin-top: 16px; }
        .epm-col { gap: 16px; }
    }

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

    .ringtone-card { border: 1px solid #ccc; border-radius: 6px; padding: 12px; background: #f7f7f7; margin-top: 10px; }
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
    .action-icon-btn.loop-icon-btn.active {
        color: #007bff;
        background-color: #e7f1ff;
    }
    
    .upload-controls-col {
        display: flex !important;
        flex-direction: row !important;
        flex-wrap: wrap !important;
        align-items: center !important;
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

    /* One Line Keys dashboard box containing the legacy modal-launch buttons */
    .epm-key-overview { display:flex; flex-direction:column; gap:8px; }
    .epm-key-overview-row { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:10px 12px; min-height:58px; border:1px solid #ced4da; border-radius:5px; background:#f8f9fa; box-sizing:border-box; }
    .epm-key-overview-label { display:flex; flex-direction:column; gap:5px; min-width:0; }
    .epm-key-overview-label strong { color:#343a40; font-size:14px; }
    .epm-key-summary { color:#0c5460; background:#e7f3fe; border-left:3px solid #2196F3; padding:4px 8px; font-size:12px; line-height:1.35; }
    .epm-key-edit { flex:0 0 auto; display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border:1px solid #b8c7d1; border-radius:4px; background:#e7f3fe; color:#245269; cursor:pointer; font-weight:600; white-space:nowrap; }
    .epm-key-edit:hover { background:#d5eaf9; border-color:#91b7cc; }
    .epm-key-modal { display:flex; position:fixed; inset:0; z-index:20000; background:rgba(0,0,0,.48); padding:4vh 18px; box-sizing:border-box; align-items:center; justify-content:center; visibility:hidden; opacity:0; pointer-events:none; transition:opacity .22s ease, visibility 0s linear .30s; }
    .epm-key-modal.is-open { visibility:visible; opacity:1; pointer-events:auto; transition:opacity .22s ease, visibility 0s linear 0s; }
    .epm-key-modal-dialog { display:flex; flex-direction:column; width:min(1180px, 96vw); max-height:92vh; background:#fff; border:1px solid #aaa; border-radius:5px; box-shadow:0 8px 32px rgba(0,0,0,.35); overflow:hidden; opacity:0; transform:scale(.88) translateY(18px); transform-origin:center center; transition:transform .28s cubic-bezier(.2,.75,.25,1), opacity .22s ease; }
    .epm-key-modal.is-open .epm-key-modal-dialog { opacity:1; transform:scale(1) translateY(0); }
    @media (prefers-reduced-motion: reduce) { .epm-key-modal, .epm-key-modal-dialog { transition:none !important; } }
    .epm-key-modal-head { display:flex; align-items:center; justify-content:space-between; gap:15px; padding:13px 16px; border-bottom:1px solid #ced4da; background:#f8f9fa; color:#555; font-size:16px; flex:0 0 auto; }
    .epm-key-modal-x { border:0; background:transparent; color:#777; font-size:25px; line-height:1; cursor:pointer; padding:0 2px; }
    .epm-key-modal-content { padding:12px 16px; overflow:auto; min-height:0; }
    .epm-key-modal-content .epm-key-editor { border:0; box-shadow:none; margin:0; background:#fff; }
    .epm-key-modal-content .epm-key-editor > .epm-box-body { padding:0; }
    .epm-key-modal-foot { display:flex; align-items:center; gap:12px; padding:10px 16px; border-top:1px solid #ced4da; background:#f8f9fa; flex:0 0 auto; }
    .epm-key-modal-note { color:#6c757d; font-size:12px; flex:1; }
    .epm-key-modal-close { margin:0 !important; background:#6c757d !important; }
    body.epm-key-modal-open { overflow:hidden; }
    @media (max-width:700px) {
      .epm-key-overview-row { align-items:flex-start; flex-direction:column; }
      .epm-key-edit { align-self:flex-start; }
      .epm-key-modal { padding:2vh 8px; }
      .epm-key-modal-dialog { width:98vw; max-height:96vh; }
      .epm-key-modal-content { padding:8px; }
    }


    /* OpenVPN Manager-inspired teal/green theme */
    :root {
        --epm-teal: #0b5960;
        --epm-border: #5b9e8e;
        --epm-pale-green: #d4edda;
        --epm-success: #5cb85c;
        --epm-info: #5bc0de;
        --epm-warning: #f0ad4e;
        --epm-danger: #d9534f;
    }
    .gen-tab-bar { border-bottom-color: var(--epm-border) !important; }
    .gen-tab-btn { color: var(--epm-teal); background: #eef4f1; border-color: #b7d3c7; }
    .gen-tab-btn.active { background: var(--epm-teal) !important; border-color: var(--epm-teal) !important; color: #fff !important; }
    .gen-section-title { border-bottom-color: var(--epm-border) !important; color: var(--epm-teal) !important; }
    .epm-box { border-color: var(--epm-border) !important; }
    .epm-box-head { background: #ddecec !important; border-bottom-color: var(--epm-border) !important; color: var(--epm-teal) !important; }
    .epm-box-title { color: var(--epm-teal) !important; }
    .epm-grip:hover, .epm-tool:hover { color: var(--epm-teal) !important; }
    .epm-tool:hover { background: #c9e1d7 !important; }
    .epm-dash-reset, .gen-key-btn-reset { background: #6c757d !important; }
    .epm-key-overview-row { border-color: #b7d3c7 !important; background: #f5f8f6 !important; }
    .epm-key-overview-label strong { color: var(--epm-teal) !important; }
    .epm-key-summary, .spec-note { background: #e3f0ec !important; border-left-color: var(--epm-border) !important; color: var(--epm-teal) !important; }
    .epm-key-edit { background: #5bc0de !important; border-color: #46b8da !important; color: #fff !important; }
    .epm-key-edit:hover { background: #31b0d5 !important; border-color: #269abc !important; }
    .epm-key-modal-dialog { border-color: var(--epm-border) !important; }
    .epm-key-modal-head, .epm-key-modal-foot { background: #ddecec !important; border-color: var(--epm-border) !important; }
    .epm-key-modal-head { color: var(--epm-teal) !important; }
    .epm-key-modal-x { color: var(--epm-teal) !important; }
    .epm-key-modal-close { background: #6c757d !important; }
    .ringtone-card, .oss-action-card { border-color: #b7d3c7 !important; background: #FCFCFC !important; }
    .ringtone-list-container { border-color: #c6ddd4 !important; }
    .ringtone-size-badge { background: #e3ece8 !important; color: #315e54 !important; }
    .oss-table th, .scan-table th { background: #d6e4dd !important; }
    .oss-table th, .oss-table td { border-color: var(--epm-border) !important; }
    .gen-alert { background: var(--epm-pale-green) !important; color: #155724 !important; }
    .flush-banner { background: #fcf8e3 !important; border-color: #faebcc !important; border-left-color: var(--epm-warning) !important; color: #8a6d3b !important; }
    .warning-box { background: #fcf8e3 !important; border-color: #faebcc !important; border-left-color: var(--epm-warning) !important; color: #8a6d3b !important; }
    .gen-btn { background: var(--epm-success); }
    .gen-btn:hover { background: #449d44; }
    .gen-btn-danger, .gen-key-btn-cancel { background: var(--epm-danger) !important; }
    .upload-btn-aligned { background: #17a2b8 !important; }
    .custom-file-btn { background: #6c757d !important; }
    .noUi-connect { background: var(--epm-border) !important; }
    @media (prefers-reduced-motion: reduce) { .epm-key-modal, .epm-key-modal-dialog { transition: none !important; } }

    /* Green-forward OpenVPN palette refinement */
    :root {
        --epm-teal: #155b3b;
        --epm-border: #65a783;
        --epm-pale-green: #dcefe2;
        --epm-success: #28a745;
        --epm-info: #48a878;
        --epm-warning: #e5a23b;
        --epm-danger: #d9534f;
    }
    .epm-box { border-color: #65a783 !important; }
    .epm-box-head { background: #dcefe2 !important; border-bottom-color: #65a783 !important; }
    .epm-box-title, .epm-box-head { color: #155b3b !important; }
    .gen-tab-btn.active { background: #237a4b !important; border-color: #237a4b !important; }
    .gen-tab-bar, .gen-section-title { border-bottom-color: #65a783 !important; }
    .epm-key-overview-row { background: #F7F7F7 !important; border-color: #b8d8c3 !important; }
    .epm-key-summary, .spec-note { background: #e4f2e8 !important; border-left-color: #48a878 !important; color: #155b3b !important; }
    .epm-key-edit { background: #48a878 !important; border-color: #348e62 !important; color: #fff !important; }
    .epm-key-edit:hover { background: #348e62 !important; border-color: #28784f !important; }
    .epm-key-modal-head, .epm-key-modal-foot { background: ##EEF7F1 !important; border-color: #65a783 !important; }
    .epm-key-modal-dialog { border-color: #65a783 !important; }
    .ringtone-card, .oss-action-card { background: #F7F7F7 !important; border-color: #b8d8c3 !important; }
    .ringtone-list-container { border-color: #c7e0cf !important; }
    .ringtone-size-badge { background: #e1eee5 !important; color: #285d3f !important; }
    .oss-table th, .scan-table th { background: #dcefe2 !important; }
    .oss-table th, .oss-table td { border-color: #65a783 !important; }
    .gen-btn { background: #28a745 !important; }
    .gen-btn:hover { background: #218838 !important; }
    .upload-btn-aligned { background: #48a878 !important; }
    .noUi-connect { background: #48a878 !important; }

    /* Alternating green-tinted slot rows, similar to OpenVPN's striped tables */
    .epm-key-modal-content .gen-lk-row:nth-child(odd),
    .epm-key-modal-content .gen-mk-row:nth-child(odd),
    .epm-key-modal-content .gen-pk-row:nth-of-type(even) {
        background: #eaf4ed !important;
    }
    .epm-key-modal-content .gen-lk-row:nth-child(even),
    .epm-key-modal-content .gen-mk-row:nth-child(even),
    .epm-key-modal-content .gen-pk-row:nth-of-type(odd) {
        background: #f8fbf9 !important;
    }
    .epm-key-modal-content .gen-lk-row,
    .epm-key-modal-content .gen-mk-row,
    .epm-key-modal-content .gen-pk-row {
        border-bottom: 1px solid #c9dfd0 !important;
        padding: 5px 6px;
        border-radius: 3px;
    }
    .epm-key-modal-content .gen-lk-row:hover,
    .epm-key-modal-content .gen-mk-row:hover,
    .epm-key-modal-content .gen-pk-row:hover {
        background: #dcefe2 !important;
    }
    /* Notifications dialog rows: same stripes / hover as the key editors */
    .epm-notif-row { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:9px 10px; border-bottom:1px solid #c9dfd0; border-radius:3px; }
    .epm-notif-row:nth-child(odd) { background:#eaf4ed; }
    .epm-notif-row:nth-child(even) { background:#f8fbf9; }
    .epm-notif-row:hover { background:#dcefe2; }

    .epm-key-modal-content .gen-lk-head,
    .epm-key-modal-content .gen-mk-head,
    .epm-key-modal-content .gen-pk-head {
        background: #d3e8da !important;
        color: #155b3b !important;
        border-bottom: 1px solid #65a783;
    }

</style>

<!-- ============================================================================ -->
<!-- JAVASCRIPT CONTROLLERS                                                       -->
<!-- ============================================================================ -->

<script>
function toggleOvpnState(ext, mac, enable) {
    if (!ext) {
        epmAlert("Please assign an extension to this device first.");
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

    // If the server never answers, don't leave the switch greyed-out (red "not allowed"
    // cursor) forever: give up after 60s and hand control back to the user.
    var ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    var timer = ctrl ? setTimeout(function () { ctrl.abort(); }, 60000) : null;

    fetch('?display=yealink_epm&action=toggle_ovpn_state', {
        method: 'POST',
        body: formData,
        signal: ctrl ? ctrl.signal : undefined
    })
    .then(res => res.json())
    .then(data => {
        if (timer) clearTimeout(timer);
        toggleElem.disabled = false;
        if (data.status !== 'success') {
            epmAlert(data.message || 'Error updating VPN state.');
            toggleElem.checked = !enable;
        } else {
            if (statusLight) {
                if (enable) {
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
    .catch(err => {
        if (timer) clearTimeout(timer);
        toggleElem.disabled = false;
        toggleElem.checked = !enable;
        console.error('toggleOvpnState failed:', err);
        if (err && err.name === 'AbortError') {
            epmAlert('The server did not answer within 60 seconds, so the switch was re-enabled. The change may still be running - reload the page to see the current state.');
        } else {
            epmAlert('Communication error with FreePBX backend.');
        }
    });
}
</script>

<script>
    var scannedDeviceMacs = [];
    var ringtoneFileSizes = <?= json_encode($ringtone_file_sizes ?? []) ?>;
    var initialRingtoneStates = {};
    var assignedRingtoneReferences = <?= json_encode($assigned_ringtone_references ?? (object)[]) ?>;
    var initialShowFlushBtn = <?= json_encode($show_flush_ringtone_btn ?? false) ?>;
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
        "T34W": { ringFormats: ".wav, .mp3", ringSize: "Max 2MB", maxRingtone: "10", totalLimit: 10485760, logoFormat: ".jpg, .png, .bmp", logoRes: "320 x 240", logoSize: "Max 2MB" },
		"T40P":   { ringFormats: ".wav, .mp3", ringSize: "Max 100KB", maxRingtone: "5", totalLimit: 102400, logoFormat: "Monochrome BMP", logoRes: "132 x 64", logoSize: "Max 20KB" },
        "T41S":   { ringFormats: ".wav, .mp3", ringSize: "Max 100KB", maxRingtone: "10", totalLimit: 102400, logoFormat: "Monochrome BMP", logoRes: "192 x 64", logoSize: "Max 30KB" },
        "T42S":   { ringFormats: ".wav, .mp3", ringSize: "Max 100KB", maxRingtone: "10", totalLimit: 102400, logoFormat: "Monochrome BMP", logoRes: "192 x 64", logoSize: "Max 30KB" },
        "T43U":   { ringFormats: ".wav, .mp3", ringSize: "Max 300KB", maxRingtone: "10", totalLimit: 307200, logoFormat: "Monochrome BMP", logoRes: "370 x 160", logoSize: "Max 50KB" },
        "T44U": { ringFormats: ".wav, .mp3", ringSize: "Max 300KB", maxRingtone: "10", totalLimit: 307200, logoFormat: "Monochrome BMP", logoRes: "370 x 160", logoSize: "Max 50KB" },
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
            epmAlert("Please select a template to download first.");
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

    function toggleAudioLoopBtn(btn, playerId) {
        var player = document.getElementById(playerId);
        if (!player) { return; }
        var shouldLoop = !player.loop;
        player.loop = shouldLoop;
        btn.classList.toggle('active', shouldLoop);
        btn.setAttribute('aria-pressed', shouldLoop ? 'true' : 'false');
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
                epmAlert("Failed to fetch ringtone for editing.");
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
            <div style="display:flex; align-items:center; gap:2px;">
                <button type="button" class="action-icon-btn loop-icon-btn" title="Loop ${filename}" aria-pressed="false" onclick="toggleAudioLoopBtn(this, 'audio_player_${cleanId}')">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="17 1 21 5 17 9"></polyline>
                        <path d="M3 11V9a4 4 0 0 1 4-4h14"></path>
                        <polyline points="7 23 3 19 7 15"></polyline>
                        <path d="M21 13v2a4 4 0 0 1-4 4H3"></path>
                    </svg>
                </button>
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
                epmAlert('Error cropping audio: ' + data.message);
                uploadBtn.disabled = false;
                uploadBtn.innerText = 'Upload Ringtones';
            }
        })
        .catch(err => {
            epmAlert('Error processing audio request.');
            uploadBtn.disabled = false;
            uploadBtn.innerText = 'Upload Ringtones';
        });
    }

    function uploadRingtonesAsync(event) {
        if (event) event.preventDefault();
        
        var fileInput = document.getElementById('ringtone_file_input');
        var files = fileInput.files;
        
        if (!files || files.length === 0) {
            epmAlert('Please select files first using the Browse button.');
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
                    epmAlert('Upload failed for ' + file.name + ': ' + data.message);
                    uploadBtn.disabled = false;
                    uploadBtn.innerText = 'Upload Ringtones';
                }
            })
            .catch(function(err) {
                epmAlert('Error uploading ' + file.name + '.');
                uploadBtn.disabled = false;
                uploadBtn.innerText = 'Upload Ringtones';
            });
        }

        processNext();
    }

    function updateExpWallpaperFileLabel(input) {
        var label = document.getElementById('exp_wallpaper_file_label');
        if (label) {
            label.textContent = (input.files && input.files.length) ? input.files[0].name : 'No file selected';
        }
    }

    // Uploads the selected expansion-module wallpaper image immediately (before Save
    // Template is clicked), so it shows up in the "Existing file" dropdown right away.
    function uploadExpWallpaperAsync(event) {
        if (event) event.preventDefault();

        var fileInput = document.getElementById('exp_wallpaper_file_input');
        var files = fileInput ? fileInput.files : null;

        if (!files || files.length === 0) {
            epmAlert('Please select a wallpaper image first using Browse Files.');
            return;
        }

        var uploadBtn = document.getElementById('exp_wallpaper_upload_btn');
        uploadBtn.disabled = true;
        uploadBtn.innerText = 'Uploading...';

        var formDataWp = new FormData();
        formDataWp.append('single_wallpaper_ajax', '1');
        formDataWp.append('wallpaper_file', files[0]);

        fetch(window.location.href, {
            method: 'POST',
            body: formDataWp
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.status === 'success') {
                var sel = document.getElementById('select_exp_wallpaper_file');
                if (sel) {
                    var exists = false;
                    for (var i = 0; i < sel.options.length; i++) {
                        if (sel.options[i].value === data.filename) { exists = true; break; }
                    }
                    if (!exists) {
                        var opt = document.createElement('option');
                        opt.value = data.filename;
                        opt.textContent = data.filename;
                        sel.appendChild(opt);
                    }
                    sel.value = data.filename;
                }
                fileInput.value = '';
                updateExpWallpaperFileLabel(fileInput);
                uploadBtn.innerText = 'Upload Complete!';
                setTimeout(function () {
                    uploadBtn.disabled = false;
                    uploadBtn.innerText = 'Upload';
                }, 1500);
            } else {
                epmAlert('Upload failed: ' + data.message);
                uploadBtn.disabled = false;
                uploadBtn.innerText = 'Upload';
            }
        })
        .catch(function () {
            epmAlert('Error uploading wallpaper image.');
            uploadBtn.disabled = false;
            uploadBtn.innerText = 'Upload';
        });
    }

    // ---------------------------------------------------------------------
    // Generic in-page replacements for window.alert() / window.confirm().
    // Native browser dialogs can be permanently silenced by the visitor via
    // "Prevent this page from creating additional dialogs", which then makes
    // alert()/confirm() resolve instantly (as if OK/Cancel were pre-clicked)
    // for the rest of the page's life. These custom modals can't be muted
    // that way, so every alert()/confirm() in this file should route through
    // epmAlert()/epmConfirm() instead of the native functions.
    // ---------------------------------------------------------------------
    var epmAlertCallback = null;

    function epmAlert(message, callback) {
        var modal = document.getElementById('epmAlertModal');
        if (!modal) { window.alert(message); if (typeof callback === 'function') callback(); return; }
        document.getElementById('epmAlertModalMessage').textContent = message;
        epmAlertCallback = (typeof callback === 'function') ? callback : null;
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
        var ok = document.getElementById('epmAlertModalOk');
        if (ok) ok.focus();
    }

    function epmAlertOk() {
        var modal = document.getElementById('epmAlertModal');
        if (modal) {
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
        }
        var cb = epmAlertCallback;
        epmAlertCallback = null;
        if (cb) cb();
    }

    var epmConfirmCallback = null;

    function epmConfirm(message, onConfirm, opts) {
        var modal = document.getElementById('epmConfirmModal');
        if (!modal) { if (window.confirm(message) && typeof onConfirm === 'function') onConfirm(); return; }
        document.getElementById('epmConfirmModalMessage').textContent = message;
        document.getElementById('epmConfirmModalTitle').textContent = (opts && opts.title) || 'Please confirm';
        document.getElementById('epmConfirmModalOk').textContent = (opts && opts.okLabel) || 'OK';
        epmConfirmCallback = (typeof onConfirm === 'function') ? onConfirm : null;
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
        var cancel = document.getElementById('epmConfirmModalCancel');
        if (cancel) cancel.focus();
    }

    function epmConfirmAnswer(result) {
        var modal = document.getElementById('epmConfirmModal');
        if (modal) {
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
        }
        var cb = epmConfirmCallback;
        epmConfirmCallback = null;
        if (result && cb) cb();
    }

    var epmPendingDelete = null;

    // Use an in-page confirmation for ringtone/logo deletion. Unlike window.confirm(),
    // this cannot be suppressed by the browser's "don't allow this page to prompt again" setting.
    function confirmDeleteFile(filename, fileType) {
        if (!filename || filename === 'system') {
            epmAlert("Please select a valid file to delete.");
            return false;
        }

        if (fileType === 'ringtone' || fileType === 'logo') {
            epmPendingDelete = { filename: filename, fileType: fileType };
            var modal = document.getElementById('epmDeleteConfirmModal');
            var name = document.getElementById('epmDeleteConfirmFilename');
            var kind = document.getElementById('epmDeleteConfirmKind');
            name.textContent = filename;
            kind.textContent = (fileType === 'logo') ? 'logo / wallpaper' : 'ringtone';
            modal.style.display = 'flex';
            modal.setAttribute('aria-hidden', 'false');
            document.getElementById('epmDeleteConfirmCancel').focus();
            return false;
        }

        // Keep existing confirmation behavior for other file types.
        epmConfirm("Are you sure you want to permanently delete '" + filename + "' from the server?", function () {
            submitDeleteFile(filename, fileType);
        }, { title: 'Confirm deletion', okLabel: 'Delete permanently' });
        return false;
    }

    function closeDeleteConfirmModal() {
        var modal = document.getElementById('epmDeleteConfirmModal');
        if (modal) {
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
        }
        epmPendingDelete = null;
    }

    function submitConfirmedDeleteFile() {
        if (!epmPendingDelete) return;
        var pending = epmPendingDelete;
        closeDeleteConfirmModal();
        submitDeleteFile(pending.filename, pending.fileType);
    }

    function submitDeleteFile(filename, fileType) {
        var targetForm = document.getElementById('delete_file_form');
        document.getElementById('target_filename').value = filename;
        document.getElementById('target_file_type').value = fileType;

        if (['ringtone', 'logo', 'template'].includes(fileType)) {
            document.getElementById('delete_active_tab').value = 'tab_template';
            epmStore(EPM_SCROLL_KEY, 'ringtone_section');
            targetForm.action = window.location.pathname + '?display=yealink_epm';
        }
        targetForm.submit();
    }

    var epmPendingVpnDisable = null;

    // Turning VPN ON is non-destructive - goes straight through. Turning it OFF
    // revokes the phone's cert (see toggleOvpnState/revokeExtensionAndRestart),
    // so gate that behind the same style of in-page confirmation as ringtone/logo
    // deletion, and revert the switch if the user backs out.
    function handleVpnToggleChange(checkbox, ext, mac) {
        if (checkbox.checked) {
            toggleOvpnState(ext, mac, true);
            return;
        }

        epmPendingVpnDisable = { ext: ext, mac: mac };
        var modal = document.getElementById('epmVpnDisableConfirmModal');
        document.getElementById('epmVpnDisableConfirmExt').textContent = ext || '(unassigned)';
        document.getElementById('epmVpnDisableConfirmMac').textContent = mac;
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
        document.getElementById('epmVpnDisableConfirmCancel').focus();
    }

    function closeVpnDisableModal() {
        var modal = document.getElementById('epmVpnDisableConfirmModal');
        if (modal) {
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
        }
        if (epmPendingVpnDisable) {
            var cb = document.getElementById('vpn_toggle_' + epmPendingVpnDisable.mac);
            if (cb) cb.checked = true;
        }
        epmPendingVpnDisable = null;
    }

    function submitConfirmedVpnDisable() {
        if (!epmPendingVpnDisable) return;
        var pending = epmPendingVpnDisable;
        var modal = document.getElementById('epmVpnDisableConfirmModal');
        if (modal) {
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
        }
        epmPendingVpnDisable = null;
        toggleOvpnState(pending.ext, pending.mac, false);
    }

    var epmPendingBulkDelete = false;

    function confirmDeleteSelectedPhones() {
        var checked = document.querySelectorAll('.phone_checkbox:checked');
        if (checked.length === 0) {
            epmAlert('Please select at least one phone to delete.');
            return;
        }

        var macs = Array.prototype.map.call(checked, function (cb) { return cb.value; });
        epmPendingBulkDelete = true;
        var modal = document.getElementById('epmBulkDeleteConfirmModal');
        document.getElementById('epmBulkDeleteConfirmCount').textContent =
            macs.length + (macs.length === 1 ? ' phone' : ' phones');
        document.getElementById('epmBulkDeleteConfirmList').textContent = macs.join(', ');
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
        document.getElementById('epmBulkDeleteConfirmCancel').focus();
    }

    function closeBulkDeleteModal() {
        var modal = document.getElementById('epmBulkDeleteConfirmModal');
        if (modal) {
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
        }
        epmPendingBulkDelete = false;
    }

    function submitConfirmedBulkDelete() {
        if (!epmPendingBulkDelete) return;
        epmPendingBulkDelete = false;
        var modal = document.getElementById('epmBulkDeleteConfirmModal');
        if (modal) {
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
        }
        triggerDeviceAction('delete_selected');
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            if (epmPendingDelete) closeDeleteConfirmModal();
            if (epmPendingVpnDisable) closeVpnDisableModal();
            if (epmPendingBulkDelete) closeBulkDeleteModal();
            var alertModal = document.getElementById('epmAlertModal');
            if (alertModal && alertModal.style.display !== 'none') epmAlertOk();
            var confirmModal = document.getElementById('epmConfirmModal');
            if (confirmModal && confirmModal.style.display !== 'none') epmConfirmAnswer(false);
        }
    });

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

    // ---- Tab / scroll memory -------------------------------------------------
    // Kept in sessionStorage (per browser tab, never shown in the address bar) instead of
    // #anchors in the URL. A stale "#tab_devices" in the URL used to override the tab you were
    // actually working in, sending every reload back to Device Manager.
    var EPM_TAB_KEY    = 'yealink_epm_active_tab';
    var EPM_SCROLL_KEY = 'yealink_epm_scroll_to';

    function epmStore(key, value) {
        try {
            if (value === null) { sessionStorage.removeItem(key); } else { sessionStorage.setItem(key, value); }
        } catch (e) { /* storage blocked (private mode etc.) - tabs still work, just not remembered */ }
    }
    function epmRead(key) {
        try { return sessionStorage.getItem(key); } catch (e) { return null; }
    }

    // Which tab to show on page load. Priority:
    //   1. the tab the server reports after a form POST (authoritative)
    //   2. a legacy "#tab_xxx" link, used once and then removed from the address bar
    //   3. the tab last used in this browser tab
    //   4. Global Settings
    function epmResolveInitialTab(serverTab, hash, stored) {
        var valid = ['tab_global', 'tab_template', 'tab_devices'];
        var fromHash = String(hash || '').replace(/^#/, '');
        if (fromHash === 'ringtone_section') { fromHash = 'tab_template'; }
        var candidates = [serverTab, fromHash, stored];
        for (var i = 0; i < candidates.length; i++) {
            if (valid.indexOf(candidates[i]) !== -1) { return candidates[i]; }
        }
        return 'tab_global';
    }

    function switchTab(tabId) {
        epmStore(EPM_TAB_KEY, tabId);
        var contents = document.querySelectorAll('.gen-tab-content');
        var buttons = document.querySelectorAll('.gen-tab-btn');

        contents.forEach(function(el) { el.classList.remove('active'); });
        buttons.forEach(function(el) { el.classList.remove('active'); });

        var targetTab = document.getElementById(tabId);
        var targetBtn = document.getElementById('btn_' + tabId);

        if (targetTab) targetTab.classList.add('active');
        if (targetBtn) targetBtn.classList.add('active');

        var activeField = document.getElementById('active_tab_field');
        var deviceActiveField = document.getElementById('device_active_tab_field');
        var loadTplField = document.getElementById('load_tpl_active_tab_field');

        if (activeField) activeField.value = tabId;
        if (deviceActiveField) deviceActiveField.value = tabId;
        if (loadTplField) loadTplField.value = tabId;
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
        epmConfirm(
            "Are you sure you want to permanently delete Global Settings? This removes the y-config file for EVERY phone generation (y000000000000.cfg and all newer model-specific y-configs) from /tftpboot/.",
            function () {
                document.getElementById('target_filename').value = "y000000000000.cfg";
                document.getElementById('target_file_type').value = "global";
                document.getElementById('delete_file_form').submit();
            },
            { title: 'Confirm deletion', okLabel: 'Delete permanently' }
        );
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
                row.style.display = (i <= num) ? '' : 'none';
            }
        }
        if (typeof refreshKeySummaries === 'function') { refreshKeySummaries(); }
    }

    // Per-model key layout and expansion sizes come from PHP ($yealink_model_keys / $expansion_key_sizes).
    var epmModelSpecs = <?= json_encode($yealink_model_keys) ?>;
    var epmExpKeySize = <?= json_encode($expansion_key_sizes) ?>;
    var epmExpWallpaperModels = <?= json_encode($expansion_wallpaper_models) ?>;

    function epmModelSpec(model) {
        return epmModelSpecs[model] || epmModelSpecs['manual'];
    }

    function epmCurrentModel() {
        var m = document.getElementById('select_phone_model');
        return m ? m.value : 'manual';
    }

    // Memory keys are shown one page at a time: page 0 = the phone's built-in keys (if it has any),
    // then one page per expansion module.
    var memKeyPage = 0;

    function memKeyBase() {
        var b = document.getElementById('field_base_mem_keys');
        return b ? (parseInt(b.value, 10) || 0) : 0;
    }

    function memKeyPageSize() {
        var m = document.getElementById('select_exp_model');
        var size = m ? (epmExpKeySize[m.value] || 0) : 0;
        return size > 0 ? size : 20;
    }

    function memKeyRowPage(i, base, size) {
        if (i <= base) { return 0; }
        return (base > 0 ? 1 : 0) + Math.floor((i - base - 1) / size);
    }

    function setMemKeyPage(p) {
        memKeyPage = p;
        var el = document.getElementById('select_memkey_count');
        updateMemkeyVisibility(el ? el.value : 0);
    }

    function buildMemkeyPageBar(num, base, size, pages) {
        var bar = document.getElementById('memkey_pages');
        var empty = document.getElementById('memkey_empty');
        var head = document.getElementById('memkey_head');
        if (empty) { empty.style.display = (num === 0) ? 'block' : 'none'; }
        if (head) { head.style.display = (num === 0) ? 'none' : ''; }
        if (!bar) { return; }
        bar.innerHTML = '';
        if (pages < 2) { bar.style.display = 'none'; return; }
        bar.style.display = 'flex';
        var expModel = document.getElementById('select_exp_model');
        var hasExp = expModel && (epmExpKeySize[expModel.value] || 0) > 0;
        for (var p = 0; p < pages; p++) {
            var a, b, label;
            if (base > 0 && p === 0) {
                a = 1; b = Math.min(base, num);
                label = 'Built-in: keys ' + a + '-' + b;
            } else {
                var k = p - (base > 0 ? 1 : 0);
                a = base + k * size + 1;
                b = Math.min(base + (k + 1) * size, num);
                label = (hasExp ? 'Module ' + (k + 1) + ': ' : '') + 'keys ' + a + '-' + b;
            }
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'gen-key-page' + (p === memKeyPage ? ' active' : '');
            btn.textContent = label;
            btn.onclick = (function (pp) { return function () { setMemKeyPage(pp); }; })(p);
            bar.appendChild(btn);
        }
    }

    function updateMemkeyVisibility(count) {
        var num = parseInt(count, 10) || 0;
        var base = memKeyBase();
        var size = memKeyPageSize();
        var pages = num > 0 ? memKeyRowPage(num, base, size) + 1 : 1;
        if (memKeyPage >= pages) { memKeyPage = pages - 1; }
        for (var i = 1; i <= 180; i++) {
            var row = document.getElementById('memkey_row_' + i);
            if (row) {
                row.style.display = (i <= num && memKeyRowPage(i, base, size) === memKeyPage) ? '' : 'none';
            }
        }
        buildMemkeyPageBar(num, base, size, pages);
        if (typeof refreshKeySummaries === 'function') { refreshKeySummaries(); }
    }

    function updateMemkeyNote() {
        var el = document.getElementById('memkey_model_note');
        if (!el) { return; }
        var base = memKeyBase();
        el.textContent = base > 0
            ? 'This model has ' + base + ' built-in memory keys (memorykey.1-' + base + '); expansion module keys follow them.'
            : 'This model has no built-in memory keys; only expansion module keys apply.';
    }

    // Slots = built-in keys for the selected model + keys on every selected expansion module.
    // On first page load (isInit) never shrink what the saved template already uses.
    function calculateTotalMemoryKeys(isInit) {
        var baseMem = memKeyBase();
        var expModel = document.getElementById('select_exp_model').value;
        var expQty = parseInt(document.getElementById('select_exp_count').value) || 0;
        var expKeysPerUnit = epmExpKeySize[expModel] || 0;

        var totalMemKeys = baseMem + (expKeysPerUnit * expQty);
        var memElem = document.getElementById('select_memkey_count');
        if (memElem) {
            if (isInit) { totalMemKeys = Math.max(totalMemKeys, parseInt(memElem.value, 10) || 0); }
            memElem.value = totalMemKeys;
            updateMemkeyVisibility(totalMemKeys);
        }
        updateMemkeyNote();
    }

    function handleModelSelect(model, isInit) {
        var lineElem = document.getElementById('select_linekey_count');
        var baseMemElem = document.getElementById('field_base_mem_keys');
        var spec = epmModelSpec(model);

        if (lineElem) {
            if (!isInit) { lineElem.value = spec.linekeys; }
            updateLinekeyVisibility(lineElem.value);
        }

        restrictLineDropdownOptions(spec.lines);

        if (baseMemElem) { baseMemElem.value = spec.memkeys; }
        if (!isInit) { memKeyPage = 0; }
        calculateTotalMemoryKeys(!!isInit);
        if (typeof progKeyOnModelChange === 'function') { progKeyOnModelChange(model, spec.lines); }
        updateModelSpecsInfo(model);
    }

    function restrictLineDropdownOptions(maxLines) {
        for (var i = 1; i <= 29; i++) {
            var selectElem = document.querySelector('select[name="linekey_' + i + '_line"]');
            if (!selectElem) {
                continue;
            }

            var currentVal = selectElem.value;
            selectElem.innerHTML = '';
            
            for (var l = 1; l <= maxLines; l++) {
                var opt = document.createElement('option');
                opt.value = l;
                opt.textContent = 'Line ' + l;
                selectElem.appendChild(opt);
            }

            if (parseInt(currentVal, 10) <= maxLines) {
                selectElem.value = currentVal;
            } else {
                selectElem.value = '1';
            }
        }
    }

    function handleExpSelect() {
        calculateTotalMemoryKeys();

        var expModel = document.getElementById('select_exp_model');
        var wpBtn = document.getElementById('btn_exp_wallpaper');
        if (wpBtn) {
            var showsWallpaper = !!expModel && epmExpWallpaperModels.indexOf(expModel.value) !== -1;
            wpBtn.style.display = showsWallpaper ? '' : 'none';
        }
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
        // Server says phones still reference a deleted/missing ringtone -> keep the banner up.
        var requiresFlush = (initialShowFlushBtn === true);

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
        epmStore(EPM_TAB_KEY, 'tab_devices');
        window.location.href = window.location.pathname + '?display=yealink_epm&_r=' + Date.now();
    }

    function openManualAddModal() {
        document.getElementById('manual_mac').value = '001565';
        validateManualMacPrefix();
        document.getElementById('manual_ext').value = '';
        document.getElementById('manual_tpl').value = '';
        document.getElementById('manualAddModal').style.display = 'block';
        enforceUniqueExtensionSelections();
    }

    function validateManualMacPrefix() {
        var mac = document.getElementById('manual_mac').value.replace(/[^a-fA-F0-9]/g, '').toLowerCase();
        var warning = document.getElementById('manual_mac_warning');
        var yealinkOuis = <?= json_encode(getYealinkOuis()) ?>;
        var head = mac.substr(0, 6);
        var looksYealink = yealinkOuis.some(function(o) { return o.indexOf(head) === 0; });
        warning.style.display = (mac.length > 0 && !looksYealink) ? 'block' : 'none';
    }

    function closeManualAddModal() {
        document.getElementById('manualAddModal').style.display = 'none';
        epmStore(EPM_TAB_KEY, 'tab_devices');
        window.location.href = window.location.pathname + '?display=yealink_epm&_r=' + Date.now();
    }

    function submitManualAddDevice() {
        var rawMac = document.getElementById('manual_mac').value.replace(/[^a-fA-F0-9]/g, '').toLowerCase();
        var extVal = document.getElementById('manual_ext').value;
        var tplVal = document.getElementById('manual_tpl').value;
        var autoProvision = document.getElementById('manual_provision').checked ? '1' : '0';
        var btn = document.getElementById('manual_add_btn');

        if (rawMac.length !== 12) {
            epmAlert('Please enter a valid 12-character MAC address.');
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
                epmAlert(data.message || 'Error adding device.');
                btn.disabled = false;
                btn.innerText = 'Create Device Config';
                btn.style.background = '#28a745';
            }
        })
        .catch(err => {
            epmAlert('Request failed.');
            btn.disabled = false;
            btn.innerText = 'Create Device Config';
            btn.style.background = '#28a745';
        });
    }

    function runScanDebug() {
        var notesBox = document.getElementById('scan_notes');
        notesBox.style.display = 'block';
        notesBox.textContent = 'Gathering diagnostics...';
        fetch('?display=yealink_epm&action=scan_debug')
            .then(function(r) { return r.text(); })
            .then(function(text) {
                notesBox.textContent = text;
                notesBox.style.userSelect = 'text';
            })
            .catch(function(err) {
                notesBox.textContent = 'Debug request failed: ' + err;
            });
    }

    function runSubnetScan() {
        var subnet = document.getElementById('scan_subnet').value;
        var tbody = document.getElementById('scan_results_body');
        scannedDeviceMacs = [];
        tbody.innerHTML = '<tr><td colspan="6">Scanning subnet asynchronously... Please wait...</td></tr>';
        var notesBox = document.getElementById('scan_notes');
        notesBox.style.display = 'none';
        notesBox.textContent = '';
        
        fetch('?display=yealink_epm&action=scan_network&subnet=' + encodeURIComponent(subnet))
            .then(response => response.json())
            .then(data => {
                tbody.innerHTML = '';
                if (data.notes && data.notes.length) {
                    notesBox.textContent = data.notes.join('\n');
                    notesBox.style.display = 'block';
                }
                if (!data.devices || data.devices.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="6">No unconfigured Yealink devices found on subnet.</td></tr>';
                    return;
                }
                data.devices.forEach(dev => {
                    scannedDeviceMacs.push(dev.mac);
                    var viaBadge = (dev.via && dev.via !== 'arp') ? ' <small style="color:#6c757d;">(' + String(dev.via).replace(/[^a-z]/g, '') + ')</small>' : '';
                    var extOptions = `<option value="">-- Unassigned --</option>`;
                    <?php foreach ($available_extensions as $ext_id => $ext_data): ?>
                        extOptions += `<option value="<?= $ext_id ?>"><?= $ext_id ?> - <?= htmlspecialchars($ext_data['display_name']) ?></option>`;
                    <?php endforeach; ?>

                    var tplOptions = `<option value="">-- None --</option>`;
                    <?php foreach ($available_templates as $tpl_file => $tpl_label): ?>
                        tplOptions += `<option value="<?= htmlspecialchars($tpl_file) ?>"><?= htmlspecialchars($tpl_label) ?></option>`;
                    <?php endforeach; ?>

                    var row = `<tr id="scan_row_${dev.mac}">
                        <td style="vertical-align:middle;">${dev.ip}${viaBadge}</td>
                        <td style="vertical-align:middle;"><b>${dev.mac}</b></td>
                        <td>
                            <select id="scan_ext_${dev.mac}" class="shadow-box sans-font" style="padding:4px; max-width:180px;">${extOptions}</select>
                        </td>
                        <td>
                            <select id="scan_tpl_${dev.mac}" class="shadow-box sans-font" style="padding:4px;">${tplOptions}</select>
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
                epmAlert(data.message || 'Error adding device.');
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
            epmAlert('No devices available to add.');
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

        // Only trust the server's tab when this page is the result of a form POST that named one.
        var epmServerTab = <?= json_encode((($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (isset($_POST['active_tab']) || ($formData['active_tab'] ?? 'tab_global') !== 'tab_global')) ? ($formData['active_tab'] ?? null) : null) ?>;
        var epmHash = window.location.hash;

        switchTab(epmResolveInitialTab(epmServerTab, epmHash, epmRead(EPM_TAB_KEY)));

        // One-shot scroll target (replaces the old #ringtone_section anchor)
        var epmScrollTo = epmRead(EPM_SCROLL_KEY);
        if (epmHash === '#ringtone_section') { epmScrollTo = 'ringtone_section'; }
        if (epmScrollTo) {
            epmStore(EPM_SCROLL_KEY, null);
            var epmScrollElem = document.getElementById(epmScrollTo);
            if (epmScrollElem) { epmScrollElem.scrollIntoView({ behavior: 'smooth' }); }
        }

        // Tidy the address bar: drop any #anchor and the one-off ?_r= cache-buster.
        try {
            var epmUrl = new URL(window.location.href);
            if (epmUrl.hash !== '' || epmUrl.searchParams.has('_r')) {
                epmUrl.searchParams.delete('_r');
                window.history.replaceState(null, '', epmUrl.pathname + epmUrl.search);
            }
        } catch (e) {}

        var modelElem = document.getElementById('select_phone_model');
        if (modelElem) {
            handleModelSelect(modelElem.value, true);
            updateModelSpecsInfo(modelElem.value);
        }

        enforceUniqueExtensionSelections();
    });

    function handleSignModuleSubmit(event) {
        var submitBtn = document.getElementById('sign_submit_btn');
        var cancelBtn = document.getElementById('sign_cancel_btn');
        var closeX = document.getElementById('sign_modal_close_x');
        var instructions = document.getElementById('sign_modal_instructions');
        var progressContainer = document.getElementById('sign_progress_container');
        var progressBar = document.getElementById('sign_progress_bar');
        var statusText = document.getElementById('sign_status_text');

        if (submitBtn) submitBtn.disabled = true;
        if (cancelBtn) cancelBtn.disabled = true;
        if (closeX) closeX.style.display = 'none';

        if (instructions) {
            instructions.innerHTML = '<strong>Signing in progress...</strong> Please do not close or refresh this window.';
        }

        if (progressContainer) progressContainer.style.display = 'block';

        var stages = [
            { pct: 20, msg: "Cleaning stale signature caches..." },
            { pct: 50, msg: "Generating native SHA-256 file hashes..." },
            { pct: 75, msg: "Writing module.sig..." },
            { pct: 90, msg: "Purging core & framework notifications..." },
            { pct: 95, msg: "Refreshing module signatures & reloading FreePBX..." }
        ];

        var currentStage = 0;

        var progressInterval = setInterval(function() {
            if (currentStage < stages.length) {
                var stage = stages[currentStage];
                if (progressBar) {
                    progressBar.style.width = stage.pct + '%';
                    progressBar.innerText = stage.pct + '%';
                }
                if (statusText) {
                    statusText.innerText = stage.msg;
                }
                currentStage++;
            } else {
                clearInterval(progressInterval);
            }
        }, 1200);
    }
</script>

<div style="font-family: Arial, Helvetica, sans-serif !important;" class="epm-key-modal" id="keyModal_notifications" aria-hidden="true">
  <div class="epm-key-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="keyModalTitle_notifications" style="width:min(460px, 96vw);">
    <div class="epm-key-modal-head">
      <strong id="keyModalTitle_notifications"><i class="fa fa-bell" aria-hidden="true"></i>&nbsp; Notifications</strong>
      <button type="button" class="epm-key-modal-x" aria-label="Close" onclick="closeEpmKeyModal('keyModal_notifications')">&times;</button>
    </div>
    <div class="epm-key-modal-content">
      <div class="epm-notif-list">
        <div class="epm-notif-row">
          <span><?= htmlspecialchars('Display Voice Mail Popup') ?></span>
          <label class="switch" style="margin:0;">
            <input type="checkbox" data-popup-field="popup_voice_mail" <?= (($formData['popup_voice_mail'] ?? '1') === '0') ? '' : 'checked' ?> onchange="document.getElementById('popup_voice_mail').value = this.checked ? '1' : '0';">
            <span class="slider"></span>
          </label>
        </div>
        <div class="epm-notif-row">
          <span><?= htmlspecialchars('Display Missed Call Popup') ?></span>
          <label class="switch" style="margin:0;">
            <input type="checkbox" data-popup-field="popup_missed_call" <?= (($formData['popup_missed_call'] ?? '1') === '0') ? '' : 'checked' ?> onchange="document.getElementById('popup_missed_call').value = this.checked ? '1' : '0';">
            <span class="slider"></span>
          </label>
        </div>
        <div class="epm-notif-row">
          <span><?= htmlspecialchars('Display Forward Call Popup') ?></span>
          <label class="switch" style="margin:0;">
            <input type="checkbox" data-popup-field="popup_forward_call" <?= (($formData['popup_forward_call'] ?? '1') === '0') ? '' : 'checked' ?> onchange="document.getElementById('popup_forward_call').value = this.checked ? '1' : '0';">
            <span class="slider"></span>
          </label>
        </div>
        <div class="epm-notif-row">
          <span><?= htmlspecialchars('Display Text Message Popup') ?></span>
          <label class="switch" style="margin:0;">
            <input type="checkbox" data-popup-field="popup_text_message" <?= (($formData['popup_text_message'] ?? '1') === '0') ? '' : 'checked' ?> onchange="document.getElementById('popup_text_message').value = this.checked ? '1' : '0';">
            <span class="slider"></span>
          </label>
        </div>
      </div>
    </div>
    <div class="epm-key-modal-foot">
      <span class="epm-key-modal-note">Changes are included when you save the template.</span>
      <button type="button" class="gen-btn epm-key-modal-close" onclick="closeEpmKeyModal('keyModal_notifications')">Close</button>
    </div>
  </div>
</div>

<style>
    .epm-ov-edit-btn { border:1px solid #9bc3a8; background:#f3faf5; color:#2f7a4a; border-radius:4px; padding:3px 7px; cursor:pointer; line-height:1; }
    .epm-ov-edit-btn:hover { background:#dcefe2; }
    .epm-ov-edit-btn.has-ov { background:#2f9e5b; border-color:#2f9e5b; color:#fff; }
    .epm-ov-h { margin:14px 0 6px 0; font-size:12px; font-weight:bold; text-transform:uppercase; letter-spacing:.04em; opacity:.7; }
    .epm-ov-btnrow { display:flex; flex-wrap:wrap; gap:8px; }
    .epm-ov-row { display:flex; align-items:center; gap:8px; padding:6px 10px; border-bottom:1px solid #c9dfd0; flex-wrap:wrap; }
    .epm-ov-row:nth-child(odd) { background:#eaf4ed; }
    .epm-ov-row:nth-child(even) { background:#f8fbf9; }
    .epm-ov-row:hover { background:#dcefe2; }
    .epm-ov-row .epm-ov-num { width:26px; font-weight:bold; text-align:center; flex:0 0 auto; }
    .epm-ov-row .epm-ov-name { width:120px; flex:0 0 auto; font-size:12px; }
    .epm-ov-row select, .epm-ov-row input { border:none; border-bottom:1px solid #b9d3c1; background:transparent; outline:none; font-size:13px; padding:3px 2px; min-width:0; }
    .epm-ov-row input { flex:1 1 110px; }
    .epm-ov-row select { flex:0 1 auto; }
    .epm-ov-row.epm-ov-locked { opacity:.55; }
    .epm-ov-ta { width:100%; box-sizing:border-box; min-height:110px; font-family:monospace; font-size:12px; padding:8px; border:1px solid #b9d3c1; border-radius:4px; }
    .epm-ov-sel { width:100%; padding:6px; border:1px solid #b9d3c1; border-radius:4px; }
    #epm_ov_msg { font-size:12px; margin-right:auto; }
</style>
<script>
var epmOv = (function () {
    var mac = '', data = null;
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
    function el(id) { return document.getElementById(id); }

    function shell(id, title, icon, bodyId, footHtml, width) {
        return '<div class="epm-key-modal" id="' + id + '" aria-hidden="true" style="font-family: Arial, Helvetica, sans-serif !important;">' +
          '<div class="epm-key-modal-dialog" role="dialog" aria-modal="true" style="width:' + (width || 'min(560px, 96vw)') + ';">' +
            '<div class="epm-key-modal-head"><strong><i class="fa ' + icon + '" aria-hidden="true"></i>&nbsp; <span id="' + id + '_title">' + esc(title) + '</span></strong>' +
            '<button type="button" class="epm-key-modal-x" aria-label="Close" onclick="closeEpmKeyModal(\'' + id + '\')">&times;</button></div>' +
            '<div class="epm-key-modal-content" id="' + bodyId + '"></div>' +
            '<div class="epm-key-modal-foot">' + footHtml + '</div>' +
          '</div></div>';
    }

    function ensure() {
        if (el('epmOvMain')) return;
        var h = shell('epmOvMain', 'Device Overrides', 'fa-pencil', 'epm_ov_main_body',
            '<span id="epm_ov_msg"></span>' +
            '<button type="button" class="gen-btn" onclick="closeEpmKeyModal(\'epmOvMain\')">Cancel</button> ' +
            '<button type="button" class="gen-btn" onclick="epmOv.save(false)">Save</button> ' +
            '<button type="button" class="gen-btn" onclick="epmOv.save(true)">Save &amp; Sync</button>', 'min(600px, 96vw)');
        h += shell('epmOvLine', 'Line Keys', 'fa-th-list', 'epm_ov_line_body', '<span class="epm-key-modal-note">Only fields you change are saved as overrides.</span><button type="button" class="gen-btn epm-key-modal-close" onclick="closeEpmKeyModal(\'epmOvLine\')">Done</button>', 'min(760px, 96vw)');
        h += shell('epmOvMem', 'Memory Keys', 'fa-th', 'epm_ov_mem_body', '<span class="epm-key-modal-note">Built-in keys first, then any expansion module keys from the template.</span><button type="button" class="gen-btn epm-key-modal-close" onclick="closeEpmKeyModal(\'epmOvMem\')">Done</button>', 'min(760px, 96vw)');
        h += shell('epmOvProg', 'Programmable Keys', 'fa-keyboard-o', 'epm_ov_prog_body', '<span class="epm-key-modal-note">Only fields you change are saved as overrides.</span><button type="button" class="gen-btn epm-key-modal-close" onclick="closeEpmKeyModal(\'epmOvProg\')">Done</button>', 'min(760px, 96vw)');
        var d = document.createElement('div');
        d.innerHTML = h;
        while (d.firstChild) document.body.appendChild(d.firstChild);
    }

    function typeOptions(map, sel) {
        var o = '';
        Object.keys(map).forEach(function (k) { o += '<option value="' + esc(k) + '"' + (String(k) === String(sel) ? ' selected' : '') + '>' + esc(map[k]) + '</option>'; });
        return o;
    }
    function lineOptions(max, sel) {
        var o = '';
        for (var i = 1; i <= max; i++) { o += '<option value="' + i + '"' + (String(i) === String(sel) ? ' selected' : '') + '>Line ' + i + '</option>'; }
        return o;
    }

    function keyRows(list, prefix, lockFirst) {
        var h = '';
        list.forEach(function (k, i) {
            var idx = i + 1, locked = (lockFirst && idx === 1);
            h += '<div class="epm-ov-row' + (locked ? ' epm-ov-locked' : '') + '" data-idx="' + idx + '">' +
              '<span class="epm-ov-num">' + idx + '</span>' +
              '<select data-f="type"' + (locked ? ' disabled' : '') + '>' + typeOptions(data.dss, k.type) + '</select>' +
              '<input data-f="value" placeholder="Value / Extension" value="' + esc(k.value) + '"' + (locked ? ' disabled' : '') + '>' +
              '<input data-f="label" placeholder="Label" value="' + esc(k.label) + '"' + (locked ? ' disabled' : '') + '>' +
              '<input data-f="pickup" placeholder="Pickup (e.g. **)" value="' + esc(k.pickup) + '"' + (locked ? ' disabled' : '') + '>' +
              '<select data-f="line"' + (locked ? ' disabled' : '') + '>' + lineOptions(data.maxLines, k.line) + '</select></div>';
        });
        return h;
    }

    function progRows() {
        var h = '';
        data.prog.forEach(function (k) {
            h += '<div class="epm-ov-row" data-idx="' + k.id + '">' +
              '<span class="epm-ov-name">' + esc(k.name) + '</span>' +
              '<select data-f="type" onchange="epmOv.progType(this)">' + typeOptions(data.progTypes, k.type) + '</select>' +
              '<select data-f="line" data-show="line">' + lineOptions(data.maxLines, k.line) + '</select>' +
              '<input data-f="value" data-show="value" placeholder="Value" value="' + esc(k.value) + '">' +
              '<select data-f="hist" data-show="hist"><option value="0"' + (k.hist === '1' ? '' : ' selected') + '>Local History</option><option value="1"' + (k.hist === '1' ? ' selected' : '') + '>Network CallLog</option></select>' +
              (k.id <= 4 ? '<input data-f="label" data-show="label" placeholder="Label" value="' + esc(k.label) + '">' : '') +
              '</div>';
        });
        return h;
    }

    function progType(sel) {
        var row = sel.closest('.epm-ov-row'), t = parseInt(sel.value, 10), f = data.progFields[t] || [];
        row.querySelectorAll('[data-show]').forEach(function (c) {
            var w = c.getAttribute('data-show');
            c.style.display = (w === 'label') ? (t !== 0 ? '' : 'none') : (f.indexOf(w) !== -1 ? '' : 'none');
        });
    }

    function render() {
        var pp = [['voice_mail', 'Display Voice Mail Popup'], ['missed_call', 'Display Missed Call Popup'], ['forward_call', 'Display Forward Call Popup'], ['text_message', 'Display Text Message Popup']];
        var h = '<div class="epm-ov-h">Notifications</div><div class="epm-notif-list">';
        pp.forEach(function (p) {
            h += '<div class="epm-notif-row"><span>' + p[1] + '</span><label class="switch" style="margin:0;">' +
                 '<input type="checkbox" data-ov-popup="' + p[0] + '"' + (data.popups[p[0]] === '0' ? '' : ' checked') + '><span class="slider"></span></label></div>';
        });
        h += '</div><div class="epm-ov-h">Keys</div><div class="epm-ov-btnrow">' +
             '<button type="button" class="gen-btn" onclick="openEpmKeyModal(\'epmOvLine\')">Line Keys</button>' +
             '<button type="button" class="gen-btn" onclick="openEpmKeyModal(\'epmOvMem\')">Memory Keys</button>' +
             (data.prog.length ? '<button type="button" class="gen-btn" onclick="openEpmKeyModal(\'epmOvProg\')">Programmable Keys</button>' : '') +
             '</div><div class="epm-ov-h">Default Account Ringtone</div><select class="epm-ov-sel" id="epm_ov_ring">';
        data.ringtones.builtin.forEach(function (r) { h += '<option value="' + esc(r[0]) + '"' + (r[0] === data.ringtone ? ' selected' : '') + '>' + esc(r[1]) + '</option>'; });
        if (data.ringtones.custom.length) {
            h += '<optgroup label="Custom ringtones">';
            data.ringtones.custom.forEach(function (f) { h += '<option value="' + esc(f) + '"' + (f === data.ringtone ? ' selected' : '') + '>' + esc(f) + '</option>'; });
            h += '</optgroup>';
        }
        h += '</select><div class="epm-ov-h">Custom Key / Value Additions</div>' +
             '<textarea class="epm-ov-ta" id="epm_ov_custom" spellcheck="false" placeholder="key = value (one per line)">' + esc(data.custom) + '</textarea>';
        el('epm_ov_main_body').innerHTML = h;
        el('epm_ov_line_body').innerHTML = keyRows(data.linekeys, 'linekey', true);
        el('epm_ov_mem_body').innerHTML = data.memkeys.length ? keyRows(data.memkeys, 'memorykey', false) : '<div style="padding:16px; font-size:13px; opacity:.8;">This phone has no memory keys: it has no built-in ones and its template has no expansion module.</div>';
        el('epm_ov_prog_body').innerHTML = progRows();
        el('epm_ov_prog_body').querySelectorAll('select[data-f="type"]').forEach(progType);
    }

    function collect(bodyId) {
        var rows = [];
        el(bodyId).querySelectorAll('.epm-ov-row').forEach(function (r) {
            var o = { idx: parseInt(r.getAttribute('data-idx'), 10) };
            r.querySelectorAll('[data-f]').forEach(function (c) { if (!c.disabled) o[c.getAttribute('data-f')] = c.value; });
            rows.push(o);
        });
        return rows;
    }

    function open(m) {
        ensure();
        mac = m;
        el('epmOvMain_title').textContent = 'Device Overrides - ' + m.toUpperCase();
        el('epm_ov_msg').textContent = 'Loading...';
        el('epm_ov_main_body').innerHTML = '';
        openEpmKeyModal('epmOvMain');
        fetch('?display=yealink_epm&action=get_device_overrides&mac=' + encodeURIComponent(m))
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.error) { el('epm_ov_msg').textContent = j.error; return; }
                data = j;
                el('epmOvMain_title').textContent = 'Device Overrides - ' + (j.ext ? 'Ext ' + j.ext + ' - ' : '') + j.model + ' (' + m.toUpperCase() + ')';
                el('epm_ov_msg').textContent = '';
                render();
            })
            .catch(function (e) { el('epm_ov_msg').textContent = 'Load failed: ' + e; });
    }

    function save(sync) {
        if (!data) return;
        var popups = {};
        el('epm_ov_main_body').querySelectorAll('[data-ov-popup]').forEach(function (c) { popups[c.getAttribute('data-ov-popup')] = c.checked ? '1' : '0'; });
        var payload = {
            popups: popups,
            ringtone: el('epm_ov_ring').value,
            linekeys: collect('epm_ov_line_body'),
            memkeys: collect('epm_ov_mem_body'),
            progkeys: collect('epm_ov_prog_body'),
            custom: el('epm_ov_custom').value
        };
        el('epm_ov_msg').textContent = 'Saving...';
        var body = 'save_device_overrides=1&mac=' + encodeURIComponent(mac) + '&sync=' + (sync ? '1' : '0') + '&payload=' + encodeURIComponent(JSON.stringify(payload));
        fetch('?display=yealink_epm', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.error) { el('epm_ov_msg').textContent = j.error; return; }
                var msg = j.lines + ' override line' + (j.lines === 1 ? '' : 's') + ' saved' + (j.synced ? ', check-sync sent' : '') + (j.dropped ? ' (' + j.dropped + ' invalid custom line dropped)' : '') + '.';
                el('epm_ov_msg').textContent = msg;
                var eb = document.querySelector('.epm-ov-edit-btn[onclick*="epmOv.open(\'' + mac + '\')"]');
                if (eb) { eb.classList.toggle('has-ov', j.lines > 0); eb.title = j.lines > 0 ? 'Edit device overrides (this phone has overrides)' : 'Edit device overrides'; }
                setTimeout(function () { closeEpmKeyModal('epmOvMain'); }, 1000);
            })
            .catch(function (e) { el('epm_ov_msg').textContent = 'Save failed: ' + e; });
    }

    return { open: open, save: save, progType: progType };
})();
</script>

<form id="delete_file_form" method="POST" style="display:none;">
    <input type="hidden" name="delete_target_file" value="1">
    <input type="hidden" id="target_filename" name="target_filename" value="">
    <input type="hidden" id="target_file_type" name="target_file_type" value="">
    <input type="hidden" id="delete_active_tab" name="active_tab" value="tab_global">
    <input type="hidden" name="current_loaded_template" value="<?= htmlspecialchars($formData['template_name']) ?>">
</form>

<!-- Accessible, browser-independent delete confirmation for ringtones and logos -->
<div id="epmDeleteConfirmModal" class="gen-modal" role="dialog" aria-modal="true" aria-labelledby="epmDeleteConfirmTitle" aria-hidden="true"
     style="display:none; align-items:center; justify-content:center; padding:20px; box-sizing:border-box;"
     onclick="if (event.target === this) closeDeleteConfirmModal();">
    <div class="gen-modal-content epm-delete-confirm-card" style="width:460px; max-width:96vw; margin:0; padding:0; overflow:hidden; border:1px solid #a9cbb8; box-shadow:0 12px 36px rgba(0,0,0,.28);">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; padding:13px 18px; background:#dcefe3; border-bottom:1px solid #a9cbb8;">
            <h3 id="epmDeleteConfirmTitle" style="margin:0; color:#155b3b; font-size:16px;">Confirm deletion</h3>
            <button type="button" aria-label="Close confirmation" onclick="closeDeleteConfirmModal()" style="border:0; background:transparent; color:#426653; font-size:22px; line-height:1; cursor:pointer;">&times;</button>
        </div>
        <div style="padding:18px; color:#333;">
            <p style="margin:0 0 10px;">Are you sure you want to permanently delete this <strong id="epmDeleteConfirmKind">file</strong> from the server?</p>
            <div id="epmDeleteConfirmFilename" style="padding:9px 11px; background:#f1f7f3; border:1px solid #d2e5d9; border-radius:4px; overflow-wrap:anywhere; font-weight:600;"></div>
            <p style="margin:12px 0 0; color:#8a4b08; font-size:12px;">This action cannot be undone.</p>
        </div>
        <div style="display:flex; justify-content:flex-end; gap:8px; padding:12px 18px; background:#f7faf8; border-top:1px solid #e0e9e3;">
            <button id="epmDeleteConfirmCancel" type="button" class="gen-btn" style="margin:0; background:#6c757d;" onclick="closeDeleteConfirmModal()">Cancel</button>
            <button type="button" class="gen-btn-danger" style="margin:0; padding:9px 16px;" onclick="submitConfirmedDeleteFile()">Delete permanently</button>
        </div>
    </div>
</div>

<!-- Same accessible confirmation pattern, for turning a phone's VPN off (revokes its cert) -->
<div id="epmVpnDisableConfirmModal" class="gen-modal" role="dialog" aria-modal="true" aria-labelledby="epmVpnDisableConfirmTitle" aria-hidden="true"
     style="display:none; align-items:center; justify-content:center; padding:20px; box-sizing:border-box;"
     onclick="if (event.target === this) closeVpnDisableModal();">
    <div class="gen-modal-content epm-delete-confirm-card" style="width:460px; max-width:96vw; margin:0; padding:0; overflow:hidden; border:1px solid #a9cbb8; box-shadow:0 12px 36px rgba(0,0,0,.28);">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; padding:13px 18px; background:#dcefe3; border-bottom:1px solid #a9cbb8;">
            <h3 id="epmVpnDisableConfirmTitle" style="margin:0; color:#155b3b; font-size:16px;">Turn off VPN</h3>
            <button type="button" aria-label="Close confirmation" onclick="closeVpnDisableModal()" style="border:0; background:transparent; color:#426653; font-size:22px; line-height:1; cursor:pointer;">&times;</button>
        </div>
        <div style="padding:18px; color:#333;">
            <p style="margin:0 0 10px;">Turn off OpenVPN for extension <strong id="epmVpnDisableConfirmExt"></strong> (<span id="epmVpnDisableConfirmMac" style="font-family:monospace;"></span>)?</p>
            <p style="margin:12px 0 0; color:#8a4b08; font-size:12px;">This revokes the phone's VPN certificate and deletes its client package. It will need a newly issued certificate to reconnect.</p>
        </div>
        <div style="display:flex; justify-content:flex-end; gap:8px; padding:12px 18px; background:#f7faf8; border-top:1px solid #e0e9e3;">
            <button id="epmVpnDisableConfirmCancel" type="button" class="gen-btn" style="margin:0; background:#6c757d;" onclick="closeVpnDisableModal()">Cancel</button>
            <button type="button" class="gen-btn-danger" style="margin:0; padding:9px 16px;" onclick="submitConfirmedVpnDisable()">Turn off VPN</button>
        </div>
    </div>
</div>

<!-- Same accessible confirmation pattern, for the "Delete Selected Phones" bulk action -->
<div id="epmBulkDeleteConfirmModal" class="gen-modal" role="dialog" aria-modal="true" aria-labelledby="epmBulkDeleteConfirmTitle" aria-hidden="true"
     style="display:none; align-items:center; justify-content:center; padding:20px; box-sizing:border-box;"
     onclick="if (event.target === this) closeBulkDeleteModal();">
    <div class="gen-modal-content epm-delete-confirm-card" style="width:460px; max-width:96vw; margin:0; padding:0; overflow:hidden; border:1px solid #a9cbb8; box-shadow:0 12px 36px rgba(0,0,0,.28);">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; padding:13px 18px; background:#dcefe3; border-bottom:1px solid #a9cbb8;">
            <h3 id="epmBulkDeleteConfirmTitle" style="margin:0; color:#155b3b; font-size:16px;">Confirm deletion</h3>
            <button type="button" aria-label="Close confirmation" onclick="closeBulkDeleteModal()" style="border:0; background:transparent; color:#426653; font-size:22px; line-height:1; cursor:pointer;">&times;</button>
        </div>
        <div style="padding:18px; color:#333;">
            <p style="margin:0 0 10px;">Permanently delete the configuration file for <strong id="epmBulkDeleteConfirmCount">0 phones</strong> from the server?</p>
            <div id="epmBulkDeleteConfirmList" style="padding:9px 11px; background:#f1f7f3; border:1px solid #d2e5d9; border-radius:4px; overflow-wrap:anywhere; font-family:monospace; font-size:12px; max-height:120px; overflow-y:auto;"></div>
            <p style="margin:12px 0 0; color:#8a4b08; font-size:12px;">This action cannot be undone.</p>
        </div>
        <div style="display:flex; justify-content:flex-end; gap:8px; padding:12px 18px; background:#f7faf8; border-top:1px solid #e0e9e3;">
            <button id="epmBulkDeleteConfirmCancel" type="button" class="gen-btn" style="margin:0; background:#6c757d;" onclick="closeBulkDeleteModal()">Cancel</button>
            <button type="button" class="gen-btn-danger" style="margin:0; padding:9px 16px;" onclick="submitConfirmedBulkDelete()">Delete permanently</button>
        </div>
    </div>
</div>

<!-- Generic in-page replacement for window.alert(). Cannot be muted by the
     browser's "Prevent this page from creating additional dialogs" option. -->
<div id="epmAlertModal" class="gen-modal" role="alertdialog" aria-modal="true" aria-labelledby="epmAlertModalTitle" aria-hidden="true"
     style="display:none; align-items:center; justify-content:center; padding:20px; box-sizing:border-box; z-index:10000;"
     onclick="if (event.target === this) epmAlertOk();">
    <div class="gen-modal-content epm-delete-confirm-card" style="width:420px; max-width:96vw; margin:0; padding:0; overflow:hidden; border:1px solid #a9cbb8; box-shadow:0 12px 36px rgba(0,0,0,.28);">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; padding:13px 18px; background:#dcefe3; border-bottom:1px solid #a9cbb8;">
            <h3 id="epmAlertModalTitle" style="margin:0; color:#155b3b; font-size:16px;">Notice</h3>
            <button type="button" aria-label="Close" onclick="epmAlertOk()" style="border:0; background:transparent; color:#426653; font-size:22px; line-height:1; cursor:pointer;">&times;</button>
        </div>
        <div style="padding:18px; color:#333;">
            <p id="epmAlertModalMessage" style="margin:0; white-space:pre-line;"></p>
        </div>
        <div style="display:flex; justify-content:flex-end; gap:8px; padding:12px 18px; background:#f7faf8; border-top:1px solid #e0e9e3;">
            <button id="epmAlertModalOk" type="button" class="gen-btn" style="margin:0; padding:9px 16px;" onclick="epmAlertOk()">OK</button>
        </div>
    </div>
</div>

<!-- Generic in-page replacement for window.confirm(). Cannot be muted by the
     browser's "Prevent this page from creating additional dialogs" option. -->
<div id="epmConfirmModal" class="gen-modal" role="dialog" aria-modal="true" aria-labelledby="epmConfirmModalTitle" aria-hidden="true"
     style="display:none; align-items:center; justify-content:center; padding:20px; box-sizing:border-box; z-index:10000;"
     onclick="if (event.target === this) epmConfirmAnswer(false);">
    <div class="gen-modal-content epm-delete-confirm-card" style="width:460px; max-width:96vw; margin:0; padding:0; overflow:hidden; border:1px solid #a9cbb8; box-shadow:0 12px 36px rgba(0,0,0,.28);">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; padding:13px 18px; background:#dcefe3; border-bottom:1px solid #a9cbb8;">
            <h3 id="epmConfirmModalTitle" style="margin:0; color:#155b3b; font-size:16px;">Please confirm</h3>
            <button type="button" aria-label="Close" onclick="epmConfirmAnswer(false)" style="border:0; background:transparent; color:#426653; font-size:22px; line-height:1; cursor:pointer;">&times;</button>
        </div>
        <div style="padding:18px; color:#333;">
            <p id="epmConfirmModalMessage" style="margin:0; white-space:pre-line;"></p>
        </div>
        <div style="display:flex; justify-content:flex-end; gap:8px; padding:12px 18px; background:#f7faf8; border-top:1px solid #e0e9e3;">
            <button id="epmConfirmModalCancel" type="button" class="gen-btn" style="margin:0; background:#6c757d;" onclick="epmConfirmAnswer(false)">Cancel</button>
            <button id="epmConfirmModalOk" type="button" class="gen-btn-danger" style="margin:0; padding:9px 16px;" onclick="epmConfirmAnswer(true)">OK</button>
        </div>
    </div>
</div>

<!-- MODALS -->
<div id="viewConfigModal" class="gen-modal">
    <div class="gen-modal-content" style="width: 700px;">
        <h3>Device Configuration (<span id="view_cfg_mac_title"></span>)</h3>
        <textarea id="view_cfg_content" readonly class="gen-textarea shadow-box" style="height: 400px; font-size: 12px; background: #f8f9fa;"></textarea>
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
                <label style="margin-top:0;">Subnet to scan (e.g. 192.168.1.0/24):</label>
                <div style="display:flex; gap:10px;">
                    <input type="text" id="scan_subnet" style="width: 180px;" class="gen-full-width shadow-box" value="<?= $detected_host ?>">
                    <button type="button" class="gen-btn" style="padding: 5px; margin-top:0; width: 140px; height: 30px; align=middle;" onclick="runSubnetScan()">Scan Subnet</button>
<!-- hidden debug button                    <button type="button" class="gen-btn" style="margin-top:0; background:#6c757d;" onclick="runScanDebug()">Debug Scan</button> 
--!>
                </div>
            </div>

            <div style="flex:1;">
                <label style="margin-top:0;">Bulk Assign Template to All Scanned:</label>
                <select id="bulk_scanned_template" class="gen-full-width shadow-box sans-font" style="height: 30px;" onchange="applyBulkTemplateToScanned(this.value)">
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
        <div id="scan_notes" style="display:none; white-space:pre-wrap; word-break:break-word; margin-top:8px; padding:8px 10px; background:#fff8e1; border:1px solid #ffe08a; border-radius:4px; font-size:12px; color:#5c4a00; max-height:40vh; overflow-y:auto;"></div>
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
        <input type="text" id="manual_mac" class="gen-full-width shadow-box" placeholder="e.g. 001565123456" maxlength="17" style="height: 31px;" oninput="validateManualMacPrefix()">
        <div id="manual_mac_warning" style="display:none; color:#dc3545; font-weight:bold; margin-top:5px;">This doesn't look like a Yealink MAC. Please verify MAC.</div>

        <label style="margin-top:10px;">Assign Extension:</label>
        <select id="manual_ext" class="gen-full-width shadow-box sans-font" style="height: 30px;">
            <option value="">-- Unassigned --</option>
            <?php foreach ($available_extensions as $ext_id => $ext_data): ?>
                <option value="<?= $ext_id ?>"><?= $ext_id ?> - <?= htmlspecialchars($ext_data['display_name']) ?></option>
            <?php endforeach; ?>
        </select>

        <label style="margin-top:10px;">Assign Template:</label>
        <select id="manual_tpl" class="gen-full-width shadow-box sans-font" style="height: 30px;">
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
        <select id="single_template_select" class="gen-full-width ">
            <option value="">-- None --</option>
            <?php foreach ($available_templates as $tpl_file => $tpl_label): ?>
                <option value="<?= htmlspecialchars($tpl_file) ?>"><?= htmlspecialchars($tpl_label) ?></option>
            <?php endforeach; ?>
        </select>

        <label style="margin-top:10px;">Override Phone Model:</label>
        <select id="single_model_select" name="single_model" class="gen-full-width ">
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

<!-- Modal 4: Yealink EPM Sign Authentication Prompt -->
<div class="modal fade" id="signModal" tabindex="-1" role="dialog" aria-labelledby="signModalLabel">
    <div class="modal-dialog" role="document" style="max-width: 420px; margin-top: 10%;">
        <div class="modal-content">
            <form id="sign_module_form" method="post" action="config.php?display=yealink_epm" onsubmit="handleSignModuleSubmit(event)">
                <input type="hidden" name="action" value="resign_yealink_epm_module">
                <div class="modal-header">
                    <button type="button" class="close" id="sign_modal_close_x" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title" id="signModalLabel"><i class="fa fa-shield"></i> Confirm Local Module Signing</h4>
                </div>
                <div class="modal-body">
                    <p id="sign_modal_instructions">This will regenerate the module's local <strong>module.sig</strong> file (SHA-256 hashes of the current files) and clear the "signature not valid" / "tampered files" notices for Yealink EPM. It runs locally as the web server user and does not require your system password.</p>

                    <div id="sign_progress_container" style="display: none; margin-top: 15px;">
                        <div class="progress" style="height: 22px; margin-bottom: 8px; border-radius: 4px; background-color: #e9ecef; overflow: hidden; box-shadow: inset 0 1px 2px rgba(0,0,0,0.1);">
                            <div id="sign_progress_bar" class="progress-bar progress-bar-striped active" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" style="width: 0%; background-color: #007bff; line-height: 22px; font-weight: bold; font-size: 12px; transition: width 0.4s ease;">
                                0%
                            </div>
                        </div>
                        <p id="sign_status_text" style="font-size: 12px; color: #555; text-align: center; font-weight: 600; margin: 0;">Initializing signing routine...</p>
                    </div>
                </div>
                <div class="modal-footer" id="sign_modal_footer">
                    <button type="button" class="btn btn-default" id="sign_cancel_btn" data-dismiss="modal">Cancel</button>
                    <button type="submit" id="sign_submit_btn" class="btn btn-success" style="background-color: #28a745; border-color: #28a745;">Sign Module</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================================ -->
<!-- TABBED UI LAYOUT                                                             -->
<!-- ============================================================================ -->

<div class="gen-container">
    <h2>Yealink Endpoint Manager</h2>
    
    <?php if (!empty($status)) echo "<div class='gen-alert'>{$status}</div>"; ?>

    <?php if (!empty($elevationError)): ?>
        <div class="warning-box" style="margin-bottom: 15px; border-left: 4px solid #dc3545; padding: 10px;">
            &#9888; <?= htmlspecialchars($elevationError) ?>
        </div>
    <?php endif; ?>

    <div class="gen-tab-bar" style="display: flex; justify-content: space-between; align-items: center;">
        <div style="display: flex;">
            <div id="btn_tab_global" class="gen-tab-btn <?= ($formData['active_tab'] === 'tab_global') ? 'active' : '' ?>" onclick="switchTab('tab_global')">Global Settings</div>
            <div id="btn_tab_template" class="gen-tab-btn <?= ($formData['active_tab'] === 'tab_template') ? 'active' : '' ?>" onclick="switchTab('tab_template')">Template Manager</div>
            <div id="btn_tab_devices" class="gen-tab-btn <?= ($formData['active_tab'] === 'tab_devices') ? 'active' : '' ?>" onclick="switchTab('tab_devices')">Device Manager</div>
        </div>
        
        <?php if ($show_resign_button): ?>
            <div style="padding-right: 10px; margin-top: 5px;">
                <button type="button" class="btn btn-danger" style="margin: 0; font-weight: bold; background-color: #db001a; border-color: #6b000d; box-shadow: 0 0 6px rgba(220,53,69,0.4);" data-toggle="modal" data-target="#signModal">
                    <i class="fa fa-key"></i> Sign Module
                </button>
            </div>
        <?php endif; ?>
    </div>

    <!-- TAB 1: GLOBAL SETTINGS -->
    <div id="tab_global" class="gen-tab-content <?= ($formData['active_tab'] === 'tab_global') ? 'active' : '' ?>">
        <form id="main_cfg_form" method="POST" enctype="multipart/form-data">
            <input type="hidden" id="active_tab_field" name="active_tab" value="<?= htmlspecialchars($formData['active_tab']) ?>">

            <div class="epm-dash-bar">
                <span class="epm-dash-hint"><i class="fa fa-arrows"></i> Drag a box by its grip to rearrange. <i class="fa fa-arrows-h"></i> toggles half / full width, <i class="fa fa-chevron-up"></i> collapses. Layout is remembered in this browser.</span>
                <button type="button" class="gen-btn epm-dash-reset" onclick="epmDashReset('epm_dash_global')">Reset layout</button>
            </div>

            <div id="epm_dash_global" class="epm-dash" style="font-family: Arial, Helvetica, sans-serif;">

                <!-- SERVER & TIME -->
                <section class="epm-box" id="box_global_server" data-box="global_server" data-span="1">
                    <header class="epm-box-head">
                        <span class="epm-grip" title="Drag to move"><i class="fa fa-arrows"></i></span>
                        <strong class="epm-box-title">Server &amp; Time</strong>
                        <span class="epm-box-tools">
                            <button type="button" class="epm-tool" data-act="span" title="Half / full width"><i class="fa fa-arrows-h"></i></button>
                            <button type="button" class="epm-tool" data-act="fold" title="Collapse / expand"><i class="fa fa-chevron-up"></i></button>
                        </span>
                    </header>
                    <div class="epm-box-body box-bg">
                        <div class="epm-template-fields epm-template-two-col">
                            <div>
                                <label>PBX Server IP / Domain:</label>
                                <input type="text" class="shadow-box gen-full-width" name="server_ip" placeholder="<?= $default_server_target ?>" value="<?= htmlspecialchars($formData['server_ip']) ?>">
                            </div>
                            <div>
                                <label>Phone Web GUI Admin Password:</label>
                                <input type="text" class="gen-full-width shadow-box" name="admin_password" placeholder="22222" value="<?= htmlspecialchars($formData['admin_password']) ?>">
                            </div>
                        </div>
                        <div class="epm-template-fields epm-template-two-col">
                            <div>
                                <label>Time Zone:</label>
                                <select name="timezone" class="shadow-box gen-full-width">
                                    <?php foreach ($timezones as $offset => $tz_name): ?>
                                        <option value="<?= $offset ?>" <?= ($formData['timezone'] == $offset) ? 'selected' : '' ?>><?= $tz_name ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label>Time Format:</label>
                                <select name="time_format" class="shadow-box gen-full-width">
                                    <option value="0" <?= ($formData['time_format'] === '0') ? 'selected' : '' ?>>12-Hour (AM/PM)</option>
                                    <option value="1" <?= ($formData['time_format'] === '1') ? 'selected' : '' ?>>24-Hour (Military)</option>
                                </select>
                            </div>
                        </div>
                        <div class="epm-template-fields epm-template-two-col">
                            <div>
                                <label>NTP Server 1:</label>
                                <input type="text" class="shadow-box gen-full-width" name="ntp_server1" placeholder="<?= $detected_host ?>" value="<?= htmlspecialchars($formData['ntp_server1']) ?>">
                            </div>
                            <div>
                                <label>NTP Server 2:</label>
                                <input type="text" class="shadow-box gen-full-width" name="ntp_server2" placeholder="pool.ntp.org" value="<?= htmlspecialchars($formData['ntp_server2']) ?>">
                            </div>
                        </div>
                    </div>
                </section>

                <!-- AUTO PROVISIONING -->
                <section class="epm-box" id="box_auto_provision" data-box="auto_provision" data-span="1">
                    <header class="epm-box-head">
                        <span class="epm-grip" title="Drag to move"><i class="fa fa-arrows"></i></span>
                        <strong class="epm-box-title">Auto Provisioning Settings</strong>
                        <span class="epm-box-tools">
                            <button type="button" class="epm-tool" data-act="span" title="Half / full width"><i class="fa fa-arrows-h"></i></button>
                            <button type="button" class="epm-tool" data-act="fold" title="Collapse / expand"><i class="fa fa-chevron-up"></i></button>
                        </span>
                    </header>
                    <div class="epm-box-body box-bg">
                        <div class="epm-template-fields epm-template-three-col">
                            <div>
                                <label>Provisioning Mode:</label>
                                <select name="auto_provision_mode" class="shadow-box gen-full-width">
                                    <option value="7" <?= ($formData['auto_provision_mode'] === '7') ? 'selected' : '' ?>>7 - Power on + Weekly</option>
                                    <option value="6" <?= ($formData['auto_provision_mode'] === '6') ? 'selected' : '' ?>>6 - Power on + Repeatedly</option>
                                    <option value="5" <?= ($formData['auto_provision_mode'] === '5') ? 'selected' : '' ?>>5 - Weekly</option>
                                    <option value="4" <?= ($formData['auto_provision_mode'] === '4') ? 'selected' : '' ?>>4 - Repeatedly</option>
                                    <option value="1" <?= ($formData['auto_provision_mode'] === '1') ? 'selected' : '' ?>>1 - Power on</option>
                                    <option value="0" <?= ($formData['auto_provision_mode'] === '0') ? 'selected' : '' ?>>0 - Disabled</option>
                                </select>
                            </div>
                            <div>
                                <label>Weekly Provisioning:</label>
                                <select name="auto_provision_weekly_enable" class="gen-full-width shadow-box">
                                    <option value="1" <?= ($formData['auto_provision_weekly_enable'] === '1') ? 'selected' : '' ?>>1 - Enabled</option>
                                    <option value="0" <?= ($formData['auto_provision_weekly_enable'] === '0') ? 'selected' : '' ?>>0 - Disabled</option>
                                </select>
                            </div>
                            <div>
                                <label>DHCP Option:</label>
                                <select name="auto_provision_dhcp_option_enable" class="gen-full-width shadow-box">
                                    <option value="1" <?= ($formData['auto_provision_dhcp_option_enable'] === '1') ? 'selected' : '' ?>>1 - Enabled</option>
                                    <option value="0" <?= ($formData['auto_provision_dhcp_option_enable'] === '0') ? 'selected' : '' ?>>0 - Disabled</option>
                                </select>
                            </div>
                        </div>
                        <div class="epm-template-fields epm-template-three-col">
                            <div>
                                <label>Weekly Begin Time:</label>
                                <input type="text" class="gen-full-width shadow-box" name="auto_provision_weekly_begin_time" value="<?= htmlspecialchars($formData['auto_provision_weekly_begin_time']) ?>">
                            </div>
                            <div>
                                <label>Weekly End Time:</label>
                                <input name="auto_provision_weekly_end_time" type="text" class="gen-full-width shadow-box" value="<?= htmlspecialchars($formData['auto_provision_weekly_end_time']) ?>">
                            </div>
                            <div>
                                <label>Day of Week:</label>
                                <select name="auto_provision_weekly_dayofweek" class="shadow-box gen-full-width">
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
                        <div class="epm-template-fields epm-template-two-col">
                            <div>
                                <label>Server Username (Optional):</label>
                                <input name="auto_provision_username" type="text" class="shadow-box gen-full-width" value="<?= htmlspecialchars($formData['auto_provision_username']) ?>">
                            </div>
                            <div>
                                <label>Server Password (Optional):</label>
                                <input name="auto_provision_password" type="text" class="gen-full-width shadow-box" value="<?= htmlspecialchars($formData['auto_provision_password']) ?>">
                            </div>
                        </div>
                    </div>
                </section>

                <!-- SIP & CALL TRANSFER -->
                <section class="epm-box" id="box_sip_transfer" data-box="sip_transfer" data-span="1">
                    <header class="epm-box-head">
                        <span class="epm-grip" title="Drag to move"><i class="fa fa-arrows"></i></span>
                        <strong class="epm-box-title">SIP &amp; Call Transfer Features</strong>
                        <span class="epm-box-tools">
                            <button type="button" class="epm-tool" data-act="span" title="Half / full width"><i class="fa fa-arrows-h"></i></button>
                            <button type="button" class="epm-tool" data-act="fold" title="Collapse / expand"><i class="fa fa-chevron-up"></i></button>
                        </span>
                    </header>
                    <div class="epm-box-body box-bg">
                        <div class="epm-template-fields epm-template-two-col">
                            <div>
                                <label>Use Outbound Proxy in Dialog:</label>
                                <select name="sip_use_out_bound_in_dialog" class="gen-full-width shadow-box">
                                    <option value="1" <?= ($formData['sip_use_out_bound_in_dialog'] === '1') ? 'selected' : '' ?>>1 - Enabled</option>
                                    <option value="0" <?= ($formData['sip_use_out_bound_in_dialog'] === '0') ? 'selected' : '' ?>>0 - Disabled</option>
                                </select>
                            </div>
                            <div>
                                <label>DSS Key Transfer Action:</label>
                                <select name="transfer_dsskey_deal_type" class="gen-full-width shadow-box">
                                    <option value="2" <?= ($formData['transfer_dsskey_deal_type'] === '2') ? 'selected' : '' ?>>2 - Attended Transfer</option>
                                    <option value="1" <?= ($formData['transfer_dsskey_deal_type'] === '1') ? 'selected' : '' ?>>1 - Blind Transfer</option>
                                    <option value="0" <?= ($formData['transfer_dsskey_deal_type'] === '0') ? 'selected' : '' ?>>0 - New Call</option>
                                </select>
                            </div>
                        </div>
                        <div class="epm-template-fields epm-template-two-col">
                            <div>
                                <label>Blind Transfer On Hook:</label>
                                <select name="transfer_blind_tran_on_hook_enable" class="gen-full-width shadow-box">
                                    <option value="1" <?= ($formData['transfer_blind_tran_on_hook_enable'] === '1') ? 'selected' : '' ?>>1 - Enabled</option>
                                    <option value="0" <?= ($formData['transfer_blind_tran_on_hook_enable'] === '0') ? 'selected' : '' ?>>0 - Disabled</option>
                                </select>
                            </div>
                            <div>
                                <label>On-Hook Transfer:</label>
                                <select name="transfer_on_hook_trans_enable" class="gen-full-width shadow-box">
                                    <option value="1" <?= ($formData['transfer_on_hook_trans_enable'] === '1') ? 'selected' : '' ?>>1 - Enabled</option>
                                    <option value="0" <?= ($formData['transfer_on_hook_trans_enable'] === '0') ? 'selected' : '' ?>>0 - Disabled</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- DIAL PLAN / DIAL-NOW -->
                <section class="epm-box" id="box_dialnow" data-box="dialnow" data-span="1">
                    <header class="epm-box-head">
                        <span class="epm-grip" title="Drag to move"><i class="fa fa-arrows"></i></span>
                        <strong class="epm-box-title">Dial Plan (Dial-Now) Rules</strong>
                        <span class="epm-box-tools">
                            <button type="button" class="epm-tool" data-act="span" title="Half / full width"><i class="fa fa-arrows-h"></i></button>
                            <button type="button" class="epm-tool" data-act="fold" title="Collapse / expand"><i class="fa fa-chevron-up"></i></button>
                        </span>
                    </header>
                    <div class="epm-box-body box-bg">
                        <div class="epm-template-fields epm-template-two-col">
                            <div>
                                <label>Number of DialNow Pattern Slots:</label>
                                <select name="dialnow_count" class="shadow-box gen-full-width" onchange="updateDialnowVisibility(this.value)">
                                    <?php for ($d_cnt = 1; $d_cnt <= 20; $d_cnt++): ?>
                                        <option value="<?= $d_cnt ?>" <?= ($d_cnt == $max_dialnow_slots) ? 'selected' : '' ?>><?= $d_cnt ?> Slots</option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div>
                                <label>Dial Now Delay:</label>
                                <select name="dialnow_timeout" class="shadow-box gen-full-width">
                                    <?php for ($sec = 1; $sec <= 14; $sec++): ?>
                                        <option value="<?= $sec ?>" <?= ($formData['dialnow_timeout'] == $sec) ? 'selected' : '' ?>><?= $sec ?> Seconds</option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>

                        <label style="margin-top:10px;" title="Automatically pulled from outbound route named &quot;outbound&quot;">Outbound Match Patterns</label>
                        <div style="margin-top:5px;" title="Automatically pulled from outbound route named &quot;outbound&quot;">
                        <?php for ($d = 1; $d <= 20; $d += 2):
                            $next_slot = $d + 1;
                        ?>
                            <div class="gen-key-row">
                                <div id="dialnow_slot_<?= $d ?>" style="display: <?= ($d <= $max_dialnow_slots) ? 'block' : 'none' ?>;">
                                    <input type="text" class="shadow-box gen-full-width" name="dialnow_<?= $d ?>" placeholder="Rule <?= $d ?>" value="<?= htmlspecialchars($formData["dialnow_{$d}"] ?? '') ?>">
                                </div>
                                <?php if ($next_slot <= 20): ?>
                                    <div id="dialnow_slot_<?= $next_slot ?>" style="display: <?= ($next_slot <= $max_dialnow_slots) ? 'block' : 'none' ?>;">
                                        <input type="text" class="shadow-box gen-full-width" name="dialnow_<?= $next_slot ?>" placeholder="Rule <?= $next_slot ?>" value="<?= htmlspecialchars($formData["dialnow_{$next_slot}"] ?? '') ?>">
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endfor; ?>
                        </div>
                    </div>
                </section>

                <!-- GLOBAL CUSTOM INPUTS -->
                <section class="epm-box" id="box_global_custom_inputs" data-box="global_custom_inputs" data-span="1">
                    <header class="epm-box-head">
                        <span class="epm-grip" title="Drag to move"><i class="fa fa-arrows"></i></span>
                        <strong class="epm-box-title">Global Custom Key / Value Additions</strong>
                        <span class="epm-box-tools">
                            <button type="button" class="epm-tool" data-act="span" title="Half / full width"><i class="fa fa-arrows-h"></i></button>
                            <button type="button" class="epm-tool" data-act="fold" title="Collapse / expand"><i class="fa fa-chevron-up"></i></button>
                        </span>
                    </header>
                    <div class="epm-box-body box-bg" >
                        
                        <textarea style="font-size: 14px;" name="custom_inputs_global" placeholder="Add raw global Yealink configuration flags (applied to every y-config file, one per line)" title="Add raw global Yealink configuration flags (applied to every y-config file, one per line)" class="gen-textarea shadow-box"><?= htmlspecialchars($formData['custom_inputs_global']) ?></textarea>
                    </div>
                </section>

                <?php if (!empty($generated_common_cfg)): ?>
                <!-- GENERATED COMMON OUTPUT -->
                <section class="epm-box" id="box_global_generated_output" data-box="global_generated_output" data-span="1">
                    <header class="epm-box-head">
                        <span class="epm-grip" title="Drag to move"><i class="fa fa-arrows"></i></span>
                        <strong class="epm-box-title">Generated Common Output</strong>
                        <span class="epm-box-tools">
                            <button type="button" class="epm-tool" data-act="span" title="Half / full width"><i class="fa fa-arrows-h"></i></button>
                            <button type="button" class="epm-tool" data-act="fold" title="Collapse / expand"><i class="fa fa-chevron-up"></i></button>
                        </span>
                    </header>
                    <div class="epm-box-body box-bg">
                        <textarea readonly class="gen-textarea" style="height:300px;"><?= htmlspecialchars($generated_common_cfg) ?></textarea>
                        <p style="font-size:12px; color:#666; margin-top:6px;">
                            This content is synchronized for all Yealink models listed in the template presets. All y-config files saved in <code>/tftpboot/</code>.
                        </p>
                    </div>
                </section>
                <?php endif; ?>
            </div>

            <br>
            <div style="display: flex; gap: 10px; margin-top: 10px;">
                <button type="submit" name="save_global" class="gen-btn" style="background: #28a745; margin-top:0;">Save Global Settings to /tftpboot/</button>
                <?php
                $any_global_cfg_exists = false;
                foreach (array_keys(yealinkGlobalCfgMap()) as $g_basename) {
                    if (file_exists($tftp_dir . $g_basename . ".cfg")) { $any_global_cfg_exists = true; break; }
                }
                ?>
                <?php if ($any_global_cfg_exists): ?>
                    <button type="button" class="gen-btn-danger" style="margin-top:0;" onclick="confirmDeleteGlobalConfig()">Delete Global Settings (all y-configs)</button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- TAB 2: TEMPLATE MANAGER -->
    <div id="tab_template" class="gen-tab-content <?= ($formData['active_tab'] === 'tab_template') ? 'active' : '' ?>">
        <div class="gen-load-box" style="width: 600px; border: 1px solid #a2a3a2; box-shadow: 2px 2px 4px #a2a3a2; background: #f7f7f7;">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" id="load_tpl_active_tab_field" name="active_tab" value="tab_template">
                <label>Active / Edit Template:</label>
                <div style="display: flex; gap: 8px; align-items: center;">
                    <?php 
                    $selected_tpl_option = $_POST['template_to_load'] ?? (!empty($formData['template_name']) ? $formData['template_name'] . '.template.cfg' : '');
                    ?>
                    <select id="select_template_file" name="template_to_load" style="width: 315px; flex: none; border: none !important; background: transparent !important; outline: none !important; box-shadow: 2px 2px 5px #307847;">
                        <option value="" <?= empty($selected_tpl_option) ? 'selected' : '' ?>>-- Select a template to edit --</option>
                        <?php foreach ($available_templates as $tpl_file => $tpl_label): ?>
                            <option value="<?= htmlspecialchars($tpl_file) ?>" <?= ($selected_tpl_option === $tpl_file) ? 'selected' : '' ?>><?= htmlspecialchars($tpl_label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" name="load_template" class="gen-btn" style="margin-top:0; background:#6c757d;">Load</button>
                    <button type="button" class="gen-btn" title="Files Saved to /tftpboot/templates/" style="margin-top:0; background:#17a2b8;" onclick="downloadSelectedTemplate()">Download</button>
                    <button type="button" class="gen-btn-danger" style="margin-top:0;" onclick="confirmDeleteFile(document.getElementById('select_template_file').value, 'template')">Delete</button>
                </div>
            </form>
            
            <form method="POST" enctype="multipart/form-data" style="margin-top:10px; border-top:1px dashed #ccc; padding-top:8px;">
                <input type="hidden" name="active_tab" value="tab_template">
                <div style="display:flex; align-items:center; gap:10px;">
                    <label style="margin:0; font-weight:bold; white-space:nowrap; font-size:13px;">Upload External Template File:</label>
                    <input type="file" name="template_upload" accept=".cfg,.template.cfg" style="padding:4px; font-size:12px; border: none; background: transparent; outline: none;">
                    <button type="submit" name="upload_template_file" class="gen-btn" style="width: 300px; margin:0; padding:6px 12px; font-size:12px; background:#28a745;" title="Files Saved to /tftpboot/templates/">Upload</button>
                </div>
            </form>
        </div>

        <form id="template_cfg_form" action="?display=yealink_epm" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="active_tab" value="tab_template">
            <input type="hidden" name="current_loaded_template" value="<?= htmlspecialchars($formData['template_name']) ?>">

            <div class="epm-dash-bar">
                <span class="epm-dash-hint"><i class="fa fa-arrows"></i> Drag a box by its grip to rearrange. <i class="fa fa-arrows-h"></i> toggles half / full width, <i class="fa fa-chevron-up"></i> collapses. Layout is remembered in this browser.</span>
                <button type="button" class="gen-btn epm-dash-reset" onclick="epmDashReset('epm_dash')">Reset layout</button>
            </div>

            <div id="epm_dash" class="epm-dash" style="font-family: Arial, Helvetica, sans-serif;" >
                <!-- Template configuration dashboard box -->
                <section class="epm-box" id="box_template_settings" data-box="template_settings" data-span="1">
                    <header class="epm-box-head">
                        <span class="epm-grip" title="Drag to move"><i class="fa fa-arrows"></i></span>
                        <strong class="epm-box-title">Template Settings</strong>
                        
                        <span class="epm-box-tools">
                            <button type="button" class="epm-tool" data-act="span" title="Half / full width"><i class="fa fa-arrows-h"></i></button>
                            <button type="button" class="epm-tool" data-act="fold" title="Collapse / expand"><i class="fa fa-chevron-up"></i></button>
                        </span>
                    </header>
                    <div class="epm-box-body box-bg">
                        <div class="epm-template-fields epm-template-two-col">
                            <div>
                                <label>Template Name:</label>
                                <input type="text" class="gen-full-width shadow-box" name="template_name" placeholder="e.g., T46_Reception" value="<?= htmlspecialchars($formData['template_name']) ?>">
                            </div>
                            <div>
                                <label>Phone Model Presets:</label>
                                <select id="select_phone_model" name="phone_model" class="gen-full-width shadow-box" onchange="handleModelSelect(this.value)">
                                    <?php foreach ($yealink_models as $m_key => $m_label): ?>
                                        <option value="<?= $m_key ?>" <?= ($formData['phone_model'] === $m_key) ? 'selected' : '' ?>><?= $m_label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label>Expansion Module Model:</label>
                                <div style="display:flex; gap:6px; align-items:center;">
                                    <select id="select_exp_model" name="exp_model" class="gen-full-width shadow-box" onchange="handleExpSelect()">
                                        <?php foreach ($expansion_models as $e_key => $e_label): ?>
                                            <option value="<?= $e_key ?>" <?= ($formData['exp_model'] === $e_key) ? 'selected' : '' ?>><?= $e_label ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" id="btn_exp_wallpaper" class="epm-key-edit" style="<?= in_array($formData['exp_model'], $expansion_wallpaper_models, true) ? '' : 'display:none;' ?>" onclick="openEpmKeyModal('keyModal_expwallpaper')" aria-label="Edit Expansion Module Wallpaper" title="Edit Expansion Module Wallpaper"><i class="fa fa-picture-o"></i></button>
                                </div>
                            </div>
                            <div>
                                <label>Expansion Quantity:</label>
                                <select id="select_exp_count" name="exp_count" class="gen-full-width shadow-box" onchange="handleExpSelect()">
                                    <option value="0" <?= ($formData['exp_count'] === '0') ? 'selected' : '' ?>>-- None --</option>
                                    <option value="1" <?= ($formData['exp_count'] === '1') ? 'selected' : '' ?>>1 Unit</option>
                                    <option value="2" <?= ($formData['exp_count'] === '2') ? 'selected' : '' ?>>2 Units</option>
                                    <option value="3" <?= ($formData['exp_count'] === '3') ? 'selected' : '' ?>>3 Units</option>
                                </select>
                            </div>
                        </div>
                        <div class="epm-template-fields epm-template-three-col">
                            <div>
                                <label>SIP Port:</label>
                                <input type="text"  class="gen-full-width shadow-box" name="sip_port" placeholder="<?= htmlspecialchars($default_sip_port) ?>" value="<?= htmlspecialchars($formData['sip_port']) ?>">
                            </div>
                            <div>
                                <label>SIP Listen Port:</label>
                                <input type="text" class="gen-full-width shadow-box" name="sip_listen_port" placeholder="5062" value="<?= htmlspecialchars($formData['sip_listen_port']) ?>">
                            </div>
                            <div>
                                <label>Voicemail Extension Number:</label>
                                <div style="display:flex; gap:8px; align-items:center;">
                                    <input type="text" class="gen-full-width shadow-box" name="voicemail_number" placeholder="*97" value="<?= htmlspecialchars($formData['voicemail_number']) ?>" style="flex:1; min-width:0;">
                                    <button type="button" id="popup_settings_btn" class="gen-btn" style="white-space:nowrap; margin:0;" title="Notification popups" onclick="openEpmKeyModal('keyModal_notifications')"><i class="fa fa-bell"></i> Notifications</button>
                                </div>
                                <input type="hidden" id="popup_voice_mail" name="popup_voice_mail" value="<?= htmlspecialchars($formData['popup_voice_mail'] ?? '1') ?>">
                                <input type="hidden" id="popup_missed_call" name="popup_missed_call" value="<?= htmlspecialchars($formData['popup_missed_call'] ?? '1') ?>">
                                <input type="hidden" id="popup_forward_call" name="popup_forward_call" value="<?= htmlspecialchars($formData['popup_forward_call'] ?? '1') ?>">
                                <input type="hidden" id="popup_text_message" name="popup_text_message" value="<?= htmlspecialchars($formData['popup_text_message'] ?? '1') ?>">
                            </div>
                        </div>
                    </div>
                </section>


                <!-- LINE KEYS -->
                <!-- Combined Keys dashboard box -->
<section class="epm-box" id="box_linekeys_launcher" data-box="linekeys" data-span="1" >
  <header class="epm-box-head">
    <span class="epm-grip" title="Drag to move"><i class="fa fa-arrows"></i></span>
    <strong class="epm-box-title">Line Keys</strong>
    
    <span class="epm-box-tools">
      <button type="button" class="epm-tool" data-act="span" title="Half / full width"><i class="fa fa-arrows-h"></i></button>
      <button type="button" class="epm-tool" data-act="fold" title="Collapse / expand"><i class="fa fa-chevron-up"></i></button>
    </span>
  </header>
  <div class="epm-box-body" >
    <div class="epm-key-overview">
      <div class="epm-key-overview-row">
        <div class="epm-key-overview-label"><strong>Line Keys</strong><span id="summary_linekeys" class="epm-key-summary"></span></div>
        <button type="button" class="epm-key-edit" onclick="openEpmKeyModal('keyModal_linekeys')" aria-label="Edit Line Keys" title="Edit Line Keys"><i class="fa fa-pencil"></i> Edit Line Keys</button>
      </div>
      <div class="epm-key-overview-row">
        <div class="epm-key-overview-label"><strong>Memory / Expansion Keys</strong><span id="summary_memkeys" class="epm-key-summary"></span></div>
        <button type="button" class="epm-key-edit" onclick="openEpmKeyModal('keyModal_memkeys')" aria-label="Edit Memory Keys" title="Edit Memory Keys"><i class="fa fa-pencil"></i> Edit Memory Keys</button>
      </div>
      <div class="epm-key-overview-row">
        <div class="epm-key-overview-label"><strong>Programmable Keys</strong><span id="summary_progkeys" class="epm-key-summary"></span></div>
        <button type="button" class="epm-key-edit" onclick="openEpmKeyModal('keyModal_progkeys')" aria-label="Edit Programmable Keys" title="Edit Programmable Keys"><i class="fa fa-pencil"></i> Edit Programmable Keys</button>
      </div>
    </div>
  </div>
</section>

                
                

                <!-- RINGTONES + LOGO -->
                <section class="epm-box" id="ringtone_section" data-box="ringtones" data-span="1">
                    <header class="epm-box-head">
                        <span class="epm-grip" title="Drag to move"><i class="fa fa-arrows"></i></span>
                        <strong class="epm-box-title">Ringtones</strong>
                       
                        <span class="epm-box-tools">
                            <button type="button" class="epm-tool" data-act="span" title="Half / full width"><i class="fa fa-arrows-h"></i></button>
                            <button type="button" class="epm-tool" data-act="fold" title="Collapse / expand"><i class="fa fa-chevron-up"></i></button>
                        </span>
                    </header>
                    <div class="epm-box-body">
            <div id="flush_banner_container" class="flush-banner" style="display: <?= $show_flush_ringtone_btn ? 'block' : 'none' ?>;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <strong>&#9888; Unreferenced / Deleted Ringtone(s) Detected in Phone Configs:</strong> One or more device <code>[mac].cfg</code> files assigned to this template reference ringtones that have been deleted or unchecked. Click below to issue a flush directive and sync all affected phones.
                    </div>
                    <button type="submit" name="flush_template_ringtones" class="gen-btn-danger" style="margin:0; white-space:nowrap; padding:8px 14px; font-weight:bold;" onclick="epmStore(EPM_SCROLL_KEY, 'ringtone_section'); this.form.action='?display=yealink_epm';">
                        Flush Ringtones From Phones
                    </button>
                </div>
            </div>

            <div class="ringtone-card">
                <label style="margin-top:0;">Provision Uploaded Sound Files to Phone:</label>
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

                                <div style="display:flex; align-items:center; gap:2px;">
                                    <button type="button" class="action-icon-btn loop-icon-btn" title="Loop <?= htmlspecialchars($r_file) ?>" aria-pressed="false" onclick="toggleAudioLoopBtn(this, 'audio_player_<?= $clean_id ?>')">
                                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <polyline points="17 1 21 5 17 9"></polyline>
                                            <path d="M3 11V9a4 4 0 0 1 4-4h14"></path>
                                            <polyline points="7 23 3 19 7 15"></polyline>
                                            <path d="M21 13v2a4 4 0 0 1-4 4H3"></path>
                                        </svg>
                                    </button>
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
                    <label style="margin-top:0; margin-bottom:8px;" title="Files Saved to /PhoneSetting/ringtones">Upload New Ringtones:</label>
                    
                    <div class="upload-controls-col">
                        <label for="ringtone_file_input" class="custom-file-btn">Browse Files</label>
                        <input type="file" id="ringtone_file_input" accept=".wav,.mp3" multiple style="display:none;" onchange="updateVerticalFileList(this)">
                        
                        <button type="button" id="async_upload_btn" onclick="uploadRingtonesAsync(event)" class="upload-btn-aligned" title="Files Saved to /PhoneSetting/ringtones">
                            Upload Ringtones
                        </button>

                        <div style="flex-basis:100%; height:0;"></div>
                        <textarea id="selected_files_textarea" readonly placeholder="No files selected"></textarea>
                    </div>
                </div>
            </div>

                    </div>
                </section>

                <!-- Separate dashboard box for account ringtone defaults and wallpaper/logo -->
                <section class="epm-box" id="box_ringtone_defaults" data-box="ringtone_defaults" data-span="1">
                    <header class="epm-box-head">
                        <span class="epm-grip" title="Drag to move"><i class="fa fa-arrows"></i></span>
                        <strong class="epm-box-title">Ringtone Defaults &amp; Wallpaper</strong>
                        
                        <span class="epm-box-tools">
                            <button type="button" class="epm-tool" data-act="span" title="Half / full width"><i class="fa fa-arrows-h"></i></button>
                            <button type="button" class="epm-tool" data-act="fold" title="Collapse / expand"><i class="fa fa-chevron-up"></i></button>
                        </span>
                    </header>
                    <div class="epm-box-body">
                        <div class="epm-sub-grid">
            <div class="ringtone-card" >
                <label style="margin-top:0;">Default Account Ringtone:</label>
                <p style="font-size:12px; color:#666; margin-top:2px; margin-bottom:10px;">Ringtone for incoming calls on Account 1:</p>

                <select id="account_ringtone_select" name="account_ringtone" class="gen-full-width shadow-box">
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

                            <div class="ringtone-card">
                                <label style="margin-top:0;">Wallpaper / Logo </small>:</label>
            <div id="logo_spec_note" class="spec-note">Loading specs...</div>
            <div class="epm-stack">
                <div>
                    <label style="margin-top:6px;">Existing file:</label>
                    <div style="display: flex; gap: 5px;">
                        <select id="select_logo_file" name="logo_file" class="gen-full-width shadow-box">
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
                    <label style="margin-top:6px;" title="Files saved to /PhoneSettings/logo/">Upload New Wallpaper / Logo:</label>
                    <input type="file" name="logo_upload" class="gen-full-width" accept=".dob,.jpg,.png,.bmp">
                </div>
            </div>

                            </div>
                        </div>
                    </div>
                </section>

                
                

                <section class="epm-box" id="box_custom_inputs" data-box="custom_inputs" data-span="1">
                    <header class="epm-box-head">
                        <span class="epm-grip" title="Drag to move"><i class="fa fa-arrows"></i></span>
                        <strong class="epm-box-title">Template Custom Key / Value Additions</strong>                     
                        <span class="epm-box-tools">
                            <button type="button" class="epm-tool" data-act="span" title="Half / full width"><i class="fa fa-arrows-h"></i></button>
                            <button type="button" class="epm-tool" data-act="fold" title="Collapse / expand"><i class="fa fa-chevron-up"></i></button>
                        </span>
                    </header>
                    <div class="epm-box-body box-bg">
                         <textarea name="custom_inputs" placeholder="Add raw Yealink configuration flags for this template (One per line)" title="Add raw Yealink configuration flags for this template (One per line)" class="gen-textarea shadow-box"><?= htmlspecialchars($formData['custom_inputs']) ?></textarea>
                    </div>
                </section>

                <?php if (!empty($generated_template_cfg)): ?>
                <section class="epm-box" id="box_generated_output" data-box="generated_output" data-span="1">
                    <header class="epm-box-head">
                        <span class="epm-grip" title="Drag to move"><i class="fa fa-arrows"></i></span>
                        <strong class="epm-box-title">Generated Template Output</strong>
                        
                        <span class="epm-box-tools">
                            <button type="button" class="epm-tool" data-act="span" title="Half / full width"><i class="fa fa-arrows-h"></i></button>
                            <button type="button" class="epm-tool" data-act="fold" title="Collapse / expand"><i class="fa fa-chevron-up"></i></button>
                        </span>
                    </header>
                    <div class="epm-box-body box-bg" >
                        <textarea readonly class="gen-textarea shadow-box" style="height: 450px;"><?= htmlspecialchars($generated_template_cfg) ?></textarea>
                    </div>
                </section>
                <?php endif; ?>
            </div>

<div style="font-family: Arial, Helvetica, sans-serif !important;" class="epm-key-modal" id="keyModal_linekeys" aria-hidden="true">
  <div class="epm-key-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="keyModalTitle_linekeys">
    <div class="epm-key-modal-head">
      <strong id="keyModalTitle_linekeys"><i class="fa fa-key" aria-hidden="true"></i>&nbsp; Line Keys</strong>
      <button type="button" class="epm-key-modal-x" aria-label="Close" onclick="closeEpmKeyModal('keyModal_linekeys')">&times;</button>
    </div>
    <div class="epm-key-modal-content"><section class="epm-box epm-key-editor" id="box_linekeys">
                    <div class="epm-box-body">
                        <div class="epm-toolbar">
                            <label for="select_linekey_count">Slots</label>
                            <select id="select_linekey_count" name="linekey_count" style="width: 100px; border: none; background: transparent; outline: none; box-shadow: 0px 0px 5px #307847;" onchange="updateLinekeyVisibility(this.value)">
                                <?php for ($l_cnt = 1; $l_cnt <= 29; $l_cnt++): ?>
                                    <option value="<?= $l_cnt ?>" <?= ($l_cnt == $max_linekeys) ? 'selected' : '' ?>><?= $l_cnt ?> Line Keys</option>
                                <?php endfor; ?>
                            </select>
                            <span class="epm-toolbar-note">Defaults to the model's key count when you change the phone model.</span>
                        </div>
                        <div class="epm-toolbar">
                            <span class="epm-toolbar-note">Drag a key's number to move it, or click a column header to sort (key 1 stays fixed).</span>
                        </div>
                        <div class="epm-scroll">
            <div class="gen-kh gen-lk-head">
		<span class="gen-key-num"></span>
		<span style="padding-left: 20px;">Type</span>
		<span class="gen-key-sortable" data-key-type="linekey" data-field="value" onclick="toggleKeySort('linekey','value')"  style="padding-left: 15px;">Extension <span class="sort-caret">⇅</span></span>
		<span class="gen-key-sortable" data-key-type="linekey" data-field="label" onclick="toggleKeySort('linekey','label')" style="padding-left: 15px;">Label <span class="sort-caret">⇅</span></span>
		<span style="padding-left: 10px;">Pickup</span>
		<span style="padding-left: 10px;">Line</span>
	    </div>

            <div style="margin-top:10px;">
            <?php for ($i = 1; $i <= 29; $i++): ?>
                <div id="linekey_row_<?= $i ?>" class="gen-key-row gen-lk-row" style="<?= ($i <= $max_linekeys) ? '' : 'display: none;' ?>">
                    <?php if ($i === 1): ?>
                        <span class="gen-key-num"><?= $i ?></span>
                    <?php else: ?>
                        <span class="gen-key-num gen-key-draggable" draggable="true" data-key-type="linekey" data-idx="<?= $i ?>" title="Drag to reorder"><?= $i ?></span>
                    <?php endif; ?>
                    <?php if ($i === 1): ?>
                        <select name="linekey_1_type" style="background-color: #e9ecef; pointer-events: none; border: none; width: 122px; background: transparent; outline: none;" readonly tabindex="-1">
                            <option value="15" selected>Line (15)</option>
                        </select>
                        <input type="text" name="linekey_1_value" placeholder="Extension Number" value="<?= htmlspecialchars($formData["linekey_1_value"] ?? '') ?>" readonly style="background-color: #e9ecef; border: none; background: transparent; outline: none;">
                        <input type="text" name="linekey_1_label" placeholder="Extension Name" value="<?= htmlspecialchars($formData["linekey_1_label"] ?? '') ?>" readonly style="background-color: #e9ecef; border: none; background: transparent; outline: none;">
                        <input type="text" name="linekey_1_pickup" placeholder="Pickup (**)" value="<?= htmlspecialchars($formData["linekey_1_pickup"] ?? '') ?>" readonly style="background-color: #e9ecef; border: none; background: transparent; outline: none;">
                        <select name="linekey_1_line" Style=" border: none; background: transparent; outline: none;">
                            <?php 
                            $lk_line_1 = $formData["linekey_1_line"] ?? '1';
                            for ($l = 1; $l <= 16; $l++): 
                            ?>
                                <option value="<?= $l ?>" <?= ($lk_line_1 == $l) ? 'selected' : '' ?>>Line <?= $l ?></option>
                            <?php endfor; ?>
                        </select>
                    <?php else: ?>
                        <select name="linekey_<?= $i ?>_type" Style=" border: none; width: 122px; background: transparent; outline: none;">
                            <?php 
                            $current_type = $formData["linekey_{$i}_type"] ?? '16';
                            foreach ($dss_key_types as $k_code => $k_label): 
                            ?>
                                <option value="<?= $k_code ?>" <?= ($current_type == $k_code) ? 'selected' : '' ?>><?= $k_label ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="linekey_<?= $i ?>_value" Style=" border: none; background: transparent; outline: none;" placeholder="Line Key <?= $i ?> Extension" title="Line Key <?= $i ?>" inputmode="numeric" pattern="[0-9]*" oninput="epmDigitsOnly(this)" value="<?= htmlspecialchars($formData["linekey_{$i}_value"] ?? '') ?>">
                        <input type="text" name="linekey_<?= $i ?>_label" Style=" border: none; background: transparent; outline: none;" placeholder="Label" title="Label" value="<?= htmlspecialchars($formData["linekey_{$i}_label"] ?? '') ?>">
                        <input type="text" name="linekey_<?= $i ?>_pickup" Style=" border: none; background: transparent; outline: none;" placeholder="Pickup (**)" title="Pickup (**)" value="<?= htmlspecialchars($formData["linekey_{$i}_pickup"] ?? '**') ?>">
                        <select Style=" border: none; background: transparent; outline: none;" name="linekey_<?= $i ?>_line">
                            <?php 
                            $current_line = $formData["linekey_{$i}_line"] ?? '1';
                            for ($l = 1; $l <= 16; $l++): 
                            ?>
                                <option value="<?= $l ?>" <?= ($current_line == $l) ? 'selected' : '' ?>>Line <?= $l ?></option>
                            <?php endfor; ?>
                        </select>
                    <?php endif; ?>
                </div>
            <?php endfor; ?>
            </div>

                        </div>
                    </div>
                </section></div>
    <div class="epm-key-modal-foot">
      <span class="epm-key-modal-note">Changes are included when you save the template.</span>
      <button type="button" class="gen-btn epm-key-modal-close" onclick="closeEpmKeyModal('keyModal_linekeys')">Close</button>
    </div>
  </div>
</div>
<div style="font-family: Arial, Helvetica, san-sarif !important;" class="epm-key-modal" id="keyModal_memkeys" aria-hidden="true">
  <div class="epm-key-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="keyModalTitle_memkeys">
    <div class="epm-key-modal-head">
      <strong id="keyModalTitle_memkeys"><i class="fa fa-key" aria-hidden="true"></i>&nbsp; Memory / Expansion Keys</strong>
      <button type="button" class="epm-key-modal-x" aria-label="Close" onclick="closeEpmKeyModal('keyModal_memkeys')">&times;</button>
    </div>
    <div class="epm-key-modal-content"><section class="epm-box epm-key-editor" id="box_memkeys">
                    <div class="epm-box-body">
                        <input type="hidden" id="field_base_mem_keys" value="0">
                        <div class="epm-toolbar">
                            <label for="select_memkey_count">Slots</label>
                            <select id="select_memkey_count" style="width: 85px; border: none; background: transparent; outline: none; box-shadow: 0px 0px 5px #307847;" name="memkey_count" onchange="updateMemkeyVisibility(this.value)">
                                <option value="0" <?= (0 == $max_memkeys) ? 'selected' : '' ?>>0 Slots</option>
                                <?php for ($k = 1; $k <= 180; $k++): ?>
                                    <option value="<?= $k ?>" <?= ($k == $max_memkeys) ? 'selected' : '' ?>><?= $k ?> Slots</option>
                                <?php endfor; ?>
                            </select>
                            <span id="memkey_model_note" class="epm-toolbar-note"></span>
                        </div>
                        <div class="epm-toolbar">
                            <span class="epm-toolbar-note">Drag a key's number to move it (works across all module pages), or click a column header to sort.</span>
                        </div>
                        <div id="memkey_pages" class="gen-key-pages" style="display:none;"></div>
                        <p id="memkey_empty" class="gen-key-empty" style="display:none;">No memory key slots. This model has no built-in memory keys; pick an expansion module above, or set a slot count.</p>
                        <div class="epm-scroll">

            <div id="memkey_head" class="gen-kh gen-mk-head">
		<span  class="gen-key-num"></span>
		<span style="padding-left: 20px;">Type</span>
		<span style="padding-left: 15px;"class="gen-key-sortable" data-key-type="memkey" data-field="value" onclick="toggleKeySort('memkey','value')">Extension <span class="sort-caret">⇅</span></span>
		<span style="padding-left: 13px;"class="gen-key-sortable" data-key-type="memkey" data-field="label" onclick="toggleKeySort('memkey','label')">Label <span class="sort-caret">⇅</span></span>
		<span style="padding-left: 10px;">Pickup</span>
		<span style="padding-left: 10px;">Line</span>
	    </div>

            <div style="margin-top:10px;">
            <?php for ($i = 1; $i <= 180; $i++): ?>
                <div id="memkey_row_<?= $i ?>" class="gen-key-row gen-mk-row" style="<?= ($i <= $max_memkeys) ? '' : 'display: none;' ?>">
                    <span class="gen-key-num gen-key-draggable" draggable="true" data-key-type="memkey" data-idx="<?= $i ?>" title="Drag to reorder" Style="padding-right: 15px;"><?= $i ?></span>
                    <select name="memkey_<?= $i ?>_type" Style=" border: none; background: transparent; outline: none;">
                        <?php
                        $current_mk_type = $formData["memkey_{$i}_type"] ?? '16';
                        foreach ($dss_key_types as $mk_code => $mk_label):
                        ?>
                            <option value="<?= $mk_code ?>" <?= ($current_mk_type == $mk_code) ? 'selected' : '' ?>><?= $mk_label ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" Style=" border: none; background: transparent; outline: none;" name="memkey_<?= $i ?>_value" placeholder="Memory Key <?= $i ?> Extension" title="Memory Key <?= $i ?> Extension" inputmode="numeric" pattern="[0-9]*" oninput="epmDigitsOnly(this)" value="<?= htmlspecialchars($formData["memkey_{$i}_value"] ?? '') ?>">
                    <input type="text" Style=" border: none; background: transparent; outline: none;" name="memkey_<?= $i ?>_label" placeholder="Label" title="Label" value="<?= htmlspecialchars($formData["memkey_{$i}_label"] ?? '') ?>">
                    <input type="text" Style=" border: none; background: transparent; outline: none;" name="memkey_<?= $i ?>_pickup" placeholder="Pickup Value" title="Pickup Value" value="<?= htmlspecialchars($formData["memkey_{$i}_pickup"] ?? '**') ?>">
                    <select Style=" border: none; background: transparent; outline: none;" name="memkey_<?= $i ?>_line">
                        <?php
                        $current_mk_line = $formData["memkey_{$i}_line"] ?? '1';
                        for ($ml = 1; $ml <= 16; $ml++):
                        ?>
                            <option value="<?= $ml ?>" <?= ($current_mk_line == $ml) ? 'selected' : '' ?>>Line <?= $ml ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            <?php endfor; ?>
            </div>

                        </div>
                    </div>
                </section></div>
    <div class="epm-key-modal-foot">
      <span class="epm-key-modal-note">Changes are included when you save the template.</span>
      <button type="button" class="gen-btn epm-key-modal-close" onclick="closeEpmKeyModal('keyModal_memkeys')">Close</button>
    </div>
  </div>
</div>
<div class="epm-key-modal" id="keyModal_progkeys" aria-hidden="true">
  <div class="epm-key-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="keyModalTitle_progkeys">
    <div class="epm-key-modal-head">
      <strong id="keyModalTitle_progkeys"><i class="fa fa-key" aria-hidden="true"></i>&nbsp; Programmable Keys</strong>
      <button type="button" class="epm-key-modal-x" aria-label="Close" onclick="closeEpmKeyModal('keyModal_progkeys')">&times;</button>
    </div>
    <div class="epm-key-modal-content sans-font"><section class="epm-box epm-key-editor" id="box_progkeys">
                    <div class="epm-box-body">
                        <div class="epm-toolbar">
                            <span id="progkey_model_note" class="epm-toolbar-note"></span>
                            <button type="button" class="gen-key-btn-reset" style="background: #28a745 !important;" onclick="progKeyResetDefaults()">Reset to default</button>
                        </div>
                        <p class="gen-key-note">Only SoftKey 1-4 show a label on screen. Keys left at their factory function are not written to the template. Mute (Cancel on T21/T23) only takes effect when features.keep_mute.enable = 0.</p>
                        <div class="gen-key-pages" style="display:flex;">
                            <button type="button" class="gen-key-page active" data-pk-tab="hard" onclick="setProgKeyTab('hard')">Hard Keys</button>
                            <button type="button" class="gen-key-page" data-pk-tab="soft" onclick="setProgKeyTab('soft')">Soft Keys</button>
                        </div>
                        <div class="epm-scroll">

                        <div class="gen-kh gen-pk-head">
				<span class="gen-pk-name" Style="padding-left: 7px;">Key</span>
				<span Style="padding-left: 15px;">Type</span>
				<span Style="padding-left: 10px;">Line / History</span>
				<span Style="padding-left: 10px;">Value</span>
				<span Style="padding-left: 10px;">Label</span>
			</div>

                        <?php $pk_model = $formData['phone_model'] ?? 'manual'; ?>
                        <?php foreach ($prog_key_names as $pid => $pname):
                            $pk_type = (string)($formData["progkey_{$pid}_type"] ?? '0');
                            $pk_line = (string)($formData["progkey_{$pid}_line"] ?? '1');
                            $pk_hist = (string)($formData["progkey_{$pid}_hist"] ?? '0');
                            // Snapshot, at load time, whether this key is already non-default
                            // (i.e. already saved/pushed to phones). Rides along as a hidden
                            // field so that if the admin reverts it back to default during this
                            // edit, the save still knows to write the default explicitly instead
                            // of silently dropping the key - see epm_build_prog_keys_block().
                            $pk_was_custom = epm_prog_key_is_custom($pid, $pk_model, $formData, $prog_meta);
                        ?>
                        <div id="progkey_row_<?= $pid ?>" class="gen-key-row gen-pk-row" data-pk-group="<?= ($pid <= 4) ? 'soft' : 'hard' ?>" style="<?= ($pid <= 4) ? 'display:none;' : '' ?>">
                            <input type="hidden" name="progkey_<?= $pid ?>_wascustom" value="<?= $pk_was_custom ? '1' : '' ?>">
                            <!-- Snapshot of what's already in effect (before this edit) so a save that
                                 drops a field the new type doesn't use can null it out instead of just
                                 leaving the stale value on the phone - see epm_prog_key_null_fields(). -->
                            <input type="hidden" name="progkey_<?= $pid ?>_prevtype" value="<?= htmlspecialchars($pk_type) ?>">
                            <input type="hidden" name="progkey_<?= $pid ?>_prevline" value="<?= htmlspecialchars($pk_line) ?>">
                            <input type="hidden" name="progkey_<?= $pid ?>_prevhist" value="<?= htmlspecialchars($pk_hist) ?>">
                            <input type="hidden" name="progkey_<?= $pid ?>_prevvalue" value="<?= htmlspecialchars($formData["progkey_{$pid}_value"] ?? '') ?>">
                            <input type="hidden" name="progkey_<?= $pid ?>_prevlabel" value="<?= htmlspecialchars($formData["progkey_{$pid}_label"] ?? '') ?>">
                            <span class="gen-pk-name"><?= htmlspecialchars($pname) ?></span>
                            <select Style=" border: none; background: transparent; outline: none;" name="progkey_<?= $pid ?>_type" onchange="progKeyRefreshRow(<?= $pid ?>);">
                                <?php foreach ($prog_key_types as $t_code => $t_label): ?>
                                    <option value="<?= $t_code ?>" <?= ($pk_type === (string)$t_code) ? 'selected' : '' ?>><?= htmlspecialchars($t_label) ?> (<?= $t_code ?>)</option>
                                <?php endforeach; ?>
                                <?php if (!ctype_digit($pk_type) || !isset($prog_key_types[(int)$pk_type])): ?>
                                    <option value="<?= htmlspecialchars($pk_type) ?>" selected>Other (<?= htmlspecialchars($pk_type) ?>)</option>
                                <?php endif; ?>
                            </select>
                            <div class="gen-pk-slot gen-pk-slot-linehist">
                                <select Style=" border: none; background: transparent; outline: none;" name="progkey_<?= $pid ?>_line" class="pk-line">
                                    <?php for ($l = 0; $l <= 16; $l++): ?>
                                        <option value="<?= $l ?>" <?= ($pk_line === (string)$l) ? 'selected' : '' ?>><?= ($l === 0) ? 'Auto / All (0)' : 'Line ' . $l ?></option>
                                    <?php endfor; ?>
                                </select>
                                <select Style=" border: none; background: transparent; outline: none;" name="progkey_<?= $pid ?>_hist" class="pk-hist">
                                    <option value="0" <?= ($pk_hist === '1') ? '' : 'selected' ?>>Local History</option>
                                    <option value="1" <?= ($pk_hist === '1') ? 'selected' : '' ?>>Network CallLog</option>
                                </select>
                                <span class="pk-na">N/A</span>
                            </div>
                            <div class="gen-pk-slot gen-pk-slot-value">
                                <input type="text" Style=" border: none; background: transparent; outline: none;" class="pk-value" name="progkey_<?= $pid ?>_value" placeholder="Number / URL" value="<?= htmlspecialchars($formData["progkey_{$pid}_value"] ?? '') ?>">
                                <span class="pk-na">N/A</span>
                            </div>
                            <div class="gen-pk-slot gen-pk-slot-label">
                                <input type="text" Style=" border: none; background: transparent; outline: none;" class="pk-label" name="progkey_<?= $pid ?>_label" placeholder="Label" value="<?= htmlspecialchars($formData["progkey_{$pid}_label"] ?? '') ?>">
                                <span class="pk-na">N/A</span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                </section></div>
    <div class="epm-key-modal-foot">
      <span class="epm-key-modal-note">Changes are included when you save the template.</span>
      <button type="button" class="gen-btn epm-key-modal-close" onclick="closeEpmKeyModal('keyModal_progkeys')">Close</button>
    </div>
  </div>
</div>

<div class="epm-key-modal" id="keyModal_expwallpaper" aria-hidden="true">
  <div class="epm-key-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="keyModalTitle_expwallpaper" style="width:min(560px, 96vw);">
    <div class="epm-key-modal-head">
      <strong id="keyModalTitle_expwallpaper"><i class="fa fa-picture-o" aria-hidden="true"></i>&nbsp; Expansion Module Wallpaper</strong>
      <button type="button" class="epm-key-modal-x" aria-label="Close" onclick="closeEpmKeyModal('keyModal_expwallpaper')">&times;</button>
    </div>
    <div class="epm-key-modal-content"><section class="epm-box epm-key-editor" id="box_expwallpaper">
                    <div class="epm-box-body">
                        <p class="gen-key-note">EXP43 and EXP50 have a color LCD and can show a custom wallpaper/background image (272&times;480). EXP20 and EXP40 do not support this. The module wallpaper is chosen separately from the phone wallpaper, so they can be different images. Yealink downloads images through a single <code>wallpaper_upload.url</code>, so when the two differ, the phone re-provisioning may need two cycles for both images to appear.</p>
                        <div class="epm-stack">
                            <div>
                                <label style="margin-top:6px;">Existing file:</label>
                                <div style="display: flex; gap: 5px;">
                                    <select id="select_exp_wallpaper_file" name="exp_wallpaper_file" class="gen-full-width shadow-box">
                                        <option value="">-- None --</option>
                                        <?php foreach ($logo_filenames as $wp_file): ?>
                                            <option value="<?= $wp_file ?>" <?= ($formData['exp_wallpaper_file'] === $wp_file) ? 'selected' : '' ?>><?= $wp_file ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="gen-btn-danger" style="margin-top:0;" onclick="confirmDeleteFile(document.getElementById('select_exp_wallpaper_file').value, 'logo')">Delete</button>
                                </div>
                            </div>
                            <div>
                                <label style="margin-top:6px;" title="Files saved to /PhoneSettings/logo/">Upload New Wallpaper (272&times;480):</label>
                                <div class="upload-controls-col">
                                    <label for="exp_wallpaper_file_input" class="custom-file-btn">Browse Files</label>
                                    <input type="file" id="exp_wallpaper_file_input" name="exp_wallpaper_upload" accept=".jpg,.jpeg,.png,.bmp" style="display:none;" onchange="updateExpWallpaperFileLabel(this)">
                                    <button type="button" id="exp_wallpaper_upload_btn" onclick="uploadExpWallpaperAsync(event)" class="upload-btn-aligned" title="Files saved to /PhoneSettings/logo/">Upload</button>
                                    <div style="flex-basis:100%; height:0;"></div>
                                    <span id="exp_wallpaper_file_label" style="font-size:12px; color:#555;">No file selected</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </section></div>
    <div class="epm-key-modal-foot">
      <span class="epm-key-modal-note">Changes are included when you save the template.</span>
      <button type="button" class="gen-btn epm-key-modal-close" onclick="closeEpmKeyModal('keyModal_expwallpaper')">Close</button>
    </div>
  </div>
</div>

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
                            <th style="text-align:center; width: 100px;" title="Download Key Package in OpenVPN Manager Module">VPN</th>
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
					       style="padding:4px; font-weight:bold; width:120px; font-family:monospace; text-transform:lowercase; border-radius:4px; border:1px solid #ccc; background-color:#e9ecef; box-shadow: 0 0 8px #307847">
                                        
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
                                    <div style="display:flex; align-items:center; gap:6px;">
                                    <select class="shadow-box sans-font" id="phone_tpl_<?= htmlspecialchars($dev['mac']) ?>" name="phone_template[<?= htmlspecialchars($dev['mac']) ?>]" style="padding:4px; border-radius:4px; border:1px solid #ccc;">
                                        <option value="" <?= empty($dev['template']) ? 'selected' : '' ?>>-- None --</option>
                                        <?php foreach ($available_templates as $tpl_file => $tpl_label): ?>
                                            <option value="<?= htmlspecialchars($tpl_file) ?>" <?= ($dev['template'] === $tpl_file) ? 'selected' : '' ?>><?= htmlspecialchars($tpl_label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="epm-ov-edit-btn<?= !empty($dev['has_overrides']) ? ' has-ov' : '' ?>" title="<?= !empty($dev['has_overrides']) ? 'Edit device overrides (this phone has overrides)' : 'Edit device overrides' ?>" onclick="epmOv.open('<?= htmlspecialchars($dev['mac']) ?>')"><i class="fa fa-pencil" aria-hidden="true"></i></button>
                                    </div>
                                </td>
                                <td>
                                    <select class="shadow-box sans-font" name="phone_extension[<?= htmlspecialchars($dev['mac']) ?>]" style="padding:4px; border-radius:4px; border:1px solid #ccc;">
                                        <option value="">-- Unassigned --</option>
                                        <?php foreach ($all_extensions as $ext_id => $ext_data): ?>
                                            <option value="<?= $ext_id ?>" <?= ($dev['ext'] == $ext_id) ? 'selected' : '' ?>><?= $ext_id ?> - <?= htmlspecialchars($ext_data['display_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>

                                <?php if ($ovpn_installed): ?>
                                    <td style="text-align:center;">
                                        <div style="display:inline-flex; align-items:center; justify-content:center;">
                                            <label class="switch" style="margin:0;" title="<?= empty($clean_ext) ? 'VPN unavailable: assign an extension to this device first' : 'Create or Delete OpenVPN keys' ?>">
                                                <input type="checkbox" 
                                                       id="vpn_toggle_<?= htmlspecialchars($dev['mac']) ?>" 
                                                       <?= $vpn_enabled ? 'checked' : '' ?> 
                                                       <?= empty($clean_ext) ? 'disabled' : '' ?>
                                                       onchange="handleVpnToggleChange(this, '<?= $clean_ext ?>', '<?= htmlspecialchars($dev['mac']) ?>')">
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
                    <button type="button" class="gen-btn-danger" style="margin:0;" onclick="confirmDeleteSelectedPhones()">Delete</button>
                    <span>Delete Selected Phones</span>
                </div>
                <div class="oss-action-line" style="flex-wrap: wrap; gap: 10px;">
                    <button type="button" class="gen-btn" style="margin:0; background:#28a745;" onclick="triggerDeviceAction('rebuild_selected')">Rebuild Selected</button>
                    
                    <select class="shadow-box sans-font" name="bulk_selected_template" style="padding: 6px; border-radius: 4px; border: 1px solid #ccc;">
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

                    <select name="global_filter_model" class="shadow-box sans-font" style="padding: 6px; border-radius: 4px; border: 1px solid #ccc;">
                        <option value="">-- Select Model --</option>
                        <?php foreach ($registered_models as $registered_m): ?>
                            <option value="<?= htmlspecialchars($registered_m) ?>"><?= htmlspecialchars($registered_m) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select name="global_filter_template" class="shadow-box sans-font" style="padding: 6px; border-radius: 4px; border: 1px solid #ccc;">
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

<script>
    // ---- Key boxes: shared plumbing ----------------------------------------
    var progKeyModels = <?= json_encode($prog_key_models) ?>;
    var progKeyModelDefaults = <?= json_encode($prog_key_model_defaults) ?>;
    var progKeyTypeFields = <?= json_encode($prog_key_type_fields) ?>;
    var progKeyIds = <?= json_encode(array_keys($prog_key_names)) ?>;
    var progKeyMaxLines = 16;
    var progKeyPrevModel = null;
    var progKeyActiveGroup = 'hard';

    function progKeyGroupOf(id) {
        return (id <= 4) ? 'soft' : 'hard';
    }

    function keyField(name) {
        return document.querySelector('[name="' + name + '"]');
    }

    // ---- Extension fields: digits only (whole numbers) ----------------------
    function epmDigitsOnly(el) {
        var cleaned = el.value.replace(/[^0-9]/g, '');
        if (cleaned !== el.value) { el.value = cleaned; }
    }

    // ---- Line Key / Memory Key reordering & sorting --------------------------
    var EPM_KEY_FIELD_MAP = {
        linekey: { fields: ['type', 'value', 'label', 'pickup', 'line'], countField: 'select_linekey_count', minIdx: 2 },
        memkey:  { fields: ['type', 'value', 'label', 'pickup', 'line'], countField: 'select_memkey_count',  minIdx: 1 }
    };

    function epmKeyCount(keyType) {
        var map = EPM_KEY_FIELD_MAP[keyType];
        var el = map ? document.getElementById(map.countField) : null;
        return el ? (parseInt(el.value, 10) || 0) : 0;
    }

    function epmGetRowData(keyType, idx) {
        var fields = EPM_KEY_FIELD_MAP[keyType].fields;
        var data = {};
        fields.forEach(function (f) {
            var el = keyField(keyType + '_' + idx + '_' + f);
            data[f] = el ? el.value : '';
        });
        return data;
    }

    function epmSetRowData(keyType, idx, data) {
        var fields = EPM_KEY_FIELD_MAP[keyType].fields;
        fields.forEach(function (f) {
            var el = keyField(keyType + '_' + idx + '_' + f);
            if (el) { el.value = (data && data[f] !== undefined) ? data[f] : ''; }
        });
    }

    // Moves the key at fromIdx to toIdx, shifting the keys in between — the
    // slot numbers (and their underlying config names) never move, only the
    // data inside them does. Line Key 1 (the account/extension key) is fixed
    // and excluded automatically via minIdx.
    function reorderKeyRows(keyType, fromIdx, toIdx) {
        var map = EPM_KEY_FIELD_MAP[keyType];
        if (!map) { return; }
        var minIdx = map.minIdx;
        var count = epmKeyCount(keyType);
        if (count < minIdx) { return; }
        fromIdx = Math.max(minIdx, Math.min(count, fromIdx));
        toIdx = Math.max(minIdx, Math.min(count, toIdx));
        if (fromIdx === toIdx) { return; }

        var rows = [];
        for (var i = minIdx; i <= count; i++) { rows.push(epmGetRowData(keyType, i)); }

        var item = rows.splice(fromIdx - minIdx, 1)[0];
        rows.splice(toIdx - minIdx, 0, item);

        for (var j = 0; j < rows.length; j++) { epmSetRowData(keyType, minIdx + j, rows[j]); }
        refreshKeySummaries();
    }

    // field: 'value' (Extension, sorted numerically) or 'label' (sorted alphabetically).
    // mode: 'asc' or 'desc'. Blank entries always sort to the end either way.
    function sortKeyRows(keyType, field, mode) {
        var map = EPM_KEY_FIELD_MAP[keyType];
        if (!map) { return; }
        var minIdx = map.minIdx;
        var count = epmKeyCount(keyType);
        if (count < minIdx) { return; }

        var rows = [];
        for (var i = minIdx; i <= count; i++) { rows.push(epmGetRowData(keyType, i)); }

        var isNumeric = (field === 'value');
        rows.sort(function (a, b) {
            var av = (a[field] || '').trim();
            var bv = (b[field] || '').trim();
            if (av === '' && bv === '') { return 0; }
            if (av === '') { return 1; }
            if (bv === '') { return -1; }
            if (isNumeric) {
                var an = parseInt(av, 10);
                var bn = parseInt(bv, 10);
                if (isNaN(an)) { an = 0; }
                if (isNaN(bn)) { bn = 0; }
                return (mode === 'desc') ? (bn - an) : (an - bn);
            }
            var cmp = av.localeCompare(bv, undefined, { sensitivity: 'base', numeric: true });
            return (mode === 'desc') ? -cmp : cmp;
        });

        for (var j = 0; j < rows.length; j++) { epmSetRowData(keyType, minIdx + j, rows[j]); }
        refreshKeySummaries();
    }

    // ---- Column-header sort arrows (Extension / Label) -----------------------
    var epmKeySortState = {
        linekey: { field: null, dir: null },
        memkey:  { field: null, dir: null }
    };

    function toggleKeySort(keyType, field) {
        var state = epmKeySortState[keyType];
        if (!state) { return; }
        var dir = (state.field === field && state.dir === 'asc') ? 'desc' : 'asc';
        state.field = field;
        state.dir = dir;
        sortKeyRows(keyType, field, dir);
        updateKeySortIcons(keyType);
    }

    function updateKeySortIcons(keyType) {
        var state = epmKeySortState[keyType];
        if (!state) { return; }
        document.querySelectorAll('.gen-key-sortable[data-key-type="' + keyType + '"]').forEach(function (el) {
            var field = el.getAttribute('data-field');
            var icon = el.querySelector('.sort-caret');
            if (!icon) { return; }
            if (state.field === field) {
                icon.textContent = (state.dir === 'asc') ? '\u25b2' : '\u25bc';
            } else {
                icon.textContent = '\u21c5';
            }
        });
    }

    (function () {
        var dragKeyType = null;
        var dragFromIdx = null;

        function rowKeyIdx(row, keyType) {
            if (!row || !row.id) { return null; }
            var prefix = keyType + '_row_';
            if (row.id.indexOf(prefix) !== 0) { return null; }
            var n = parseInt(row.id.slice(prefix.length), 10);
            return isNaN(n) ? null : n;
        }

        document.addEventListener('dragstart', function (e) {
            var handle = e.target.closest && e.target.closest('.gen-key-draggable');
            if (!handle) { return; }
            dragKeyType = handle.getAttribute('data-key-type');
            dragFromIdx = parseInt(handle.getAttribute('data-idx'), 10);
            if (e.dataTransfer) {
                e.dataTransfer.effectAllowed = 'move';
                try { e.dataTransfer.setData('text/plain', String(dragFromIdx)); } catch (err) { /* ignore */ }
            }
            var row = handle.closest('.gen-key-row');
            if (row) { row.classList.add('gen-key-dragging'); }
        });

        document.addEventListener('dragend', function () {
            document.querySelectorAll('.gen-key-row.gen-key-dragging').forEach(function (r) { r.classList.remove('gen-key-dragging'); });
            document.querySelectorAll('.gen-key-row.gen-key-drop-target').forEach(function (r) { r.classList.remove('gen-key-drop-target'); });
            dragKeyType = null;
            dragFromIdx = null;
        });

        document.addEventListener('dragover', function (e) {
            if (!dragKeyType) { return; }
            var row = e.target.closest && e.target.closest('.gen-key-row');
            if (!row || rowKeyIdx(row, dragKeyType) === null) { return; }
            e.preventDefault();
            if (e.dataTransfer) { e.dataTransfer.dropEffect = 'move'; }
            document.querySelectorAll('.gen-key-row.gen-key-drop-target').forEach(function (r) { if (r !== row) { r.classList.remove('gen-key-drop-target'); } });
            row.classList.add('gen-key-drop-target');
        });

        document.addEventListener('drop', function (e) {
            if (!dragKeyType) { return; }
            var row = e.target.closest && e.target.closest('.gen-key-row');
            var toIdx = row ? rowKeyIdx(row, dragKeyType) : null;
            if (toIdx !== null) {
                e.preventDefault();
                if (dragFromIdx !== null && dragFromIdx !== toIdx) {
                    reorderKeyRows(dragKeyType, dragFromIdx, toIdx);
                }
            }
            document.querySelectorAll('.gen-key-row.gen-key-drop-target').forEach(function (r) { r.classList.remove('gen-key-drop-target'); });
        });
    })();

    document.addEventListener('keydown', function (e) {
        // Enter inside a key box must not submit the whole template form.
        if (e.key === 'Enter' && e.target && e.target.tagName === 'INPUT' && e.target.type === 'text' && e.target.closest && e.target.closest('.epm-box')) {
            e.preventDefault();
        }
    });

    ['input', 'change'].forEach(function (evt) {
        document.addEventListener(evt, function (e) {
            if (!e.target || !e.target.closest) { return; }
            var pkRow = e.target.closest('.gen-pk-row');
            if (pkRow) { pkRow.dataset.touched = '1'; }
            if (e.target.closest('#box_linekeys, #box_memkeys, #box_progkeys')) { refreshKeySummaries(); }
        });
    });

    // ---- Programmable keys ---------------------------------------------------
    function progKeyType(id) {
        var s = keyField('progkey_' + id + '_type');
        var n = s ? parseInt(s.value, 10) : 0;
        return isNaN(n) ? 0 : n;
    }

    function progKeyHas(type, field) {
        var a = progKeyTypeFields[type];
        return !!a && a.indexOf(field) > -1;
    }

    function progKeyDefs(model) {
        return progKeyModelDefaults[model] || progKeyModelDefaults['manual'];
    }

    function progKeyBuildLineOptions(sel, type) {
        var cur = sel.value || '1';
        var allowZero = (type === 2 || type === 13 || type === 14);
        sel.innerHTML = '';
        if (allowZero) {
            var z = document.createElement('option');
            z.value = '0';
            z.textContent = (type === 2) ? 'All lines (0)' : 'Auto (0)';
            sel.appendChild(z);
        }
        for (var l = 1; l <= progKeyMaxLines; l++) {
            var o = document.createElement('option');
            o.value = String(l);
            o.textContent = 'Line ' + l;
            sel.appendChild(o);
        }
        sel.value = cur;
        if (sel.selectedIndex < 0) { sel.value = '1'; }
    }

    // Swaps a field out for an "N/A" placeholder when the selected type doesn't
    // use it - same treatment for Value/Label as Line/History already get,
    // rather than just disabling the input (which is easy to miss visually).
    function progKeySwap(input, na, on) {
        if (!input || !na) { return; }
        input.style.display = on ? '' : 'none';
        na.style.display = on ? 'none' : '';
    }

    function progKeyRefreshRow(id) {
        var row = document.getElementById('progkey_row_' + id);
        if (!row) { return; }
        var t = progKeyType(id);
        var line = row.querySelector('.pk-line');
        var hist = row.querySelector('.pk-hist');
        var na = row.querySelector('.gen-pk-slot-linehist .pk-na');
        var showLine = progKeyHas(t, 'line');
        var showHist = progKeyHas(t, 'hist');
        line.style.display = showLine ? '' : 'none';
        hist.style.display = showHist ? '' : 'none';
        na.style.display = (showLine || showHist) ? 'none' : '';
        if (showLine) { progKeyBuildLineOptions(line, t); }
        progKeySwap(row.querySelector('.pk-value'), row.querySelector('.gen-pk-slot-value .pk-na'), progKeyHas(t, 'value'));
        progKeySwap(row.querySelector('.pk-label'), row.querySelector('.gen-pk-slot-label .pk-na'), id <= 4 && t !== 0);
        refreshKeySummaries();
    }

    function progKeyRefreshAll() {
        progKeyIds.forEach(progKeyRefreshRow);
    }

    // Same test the server uses to decide whether a key is written to the template.
    function progKeyIsCustom(id, defs) {
        var t = progKeyType(id);
        if (t !== defs[id]) { return true; }
        var row = document.getElementById('progkey_row_' + id);
        if (!row) { return false; }
        var val = function (sel) { var e = row.querySelector(sel); return e ? e.value.trim() : ''; };
        if (progKeyHas(t, 'value') && val('.pk-value') !== '') { return true; }
        if (id <= 4 && t !== 0 && val('.pk-label') !== '') { return true; }
        if (progKeyHas(t, 'line') && val('.pk-line') !== '1') { return true; }
        if (progKeyHas(t, 'hist') && val('.pk-hist') !== '0') { return true; }
        return false;
    }

    function progKeyOnModelChange(model, lines) {
        progKeyMaxLines = (model === 'manual' || !lines) ? 16 : lines;
        var firstCall = (progKeyPrevModel === null);
        var newDefs = progKeyDefs(model);
        var ids = progKeyModels[model] || progKeyModels['manual'];

        progKeyIds.forEach(function (id) {
            var row = document.getElementById('progkey_row_' + id);
            if (!row) { return; }
            if (firstCall) {
                // Whatever the server rendered that differs from the factory layout counts as edited.
                row.dataset.touched = progKeyIsCustom(id, newDefs) ? '1' : '';
            } else if (!row.dataset.touched) {
                // Keys nobody edited follow the new model's factory layout.
                var sel = keyField('progkey_' + id + '_type');
                if (sel) { sel.value = String(newDefs[id]); }
            }
        });
        progKeyPrevModel = model;
        applyProgKeyVisibility();
        progKeyRefreshAll();

        var modelSel = document.getElementById('select_phone_model');
        var label = (modelSel && modelSel.selectedIndex >= 0) ? modelSel.options[modelSel.selectedIndex].text : model;
        var note = document.getElementById('progkey_model_note');
        if (note) {
            note.textContent = (model === 'manual')
                ? 'Manual / Generic: every key is listed. Pick a phone model to show only the keys it has.'
                : ids.length + ' programmable keys on ' + label + '.';
        }
        refreshKeySummaries();
    }

    // Shows only the rows that belong to BOTH the selected phone model and the
    // active Hard Keys / Soft Keys tab.
    function applyProgKeyVisibility() {
        var modelSel = document.getElementById('select_phone_model');
        var model = modelSel ? modelSel.value : 'manual';
        var ids = progKeyModels[model] || progKeyModels['manual'];
        progKeyIds.forEach(function (id) {
            var row = document.getElementById('progkey_row_' + id);
            if (!row) { return; }
            var inModel = ids.indexOf(id) > -1;
            var inGroup = (progKeyGroupOf(id) === progKeyActiveGroup);
            row.style.display = (inModel && inGroup) ? '' : 'none';
        });
    }

    function setProgKeyTab(group) {
        progKeyActiveGroup = group;
        document.querySelectorAll('#box_progkeys .gen-key-page[data-pk-tab]').forEach(function (btn) {
            btn.classList.toggle('active', btn.getAttribute('data-pk-tab') === group);
        });
        applyProgKeyVisibility();
    }

    function progKeyResetDefaults() {
        var modelSel = document.getElementById('select_phone_model');
        var model = modelSel ? modelSel.value : 'manual';
        var defs = progKeyDefs(model);
        var ids = progKeyModels[model] || progKeyModels['manual'];
        ids.forEach(function (id) {
            var row = document.getElementById('progkey_row_' + id);
            if (!row) { return; }
            keyField('progkey_' + id + '_type').value = String(defs[id]);
            row.querySelector('.pk-line').value = '1';
            row.querySelector('.pk-hist').value = '0';
            row.querySelector('.pk-value').value = '';
            row.querySelector('.pk-label').value = '';
            row.dataset.touched = '';
        });
        progKeyRefreshAll();
    }



    function openEpmKeyModal(id) {
        var modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden','false');
        document.body.classList.add('epm-key-modal-open');
        var closeBtn = modal.querySelector('.epm-key-modal-x');
        if (closeBtn) closeBtn.focus();
    }
    function closeEpmKeyModal(id) {
        var modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden','true');
        if (!document.querySelector('.epm-key-modal.is-open')) {
            document.body.classList.remove('epm-key-modal-open');
        }
    }
    document.addEventListener('click', function(e) {
        if (e.target.classList && e.target.classList.contains('epm-key-modal')) {
            closeEpmKeyModal(e.target.id);
        }
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            var opened = document.querySelector('.epm-key-modal.is-open');
            if (opened) closeEpmKeyModal(opened.id);
        }
    });

    // ---- Dashboard boxes: two independent columns, drag anywhere, half/full width, collapse ----
    // Reusable across every dashboard grid on the page (Template Manager, Global Settings, ...);
    // each instance gets its own element id + localStorage key so their layouts don't collide.
    //
    // Layout model: a dash is a list of "bands" stacked top to bottom. A band is either a single
    // full-width box, or a pair of columns (each an independently-ordered, independent-height list
    // of box ids). Boxes are dragged straight into whichever column/position the cursor is over, so
    // a column never has to wait for the box beside it to "catch up".
    (function () {
        var instances = {};

        function createDash(dashId, storeKey) {
            var dash = document.getElementById(dashId);
            if (!dash) { return; }

            var dragged = null;

            function allBoxes() { return Array.prototype.slice.call(dash.querySelectorAll('.epm-box')); }
            function boxEl(id) { return dash.querySelector('.epm-box[data-box="' + id + '"]'); }

            // Bands implied by the box order + data-span already in the markup, used on first visit
            // and by "Reset layout". Non-full boxes alternate into column 1 / column 2 in doc order.
            function defaultBands() {
                var bands = [], pending = null, turn = 0;
                allBoxes().forEach(function (b) {
                    var id = b.dataset.box;
                    if (b.dataset.span === '2') {
                        if (pending) { bands.push(pending); pending = null; }
                        bands.push({ full: id });
                    } else {
                        if (!pending) { pending = { cols: [[], []] }; }
                        pending.cols[turn % 2].push(id);
                        turn++;
                    }
                });
                if (pending) { bands.push(pending); }
                return bands;
            }
            var initialDefaultBands = defaultBands();
            var initialFold = {};
            allBoxes().forEach(function (b) { initialFold[b.dataset.box] = false; });

            // Rebuild the DOM from a bands structure, moving (not cloning) the existing box elements.
            // Also fixes up each box's data-span to match how it's actually placed.
            function render(bands, fold) {
                var frag = document.createDocumentFragment();
                bands.forEach(function (band) {
                    if (band.full) {
                        var b = boxEl(band.full);
                        if (!b) { return; }
                        b.dataset.span = '2';
                        var wrap = document.createElement('div');
                        wrap.className = 'epm-band epm-band-full';
                        wrap.appendChild(b);
                        frag.appendChild(wrap);
                    } else {
                        var hasAny = (band.cols[0] && band.cols[0].length) || (band.cols[1] && band.cols[1].length);
                        if (!hasAny) { return; }
                        var wrap2 = document.createElement('div');
                        wrap2.className = 'epm-band';
                        [0, 1].forEach(function (ci) {
                            var col = document.createElement('div');
                            col.className = 'epm-col';
                            col.dataset.col = String(ci);
                            (band.cols[ci] || []).forEach(function (id) {
                                var b2 = boxEl(id);
                                if (!b2) { return; }
                                b2.dataset.span = '1';
                                col.appendChild(b2);
                            });
                            wrap2.appendChild(col);
                        });
                        frag.appendChild(wrap2);
                    }
                });
                dash.innerHTML = '';
                dash.appendChild(frag);
                allBoxes().forEach(function (b) {
                    b.classList.toggle('epm-folded', !!(fold && fold[b.dataset.box]));
                });
            }

            // Read the live DOM (source of truth while dragging) back into a bands structure.
            function readBands() {
                var bands = [];
                Array.prototype.slice.call(dash.children).forEach(function (bandEl) {
                    if (bandEl.classList.contains('epm-band-full')) {
                        var b = bandEl.querySelector('.epm-box');
                        if (b) { bands.push({ full: b.dataset.box }); }
                    } else {
                        var cols = [[], []];
                        Array.prototype.slice.call(bandEl.children).forEach(function (colEl, ci) {
                            Array.prototype.slice.call(colEl.children).forEach(function (b) { cols[ci].push(b.dataset.box); });
                        });
                        bands.push({ cols: cols });
                    }
                });
                return bands;
            }
            function readFold() {
                var fold = {};
                allBoxes().forEach(function (b) { fold[b.dataset.box] = b.classList.contains('epm-folded'); });
                return fold;
            }
            function persist() {
                try { localStorage.setItem(storeKey, JSON.stringify({ bands: readBands(), fold: readFold() })); } catch (e) {}
            }
            function readSaved() {
                try { return JSON.parse(localStorage.getItem(storeKey)) || null; } catch (e) { return null; }
            }
            function removeEmptyBands() {
                Array.prototype.slice.call(dash.children).forEach(function (bandEl) {
                    if (bandEl.classList.contains('epm-band-full')) { return; }
                    var total = 0;
                    Array.prototype.slice.call(bandEl.children).forEach(function (colEl) { total += colEl.children.length; });
                    if (total === 0) { bandEl.remove(); }
                });
            }

            var saved = readSaved();
            render(saved && saved.bands ? saved.bands : initialDefaultBands, saved ? saved.fold : initialFold);

            // ---- collapse / half-full toggle ----
            dash.addEventListener('click', function (e) {
                var t = e.target.closest ? e.target.closest('.epm-tool') : null;
                if (!t) { return; }
                var box = t.closest('.epm-box');
                var id = box.dataset.box;
                if (t.dataset.act === 'fold') {
                    box.classList.toggle('epm-folded');
                    persist();
                    return;
                }
                if (t.dataset.act === 'span') {
                    var bands = readBands();
                    var isFull = box.dataset.span === '2';
                    var next = [];
                    if (isFull) {
                        // Going full -> half: drop it into its own new band as a lone column-1 item.
                        bands.forEach(function (band) {
                            next.push(band.full === id ? { cols: [[id], []] } : band);
                        });
                    } else {
                        // Going half -> full: pull it out of its column, insert a full-width band there.
                        bands.forEach(function (band) {
                            if (band.full) { next.push(band); return; }
                            var c0 = band.cols[0].filter(function (x) { return x !== id; });
                            var c1 = band.cols[1].filter(function (x) { return x !== id; });
                            var removed = c0.length !== band.cols[0].length || c1.length !== band.cols[1].length;
                            if (removed) {
                                if (c0.length || c1.length) { next.push({ cols: [c0, c1] }); }
                                next.push({ full: id });
                            } else {
                                next.push(band);
                            }
                        });
                    }
                    render(next, readFold());
                    persist();
                }
            });

            // Only start a drag from the grip, so text fields and audio players keep working normally.
            dash.addEventListener('mousedown', function (e) {
                var g = e.target.closest ? e.target.closest('.epm-grip') : null;
                if (g) { g.closest('.epm-box').setAttribute('draggable', 'true'); }
            });
            document.addEventListener('mouseup', function () {
                allBoxes().forEach(function (b) { b.removeAttribute('draggable'); });
            });
            dash.addEventListener('dragstart', function (e) {
                var b = e.target;
                if (!b || !b.classList || !b.classList.contains('epm-box') || b.getAttribute('draggable') !== 'true') { return; }
                dragged = b;
                b.classList.add('epm-dragging');
                if (e.dataTransfer) { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', b.dataset.box); }
            });
            dash.addEventListener('dragover', function (e) {
                if (!dragged) { return; }
                e.preventDefault();

                if (dragged.dataset.span === '2') {
                    // Full-width boxes reorder by band, not by column.
                    var srcBand = dragged.closest('.epm-band');
                    var targetBand = e.target.closest ? e.target.closest('.epm-band') : null;
                    if (!targetBand || targetBand === srcBand) { return; }
                    var rb = targetBand.getBoundingClientRect();
                    var afterB = e.clientY > rb.top + rb.height / 2;
                    dash.insertBefore(srcBand, afterB ? targetBand.nextSibling : targetBand);
                    return;
                }

                var col = e.target.closest ? e.target.closest('.epm-col') : null;
                if (!col) { return; }
                dash.querySelectorAll('.epm-col-drop-target').forEach(function (c) { c.classList.remove('epm-col-drop-target'); });
                col.classList.add('epm-col-drop-target');

                var siblings = Array.prototype.slice.call(col.children).filter(function (k) { return k !== dragged; });
                var after = null;
                for (var i = 0; i < siblings.length; i++) {
                    var r = siblings[i].getBoundingClientRect();
                    if (e.clientY < r.top + r.height / 2) { after = siblings[i]; break; }
                }
                if (after) { col.insertBefore(dragged, after); } else { col.appendChild(dragged); }
            });
            dash.addEventListener('drop', function (e) { if (dragged) { e.preventDefault(); } });
            dash.addEventListener('dragend', function () {
                dash.querySelectorAll('.epm-col-drop-target').forEach(function (c) { c.classList.remove('epm-col-drop-target'); });
                if (!dragged) { return; }
                dragged.classList.remove('epm-dragging');
                dragged.removeAttribute('draggable');
                dragged = null;
                removeEmptyBands();
                persist();
            });

            instances[dashId] = {
                reset: function () {
                    try { localStorage.removeItem(storeKey); } catch (e) {}
                    render(initialDefaultBands, initialFold);
                }
            };
        }

        window.epmDashReset = function (dashId) {
            var inst = instances[dashId || 'epm_dash'];
            if (inst) { inst.reset(); }
        };

        function init() {
            createDash('epm_dash', 'epm_dash_layout_v5');
            createDash('epm_dash_global', 'epm_dash_global_layout_v2');
        }

        if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
    })();

    // ---- Summary lines on the template tab ----------------------------------
    function plural(n, word) {
        return n + ' ' + word + (n === 1 ? '' : 's');
    }

    function refreshKeySummaries() {
        var el, n, cfg, i;

        el = document.getElementById('summary_linekeys');
        var lc = document.getElementById('select_linekey_count');
        if (el && lc) {
            n = parseInt(lc.value, 10) || 0;
            cfg = 0;
            for (i = 1; i <= n; i++) {
                var lv = keyField('linekey_' + i + '_value');
                var ll = keyField('linekey_' + i + '_label');
                if ((lv && lv.value.trim() !== '') || (ll && ll.value.trim() !== '')) { cfg++; }
            }
            el.textContent = plural(n, 'slot') + ', ' + cfg + ' set';
        }

        el = document.getElementById('summary_memkeys');
        var mc = document.getElementById('select_memkey_count');
        if (el && mc) {
            n = parseInt(mc.value, 10) || 0;
            if (n === 0) {
                el.textContent = 'No slots on this model (add an expansion module)';
            } else {
                cfg = 0;
                for (i = 1; i <= n; i++) {
                    var mv = keyField('memkey_' + i + '_value');
                    var ml = keyField('memkey_' + i + '_label');
                    if ((mv && mv.value.trim() !== '') || (ml && ml.value.trim() !== '')) { cfg++; }
                }
                var mb = Math.min(memKeyBase(), n);
                el.textContent = plural(n, 'slot') + (mb > 0 ? ' (' + mb + ' built-in)' : '') + ', ' + cfg + ' set';
            }
        }

        el = document.getElementById('summary_progkeys');
        var modelSel = document.getElementById('select_phone_model');
        if (el && modelSel) {
            var model = modelSel.value;
            var ids = progKeyModels[model] || progKeyModels['manual'];
            var defs = progKeyDefs(model);
            var changed = 0;
            ids.forEach(function (id) { if (progKeyIsCustom(id, defs)) { changed++; } });
            el.textContent = plural(ids.length, 'key') + ', ' + changed + ' changed from default';
        }
    }
</script>
