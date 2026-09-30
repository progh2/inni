# NAS(도커)에 올리기

inni v2는 **컨테이너 1개**로 돈다. 데이터(DB·사진·백업)는 전부 `./data` 폴더 하나에 있다.
시놀로지를 예로 들지만, 도커가 되는 NAS·PC(QNAP, 우분투, 윈도우 Docker Desktop)면 같다.

| 필요한 것 | 비고 |
|-----------|------|
| 도커 + Compose v2 | 시놀로지는 **Container Manager**(DSM 7.2+) |
| CPU | x86-64 또는 ARM64. 메모리 200MB 안팎 |
| Firebase 프로젝트 | 로그인용 → [firebase-setup.md](firebase-setup.md) |
| (권장) 도메인 + https | 휴대폰 카메라·마이크·로그인에 필요 → §3 |

---

## 1. 설치

### 1-1. 파일 올리기
NAS에 폴더를 만든다(예: `/volume1/docker/inni`). 이 저장소를 받아 그 폴더에 둔다.

```bash
# NAS 에 SSH 로 들어가서(또는 File Station 으로 zip 을 올려 풀어도 됨)
cd /volume1/docker
git clone https://github.com/<계정>/inni.git
cd inni
cp .env.example .env
```

`.env`를 열어 채운다. 최소한 이것들:

```ini
FIREBASE_PROJECT_ID=...
FIREBASE_API_KEY=...
ADMIN_EMAILS=kim@hanbit.hs.kr
ALLOWED_DOMAINS=hanbit.hs.kr
SCHOOL_NAME=한빛고등학교
PUBLIC_URL=https://inni.hanbit.synology.me
INNI_PORT=8080
```

### 1-2. 띄우기

**SSH**
```bash
docker compose up -d --build
docker compose logs -f inni      # "inni 2.x.x · http://0.0.0.0:3000 · 로그인 firebase …" 가 보이면 성공 (Ctrl+C 로 빠져나오기)
```

**Container Manager(화면)**
1. Container Manager → **프로젝트** → **생성**
2. 프로젝트 이름 `inni`, 경로 `/docker/inni`, 원본은 **기존 compose.yaml 사용**
3. 다음 → 완료. 빌드가 끝나면(처음 몇 분) 컨테이너 `inni-inni-1`이 **정상(healthy)** 이 된다.

> 폴더에 예전 `docker-compose.yml`(PHP 판)이 같이 있으면 Compose는 **`compose.yaml`을 먼저** 쓴다(경고가 한 줄 나온다). 예전 파일 정리는 README의 "예전 inni(PHP)" 참고.
>
> **예전 inni(PHP)를 같은 폴더에서 도커로 돌리고 있었다면**: 프로젝트·서비스 이름이 같아서(`inni-inni-1`) `docker compose up -d --build`가 **예전 컨테이너를 v2로 바꿔 띄운다**(포트도 같은 8080). 예전 데이터 `data/inni.sqlite`는 그대로 남고, v2 처음 설정 화면에서 가져온다 → [백업·이전 §4](backup-restore.md#4-예전-inniphp에서-옮기기). 바꾸기 전에 `data/` 폴더를 한 번 복사해 두면 안심.

### 1-3. 확인
- NAS 안에서: `http://NAS주소:8080/health` → `{"status":"ok",...}`
- 브라우저: https 주소(§3)로 열고 `ADMIN_EMAILS` 계정으로 로그인 → 처음 설정 화면.

## 2. 처음 설정 화면

관리자가 처음 들어오면 셋 중 하나를 고른다.

| 선택 | 언제 |
|------|------|
| **장소부터 빠르게 만들기** | 새로 시작. `건물: 실, 실, …`을 한 줄씩 적으면 한꺼번에 만든다 |
| **백업 파일로 복원** | 다른 NAS·PC에서 쓰던 inni v2 백업(zip)을 옮겨 올 때 |
| **예시로 둘러보기** | 먼저 연습해 보고 싶을 때(나중에 지우고 시작) |
| **예전 inni 데이터 가져오기** | `data/inni.sqlite`(PHP 판 DB)가 있을 때만 보인다 → [백업·이전 §4](backup-restore.md#4-예전-inniphp에서-옮기기) |

엑셀 대장이 있으면: 시스템(09) → 백업·이전 → **CSV 가져오기**(양식 내려받아 채우기, 미리보기 후 반영).

## 3. HTTPS (휴대폰 카메라·마이크·로그인에 필요)

브라우저는 **https가 아닌 주소에서 카메라·마이크를 막는다**(PC의 `localhost`만 예외). 그러면 휴대폰으로 스캔·말하기를 못 한다.
라벨 QR을 **휴대폰 기본 카메라**로 찍는 방식은 http에서도 되지만, 앱 안 스캐너와 음성은 https가 있어야 한다.

### 시놀로지: DDNS + 무료 인증서 + 역방향 프록시
1. **DDNS**: 제어판 → 외부 액세스 → DDNS → 추가 → 서비스 공급자 `Synology` → 호스트 이름 예) `hanbit.synology.me`
2. **인증서**: 제어판 → 보안 → 인증서 → 추가 → Let's Encrypt → 도메인 `inni.hanbit.synology.me` (와일드카드 인증서면 생략)
3. **역방향 프록시**: 제어판 → 로그인 포털 → 고급 → 역방향 프록시 → 생성
   - 소스: 프로토콜 `HTTPS`, 호스트 이름 `inni.hanbit.synology.me`, 포트 `443`
   - 대상: 프로토콜 `HTTP`, 호스트 이름 `localhost`, 포트 `8080`(= `INNI_PORT`)
   - **사용자 지정 머리글** → 생성 → **WebSocket** (실시간 알림이 끊기지 않게. SSE 는 이것 없이도 대부분 되지만 켜 두는 편이 안전)
4. 제어판 → 보안 → 인증서 → **설정**에서 `inni.hanbit.synology.me`에 방금 인증서를 연결.
5. `.env`의 `PUBLIC_URL=https://inni.hanbit.synology.me`, Firebase **승인된 도메인**에 `inni.hanbit.synology.me` 추가.

학교 밖에서 쓸 필요가 없으면 공유기에서 443 포트만 NAS로 넘기지 않거나, 학교 DNS에 내부용 이름을 만들어 쓴다(인증서는 DNS 인증 방식).

> 역방향 프록시 뒤에서는 inni가 `X-Forwarded-*` 머리글을 믿어야 로그인 쿠키에 `Secure`가 붙는다.
> `TRUST_PROXY` 기본값(`loopback, linklocal, uniquelocal`)이 NAS 안의 프록시를 믿는다. 바꿀 일은 거의 없다.

## 4. AI 연결 (선택)

시스템(09) → **AI 코어** → 연결 추가. 연결은 여러 개 등록해 두고 **대화(이니)** 와 **사진 읽기(비전)** 에 각각 고른다.

| 종류 | 주소 예 | 메모 |
|------|---------|------|
| **ChatGPT (OpenAI API)** | (주소 없음) + API 키 `sk-…` | `gpt-4.1-mini`·`gpt-5-mini` 권장(대화+사진) |
| **AIAPI 관제 함교**(aiapi-manager) | `http://host.docker.internal:4000` + 가상 키 | 같은 NAS의 aiapi-manager(LiteLLM). 사용량이 그쪽 통계에 잡힌다 |
| **Ollama** | `http://192.168.0.20:11434` | 학교 PC의 무료 로컬 모델. 대화 `qwen3:8b`, 사진 `qwen2.5vl:7b`·`gemma3` |
| **OpenAI 호환 서버** | `http://192.168.0.20:1234/v1` | LM Studio·vLLM·llama.cpp 등 |

- 컨테이너 안의 `localhost`는 **컨테이너 자신**이다. 같은 NAS의 다른 서비스는 `host.docker.internal`(compose에 설정됨), 다른 PC는 그 PC의 IP로.
- Ollama를 다른 PC에서 부르려면 그 PC에서 `OLLAMA_HOST=0.0.0.0`으로 띄운다.
- **[연결 시험]** 이 도구 호출·사진 읽기 가능 여부까지 알려 준다. 학교 밖 모델에는 사람 이름을 가명으로 바꿔 보낸다(설정에서 끄기 가능). 월 토큰 상한도 있다.

## 5. 제품 검색·사진 (선택)

시스템(09) → **제품 검색·사진**

- **네이버 검색 API**(쇼핑·이미지): <https://developers.naver.com> → 애플리케이션 등록 → 검색 API → Client ID/Secret
- **카카오 이미지 검색**: <https://developers.kakao.com> → 앱 → REST API 키
- 키가 없어도 등록 화면에 검색 사이트 바로가기가 뜨고, **제품 링크 붙여넣기**로 정보·사진을 가져올 수 있다.

### 배경 지우기
사진 편집기의 **빠른 지우기**(단색 배경)·지우개·자르기·밝기는 설치 없이 늘 된다. 복잡한 배경은 AI 배경 지우기 방식을 고른다.

| AI 배경 지우기 방식 | 설정 |
|------|------|
| **브라우저에서**(기본) | 처음 한 번 모델(약 40MB)을 인터넷에서 받는다. NAS에 부담 없음 |
| **배경 제거 서버(rembg)** | `docker compose --profile rembg up -d` → rembg 서버 주소에 `http://rembg:7000`. 처음 한 번 모델을 받은 뒤로는 사진이 NAS 밖으로 나가지 않음 |
| **끄기** | 빠른 지우기·지우개만 |

## 6. 알림(텔레그램, 선택)

시스템(09) → 알림: 봇 토큰(@BotFather)과 채팅 ID → [시험 보내기]. 15분마다 살펴서 **새로 생긴** 연체·재고 부족·고장 신고를 한 번씩 보낸다(같은 일은 다시 안 보냄). 자동 백업 결과도 받을 수 있다.

## 7. 업데이트

```bash
cd /volume1/docker/inni
git pull                              # 또는 새 파일로 덮어쓰기(.env 와 data/ 는 그대로 두기)
docker compose up -d --build
```

- 업데이트 전에 시스템 → 백업·이전 → **지금 백업**을 한 번 눌러 두면 안심.
- DB 구조가 바뀌면 시작할 때 자동으로 옮긴다(되돌릴 땐 예전 판 + 업데이트 전 백업).

## 8. 자주 묻는 것

| 증상 | 확인 |
|------|------|
| 컨테이너가 계속 재시작 | `docker compose logs inni` — 대개 `.env`의 Firebase 값 누락 |
| 포트가 겹친다 | `.env`의 `INNI_PORT`를 바꾸고 `docker compose up -d` |
| 휴대폰에서 스캐너가 "카메라를 막습니다" | https가 아님 → §3. 급하면 "사진 찍어서 읽기"나 기본 카메라로 QR |
| 라벨 QR을 찍으면 엉뚱한 주소 | `PUBLIC_URL`을 https 주소로 |
| AI 연결 시험 실패 | 주소의 `localhost` → `host.docker.internal` 또는 PC IP. 방화벽. 모델 이름 |
| 3D 지도가 느리다 | `T` 키로 끄기(기기별로 기억). 휴대폰은 기본으로 꺼짐 |
| 로그 보기 | `docker compose logs --tail 200 inni` |

데이터 위치: `./data/inni.db`(DB) · `./data/uploads/`(사진) · `./data/backups/`(백업 zip)
