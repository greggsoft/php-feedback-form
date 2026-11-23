#!/bin/sh

set -eu

smtp_config=/tmp/msmtprc
port="${PORT:-8080}"

if [ -n "${SMTP_HOST:-}" ]; then
    : "${SMTP_USER:?Set SMTP_USER when SMTP_HOST is configured}"
    : "${SMTP_PASSWORD:?Set SMTP_PASSWORD when SMTP_HOST is configured}"
    : "${SENDER_EMAIL:?Set SENDER_EMAIL when SMTP_HOST is configured}"

    umask 077
    {
        printf '%s\n' 'defaults'
        printf '%s\n' 'auth on'
        printf '%s\n' 'tls on'
        printf '%s\n' 'tls_trust_file /etc/ssl/certs/ca-certificates.crt'
        printf '%s\n' 'account default'
        printf 'host %s\n' "$SMTP_HOST"
        printf 'port %s\n' "${SMTP_PORT:-587}"
        printf 'from %s\n' "$SENDER_EMAIL"
        printf 'user %s\n' "$SMTP_USER"
        printf 'password %s\n' "$SMTP_PASSWORD"

        case "${SMTP_TLS_MODE:-starttls}" in
            starttls)
                printf '%s\n' 'tls_starttls on'
                ;;
            tls)
                printf '%s\n' 'tls_starttls off'
                ;;
            *)
                printf '%s\n' 'SMTP_TLS_MODE must be starttls or tls.' >&2
                exit 1
                ;;
        esac
    } > "$smtp_config"
else
    printf '%s\n' 'SMTP is not configured; PHP mail() calls will fail.' >&2
fi

exec php \
    -d "sendmail_path=/usr/bin/msmtp -C $smtp_config -t -i" \
    -S "0.0.0.0:$port" \
    -t /app
