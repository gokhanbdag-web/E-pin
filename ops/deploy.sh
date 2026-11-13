#!/usr/bin/env bash
set -Eeuo pipefail

STAGING="/opt/epin-staging"
LIVE="/home/epinmania.com/public_html"
BASE="/opt/epin-deploy"
PHP="/usr/local/lsws/lsphp85/bin/php"
OWNER="epinm6771"
GROUP="nobody"
REPO="https://github.com/gokhanbdag-web/E-pin.git"
LOG="$BASE/deploy.log"
LOCK="$BASE/deploy.lock"

mkdir -p "$BASE"
exec >>"$LOG" 2>&1
echo
echo "=== DEPLOY $(date -Is) ==="

exec 9>"$LOCK"
if ! flock -n 9; then
    echo "ATLANDI: Başka bir dağıtım zaten çalışıyor."
    exit 0
fi

fail() {
    local code=$?
    echo "HATA: Dağıtım başarısız (exit=$code), zaman=$(date -Is)"
    exit "$code"
}
trap fail ERR

for command in git rsync flock curl; do
    command -v "$command" >/dev/null 2>&1 || {
        echo "Eksik komut: $command"
        exit 1
    }
done
[[ -x "$PHP" ]] || { echo "PHP CLI bulunamadı: $PHP"; exit 1; }
[[ -d "$LIVE" ]] || { echo "Canlı dizin bulunamadı: $LIVE"; exit 1; }

if [[ ! -d "$STAGING/.git" ]]; then
    rm -rf "$STAGING"
    git clone --branch main --single-branch "$REPO" "$STAGING"
fi

git -C "$STAGING" remote set-url origin "$REPO"
git -C "$STAGING" fetch --prune origin main
git -C "$STAGING" checkout -B main origin/main
git -C "$STAGING" reset --hard origin/main

COMMIT="$(git -C "$STAGING" rev-parse --short HEAD)"
echo "KAYNAK: main @ $COMMIT"

echo "KONTROL: Staging PHP dosyaları denetleniyor."
while IFS= read -r -d '' file; do
    "$PHP" -l "$file" >/dev/null
done < <(find "$STAGING" -type f -name '*.php' -not -path "$STAGING/.git/*" -print0)
echo "BAŞARILI: Staging PHP sözdizimi kontrolü."

echo "DAĞITIM: Dosyalar canlı dizine aktarılıyor."
rsync -rlptDz --delete \
    --exclude='/config.php' \
    --include='/storage/.htaccess' \
    --exclude='/storage/*' \
    --include='/uploads/.htaccess' \
    --exclude='/uploads/*' \
    --exclude='/deploy-hook.php' \
    --exclude='/.git/' \
    --exclude='/.github/' \
    --exclude='/tests/' \
    --exclude='/ops/' \
    --exclude='/README.md' \
    --exclude='/.gitignore' \
    "$STAGING/" "$LIVE/"

echo "KONTROL: Canlı PHP dosyaları denetleniyor."
"$PHP" -l "$LIVE/index.php"
"$PHP" -l "$LIVE/deploy-hook.php"
find "$LIVE" -type d -not -path "$LIVE/storage*" -not -path "$LIVE/uploads*" -exec chmod 755 {} +
find "$LIVE" -type f -not -path "$LIVE/storage/*" -not -path "$LIVE/uploads/*" -exec chmod 644 {} +
for persistent in "$LIVE/storage" "$LIVE/uploads"; do
    if [[ -d "$persistent" ]]; then
        find "$persistent" -type d -exec chmod 750 {} +
        find "$persistent" -type f -exec chmod 640 {} +
    fi
done
chmod 640 "$LIVE/deploy-hook.php"

if [[ "$(id -u)" -eq 0 ]]; then
    chown -R "$OWNER:$GROUP" "$LIVE"
    chown "$OWNER:$GROUP" "$BASE" "$LOG" "$LOCK" 2>/dev/null || true
fi

echo "SAĞLIK: https://epinmania.com/ HTTP kontrolü yapılıyor."
HTTP_CODE="$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' --max-time 30 https://epinmania.com/)"
case "$HTTP_CODE" in
    200|301|302) ;;
    *) echo "HTTP sağlık kontrolü başarısız: HTTP $HTTP_CODE"; exit 1 ;;
esac

echo "BAŞARILI: $COMMIT | $(date -Is) | Dağıtım tamamlandı | HTTP sağlık kontrolü: HTTP $HTTP_CODE"
