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

### 백업

- DB: `data/inni.sqlite` (+ `-wal`/`-shm` 있으면 함께)
- 사진: `public/uploads/`

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
```

메모리 DB로 수량 검증, 재고 부족, 권한, 품목·위치 일치, 출고 이력 및 저장 실패 시 롤백을 확인합니다. 실제 재고 데이터는 변경하지 않습니다.
