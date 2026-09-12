# 개발 현황 (2026-09-12)

## 프로젝트 구조

- PHP 8.1+ / SQLite / 서버 렌더링 템플릿. 별도 패키지 매니저 없이 실행.
- `public/index.php` → `app/Router.php` → `app/Controllers/` → `templates/`.
- `app/Database.php`가 최초 실행 시 스키마 및 데모 데이터를 생성.
- 사진은 `public/uploads/`, DB는 기본 `data/inni.sqlite`.
- PRD와 ERD에는 이전 Firebase 기획이 일부 남아 있음. 현재 구현 스택은 README 기준.

## 구현된 주요 흐름

- 데모·Google OAuth 로그인, 역할 검사 및 사용자 승인.
- 품목·장비 등록, 검색, **품목 목록(검색 없이 브라우즈, 유형·재고부족 필터)**, 실별 조회, 장비 대여·반납·이동.
- 사진 업로드, 장비 고장 신고, QR 스캔 및 라벨 인쇄.
- 소모품·부품 사용 출고: 품목 상세에서 위치별 수량과 사용 사유 입력.
  - 관리자·담당교사·일반교사가 출고 가능.
  - 소수 수량 지원, 재고 초과·0 이하·비정상 수량 및 다른 품목의 재고 지정 차단.
  - 조건부 UPDATE로 잔량 검사와 차감 처리. 이력 저장까지 같은 트랜잭션으로 묶음.
  - POST 및 세션 CSRF 토큰 검사(`Csrf::requirePost`, 품목 상세에 최신 이력 20개 표시).
  - 사용 출고는 반환할 대여가 아니므로 `loans` 대신 `activity_logs`에 기록.
- 품목 수정(owner/manager): 이름·단위·최소재고·제조사·태그·메모·사진·즐겨찾기. 유형/QR/재고 수량은 바꾸지 않음.
- 재입고(owner/manager): 기존 로트 증가 또는 새 위치에 `stock_lots` 생성. `activity_logs.action=restock`.
- 출고 취소(owner/manager/teacher): `stock_issue_cancels.issue_log_id` UNIQUE로 이중 취소 실패 폐쇄. 수량 복원과 `cancel_issue` 이력을 같은 트랜잭션으로.
- 가벼운 실사(owner/manager): 실 선택 → 예상 장비·품목 목록 → #7과 같은 카메라/코드 입력으로 확인 → 종료 시 미확인 목록. 진행 중 세션은 1건. 이어하기·텔레그램·엑셀은 없음.
- 품목 CSV(owner/manager, `canWrite`): 더보기·설정에서 템플릿/목록 내려받기, POST+CSRF 업로드로 신규·수정. 충돌·오류 행은 건너뛰고 이유를 보여 줌. **CSV UTF-8** (Excel CP949도 읽음). xlsx/에듀파인 파일 동기화/실사 연동/텔레그램은 없음. 사업명·예산연도 열 포함.
- 구입 사업예산(#32): `catalog_items`/`assets`에 `budget_program`(자유 입력) + `budget_year`(YYYY). 기존 DB는 `Database::migrate`/`ensureGuards`로 컬럼 추가. 빈 값 허용. 등록·수정·상세·CSV 라운드트립. #29 품목 목록에서 표시·GET 필터(`budget_program`, `budget_year`). #30 기자재 현황 보드는 main에 없음 — `Budget::queryFilters`/`filterSql` 훅만.
- 알림(owner/manager 설정): 재고 부족(소모품·부품, 수량 < 최소재고)·연체 대여. 홈/대여 목록에서 연체 표시. 텔레그램 봇 푸시는 `config.php`의 `telegram.bot_token`이 있을 때만. 채팅 ID·이벤트 on/off는 설정 화면(POST+CSRF). 토큰 공백은 실패 폐쇄(연결 필요). 같은 품목/대여 중복 발송 없음. 실사 종료·AI·에듀파인은 연동하지 않음.
- AI Provider 자리(제안 전용): OpenAI / Upstage / Ollama 추상화. 키는 `config.php`의 `ai.api_key`만. 폼·SQLite·git에 키 없음. 미설정은 실패 폐쇄(설정에 연결 필요, 더보기 AI 메뉴 숨김). `Ai::suggest`는 초안만. 재고·대여·대장 쓰기 경로 없음. 챗봇·실사 연동 없음.
- 품목 목록(로그인 사용자, 일반교사 포함): `items`에서 검색어 없이 전체 브라우즈. GET 필터 `type`, `low_stock`, `budget_program`, `budget_year`. 재고부족은 알림과 같이 소모품·부품·수량 < 최소재고. 찾기(검색어 필수)는 유지.

## 이번 검증

- PHP 8.4 임시 실행 환경에서 전체 PHP 파일 45개 문법 검사 통과.
- `php tests/stock.php`: 출고·재입고·출고 취소 수량/권한/롤백 검증.
- `php tests/catalog.php`: 품목 수정 필드·권한·이력 롤백 검증.
- `php tests/catalog_list.php`: 품목 전체 브라우즈, 유형·재고부족·사업예산 필터, 잘못된 유형/예산 무시, 찾기 UX 유지, 일반교사 조회(canWrite 없음).
- `php tests/budget.php`: 사업명·예산연도 파싱, 마이그레이션, 저장/비우기, CSV 라운드트립, 목록 필터 훅.
- `php tests/csrf.php`: 세션 CSRF 허용/거부, GET 거부, 반납·재입고·출고 취소 경로 실패 폐쇄 검증.
- `php tests/loan.php`: 대여·반납 조건부 UPDATE, 이중/동시 요청 실패 폐쇄, 역할별 반납 범위, 이력 실패 롤백.
- `php tests/role_block.php`: 학생·pending·disabled의 등록·대여·출고·실사 차단, `demo_login` 키 생략 시 off.
- `php tests/inventory.php`: 실 선택·스캔/코드 확인·미확인 목록·단일 진행 세션·권한·이력 롤백.
- `php tests/catalog_csv.php`: 품목 CSV 템플릿·내보내기·가져오기, 충돌 건너뜀, 역할, xlsx 거부.
- `php tests/alerts.php`: 재고 부족·연체 텔레그램(HTTP 스텁), 토큰 공백 실패 폐쇄, 이벤트 off, 중복 방지, 설정 CSRF, owner/manager. 실제 봇 토큰 없음.
- `php tests/ai.php`: 프로바이더 미설정/불완전/미지원 실패 폐쇄, 설정 시 제안만, 재고·대여·대장 무변경, 쓰기 훅 없음, 설정 폼에 키 필드 없음. 실제 API 키 없음.
- 임시 앱 복사본·DB에서 HTTP 검증 통과: 로그인, 품목 화면, 정상 출고와 이력, 재고 부족, GET 거부, 잘못된 CSRF 토큰, 학생 권한 차단.
- 브라우저 시각 검증, 실제 카메라 스캔, Google Cloud Console 실연동(실제 client_id/secret)은 미실시.
- Google OAuth: 승인된 리디렉션 URI는 `{base_url}/index.php?r=auth/google/callback`로 고정. 로그인·설정에 동일 문자열 표시. `allowed_domains`는 비면 인증된 메일 허용, 값이 있으면 정확 일치 실패 폐쇄. `email_verified` 필수. `php tests/google_oauth.php`가 토큰 교환을 스텁한다.
- Docker: `docker compose up --build` 경로를 정리함. `php:8.3-apache`에 이미 있는 `pdo_sqlite`/`sqlite3`/`curl`/`fileinfo`/`mbstring`을 빌드에서 재설치하지 않고 검증만 함. entrypoint가 `config.php` 생성과 `data/`·`public/uploads/` 권한을 맞춤.

## 다음 작업 후보

1. ~~최초 Google 관리자 생성: 시드 데모 사용자와 OAuth 최초 owner 판정 불일치.~~ 데모 시드 계정은 첫 Google=owner 카운트에서 제외. `demo_login` 로컬 데모는 유지. `tests/bootstrap_owner.php`.
2. ~~기존 변경 요청 전반에 POST·CSRF 검사 적용.~~ 대여·반납·이동·등록·설정·사용자 승인·고장 신고·사진 업로드·출고에 `Csrf::requirePost()` 적용. 스캔 조회·라벨 인쇄·데모 로그인·로그아웃 GET은 상태 변경이 아니거나 인증 진입이라 제외.
3. ~~장비 대여·반납의 동시 요청 및 반납 권한 범위 검토.~~ `Loan::checkout`/`checkin`이 `BEGIN IMMEDIATE` + 상태 조건부 UPDATE + rowCount로 이중 대여·반납을 실패 폐쇄. 반납은 `Auth::canReturn`(owner/manager/teacher=전체, student=본인). 열린 대여는 자산당 1건 unique index.
4. ~~품목 수정·재입고, 출고 취소와 취소 이력.~~ issue #9.
4b. ~~가벼운 실사 MVP.~~ issue #10. 엑셀·알림·AI·이어하기는 별도.
4c. ~~품목 CSV 임포트/익스포트.~~ issue #11. xlsx·에듀파인 파일 동기화·실사 연동·텔레그램은 별도.
4d. ~~알림(부족·연체)+텔레그램 봇.~~ issue #12. AI·에듀파인·실사 연동은 별도.
4e. ~~AI Provider 자리(제안 전용).~~ issue #13. 실제 호출·챗봇·재고 자동변경·에듀파인·실사 연동은 별도.
5. ~~Docker의 설정 파일 생성 권한 및 필요한 PHP 확장 점검.~~ → issue #5 / Compose 경로로 처리.
6. ~~Google OAuth 리다이렉트 URI·allowed_domains.~~ 키 없이 단위/스텁 검사까지. Console 실스모크는 사람 자격 증명 필요 (issue #6).
7. ~~demo_login 운영 off 기본·역할 차단 스모크.~~ 로컬/Docker 예시는 `true`, 운영 예시·키 생략은 `false`. 학생·pending·disabled 쓰기는 `canWrite`/`canLoan`/`canReturn` + `tests/role_block.php` (issue #4).
8. ~~재료·품목 목록 (검색 없이 브라우즈).~~ issue #29. 찾기(검색어 필수)는 유지. 목록 UI 정돈은 후속.

## 작업 환경 참고

이번 환경은 `.git` 디렉터리가 비어 있어 Git 변경 이력·상태 조회 및 커밋이 불가능했음. 시스템 PHP가 없고 Docker 소켓에 접근할 수 없어 `/tmp`에 PHP 패키지를 풀어 검증했으며, 시스템 패키지 설치와 실제 재고 DB 변경은 하지 않음.
