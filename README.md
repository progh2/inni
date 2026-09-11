# inni

**Intelligent Inventory Navigation Interface** — 마이스터고/직업계고 기자재·비품·소모품·실 관리.

스택: **PHP 8.1+ · SQLite · Google OAuth(선택) · 로컬 사진 업로드**  
(Firebase 없음 — 배포·백업·사진 저장이 단순한 A안)

## 빠른 실행 (로컬)

```bash
cd /path/to/inni
cp config.example.php config.php   # 최초 1회 자동 복사되기도 함
php -S 0.0.0.0:8080 -t public
```

브라우저: http://localhost:8080  
→ **담당교사로 들어가기** (데모)

## Docker (로컬 스모크 / 자가 호스팅)

문서 루트는 이미지에서 `public/` 입니다. 컨테이너가 처음 뜰 때 `config.example.php` → `config.php`를 만듭니다. 예시 설정의 `demo_login`은 `true`라서 데모 로그인으로 바로 확인할 수 있습니다.

```bash
docker compose up --build
```

구버전 Docker는 `docker-compose up --build` 와 같습니다.

브라우저: http://localhost:8080  
→ **담당교사로 들어가기** (데모)

운영에서 데모 로그인을 끄려면 호스트에 `config.php`를 **먼저** 만든 뒤 `demo_login => false`로 바꾸고, `docker-compose.yml`의 주석 처리된 볼륨을 켭니다. 없는 경로를 마운트하면 Docker가 `config.php`를 디렉터리로 만들어 기동이 실패합니다.

```bash
cp config.example.php config.php
# config.php 편집 후:
#   volumes:
#     - ./config.php:/var/www/inni/config.php:ro
```

이미지에 포함·빌드 검증되는 PHP 확장: `pdo_sqlite`, `sqlite3`, `curl`, `fileinfo`, `mbstring`.

### 볼륨

| 호스트 | 컨테이너 | 내용 |
|--------|----------|------|
| `./data` | `/var/www/inni/data` | SQLite (`inni.sqlite` + WAL/SHM) |
| `./public/uploads` | `/var/www/inni/public/uploads` | 업로드 사진 |

## 서버 배포

1. PHP 8.1+ (확장: `pdo_sqlite`, `sqlite3`, `curl`, `fileinfo`, `mbstring`)
2. 문서 루트를 **`public/`** 으로 지정
3. `data/` 와 `public/uploads/` 쓰기 권한
4. `config.example.php` → `config.php` 수정

Apache 예:

```apache
DocumentRoot /var/www/inni/public
<Directory /var/www/inni/public>
  AllowOverride All
  Require all granted
</Directory>
```

Nginx 예: `root .../public;` + `try_files $uri /index.php?$query_string;`

### Google 로그인

1. Google Cloud Console에서 OAuth 클라이언트(웹) 생성  
2. 승인된 리디렉션 URI: 설정 화면 또는 로그인 안내에 표시되는  
   `.../index.php?r=auth/google/callback`  
3. `config.php`에 `client_id`, `client_secret` 입력  
4. 필요 시 `allowed_domains`에 학교 메일 도메인

첫 Google 로그인 사용자가 owner, 이후 사용자는 `pending` → 관리자 승인.

데모 시드 계정(`demo-owner`, `demo-teacher`)은 이 판정에서 제외합니다. 로컬에서 `demo_login`이 켜져 있어도 운영의 첫 Google 사용자는 owner가 됩니다. 시드만 있고 실제 owner가 없는 DB에 남아 있는 `pending` Google 계정도 다음 로그인 때 owner로 승격됩니다.

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

운영 시 `demo_login => false` 권장.

## 주요 화면

- 홈 / 찾기 / **스캔**(카메라 QR) / 실별 목록 / 빠른 등록  
- 장비 상세: 대여·반납·이동·사진·고장 신고·이력  
- 소모품·부품 상세: 위치별 사용 출고(수량·사유), 출고 이력
- 라벨 인쇄 (브라우저 QR + 인쇄)  
- 설정·사용자 승인

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
php tests/csrf.php
php tests/bootstrap_owner.php
```

메모리 DB로 수량 검증, 재고 부족, 권한, 품목·위치 일치, 출고 이력 및 저장 실패 시 롤백을 확인합니다. CSRF 검사는 유효 토큰 허용, 잘못된 토큰 거부, 반납 경로 GET 거부를 임시 SQLite로 확인합니다. `bootstrap_owner`는 데모 시드 사용자를 건너뛰고 첫 Google 계정을 owner로 두는 초기화 규칙을 확인합니다. 실제 재고 데이터는 변경하지 않습니다.
