#!/usr/bin/env bash

set -Eeuo pipefail

mysql_exec() {
    MYSQL_PWD="${DB_PASSWORD}" mysql \
        --host="${DB_HOST}" \
        --port="${DB_PORT}" \
        --user="${DB_USERNAME}" \
        --database="${DB_DATABASE}" \
        --batch \
        --skip-column-names \
        --execute="$1"
}

record_failure() {
    local public_id="$1" started_at="$2" finished_at
    finished_at="$(date -u '+%Y-%m-%d %H:%M:%S')"
    mysql_exec "INSERT INTO backup_logs
        (public_id, status, error_message, started_at, finished_at, created_at, updated_at)
        VALUES ('${public_id}', 'failed', 'backup_command_failed', '${started_at}', '${finished_at}', UTC_TIMESTAMP(), UTC_TIMESTAMP());" || true
}

run_backup() {
    local timestamp backup_dir database_file files_file public_id started_at
    local manifest_hash size_bytes finished_at encrypted
    timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
    backup_dir="/backups/${timestamp}"
    database_file="${backup_dir}/database.sql.gz"
    files_file="${backup_dir}/private-files.tar.gz"
    public_id="$(printf '%s-%s-%s' "${timestamp}" "${RANDOM}" "${RANDOM}" | sha256sum | cut -c1-26)"
    started_at="$(date -u '+%Y-%m-%d %H:%M:%S')"
    trap 'record_failure "${public_id}" "${started_at}"' ERR

    mkdir -p "${backup_dir}"

    MYSQL_PWD="${DB_PASSWORD}" mysqldump \
        --host="${DB_HOST}" \
        --port="${DB_PORT}" \
        --user="${DB_USERNAME}" \
        --single-transaction \
        --routines \
        --triggers \
        --set-gtid-purged=OFF \
        "${DB_DATABASE}" | gzip -9 > "${database_file}"

    tar -C /private-files -czf "${files_file}" .
    encrypted=false
    if [[ -n "${BACKUP_ENCRYPTION_KEY_FILE:-}" && -r "${BACKUP_ENCRYPTION_KEY_FILE}" ]]; then
        openssl enc -aes-256-cbc -salt -pbkdf2 -iter 200000 \
            -in "${database_file}" -out "${database_file}.enc" -pass "file:${BACKUP_ENCRYPTION_KEY_FILE}"
        openssl enc -aes-256-cbc -salt -pbkdf2 -iter 200000 \
            -in "${files_file}" -out "${files_file}.enc" -pass "file:${BACKUP_ENCRYPTION_KEY_FILE}"
        rm -f "${database_file}" "${files_file}"
        database_file="${database_file}.enc"
        files_file="${files_file}.enc"
        encrypted=true
    elif [[ "${BACKUP_REQUIRE_ENCRYPTION:-false}" == "true" ]]; then
        echo "Backup encryption is required but no readable key file was configured." >&2
        return 1
    fi

    (
        cd "${backup_dir}"
        sha256sum "$(basename "${database_file}")" "$(basename "${files_file}")" > SHA256SUMS
    )
    manifest_hash="$(sha256sum "${backup_dir}/SHA256SUMS" | cut -d' ' -f1)"
    size_bytes="$(( $(stat -c %s "${database_file}") + $(stat -c %s "${files_file}") ))"
    find /backups -mindepth 1 -maxdepth 1 -type d -mtime "+${BACKUP_RETENTION_DAYS}" -depth -delete
    finished_at="$(date -u '+%Y-%m-%d %H:%M:%S')"
    mysql_exec "INSERT INTO backup_logs
        (public_id, status, database_path, files_path, sha256, size_bytes, started_at, finished_at, created_at, updated_at)
        VALUES (
            '${public_id}', 'completed', '${timestamp}/$(basename "${database_file}")',
            '${timestamp}/$(basename "${files_file}")', '${manifest_hash}', ${size_bytes},
            '${started_at}', '${finished_at}', UTC_TIMESTAMP(), UTC_TIMESTAMP()
        );"
    trap - ERR
    echo "Backup ${timestamp} completed (encrypted=${encrypted})."
}

if [[ "${BACKUP_RUN_ONCE:-false}" == "true" ]]; then
    run_backup
    exit 0
fi

while true; do
    run_backup
    sleep 86400
done
