# FPBX_Yealink_EMP
Freepbx Endpoint Manager for Yealink Phones

As this is not a Sangoma or a commercial module, ~~you will get an unsigned module warning telling you to remove it.  To disable the warning in SSH, run: "fwconsole setting SIGNATURECHECK 0".  This can also be done in the gui under Settings -> Advanced Settings -> "Enable Module Signature Checking" -> No (if you don't see it you need to set "Display Readonly Settings" and "Override Readonly Settings" to yes)~~ This is self signed now :) 

Currently only tested with legacy T28P phones with V.73 firmware. Should work with newer yealink phones, but untested.

This EPM will install a new module ~~under the settings menu~~ called Yealink Endpoint Manager. It has all the settings I normally fill in on configs.  
On the Global Settings tab, it should automatically fill in your server ip or FQDN and pull your time zone/set ntp to the pbx.  The dial now rules get pulled from your outbound routs, but only the one named "Outbound".  This will create a ~~y000000000000.cfg~~ Y-config for all the listed Yealink phones provisioned. Currently there is no separation of global settings between models, all y-configs are the same but named to match your model.  You can add custom global configs in the field labeled Global Custom Keys. 

On the template manager tab, it will automatically pull the SIP port from what's on the sip settings UDP listening port.  Clicking your phone model number should auto expand the  Line/Memory keys for that model phone.  Line key1  is greyed out because that is reserved for the Extension number.  The pickup field can be filled in but if it's left empty it fills ** on saving the template. Ringtones and Logo/Wallpaper will get saved in /var/www/html/PhoneSettings/(logo or ringtones)/.  This module will not resize or convert images ~~or audio to make them compatible with the phones~~. It will convert wav/mp3 ringtone audio and allow you to trim the size provided your pbx has ffmpeg installed.  You can add custom settings that aren't listed in the page in the field labeled Template Custom Key / Value Additions. 

The device manager tab has a scan tool that will scan your xxx.xxx.xxx.xxx/24 subnet (ip can be manually changed if searching another subnet, not working through vpn) for available Yealink MAC addresses and their IP's and you can add them and the template on that page. You can also manually add the MAC if you if a specific phone is not found or you're connecting through vpn. 

All MAC.cfg files, y0000000000XX.cfg, and templates are saved to the /tftpboot/ folder.  These can be browsed by going to https://PBX.IP/tftpboot and https://PBX.IP/PhoneSettings. If the pbx is set to forward http to https, the http port has been shifted to :83.

1.0.0.9 updates: 

•Added upload/download template file. Save location moved to /tftpboot/templates/

•Added ringtone conversion to convert mp3 and wav to 8khz, pcmu, mono to match yealink requirements. This requires ffmpeg installed. Most freepbx has it preinstalled. The module will install it for you if not, but this must be done at the command line "fwconsole ma install yealink_epm" or "fwconsole ma downloadinstall https://github.com/hgolbar/FPBX_Yealink_EMP/archive/refs/heads/1.0.0.9.zip"

•Added ability to trim audio files.

•Added download function for ringtones

•Added flush command when deleting ringtones in use may any mac.cfg files. This pushes a mac.cfg that deletes all ringtones and reinstalls checked ones.  Once the template is saved, it rebuilds the mac.cfg to remove the delete command so it doesn't keep deleting/reinstalling the ringtone on each reboot.

1.0.10 Updates:

• Added integration with my openvpn module https://github.com/CalNu/FPBX_OVPN_MGR

• Multiple bug fixes.

1.0.3c Update:

• Added local signature and ability to self sign module to get rid of the unsigned module nag.

• Security hardened some folders in case server is internet facing while allowing /tftpboot/ and /PhoneSettings/ to be browsable from within the intranet.

• Fixed some interopeabiliy between Freepbx 16 and 17

1.0.4 Update:

• Large code rewrite to create the VPN tar using my OpenVPN manager.

• Added y-configs for all the newer phone models.

• Adjusted line keys to match the selected model number.
