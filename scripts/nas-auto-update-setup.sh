#!/bin/bash
# inni NAS 자동 업데이트 준비 (한 번만). SSH 로 NAS 에 들어가 다음을 실행한다:
#   sudo bash /volume1/docker/inni/scripts/nas-auto-update-setup.sh
#
# 하는 일: git 확인 → GitHub 연결(공개 저장소는 그대로 https, 비공개면 읽기 전용 배포 키)
#          → ZIP 으로 설치했으면 제자리에서 git 저장소로 → 첫 확인 → 작업 스케줄러 등록 안내
# 같은 NAS 의 aiapi-manager 배포 키와 섞이지 않게 inni 는 SSH 별칭(github-inni)을 따로 쓴다.
set -u
export PATH="/usr/local/bin:/usr/local/sbin:/usr/bin:/usr/sbin:/bin:/sbin:${PATH:-}"
DIR="$(cd "$(dirname "$0")/.." && pwd)"
BRANCH="${INNI_UPDATE_BRANCH:-main}"
KEY=/root/.ssh/inni_deploy
ALIAS=github-inni
g() { git -c safe.directory="$DIR" -C "$DIR" "$@"; }
say() { printf '\n\033[1;36m▶ %s\033[0m\n' "$*"; }
die() { printf '\n\033[1;31m✖ %s\033[0m\n' "$*"; exit 1; }

[ "$(id -u)" = 0 ] || die "sudo 로 실행하세요: sudo bash $0"
command -v git >/dev/null 2>&1 || die "git 이 없습니다. DSM 패키지 센터에서 'Git Server' 를 설치한 뒤 다시 실행하세요."

# 저장소: 지금 원격이 있으면 그 owner/repo, 없으면 기본값
REPO="progh2/inni"
if [ -d "$DIR/.git" ]; then
  url="$(g remote get-url origin 2>/dev/null || true)"
  guess="$(printf '%s' "$url" | sed -n -e 's#^https://[^/]*github.com/\(.*\)$#\1#p' -e 's#^git@github[^:]*:\(.*\)$#\1#p' | sed 's#\.git$##')"
  [ -n "$guess" ] && REPO="$guess"
fi
REPO_HTTPS="https://github.com/${REPO}.git"

say "1) GitHub 연결 (${REPO})"
if GIT_TERMINAL_PROMPT=0 git ls-remote --heads "$REPO_HTTPS" "$BRANCH" >/dev/null 2>&1; then
  URL="$REPO_HTTPS"
  echo "공개 저장소라 배포 키 없이 https 로 받습니다 ✓"
else
  echo "https 로 받을 수 없습니다(비공개 저장소이거나 연결 문제). 읽기 전용 배포 키를 씁니다."
  command -v ssh-keygen >/dev/null 2>&1 || die "ssh-keygen 이 없습니다. DSM 제어판 → 터미널 및 SNMP 에서 SSH 를 켜 두었는지 확인하세요."
  mkdir -p /root/.ssh && chmod 700 /root/.ssh
  [ -f "$KEY" ] || ssh-keygen -q -t ed25519 -N "" -f "$KEY" -C "inni-nas-$(hostname)" || die "키를 만들지 못했습니다"
  write_block() { # HostName Port — inni 전용 블록만 새로 쓴다(다른 설정은 그대로)
    touch /root/.ssh/config && chmod 600 /root/.ssh/config
    sed -i '/^# inni-deploy-begin$/,/^# inni-deploy-end$/d' /root/.ssh/config
    printf '# inni-deploy-begin\nHost %s\n  HostName %s\n  Port %s\n  User git\n  IdentityFile %s\n  IdentitiesOnly yes\n# inni-deploy-end\n' "$ALIAS" "$1" "$2" "$KEY" >> /root/.ssh/config
  }
  write_block github.com 22
  echo "아래 한 줄을 GitHub 저장소(https://github.com/${REPO}) → Settings → Deploy keys → Add deploy key 에 붙여 넣으세요."
  echo "Title 은 아무거나(예: 학교 NAS), 'Allow write access' 는 켜지 마세요(읽기 전용)."
  echo
  cat "$KEY.pub"
  echo
  read -r -p "등록했으면 Enter 를 누르세요… " _
  test_ssh() { ssh -o BatchMode=yes -o ConnectTimeout=10 -o StrictHostKeyChecking=accept-new -T "git@$ALIAS" 2>&1 | grep -q "successfully authenticated"; }
  if ! test_ssh; then
    echo "22번 포트로 안 되어 443 번(ssh.github.com)으로 다시 시도합니다(학교 방화벽 대비)."
    write_block ssh.github.com 443
    test_ssh || die "GitHub 에 연결하지 못했습니다. 배포 키를 등록했는지, NAS 가 인터넷에 나갈 수 있는지 확인하세요."
  fi
  echo "연결됨 ✓"
  URL="git@${ALIAS}:${REPO}.git"
fi

say "2) 저장소 설정"
if [ -d "$DIR/.git" ]; then
  g remote set-url origin "$URL"
  echo "원격: $URL"
else
  echo "ZIP 으로 설치된 폴더라 제자리에서 git 저장소로 바꿉니다. .env · data(DB·사진·백업) 는 그대로 둡니다."
  [ -f "$DIR/compose.yaml" ] && cp "$DIR/compose.yaml" "$DIR/compose.yaml.before-git" && echo "기존 compose.yaml 은 compose.yaml.before-git 으로 남겼습니다(포트 등을 고쳤다면 .env 로 옮기세요)."
  g init -q && g remote add origin "$URL" && GIT_TERMINAL_PROMPT=0 g fetch -q origin "$BRANCH" && g checkout -q -f -B "$BRANCH" "origin/$BRANCH" \
    || die "git 저장소로 바꾸지 못했습니다"
fi
GIT_TERMINAL_PROMPT=0 g fetch -q origin "$BRANCH" || die "GitHub 에서 $BRANCH 를 가져오지 못했습니다"
CUR="$(g rev-parse --abbrev-ref HEAD 2>/dev/null || true)"
if [ "$CUR" != "$BRANCH" ]; then
  echo "지금 브랜치가 '$CUR' 입니다. 자동 업데이트는 '$BRANCH' 를 따라갑니다. 바꾸려면:"
  echo "  sudo git -C $DIR checkout $BRANCH"
fi
g branch -q --set-upstream-to="origin/$BRANCH" "$BRANCH" 2>/dev/null || true

say "3) 첫 확인"
bash "$DIR/scripts/nas-auto-update.sh"
cat "$DIR/data/auto-update.json" 2>/dev/null
echo

say "4) 마지막: 작업 스케줄러에 등록"
cat <<EOF
DSM 제어판 → 작업 스케줄러 → 생성 → 예약된 작업 → 사용자 정의 스크립트
  일반     : 작업 이름 'inni 자동 업데이트', 사용자 root
  일정     : 매일 · 첫 실행 00:00 · 빈도 10분마다 · 마지막 실행 23:50
  작업 설정: 사용자 정의 스크립트에
             bash $DIR/scripts/nas-auto-update.sh
             (수업 중 적용을 피하려면: INNI_UPDATE_HOURS="0-7,17-23" bash $DIR/scripts/nas-auto-update.sh)
             '스크립트가 비정상적으로 종료되는 경우에만 실행 세부 정보를 이메일로 보내기' 를 켜 두면 실패를 메일로 받습니다.
inni 시스템(09) → 정보 → '자동 업데이트' 줄로 확인할 수 있습니다. 새 버전을 받기 전에는 늘 백업(…-before-update.zip)을 남깁니다.
EOF
