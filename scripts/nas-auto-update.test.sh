#!/bin/bash
# nas-auto-update.sh 시험. 가짜 GitHub(맨 저장소)·가짜 NAS 저장소·가짜 docker 로 돌린다.
#   bash scripts/nas-auto-update.test.sh      (npm test 에도 들어 있다)
set -u
SCRIPT="$(cd "$(dirname "$0")" && pwd)/nas-auto-update.sh"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
PASS=0; FAILN=0
ok() { if [ "$1" = 1 ]; then echo "PASS  $2"; PASS=$((PASS+1)); else echo "FAIL  $2 ${3:-}"; FAILN=$((FAILN+1)); fi; }

# 시험은 이 컴퓨터의 git 설정(~/.gitconfig)을 건드리지 않는다.
export GIT_CONFIG_GLOBAL="$WORK/gitconfig" GIT_CONFIG_NOSYSTEM=1
git config --global init.defaultBranch main
export GIT_AUTHOR_NAME=t GIT_AUTHOR_EMAIL=t@t GIT_COMMITTER_NAME=t GIT_COMMITTER_EMAIL=t@t

# 가짜 GitHub: 맨 저장소 + 개발용 복제
git init -q --bare "$WORK/origin.git"
git clone -q "$WORK/origin.git" "$WORK/dev" 2>/dev/null
mkdir -p "$WORK/dev/scripts"
cp "$SCRIPT" "$WORK/dev/scripts/"
echo "v1" > "$WORK/dev/app.txt"
printf '/data/\n' > "$WORK/dev/.gitignore"
(cd "$WORK/dev" && git add -A && git commit -qm "v1" && git push -q origin HEAD:main)
# 가짜 NAS
git clone -q "$WORK/origin.git" "$WORK/nas"
NAS="$WORK/nas"
STATUS="$NAS/data/auto-update.json"

# 가짜 docker: 부른 명령을 적고, 파일 표시로 상황을 흉내 낸다
#   UNHEALTHY(+app.txt 가 v2-bad) → 건강하지 않음 · NO_CLI → 백업 도구 없는 예전 버전 · BACKUP_FAIL → 백업 실패
cat > "$WORK/docker" <<'DOCK'
#!/bin/bash
D="$(dirname "$0")"
echo "$* ${INNI_COMMIT:+[INNI_COMMIT=$INNI_COMMIT]}" >> "$D/docker.calls"
case "$1" in
  compose)
    case "$2" in
      version) exit 0 ;;
      ps) echo fakeid ;;
      exec)
        case "$*" in
          *"test -f server/cli.js"*) [ -f "$D/NO_CLI" ] && exit 1; exit 0 ;;
          *"cli.js backup"*) [ -f "$D/BACKUP_FAIL" ] && exit 1; echo "inni-backup-20261001-000000-before-update.zip" ;;
        esac ;;
      up) exit 0 ;;
    esac ;;
  inspect)
    case "$*" in *State.Running*) echo true; exit 0 ;; esac
    if [ -f "$D/UNHEALTHY" ] && grep -q v2-bad "$NAS_DIR/app.txt" 2>/dev/null; then echo unhealthy; else echo healthy; fi ;;
  image) exit 0 ;;
esac
exit 0
DOCK
chmod +x "$WORK/docker"
run() { NAS_DIR="$NAS" DOCKER="$WORK/docker" INNI_UPDATE_LOCK="$WORK/lock" INNI_UPDATE_HEALTH_TIMEOUT=2 INNI_UPDATE_POLL=1 "$@" bash "$NAS/scripts/nas-auto-update.sh"; }
field() { sed -n "s/.*\"$1\":\"\\([^\"]*\\)\".*/\\1/p" "$STATUS"; }
push() { (cd "$WORK/dev" && echo "$1" > app.txt && git commit -qam "$1" && git push -q origin HEAD:main); }
calls() { cat "$WORK/docker.calls" 2>/dev/null; }

# 1) 변화 없음
run env
ok "$([ "$(field status)" = ok ] && echo 1)" "변화가 없으면 ok"
ok "$(calls | grep -q "compose up" || echo 1)" "변화 없을 때 빌드하지 않음"

# 2) 새 커밋 → 백업 → 가져와 빌드(커밋 표시)
push "v2"
run env
ok "$([ "$(field status)" = updated ] && [ "$(cat "$NAS/app.txt")" = v2 ] && echo 1)" "새 커밋을 가져와 적용" "$(field status) $(cat "$NAS/app.txt")"
ok "$(calls | grep -q "cli.js backup --reason before-update" && echo 1)" "적용 전에 백업을 만듦"
ok "$([ "$(field backup)" = "inni-backup-20261001-000000-before-update.zip" ] && echo 1)" "백업 이름을 상태에 남김" "$(field backup)"
HEAD_SHORT="$(git -C "$NAS" rev-parse --short HEAD)"
ok "$(calls | grep "compose up -d --build" | grep -q "INNI_COMMIT=$HEAD_SHORT" && echo 1)" "빌드할 때 커밋을 넘김"
ok "$([ -n "$(field applied_at)" ] && echo 1)" "적용 시각을 남김"
APPLIED="$(field applied_at)"
run env
ok "$([ "$(field status)" = ok ] && [ "$(field applied_at)" = "$APPLIED" ] && echo 1)" "다음 확인에서도 적용 시각 유지"

# 3) NAS 에서 고친 파일이 있으면 멈춤
push "v3"
echo "local edit" > "$NAS/app.txt"
run env
ok "$([ "$(field status)" = error ] && [ "$(cat "$NAS/app.txt")" = "local edit" ] && echo 1)" "고친 파일이 있으면 건드리지 않고 멈춤" "$(field message)"
(cd "$NAS" && git checkout -q -- app.txt)

# 4) 백업을 못 만들면 적용하지 않음
touch "$WORK/BACKUP_FAIL"
run env
ok "$([ "$(field status)" = error ] && [ "$(cat "$NAS/app.txt")" = v2 ] && echo 1)" "백업 실패면 적용하지 않고 멈춤" "$(field message)"
rm -f "$WORK/BACKUP_FAIL"

# 5) 적용 시각이 아니면 기다림
NOWH=$((10#$(TZ=KST-9 date +%H))); OTHER=$(( (NOWH + 12) % 24 ))
run env INNI_UPDATE_HOURS="$OTHER"
ok "$([ "$(field status)" = waiting ] && [ "$(cat "$NAS/app.txt")" = v2 ] && echo 1)" "적용 시각이 아니면 기다림" "$(field message)"
run env INNI_UPDATE_HOURS="$NOWH"
ok "$([ "$(field status)" = updated ] && [ "$(cat "$NAS/app.txt")" = v3 ] && echo 1)" "적용 시각이면 적용"

# 6) 새 버전이 건강하지 않으면 되돌림
touch "$WORK/UNHEALTHY"
push "v2-bad"
run env
ok "$([ "$(field status)" = rolled_back ] && [ "$(cat "$NAS/app.txt")" = v3 ] && echo 1)" "건강하지 않으면 이전 버전으로 되돌림" "$(field status) $(cat "$NAS/app.txt")"
ok "$(field message | grep -q "업데이트 전 백업" && echo 1)" "되돌림 안내에 백업 이름" "$(field message)"
# 같은 망가진 커밋은 다시 시도하지 않는다(10분마다 서비스가 흔들리지 않게)
: > "$WORK/docker.calls"
run env
ok "$([ "$(field status)" = held ] && [ "$(cat "$NAS/app.txt")" = v3 ] && echo 1)" "되돌린 커밋은 보류하고 다시 빌드하지 않음" "$(field status)"
ok "$(calls | grep -q "compose up\|cli.js backup" || echo 1)" "보류 중에는 백업·빌드를 부르지 않음"
rm -f "$WORK/UNHEALTHY"
# 고친 새 커밋이 올라오면 다시 시도한다
push "v3-fixed"
run env
ok "$([ "$(field status)" = updated ] && [ "$(cat "$NAS/app.txt")" = v3-fixed ] && [ ! -f "$NAS/data/auto-update.failed" ] && echo 1)" "고친 커밋이 오면 다시 적용" "$(field status) $(cat "$NAS/app.txt")"

# 7) 백업 도구가 없는 예전 버전이 돌고 있으면 백업 없이 진행
touch "$WORK/NO_CLI"
push "v4"
run env
ok "$([ "$(field status)" = updated ] && [ "$(cat "$NAS/app.txt")" = v4 ] && echo 1)" "예전 버전(백업 도구 없음)에서도 업데이트" "$(field status)"
rm -f "$WORK/NO_CLI"

# 8) 이미 돌고 있으면 조용히 끝남
mkdir "$WORK/lock"; before="$(field checked_at)"; sleep 1
run env
ok "$([ "$(field checked_at)" = "$before" ] && echo 1)" "잠겨 있으면 겹쳐 돌지 않음"
rmdir "$WORK/lock"

# 9) 기록 파일과 JSON 모양
ok "$(node -e 'JSON.parse(require("fs").readFileSync(process.argv[1], "utf8"))' "$STATUS" 2>/dev/null && echo 1)" "상태 파일이 올바른 JSON"
ok "$([ -s "$NAS/data/auto-update.log" ] && echo 1)" "기록 파일을 남김"
ok "$([ -z "$(git -C "$NAS" status --porcelain)" ] && echo 1)" "상태·기록 파일이 저장소를 더럽히지 않음" "$(git -C "$NAS" status --porcelain | head -3)"

echo "---- $PASS 통과, $FAILN 실패"
[ "$FAILN" = 0 ]
