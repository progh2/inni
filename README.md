# inni

**Intelligent Inventory Navigation Interface** — 마이스터고/직업계고 기자재·비품·소모품·실 관리.

스택: **PHP 8.1+ · SQLite · Google OAuth(선택) · 로컬 사진 업로드**  
(Firebase 없음 — 배포·백업·사진 저장이 단순한 A안)

## 빠른 실행 (로컬)

```bash
cd /path/to/inni
cp config.example.php config.php   # 로컬 예시: demo_login => true. 최초 1회 자동 복사되기도 함
php -S 0.0.0.0:8080 -t public
```

브라우저: http://localhost:8080  
→ **담당교사로 들어가기** (데모). `demo_login`이 `true`이면 데모 버튼이 유지됩니다.

## Docker (로컬 스모크 / 자가 호스팅)

문서 루트는 이미지에서 `public/` 입니다. 컨테이너가 처음 뜰 때 `config.example.php` → `config.php`를 만듭니다. **로컬/스모크 예시의 `demo_login`은 `true`**라서 데모 로그인으로 바로 확인할 수 있습니다. 운영 설정 예시는 `config.production.example.php` (`demo_login => false`)입니다.

```bash
docker compose up --build
```

구버전 Docker는 `docker-compose up --build` 와 같습니다.

브라우저: http://localhost:8080  
→ **담당교사로 들어가기** (데모)

운영에서는 데모 로그인을 **반드시** 끕니다. 호스트에 `config.php`를 **먼저** 만든 뒤 `demo_login => false`로 두고, `docker-compose.yml`의 주석 처리된 볼륨을 켭니다. 없는 경로를 마운트하면 Docker가 `config.php`를 디렉터리로 만들어 기동이 실패합니다.

```bash
cp config.production.example.php config.php
# config.php 에만 Google client_id / client_secret 입력. demo_login 은 false 유지.
#   volumes:
#     - ./config.php:/var/www/inni/config.php:ro
```

`php:8.3-apache`에 이미 들어 있는 PHP 확장을 빌드에서 검증합니다: `pdo_sqlite`, `sqlite3`, `curl`, `fileinfo`, `mbstring`.

### 볼륨

| 호스트 | 컨테이너 | 내용 |
|--------|----------|------|
| `./data` | `/var/www/inni/data` | SQLite (`inni.sqlite` + WAL/SHM) |
| `./public/uploads` | `/var/www/inni/public/uploads` | 업로드 사진 |

## 서버 배포

1. PHP 8.1+ (확장: `pdo_sqlite`, `sqlite3`, `curl`, `fileinfo`, `mbstring`)
2. 문서 루트를 **`public/`** 으로 지정
3. `data/` 와 `public/uploads/` 쓰기 권한
4. `config.production.example.php` → `config.php` 수정 (`demo_login => false`). 로컬 예시(`config.example.php`)를 복사했다면 이 값을 **반드시 false**로 바꾸세요. 키가 없으면 앱도 false로 취급합니다.

Apache 예:

```apache
DocumentRoot /var/www/inni/public
<Directory /var/www/inni/public>
  AllowOverride All
  Require all granted
</Directory>
```

Nginx 예: `root .../public;` + `try_files $uri /index.php?$query_string;`

### Google 로그인 (운영)

문서 루트가 `public/` 이므로 콜백은 예쁜 경로가 아닙니다. Google이 비교하는 문자열은 **한 가지**입니다.

```
{base_url}/index.php?r=auth/google/callback
```

예: `https://school.example/index.php?r=auth/google/callback`  
서브경로 배포: `https://school.example/inni/public/index.php?r=auth/google/callback`

로그인 화면과 설정 → Google 로그인에 현재 인스턴스의 **정확한 문자열**이 표시됩니다. Console에 그 값을 그대로 넣으세요. `/auth/google/callback` 만 등록하면 앱 라우트와 맞지 않습니다.

1. [Google Cloud Console](https://console.cloud.google.com/)에서 프로젝트 선택 (또는 생성)
2. **API 및 서비스 → OAuth 동의 화면**을 외부/내부 중 학교 정책에 맞게 구성. 테스트 사용자를 넣어야 하면 담당 교사 메일을 추가
3. **API 및 서비스 → 사용자 인증 정보 → 사용자 인증 정보 만들기 → OAuth 클라이언트 ID → 웹 애플리케이션**
4. **승인된 자바스크립트 원본**: 사이트의 origin (예: `https://school.example`)
5. **승인된 리디렉션 URI**: 위에서 표시된 `{base_url}/index.php?r=auth/google/callback` **한 줄, 글자 그대로**
6. 발급된 값을 **서버의 `config.php`에만** 넣기. 저장소에 `client_id` / `client_secret`을 커밋하지 말 것
   ```php
   'base_url' => 'https://school.example', // 운영에서는 반드시 공개 URL로 고정
   'demo_login' => false,
   'google' => [
       'client_id' => '...',
       'client_secret' => '...',
       'allowed_domains' => ['school.go.kr'], // 아래 정책
       'redirect_uri' => '', // 비우면 base_url로 조합. Console과 다를 때만 Exact URI
   ],
   ```
7. `allowed_domains`
   - `[]` (비움): Google이 **인증한(`email_verified`)** 모든 도메인 허용
   - 값이 있으면 **정확 일치만** 허용 (대소문자 무시). `mail.school.go.kr`은 `school.go.kr`에 포함되지 않음. 목록 밖은 거부(실패 폐쇄)
8. 운영에서는 **`demo_login => false`가 필수**. 데모 버튼이 남아 있으면 학교 계정 없이 들어갑니다. 로컬/Docker 스모크만 `true`

첫 Google 로그인 사용자가 owner, 이후 사용자는 `pending` → 관리자 승인.

데모 시드 계정(`demo-owner`, `demo-teacher`)은 이 판정에서 제외합니다. 로컬에서 `demo_login`이 켜져 있어도 운영의 첫 Google 사용자는 owner가 됩니다. 시드만 있고 실제 owner가 없는 DB에 남아 있는 `pending` Google 계정도 다음 로그인 때 owner로 승격됩니다.

`redirect_uri_mismatch`가 나면 Console 값과 로그인 화면에 찍힌 URI가 한 글자라도 다른지(http/https, 포트, 서브경로, `index.php?r=`)를 먼저 봅니다. `base_url`을 비우면 호스트/리버스 프록시에 따라 URI가 달라질 수 있습니다.

### 백업

복사할 경로 (호스트 기준, Compose 볼륨과 동일):

- DB: `data/inni.sqlite`
- WAL/SHM이 있으면 함께: `data/inni.sqlite-wal`, `data/inni.sqlite-shm`
- 사진: `public/uploads/` 디렉터리 전체

일관된 복사본이 필요하면 앱을 잠시 멈춘 뒤 복사하세요.

```bash
docker compose stop
mkdir -p "backup/$(date +%Y%m%d)"
cp -a data/inni.sqlite "backup/$(date +%Y%m%d)/" 2>/dev/null || true
cp -a data/inni.sqlite-wal data/inni.sqlite-shm "backup/$(date +%Y%m%d)/" 2>/dev/null || true
cp -a public/uploads "backup/$(date +%Y%m%d)/"
docker compose start
```

동작 중 백업은 SQLite `.backup` / `VACUUM INTO`로 DB만 뜨고, 사진은 별도로 `public/uploads/`를 복사합니다.

## 데모 계정

| 버튼 | 역할 |
|------|------|
| 담당교사 | owner — 등록·설정 |
| 일반교사 | teacher — 대여·조회 |

운영에서는 `demo_login => false`가 **필수** (`config.production.example.php` 기본값). 로컬/Docker 스모크만 `true`.

## 주요 화면

- 홈 / 찾기 / **스캔**(카메라 QR) / 실별 목록 / 빠른 등록  
- 장비 상세: 대여·반납·이동·사진·고장 신고·이력  
- 품목 수정 (담당교사·관리자), 소모품·비품·부품 **재입고**, 사용 출고 **취소**와 취소 이력  
- 소모품·부품 상세: 위치별 사용 출고(수량·사유), 출고 이력
- 라벨 인쇄 (브라우저 QR + 인쇄)  
- 실사 (담당교사·관리자): 실 선택 → 스캔/코드 확인 → 미확인 목록  
- 품목 CSV (담당교사·관리자): 템플릿/목록 내려받기, 업로드로 신규·수정. **CSV UTF-8만** (xlsx 없음). 충돌 행은 건너뛰고 보고. 에듀파인 파일 동기화·실사 연동 없음  
- 설정·사용자 승인
- 알림: 홈의 재고 부족·연체 대여. 담당교사(owner/manager)가 설정에서 텔레그램 채팅 ID·이벤트 on/off. 봇 토큰은 서버 `config.php`에만 (비면 연결 필요, 발송 안 함)
- AI 자리: 설정에 OpenAI / Upstage / Ollama 연결 상태. 키는 서버 `config.php`의 `ai.api_key`만 (비면 연결 필요, 더보기 메뉴 숨김). **제안만** — 재고·대여·대장을 자동으로 바꾸지 않음. 챗봇 없음

## 라이선스

AGPL-3.0 — 루트 `LICENSE` 참고.

## 문서

- `docs/PRD.md` — 제품 요구사항  
- `docs/ERD.md` — 도메인 모델 (구현은 SQLite 테이블로 매핑)
- `docs/STATUS.md` — 구현 현황·검증·다음 작업

## 출고 기능 검증

PHP CLI와 `pdo_sqlite` 확장이 있는 환경에서:

```bash
php tests/stock.php
php tests/catalog.php
php tests/csrf.php
php tests/bootstrap_owner.php
php tests/loan.php
php tests/google_oauth.php
php tests/role_block.php
php tests/scan_labels.php
php tests/inventory.php
php tests/catalog_csv.php
php tests/alerts.php
php tests/ai.php
```

메모리 DB로 수량 검증, 재고 부족, 권한, 품목·위치 일치, 출고·재입고·출고 취소 이력 및 저장 실패 시 롤백을 확인합니다. `catalog.php`는 품목 수정 권한과 필드 검증을 봅니다. CSRF 검사는 유효 토큰 허용, 잘못된 토큰 거부, 반납·재입고·출고 취소 경로 GET 거부를 임시 SQLite로 확인합니다. `bootstrap_owner`는 데모 시드 사용자를 건너뛰고 첫 Google 계정을 owner로 두는 초기화 규칙을 확인합니다. 대여·반납 검사는 조건부 UPDATE, 이중/동시 요청 실패 폐쇄, 역할별 반납 범위를 확인합니다. `google_oauth`는 리디렉션 URI 조합, `allowed_domains` 실패 폐쇄, 빈/불완전 클라이언트 안내를 실제 Google 키 없이(토큰 교환 스텁) 확인합니다. `role_block`은 학생·pending·disabled의 대여·출고·등록·실사 차단과 `demo_login` 키 생략 시 off를 확인합니다. `inventory.php`는 실 선택·스캔 확인·미확인 목록·단일 진행 세션·권한을 확인합니다. `catalog_csv.php`는 템플릿·내보내기·가져오기(신규/수정), 충돌 행 건너뜀, owner/manager 권한, xlsx 거부, UTF-8 BOM/CP949를 확인합니다. `alerts.php`는 재고 부족·연체 대여 알림과 텔레그램 발송을 HTTP 스텁으로 확인하고, 토큰 공백 실패 폐쇄·이벤트 off·중복 발송 방지·설정 CSRF·owner/manager 권한을 봅니다. `ai.php`는 프로바이더 미설정 실패 폐쇄와 AI 제안이 재고·대여·대장을 쓰지 않음을 봅니다. 실제 재고 데이터는 변경하지 않습니다.
