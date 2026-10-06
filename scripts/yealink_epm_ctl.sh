#!/bin/bash
# yealink_epm_ctl - root helper for the Yealink Endpoint Manager module.
#
# Not meant to be run by hand. setup-root.sh copies it to /usr/local/sbin/yealink_epm_ctl
# (root-owned, so the web user cannot edit it) and adds a sudoers rule that lets the web
# user run ONLY these subcommands:
#
#   version          prints the helper version
#   status           prints the port Apache is currently serving provisioning on (if any)
#   diagnose         read-only: lists Redirect / Rewrite rules found in Apache's config and the
#                    web-root .htaccess files (to find what is redirecting a port to https)
#   browse           makes Apache honour the .htaccess files in /tftpboot (LAN-only rule, folder
#                    login, hidden folders, listings) on listeners that use it as their document
#                    root, e.g. FreePBX Port Management's HTTP Provisioning port
#   setport <port>   makes Apache serve /PhoneSettings and /tftpboot over plain HTTP on <port>
#
# setport adds a Listen + a small virtual host that serves ONLY those two folders (their own
# LAN-only .htaccess files still apply), refuses a port another service already uses, tests
# the Apache config before reloading and rolls back if Apache rejects it, and opens the port
# in ufw / firewalld when one is active.

VERSION="5"
TFTP_ROOT="/tftpboot"
WEBROOT="__WEBROOT__"
[ -d "$WEBROOT/PhoneSettings" ] || WEBROOT="/var/www/html"

say()  { echo "[yealink_epm_ctl] $*"; }
warn() { echo "[yealink_epm_ctl] WARNING: $*"; }
die()  { echo "[yealink_epm_ctl] ERROR: $*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "must run as root (through sudo)."

if [ -d /etc/apache2/sites-available ]; then
    FLAVOR="debian"; CONF_NAME="yealink_epm_prov"; CONF="/etc/apache2/sites-available/${CONF_NAME}.conf"
    APACHE_TEST="apache2ctl"; APACHE_SVC="apache2"
elif [ -d /etc/httpd/conf.d ]; then
    FLAVOR="redhat"; CONF="/etc/httpd/conf.d/yealink_epm_prov.conf"
    APACHE_TEST="apachectl"; APACHE_SVC="httpd"
else
    die "could not find an Apache configuration folder (/etc/apache2 or /etc/httpd)."
fi
command -v "$APACHE_TEST" >/dev/null 2>&1 || APACHE_TEST="apachectl"

current_port() { [ -f "$CONF" ] && sed -n 's/^Listen \([0-9]\{1,5\}\)$/\1/p' "$CONF" | head -n1; }

# FreePBX Port Management's HTTP Provisioning site (listener on port 83 by default) uses /tftpboot
# as its document root and sets "AllowOverride None" for it, so Apache ignores the .htaccess the
# module writes there: no LAN-only rule, no folder login, no hidden folders, no DirectoryIndex
# override. This drop-in sets AllowOverride All for /tftpboot so those rules apply on that port
# exactly as they do on the others. It is installed as a *site* file (sites-enabled is read after
# Sysadmin's sangoma.conf, and the later of two <Directory> blocks for the same folder wins), and
# Sysadmin's own regenerated config is never edited. It only matches listeners whose document root
# is /tftpboot itself.
browse_on() {
    local DROP DROP_NAME="yealink_epm_browse" BK="" OLD="" OLDBK=""
    if [ "$FLAVOR" = "debian" ]; then
        DROP="/etc/apache2/sites-available/${DROP_NAME}.conf"
        OLD="/etc/apache2/conf-available/${DROP_NAME}.conf"      # 1.1.13/1.1.14 location (read too early)
    else
        DROP="/etc/httpd/conf.d/zz-${DROP_NAME}.conf"           # zz- so it is read after the other files
    fi
    [ -d "$TFTP_ROOT" ] || die "$TFTP_ROOT not found - run install_tftp.sh first."
    [ -f "$DROP" ] && { BK="$(mktemp)"; cp -p "$DROP" "$BK"; }
    if [ -n "$OLD" ] && [ -f "$OLD" ]; then
        OLDBK="$(mktemp)"; cp -p "$OLD" "$OLDBK"
        a2disconf "$DROP_NAME" >/dev/null 2>&1
        rm -f "$OLD"
    fi
    cat > "$DROP" <<EOF2
# Written by the Yealink EPM root helper - honour the module's .htaccess files for ${TFTP_ROOT}.
# FreePBX Port Management's HTTP Provisioning site sets AllowOverride None for this folder; this
# (read later) block turns it back on so the LAN-only rule, folder login and hidden folders apply.
# To remove: a2dissite ${DROP_NAME}; rm ${DROP}; reload Apache.
<Directory ${TFTP_ROOT}/>
    AllowOverride All
</Directory>
EOF2
    chmod 644 "$DROP"
    [ "$FLAVOR" = "debian" ] && a2ensite "$DROP_NAME" >/dev/null 2>&1
    local T
    T="$("$APACHE_TEST" configtest 2>&1)"
    if ! echo "$T" | grep -qi "Syntax OK"; then
        if [ -n "$BK" ]; then cp -p "$BK" "$DROP"; rm -f "$BK"
        else
            [ "$FLAVOR" = "debian" ] && a2dissite "$DROP_NAME" >/dev/null 2>&1
            rm -f "$DROP"
        fi
        if [ -n "$OLDBK" ]; then cp -p "$OLDBK" "$OLD"; a2enconf "$DROP_NAME" >/dev/null 2>&1; rm -f "$OLDBK"; fi
        echo "$T" | grep -v "secure firewall" | head -n 6 >&2
        die "Apache rejected the new configuration; nothing was changed."
    fi
    rm -f "$BK" "$OLDBK"
    if command -v systemctl >/dev/null 2>&1; then
        systemctl reload "$APACHE_SVC" >/dev/null 2>&1 || die "configuration saved, but Apache could not be reloaded - run: systemctl restart $APACHE_SVC"
    else
        service "$APACHE_SVC" reload >/dev/null 2>&1 || die "configuration saved, but Apache could not be reloaded - run: service $APACHE_SVC restart"
    fi
    say "Apache now honours the .htaccess files in $TFTP_ROOT"
}

case "$1" in
    version)
        echo "$VERSION"
        exit 0
        ;;
    status)
        current_port
        exit 0
        ;;
    diagnose)
        diagnose
        exit 0
        ;;
    browse)
        browse_on
        exit 0
        ;;
    setport)
        [ $# -eq 2 ] || die "usage: setport <port>"
        PORT="$2"
        ;;
    *)
        die "unknown command '$1'"
        ;;
esac

case "$PORT" in ''|*[!0-9]*) die "'$PORT' is not a valid port." ;; esac
[ "${#PORT}" -le 5 ] && [ "$PORT" -ge 1 ] && [ "$PORT" -le 65535 ] || die "port must be 1-65535."
[ "$PORT" -ne 80 ] && [ "$PORT" -ne 443 ] || die "port $PORT is a normal web port - choose a different one."

[ -d "$WEBROOT/PhoneSettings" ] || die "$WEBROOT/PhoneSettings not found - reinstall the module first."
[ -d "$TFTP_ROOT" ]             || die "$TFTP_ROOT not found - run install_tftp.sh first."

# Is something other than our own listener already on that port?
OLD="$(current_port)"
if ss -ltn 2>/dev/null | awk '{print $4}' | grep -Eq "[:.]${PORT}\$"; then
    [ "$OLD" = "$PORT" ] || die "port $PORT is already in use by another service."
fi

# Error-log path: Debian defines APACHE_LOG_DIR in envvars; use a plain path if it doesn't.
if [ "$FLAVOR" = "debian" ]; then
    if grep -q 'APACHE_LOG_DIR' /etc/apache2/envvars 2>/dev/null; then
        ERRLOG='${APACHE_LOG_DIR}/yealink_epm_prov_error.log'
    else
        ERRLOG='/var/log/apache2/yealink_epm_prov_error.log'
    fi
else
    ERRLOG='logs/yealink_epm_prov_error.log'
fi

BACKUP=""
[ -f "$CONF" ] && { BACKUP="$(mktemp)"; cp -p "$CONF" "$BACKUP"; }

# The listener mirrors FreePBX's own HTTP Provisioning site: the document root is the TFTP
# folder, so a phone asking for /<mac>.cfg (the module's provisioning URL has no folder in it)
# is answered, and /PhoneSettings and /tftpboot are aliased in so both can be browsed.
# .htaccess files stay active (AllowOverride All) so the LAN-only rule and the folder login
# keep working here.
cat > "$CONF" <<EOF
# Written by the Yealink EPM root helper - plain-HTTP provisioning listener.
# Change the port from the module's Global Settings page; delete this file to remove it.
Listen ${PORT}
<VirtualHost *:${PORT}>
    ServerName yealink-epm-provisioning
    DocumentRoot ${TFTP_ROOT}
    ErrorLog ${ERRLOG}

    # Explicit mappings win over any server-wide Redirect that Apache would otherwise inherit.
    Alias /PhoneSettings ${WEBROOT}/PhoneSettings
    Alias /tftpboot      ${TFTP_ROOT}

    <Directory ${TFTP_ROOT}>
        Options +FollowSymLinks +Indexes
        DirectoryIndex disabled
        IndexIgnore .??* *.php
        AllowOverride All
        Require all granted
    </Directory>
    <Directory ${WEBROOT}/PhoneSettings>
        Options +FollowSymLinks +Indexes
        DirectoryIndex disabled
        IndexIgnore .??* *.php
        AllowOverride All
        Require all granted
    </Directory>

    # Never serve dot-files or PHP from here
    <FilesMatch "(^\\.|\\.php\$)">
        Require all denied
    </FilesMatch>
</VirtualHost>
EOF
chmod 644 "$CONF"
[ "$FLAVOR" = "debian" ] && a2ensite "$CONF_NAME" >/dev/null 2>&1

TESTOUT="$("$APACHE_TEST" configtest 2>&1)"
if ! echo "$TESTOUT" | grep -qi "Syntax OK"; then
    if [ -n "$BACKUP" ]; then cp -p "$BACKUP" "$CONF"; rm -f "$BACKUP"
    else
        [ "$FLAVOR" = "debian" ] && a2dissite "$CONF_NAME" >/dev/null 2>&1
        rm -f "$CONF"
    fi
    echo "$TESTOUT" | grep -v "secure firewall" | head -n 6 >&2
    die "Apache rejected the new configuration; nothing was changed."
fi
[ -n "$BACKUP" ] && rm -f "$BACKUP"

# Graceful reload only (never a restart: this can be running inside a web request served by Apache).
if command -v systemctl >/dev/null 2>&1; then
    systemctl reload "$APACHE_SVC" >/dev/null 2>&1 || die "configuration saved, but Apache could not be reloaded - run: systemctl restart $APACHE_SVC"
else
    service "$APACHE_SVC" reload >/dev/null 2>&1 || die "configuration saved, but Apache could not be reloaded - run: service $APACHE_SVC restart"
fi
say "Apache is listening on port $PORT"

FW_DONE=0
if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
    ufw allow "${PORT}/tcp" >/dev/null 2>&1 && { say "ufw: allowed ${PORT}/tcp"; FW_DONE=1; }
fi
if command -v firewall-cmd >/dev/null 2>&1 && firewall-cmd --state >/dev/null 2>&1; then
    firewall-cmd --permanent --add-port="${PORT}/tcp" >/dev/null 2>&1 && firewall-cmd --reload >/dev/null 2>&1 \
        && { say "firewalld: allowed ${PORT}/tcp"; FW_DONE=1; }
fi
if [ "$FW_DONE" -eq 0 ]; then
    if iptables -S 2>/dev/null | grep -q "fpbxfirewall"; then
        warn "the FreePBX Firewall module manages iptables, so no rule was added - allow TCP ${PORT} for your phones' network in Connectivity > Firewall."
    else
        warn "no active ufw/firewalld found, so no firewall rule was added - if this server uses iptables, allow TCP ${PORT} from your phone networks."
    fi
fi

if command -v curl >/dev/null 2>&1; then
    CODE=""
    for i in 1 2 3 4 5; do
        CODE="$(curl -s -o /dev/null -m 3 -w '%{http_code}' "http://127.0.0.1:${PORT}/PhoneSettings/" 2>/dev/null)"
        case "$CODE" in 200|403) break ;; esac
        sleep 1
    done
    case "$CODE" in
        200|403) say "self-test OK (HTTP $CODE)" ;;
        301|302|303|307|308)
            LOC="$(curl -s -o /dev/null -m 3 -w '%{redirect_url}' "http://127.0.0.1:${PORT}/PhoneSettings/" 2>/dev/null)"
            warn "Apache is listening on ${PORT}, but another rule still redirects it to ${LOC:-https} - use 'Find the redirect rule' on the module page."
            ;;
        *)       warn "self-test: http://127.0.0.1:${PORT}/PhoneSettings/ answered '${CODE:-no response}' - check the Apache error log." ;;
    esac
fi
exit 0
