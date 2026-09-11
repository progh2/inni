# 개발 현황 (2026-09-10)

## 프로젝트 구조

- PHP 8.1+ / SQLite / 서버 렌더링 템플릿. 별도 패키지 매니저 없이 실행.
- `public/index.php` → `app/Router.php` → `app/Controllers/` → `templates/`.
- `app/Database.php`가 최초 실행 시 스키마 및 데모 데이터를 생성.
- 사진은 `public/uploads/`, DB는 기본 `data/inni.sqlite`.
- PRD와 ERD에는 이전 Firebase 기획이 일부 남아 있음. 현재 구현 스택은 README 기준.

## 구현된 주요 흐름

- 데모·Google OAuth 로그인, 역할 검사 및 사용자 승인.
- 품목·장비 등록, 검색, 실별 조회, 장비 대여·반납·이동.
- 사진 업로드, 장비 고장 신고, QR 스캔 및 라벨 인쇄.
- 소모품·부품 사용 출고: 품목 상세에서 위치별 수량과 사용 사유 입력.
  - 관리자·담당교사·일반교사가 출고 가능.
  - 소수 수량 지원, 재고 초과·0 이하·비정상 수량 및 다른 품목의 재고 지정 차단.
  - 조건부 UPDATE로 잔량 검사와 차감 처리. 이력 저장까지 같은 트랜잭션으로 묶음.
  - POST 및 세션 CSRF 토큰 검사. 품목 상세에 최신 이력 20개 표시.
  - 사용 출고는 반환할 대여가 아니므로 `loans` 대신 `activity_logs`에 기록.

## 이번 검증

- PHP 8.4 임시 실행 환경에서 전체 PHP 파일 45개 문법 검사 통과.
- `php tests/stock.php`: 메모리 SQLite DB를 사용하는 25개 검증 통과.
- 임시 앱 복사본·DB에서 HTTP 검증 통과: 로그인, 품목 화면, 정상 출고와 이력, 재고 부족, GET 거부, 잘못된 CSRF 토큰, 학생 권한 차단.
- 브라우저 시각 검증, 실제 카메라 스캔, Google OAuth 실연동은 미실시.
- Docker: `docker compose up --build` 경로를 정리함. 이미지에 `pdo_sqlite`/`sqlite3`/`curl`/`fileinfo`/`mbstring` 설치·빌드 검증, entrypoint가 `config.php` 생성과 `data/`·`public/uploads/` 권한을 맞춤. 에이전트 환경에서 Compose 기동을 확인하려면 Docker 소켓이 필요함.

## 다음 작업 후보

1. 최초 Google 관리자 생성: 현재 시드가 데모 사용자를 항상 만들고, OAuth는 전체 사용자 수로 최초 여부를 판단하므로 README의 ‘첫 Google 사용자 owner’ 동작과 불일치. 운영 초기화 정책과 함께 수정 필요.
2. 기존 변경 요청 전반에 POST·CSRF 검사 적용. 이번 출고 경로에는 적용했지만 기존 경로는 별도 점검 필요.
3. 장비 대여·반납의 동시 요청 및 반납 권한 범위 검토.
4. 품목 수정·재입고, 출고 취소와 취소 이력, 실사 등 남은 요구사항 구현.
5. ~~Docker의 설정 파일 생성 권한 및 필요한 PHP 확장 점검.~~ → issue #5 / Compose 경로로 처리.

## 작업 환경 참고

이번 환경은 `.git` 디렉터리가 비어 있어 Git 변경 이력·상태 조회 및 커밋이 불가능했음. 시스템 PHP가 없고 Docker 소켓에 접근할 수 없어 `/tmp`에 PHP 패키지를 풀어 검증했으며, 시스템 패키지 설치와 실제 재고 DB 변경은 하지 않음.
