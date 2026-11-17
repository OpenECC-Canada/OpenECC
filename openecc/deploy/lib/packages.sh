# The Debian packages OpenECC needs. Shared by install.sh and appliance/setup_mode.sh (source this file).
# install.sh adds certbot (Let's Encrypt) and a time-sync service when needed.
# shellcheck disable=SC2034
OPENECC_PACKAGES=(apache2 mariadb-server mariadb-client php php-cli libapache2-mod-php php-mysql php-curl php-mbstring
                  php-xml php-zip php-gd php-intl php-tcpdf pdftk-java poppler-utils ghostscript rsync ca-certificates openssl)
