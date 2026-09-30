#!/bin/bash
# inni NAS 자동 업데이트: GitHub 에 새 커밋이 있으면 백업 → 가져오기 → 다시 빌드하고,
# 새 버전이 제대로 뜨지 않으면 이전 버전으로 되돌린다. (aiapi-manager 와 같은 방식)
#
# DSM 제어판 → 작업 스케줄러 → 생성 → 예약된 작업 → 사용자 정의 스크립트
#   사용자: root · 일정: 매일 10분마다
#   명령: bash /volume1/docker/inni/scripts/nas-auto-update.sh
#
# 선택 환경 변수 (명령 앞에 붙인다. 예: INNI_UPDATE_HOURS="0-7,17-23" bash .../nas-auto-update.sh)
#   INNI_UPDATE_BRANCH          가져올 브랜치 (기본 main)
#   INNI_UPDATE_HOURS           새 버전을 적용할 서울 시각. "0-7,17-23" 이면 수업 중에는 확인만 하고 적용을 미룬다
#   INNI_UPDATE_DRY_RUN=1       적용하지 않고 무엇을 할지만 남긴다
#   INNI_UPDATE_HEALTH_TIMEOUT  새 버전이 건강해지기를 기다릴 초 (기본 240)
#   INNI_UPDATE_SKIP_BACKUP=1   업데이트 전 백업을 건너뛴다(권하지 않음)
#
# 결과는 data/auto-update.json(inni 시스템 → 정보 → 자동 업데이트)과 data/auto-update.log 에 남는다.
# 제대로 뜨지 않아 되돌린 커밋은 data/auto-update.failed 에 적어 두고 다시 시도하지 않는다(10분마다 서비스가
# 흔들리지 않게). 고친 새 커밋이 올라오면 다시 시도한다. 같은 커밋을 다시 해 보려면 그 파일을 지운다.
set -u
export PATH="/usr/local/bin:/usr/local/sbin:/usr/bin:/usr/sbin:/bin:/sbin:${PATH:-}"

DIR="$(cd "$(dirname "$0")/.." && pwd)"
BRANCH="${INNI_UPDATE_BRANCH:-main}"
HOURS="${INNI_UPDATE_HOURS:-}"
DRY_RUN="${INNI_UPDATE_DRY_RUN:-}"
HEALTH_TIMEOUT="${INNI_UPDATE_HEALTH_TIMEOUT:-240}"
POLL="${INNI_UPDATE_POLL:-5}"
SKIP_BACKUP="${INNI_UPDATE_SKIP_BACKUP:-}"
SERVICE="${INNI_UPDATE_SERVICE:-inni}"
DOCKER="${DOCKER:-docker}"
STATE_DIR="$DIR/data"
STATUS="$STATE_DIR/auto-update.json"
LOG="$STATE_DIR/auto-update.log"
FAILED="$STATE_DIR/auto-update.failed"
LOCK="${INNI_UPDATE_LOCK:-/tmp/inni-auto-update.lock}"
BACKUP_NAME=""

mkdir -p "$STATE_DIR"

# 서울 시각. 시간대 자료가 없는 NAS 에서도 되도록 POSIX 표기(KST-9)를 쓴다.
now() { TZ=KST-9 date '+%Y-%m-%dT%H:%M:%S+09:00'; }
log() { echo "$(now) $*" >> "$LOG"; }
# root 로 돌 때 저장소 주인이 달라도 git 이 멈추지 않게 한다.
g() { git -c safe.directory="$DIR" -C "$DIR" "$@"; }
# JSON 문자열: 역슬래시·따옴표를 막고 줄바꿈은 빈칸으로
jstr() { printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' | tr '\n\r\t' '   '; }

write_status() { # 상태 메시지 [적용 시각]
  local status="$1" msg="$2" applied_at="${3:-}" head="" subject=""
  if [ -z "$applied_at" ] && [ -f "$STATUS" ]; then
    applied_at="$(sed -n 's/.*"applied_at":"\([^"]*\)".*/\1/p' "$STATUS" | head -n 1)"
  fi
  head="$(g rev-parse --short HEAD 2>/dev/null || true)"
  subject="$(g log -1 --format=%s 2>/dev/null || true)"
  printf '{"checked_at":"%s","status":"%s","message":"%s","branch":"%s","commit":"%s","subject":"%s","applied_at":"%s","hours":"%s","backup":"%s"}\n' \
    "$(now)" "$status" "$(jstr "$msg")" "$(jstr "$BRANCH")" "$head" "$(jstr "$subject")" "$applied_at" "$(jstr "$HOURS")" "$(jstr "$BACKUP_NAME")" > "$STATUS.tmp" \
    && mv "$STATUS.tmp" "$STATUS"
  [ "$status" = "ok" ] || log "[$status] $msg"
}

fail() { write_status error "$1"; exit 1; }

# 새 버전을 적용해도 되는 시각인지. "0-7,17-23" · "22-6"(자정 넘김) · "12"(한 시간)
in_hours() {
  [ -z "$HOURS" ] && return 0
  local h range a b
  h=$((10#$(TZ=KST-9 date +%H)))
  for range in ${HOURS//,/ }; do
    a="${range%-*}"
    b="${range#*-}"
    [[ "$a" =~ ^[0-9]+$ && "$b" =~ ^[0-9]+$ ]] || continue
    a=$((10#$a))
    b=$((10#$b))
    if [ "$a" -le "$b" ]; then
      [ "$h" -ge "$a" ] && [ "$h" -le "$b" ] && return 0
    else
      { [ "$h" -ge "$a" ] || [ "$h" -le "$b" ]; } && return 0
    fi
  done
  return 1
}

compose() {
  if $DOCKER compose version >/dev/null 2>&1; then
    (cd "$DIR" && $DOCKER compose "$@")
  elif command -v docker-compose >/dev/null 2>&1; then
    (cd "$DIR" && docker-compose "$@")
  else
    return 127
  fi
}

container_id() { compose ps -q "$SERVICE" 2>/dev/null | head -n 1; }

wait_healthy() {
  local end state id
  end=$(( $(date +%s) + HEALTH_TIMEOUT ))
  while :; do
    id="$(container_id)"
    if [ -n "$id" ]; then
      state="$($DOCKER inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$id" 2>/dev/null || echo missing)"
      case "$state" in healthy | running) return 0 ;; esac
    fi
    [ "$(date +%s)" -ge "$end" ] && return 1
    sleep "$POLL"
  done
}

# 새 버전을 받기 전에 지금 DB·사진을 zip 으로 남긴다(data/backups/…-before-update.zip, 최근 5개).
# 돌고 있는 컨테이너 안의 지금 버전으로 만든다. 꺼져 있거나 이 도구가 없는 예전 버전이면 건너뛴다.
backup_before_update() {
  if [ -n "$SKIP_BACKUP" ]; then log "업데이트 전 백업 건너뜀(INNI_UPDATE_SKIP_BACKUP)"; return 0; fi
  local id
  id="$(container_id)"
  if [ -z "$id" ] || [ "$($DOCKER inspect -f '{{.State.Running}}' "$id" 2>/dev/null)" != "true" ]; then
    log "컨테이너가 꺼져 있어 업데이트 전 백업 없이 진행합니다"
    return 0
  fi
  if ! compose exec -T "$SERVICE" test -f server/cli.js >/dev/null 2>&1; then
    log "지금 버전에는 백업 도구(server/cli.js)가 없어 업데이트 전 백업을 건너뜁니다"
    return 0
  fi
  BACKUP_NAME="$(compose exec -T "$SERVICE" node server/cli.js backup --reason before-update 2>> "$LOG" | tail -n 1 | tr -d '\r')"
  [ -n "$BACKUP_NAME" ] || return 1
  log "업데이트 전 백업: $BACKUP_NAME"
}

# 저장소 파일 주인을 처음 받은 계정으로 되돌린다(root 로 돌아도 나중에 손으로 git 을 쓸 수 있게). data 폴더는 건드리지 않는다.
restore_owner() {
  [ "$(id -u)" = 0 ] || return 0
  local owner
  owner="$(stat -c '%u:%g' "$DIR" 2>/dev/null)" || return 0
  [ "$owner" = "0:0" ] && return 0
  chown -R "$owner" "$DIR/.git" 2>/dev/null
  (cd "$DIR" && g ls-files -z | xargs -0 chown "$owner" 2>/dev/null)
  return 0
}

build_up() { # 지금 HEAD 로 이미지를 다시 만들어 띄운다(커밋은 시스템 → 정보에 보인다)
  export INNI_COMMIT
  INNI_COMMIT="$(g rev-parse --short HEAD)"
  compose up -d --build >> "$LOG" 2>&1
}

# ---------------------------------------------------------------- 시작
# 겹쳐 돌지 않게 잠근다. 한 시간 넘은 잠금은 죽은 것으로 본다.
if ! mkdir "$LOCK" 2>/dev/null; then
  if [ -n "$(find "$LOCK" -maxdepth 0 -mmin +60 2>/dev/null)" ]; then
    rm -rf "$LOCK"
    mkdir "$LOCK" 2>/dev/null || exit 0
  else
    exit 0
  fi
fi
trap 'rm -rf "$LOCK"' EXIT

# 기록 파일이 커지면 끝부분만 남긴다.
if [ -f "$LOG" ] && [ "$(wc -c < "$LOG")" -gt 524288 ]; then
  tail -n 400 "$LOG" > "$LOG.tmp" && mv "$LOG.tmp" "$LOG"
fi

command -v git >/dev/null 2>&1 || fail "git 이 없습니다. 패키지 센터에서 Git Server 를 설치하세요"
[ -d "$DIR/.git" ] || fail "git 저장소가 아닙니다. docs/nas-deploy.md 7장의 준비 단계를 따라 주세요"

if ! GIT_TERMINAL_PROMPT=0 g fetch --quiet origin "$BRANCH" 2>> "$LOG"; then
  fail "GitHub 에서 가져오지 못했습니다. 인터넷 연결(비공개 저장소면 배포 키)을 확인하세요"
fi
LOCAL="$(g rev-parse HEAD)"
REMOTE="$(g rev-parse "origin/$BRANCH")" || fail "origin/$BRANCH 를 찾을 수 없습니다"

if [ "$LOCAL" = "$REMOTE" ]; then
  write_status ok "최신 상태입니다"
  exit 0
fi
if ! g merge-base --is-ancestor "$LOCAL" "$REMOTE"; then
  fail "NAS 쪽 기록이 GitHub 과 갈라졌습니다. 손으로 확인해 주세요(git status)"
fi
DIRTY="$(g status --porcelain --untracked-files=no)"
if [ -n "$DIRTY" ]; then
  fail "NAS 에서 고친 파일이 있어 멈췄습니다: $(echo "$DIRTY" | head -n 3 | tr '\n' ' ')— 포트 등은 .env 로 옮기세요"
fi
NEW_SUBJECT="$(g log -1 --format=%s "$REMOTE")"
COUNT="$(g rev-list --count "$LOCAL..$REMOTE")"
if [ -f "$FAILED" ] && [ "$(cat "$FAILED")" = "$REMOTE" ]; then
  write_status held "새 버전($(g rev-parse --short "$REMOTE"))은 지난번에 제대로 뜨지 않아 보류 중입니다. 고친 버전이 올라오면 다시 시도합니다(지금 다시 해 보려면 data/auto-update.failed 삭제)"
  exit 0
fi
if ! in_hours; then
  write_status waiting "새 버전 ${COUNT}개가 있지만 적용 시각($HOURS)이 아니라 기다립니다: $NEW_SUBJECT"
  exit 0
fi
if [ -n "$DRY_RUN" ]; then
  write_status dry_run "적용할 새 버전 ${COUNT}개: $NEW_SUBJECT"
  exit 0
fi

compose version >/dev/null 2>&1 || fail "docker compose 를 찾을 수 없습니다"
backup_before_update || fail "업데이트 전 백업을 만들지 못해 멈췄습니다(auto-update.log 확인). 급하면 INNI_UPDATE_SKIP_BACKUP=1"
log "새 버전 ${COUNT}개 적용 시작 ($(g rev-parse --short "$LOCAL") → $(g rev-parse --short "$REMOTE"))"
g log --oneline "$LOCAL..$REMOTE" | head -n 20 >> "$LOG"
g merge --ff-only --quiet "$REMOTE" || fail "새 버전을 가져오지 못했습니다(fast-forward 실패)"
restore_owner

if build_up && wait_healthy; then
  $DOCKER image prune -f >/dev/null 2>&1 || true
  rm -f "$FAILED"
  write_status updated "새 버전을 적용했습니다: $NEW_SUBJECT" "$(now)"
  exit 0
fi

# 새 버전이 뜨지 않으면 이전 버전으로 되돌리고, 이 커밋은 다시 시도하지 않게 적어 둔다.
log "새 버전이 제대로 뜨지 않아 $(g rev-parse --short "$LOCAL") 로 되돌립니다"
echo "$REMOTE" > "$FAILED"
g reset --hard --quiet "$LOCAL"
restore_owner
build_up
if wait_healthy; then
  write_status rolled_back "새 버전($(g rev-parse --short "$REMOTE"))이 제대로 뜨지 않아 이전 버전으로 되돌렸습니다${BACKUP_NAME:+. 업데이트 전 백업: $BACKUP_NAME}. data/auto-update.log 를 확인하세요"
else
  write_status error "새 버전도, 되돌린 버전도 제대로 뜨지 않습니다${BACKUP_NAME:+(업데이트 전 백업: $BACKUP_NAME)}. Container Manager 에서 확인하세요"
fi
exit 1
