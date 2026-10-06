#!/bin/bash
# setup-root.sh - ONE-TIME root setup for the Yealink Endpoint Manager module.
#
# Run once, as root:   bash setup-root.sh
#   (add --yealink-only to skip the ovpn_mgr step below)
#
# It installs a small root-owned helper (/usr/local/sbin/yealink_epm_ctl) and a sudoers rule
# that lets the web server user run only that helper's subcommands (version, status, diagnose, browse,
# setport <port>). After this, choosing a different "HTTP Shift Port" in Global Settings is
# applied by the page itself - no more SSH. The helper only ever edits one file,
# yealink_epm_prov.conf, in Apache's config folder.
#
# If the ovpn_mgr module is installed, this script then also runs ovpn_mgr's own
# scripts/setup-root.sh (unchanged, exactly as that module tells you to run it), so one command
# sets up both modules. Safe to re-run. Re-run it if the module page says the helper is out of date.

SRC_NAME="yealink_epm_ctl.sh"
DEST="/usr/local/sbin/yealink_epm_ctl"
SUDOERS="/etc/sudoers.d/yealink_epm"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd -P)"      # .../yealink_epm/scripts
MODULE_DIR="$(dirname "$SCRIPT_DIR")"                 # .../yealink_epm
case "$MODULE_DIR" in
    */admin/modules/*) WEBROOT="${MODULE_DIR%/admin/modules/*}" ;;
    *)                 WEBROOT="/var/www/html" ;;
esac

say() { echo "[yealink_epm setup] $*"; }
die() { echo "[yealink_epm setup] ERROR: $*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Please run this script as root."
[ -f "$SCRIPT_DIR/$SRC_NAME" ] || die "$SRC_NAME not found next to this script."
command -v visudo >/dev/null 2>&1 || die "visudo not found - install sudo first."

# Users that need the sudo rule = the user(s) PHP really runs as. The distro's envvars file can say
# one thing (www-data) while Apache/PHP-FPM actually runs as another (FreePBX: asterisk), so look at
# the running processes first; fall back to the configured user. 'asterisk' (FreePBX's own web
# user, and the one ovpn_mgr already uses) is always included when it exists.
WEB_USERS=""
add_user() {
    id "$1" >/dev/null 2>&1 || return 0
    case " $WEB_USERS " in *" $1 "*) return 0 ;; esac
    WEB_USERS="$WEB_USERS $1"
}
for u in $(ps -eo user:32=,comm= 2>/dev/null | awk '$2 ~ /^(apache2|httpd|php-fpm.*)$/ && $1 != "root" {print $1}' | sort -u); do
    add_user "$u"
done
if [ -z "$WEB_USERS" ]; then
    CFG_USER=""
    if [ -f /etc/apache2/envvars ]; then
        CFG_USER="$(bash -c '. /etc/apache2/envvars 2>/dev/null; echo "$APACHE_RUN_USER"')"
    elif [ -f /etc/httpd/conf/httpd.conf ]; then
        CFG_USER="$(awk '/^User[[:space:]]/ {print $2; exit}' /etc/httpd/conf/httpd.conf)"
    fi
    [ -n "$CFG_USER" ] && add_user "$CFG_USER"
fi
add_user asterisk
WEB_USERS="${WEB_USERS# }"
[ -n "$WEB_USERS" ] || die "could not work out which user the web server runs as."
say "Web server user(s): $WEB_USERS"

# 1. Helper: root-owned copy (the module's own copy is writable by the web user, so it is never run directly)
sed "s#__WEBROOT__#${WEBROOT}#" "$SCRIPT_DIR/$SRC_NAME" > "$DEST.tmp" || die "Could not write $DEST.tmp"
chown root:root "$DEST.tmp"; chmod 0755 "$DEST.tmp"
mv -f "$DEST.tmp" "$DEST" || die "Could not install $DEST"
say "Installed $DEST"

# 2. sudoers rule, validated before it goes live
TMP="$(mktemp)"
{
    echo "# Yealink Endpoint Manager: lets the web user apply the HTTP shift port. See setup-root.sh."
    for u in $WEB_USERS; do
        echo "${u} ALL=(root) NOPASSWD: ${DEST} version, ${DEST} status, ${DEST} diagnose, ${DEST} browse, ${DEST} setport [0-9]*"
    done
} > "$TMP"
if ! visudo -cf "$TMP" >/dev/null 2>&1; then
    rm -f "$TMP"
    die "The generated sudoers rule did not validate; nothing was installed."
fi
install -o root -g root -m 0440 "$TMP" "$SUDOERS" || { rm -f "$TMP"; die "Could not install $SUDOERS"; }
rm -f "$TMP"
say "Installed $SUDOERS"
grep -Rqs "includedir */etc/sudoers.d\|@includedir */etc/sudoers.d" /etc/sudoers || say "WARNING: /etc/sudoers does not appear to include /etc/sudoers.d - the rule may not be active."

# 3. Test exactly what the web page will do, as each web user
OK_USERS=""
for u in $WEB_USERS; do
    OUT="$(sudo -u "$u" sudo -n "$DEST" version 2>&1)"
    if [ "$OUT" = "5" ]; then
        say "Self-test OK: $u can run the helper through sudo."
        OK_USERS="$OK_USERS $u"
    else
        say "WARNING: self-test failed for $u (got: ${OUT:-nothing})."
    fi
done
[ -n "$OK_USERS" ] || die "Self-test failed for every web user. The page will not be able to apply port changes."
say "Yealink EPM is set up."

# ---------------------------------------------------------------------------
# ovpn_mgr: run its own one-time script too, if that module is installed
# ---------------------------------------------------------------------------
OVPN_SETUP="$WEBROOT/admin/modules/ovpn_mgr/scripts/setup-root.sh"
if [ "$1" = "--yealink-only" ]; then
    say "Skipping ovpn_mgr (--yealink-only)."
elif [ -f "$OVPN_SETUP" ]; then
    say "ovpn_mgr found - running its setup-root.sh as well ($OVPN_SETUP)"
    echo "------------------------------------------------------------"
    if bash "$OVPN_SETUP"; then
        echo "------------------------------------------------------------"
        say "ovpn_mgr setup finished."
    else
        echo "------------------------------------------------------------"
        say "WARNING: ovpn_mgr's setup-root.sh reported an error (see its output above). Yealink EPM itself is set up."
    fi
else
    say "ovpn_mgr's setup-root.sh not found at $OVPN_SETUP - nothing else to run."
fi

say "Done. Reload the Yealink EPM page; port changes are now applied from Global Settings."
