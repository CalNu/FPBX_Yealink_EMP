#!/bin/bash
# install_tftp.sh - installs and configures a TFTP server for the Yealink Endpoint Manager.
#
#   * creates /tftpboot (+ templates) and every module link that lives inside it, which the
#     module installer cannot do when it runs as the asterisk user
#   * installs the TFTP server package (Debian/Ubuntu: tftpd-hpa, RHEL/Rocky/CentOS: tftp-server)
#   * serves /tftpboot
#   * links /tftpboot into /var/www/html
#   * sets ownership/permissions
#   * opens UDP 69 in the firewall
#   * pre-authorizes the web server user to move the HTTP provisioning port (runs
#     setup-root.sh --yealink-only), so if HTTP is ever redirected to HTTPS no separate
#     setup step is needed
#
# Run as root:   bash install_tftp.sh        (safe to re-run)

TFTP_ROOT="/tftpboot"
OWNER="asterisk"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd -P)"      # .../yealink_epm/scripts
MODULE_DIR="$(dirname "$SCRIPT_DIR")"                 # .../yealink_epm
case "$MODULE_DIR" in
    */admin/modules/*) WEBROOT="${MODULE_DIR%/admin/modules/*}" ;;
    *)                 WEBROOT="/var/www/html" ;;
esac

say()  { echo "[yealink_epm tftp] $*"; }
warn() { echo "[yealink_epm tftp] WARNING: $*" >&2; }

if [ "$(id -u)" -ne 0 ]; then
    echo "Please run this script as root."
    exit 1
fi

# ---------------------------------------------------------------------------
# 0. Folders and module links (same set install.php creates; safe to repeat)
# ---------------------------------------------------------------------------
mkdir -p "$TFTP_ROOT/templates"
chown "$OWNER:$OWNER" "$TFTP_ROOT" "$TFTP_ROOT/templates" 2>/dev/null
chmod 775 "$TFTP_ROOT" "$TFTP_ROOT/templates"
say "Folder ready: $TFTP_ROOT (and $TFTP_ROOT/templates)"

# make_link <target> <link>: never replaces a real folder, leaves correct links alone
make_link() {
    local target="$1" link="$2"
    if [ -L "$link" ] && [ "$(readlink "$link")" = "$target" ]; then
        return 0
    fi
    if [ -d "$link" ] && [ ! -L "$link" ]; then
        warn "$link is a real folder - left alone."
        return 0
    fi
    rm -f "$link" 2>/dev/null
    if ln -s "$target" "$link"; then
        chown -h "$OWNER:$OWNER" "$link" 2>/dev/null
        say "Linked $link -> $target"
    else
        warn "Could not create $link -> $target"
    fi
}

if [ -d "$WEBROOT/PhoneSettings" ] && [ ! -L "$WEBROOT/PhoneSettings" ]; then
    make_link "$TFTP_ROOT"                "$WEBROOT/PhoneSettings/tftpboot"
    make_link "$MODULE_DIR"               "$WEBROOT/PhoneSettings/yealink_epm"
    make_link "$WEBROOT/PhoneSettings"    "$TFTP_ROOT/PhoneSettings"
    make_link "$WEBROOT/PhoneSettings"    "$MODULE_DIR/PhoneSettings"
    # /tftpboot gets the same LAN-only .htaccess as PhoneSettings
    if [ -f "$WEBROOT/PhoneSettings/.htaccess" ] && [ ! -f "$TFTP_ROOT/.htaccess" ]; then
        cp "$WEBROOT/PhoneSettings/.htaccess" "$TFTP_ROOT/.htaccess"
        chown "$OWNER:$OWNER" "$TFTP_ROOT/.htaccess" 2>/dev/null
        chmod 644 "$TFTP_ROOT/.htaccess"
    fi
else
    warn "$WEBROOT/PhoneSettings not found - reinstall the module (fwconsole ma install yealink_epm), then run this script again."
fi
make_link "$MODULE_DIR"  "$TFTP_ROOT/yealink_epm"
make_link "$TFTP_ROOT"   "$MODULE_DIR/tftpboot"
make_link "$TFTP_ROOT"   "$WEBROOT/tftpboot"
make_link "$TFTP_ROOT"   "$WEBROOT/tftp"

# ---------------------------------------------------------------------------
# 0b. Pre-authorize the HTTP shift port (root helper + narrow sudo rule). Done here, while we
#     are root anyway, so the module page can later set up the Apache listener by itself if
#     HTTP is redirected to HTTPS. Runs before the package install so a TFTP failure below
#     cannot skip it. Yealink only - ovpn_mgr's own setup is not touched here.
# ---------------------------------------------------------------------------
if [ -f "$SCRIPT_DIR/setup-root.sh" ]; then
    say "Pre-authorizing the HTTP shift port (setup-root.sh --yealink-only)..."
    if bash "$SCRIPT_DIR/setup-root.sh" --yealink-only; then
        say "HTTP shift port pre-authorized."
    else
        warn "Could not pre-authorize the HTTP shift port. Only needed if you redirect HTTP to HTTPS; the module page will show a command to run then."
    fi
else
    warn "setup-root.sh not found next to this script - skipping the HTTP shift port pre-authorization."
fi

# ---------------------------------------------------------------------------
# 1. Install the TFTP server
# ---------------------------------------------------------------------------
if command -v apt-get >/dev/null 2>&1; then
    PM="apt"
elif command -v dnf >/dev/null 2>&1; then
    PM="dnf"
elif command -v yum >/dev/null 2>&1; then
    PM="yum"
else
    warn "No supported package manager found (apt, dnf or yum). Install a TFTP server manually."
    exit 1
fi

mkdir -p "$TFTP_ROOT/templates"

if [ "$PM" = "apt" ]; then
    say "Installing tftpd-hpa..."
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y tftpd-hpa || { warn "tftpd-hpa install failed"; exit 1; }

    [ -f /etc/default/tftpd-hpa ] && cp -n /etc/default/tftpd-hpa /etc/default/tftpd-hpa.yealink_epm.bak
    cat > /etc/default/tftpd-hpa <<EOF
# Managed by yealink_epm (install_tftp.sh)
TFTP_USERNAME="$OWNER"
TFTP_DIRECTORY="$TFTP_ROOT"
TFTP_ADDRESS=":69"
TFTP_OPTIONS="--secure"
EOF
    systemctl enable tftpd-hpa >/dev/null 2>&1
    systemctl restart tftpd-hpa
else
    say "Installing tftp-server..."
    "$PM" install -y tftp-server || { warn "tftp-server install failed"; exit 1; }

    mkdir -p /etc/systemd/system/tftp.service.d
    cat > /etc/systemd/system/tftp.service.d/yealink_epm.conf <<EOF
# Managed by yealink_epm (install_tftp.sh)
[Service]
ExecStart=
ExecStart=/usr/sbin/in.tftpd -s $TFTP_ROOT -u $OWNER
EOF
    systemctl daemon-reload
    systemctl enable tftp.socket >/dev/null 2>&1
    systemctl restart tftp.socket
fi

# ---------------------------------------------------------------------------
# 2. Permissions (symlinks inside /tftpboot are not followed)
# ---------------------------------------------------------------------------
find "$TFTP_ROOT" -xdev ! -type l -exec chown "$OWNER:$OWNER" {} + 2>/dev/null
find "$TFTP_ROOT" -xdev -type d -exec chmod 775 {} + 2>/dev/null
find "$TFTP_ROOT" -xdev -type f -exec chmod 664 {} + 2>/dev/null
say "Set ownership ($OWNER) and permissions on $TFTP_ROOT"

if command -v getenforce >/dev/null 2>&1 && [ "$(getenforce)" = "Enforcing" ]; then
    say "SELinux is enforcing - allowing TFTP to serve $TFTP_ROOT"
    setsebool -P tftp_home_dir 1 2>/dev/null
    if command -v semanage >/dev/null 2>&1; then
        semanage fcontext -a -t tftpdir_rw_t "$TFTP_ROOT(/.*)?" 2>/dev/null
    fi
    restorecon -R "$TFTP_ROOT" 2>/dev/null
fi

# ---------------------------------------------------------------------------
# 3. Firewall: UDP 69
# ---------------------------------------------------------------------------
open_firewall() {
    if command -v firewall-cmd >/dev/null 2>&1 && systemctl is-active --quiet firewalld; then
        firewall-cmd --permanent --add-service=tftp >/dev/null && firewall-cmd --reload >/dev/null
        say "firewalld: allowed the tftp service"
        return
    fi

    if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
        ufw allow 69/udp >/dev/null
        say "ufw: allowed 69/udp"
        return
    fi

    if command -v iptables >/dev/null 2>&1; then
        if iptables -S 2>/dev/null | grep -q "fpbxfirewall"; then
            warn "The FreePBX Firewall module is active and manages iptables itself."
            warn "Allow the TFTP service for your phones' network in Connectivity > Firewall (UDP 69)."
            return
        fi

        modprobe nf_conntrack_tftp 2>/dev/null
        iptables -C INPUT -p udp --dport 69 -j ACCEPT 2>/dev/null || iptables -I INPUT -p udp --dport 69 -j ACCEPT
        # Let the connection tracker follow TFTP's data transfer from a different UDP port
        iptables -t raw -C PREROUTING -p udp --dport 69 -j CT --helper tftp 2>/dev/null \
            || iptables -t raw -A PREROUTING -p udp --dport 69 -j CT --helper tftp 2>/dev/null
        say "iptables: allowed 69/udp"

        if command -v netfilter-persistent >/dev/null 2>&1; then
            netfilter-persistent save >/dev/null 2>&1
        elif [ -f /etc/sysconfig/iptables ]; then
            iptables-save > /etc/sysconfig/iptables
        elif [ -f /etc/iptables/rules.v4 ]; then
            iptables-save > /etc/iptables/rules.v4
        else
            warn "Could not find a place to save the iptables rules - the UDP 69 rule may be lost on reboot."
            warn "If your PBX builds its firewall from a script, add UDP port 69 there as well."
        fi
        return
    fi

    warn "No firewall tool detected. If you run a firewall, allow UDP port 69 from your phones."
}
open_firewall

# ---------------------------------------------------------------------------
# 4. Check
# ---------------------------------------------------------------------------
sleep 1
if ss -lun 2>/dev/null | grep -q ':69 '; then
    say "TFTP is listening on UDP 69. Done - reload the Yealink Endpoint Manager page."
else
    warn "Nothing is listening on UDP 69 yet. Check:  systemctl status tftpd-hpa  (or tftp.socket)"
    exit 1
fi
