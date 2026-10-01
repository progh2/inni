# Firebase 로그인 설정 (학교 메일로 로그인)

inni는 **Firebase Authentication을 "누구인지 확인"하는 데만** 쓴다. 물품 데이터는 전부 학교 NAS에 있고 Firebase로 가지 않는다.
무료(Spark) 요금제로 충분하다.

로그인 방법은 두 가지이고, 둘 다 켜 두는 것을 권한다.

| 방법 | 누가 쓰나 | 사용자가 하는 일 |
|------|-----------|------------------|
| **구글 계정** | 학교가 구글 워크스페이스(학교 도메인 구글 계정)를 쓸 때 | [학교 구글 계정으로 승선] → 계정 고르기 |
| **메일 링크** | 구글 계정이 아닌 학교·교육청 메일(예: `@sen.go.kr`) | 메일 주소 입력 → 받은 메일의 링크 누르기(비밀번호 없음) |

> 어느 쪽이든 **메일함의 주인만** 들어올 수 있다(구글이 확인했거나, 그 메일로 온 링크를 눌렀거나).
> 그다음 inni가 **학교 도메인(ALLOWED_DOMAINS)** 과 **승인** 으로 한 번 더 거른다.

---

## 1. Firebase 프로젝트 만들기 (5분)

1. <https://console.firebase.google.com> → **프로젝트 추가** → 이름(예: `hanbit-inni`). Google 애널리틱스는 **사용 안 함**으로 두어도 된다.
2. 프로젝트 개요 → **웹 앱 추가(</> 아이콘)** → 앱 닉네임 `inni` → 등록.
   - "Firebase Hosting 설정"은 체크하지 않는다.
3. 화면에 나온 설정에서 두 값을 적어 둔다.

   ```js
   const firebaseConfig = {
     apiKey: "AIzaSy....",          // → FIREBASE_API_KEY
     authDomain: "hanbit-inni.firebaseapp.com",
     projectId: "hanbit-inni",      // → FIREBASE_PROJECT_ID
     ...
   };
   ```

   `apiKey`는 비밀번호가 아니다(브라우저에 공개되는 식별자). 그래도 `.env`에만 두고 git에는 올리지 않는다.

## 2. 로그인 방법 켜기

Firebase 콘솔 → **Authentication** → **시작하기** → **로그인 방법(Sign-in method)** 탭.

### 구글
1. **Google** → 사용 설정 → 프로젝트 지원 이메일 선택 → 저장.

### 메일 링크(구글 계정이 아닌 학교 메일)
1. **이메일/비밀번호** → **사용 설정**을 켜고,
2. 그 아래 **이메일 링크(비밀번호가 없는 로그인)** 도 **켠다** → 저장.
   - "이메일/비밀번호"를 켜야 링크 로그인이 켜진다. inni 화면에는 비밀번호 칸이 없다.
3. (권장) Authentication → **템플릿** → 언어를 **한국어**로.

> 한 가지만 쓰려면 `.env`의 `LOGIN_METHODS`를 `google` 또는 `email`로 둔다. 로그인 화면에서 해당 버튼만 보인다.

## 3. 승인된 도메인 추가 (가장 자주 빠뜨리는 단계)

Authentication → **설정** → **승인된 도메인** → **도메인 추가**

- 선생님들이 inni를 여는 주소의 **호스트 이름**을 넣는다. 예) `inni.hanbit.synology.me`, `nas.hanbit.hs.kr`
- `localhost`는 기본으로 들어 있다(개발용).
- 포트·`https://`는 빼고 이름만 넣는다.

빠뜨리면 로그인 때 "이 주소가 Firebase 승인된 도메인에 없습니다"가 뜬다.

> **IP 주소(예: `192.168.0.10`)로 여는 것은 권하지 않는다.** 휴대폰 카메라·마이크도 https가 아니면 막힌다.
> NAS의 DDNS 주소 + 인증서(무료)로 https를 먼저 만든다 → [NAS 배포 §3](nas-deploy.md#3-https-휴대폰-카메라마이크로그인에-필요).

## 4. inni에 값 넣기

NAS의 inni 폴더에서 `.env`를 연다(없으면 `cp .env.example .env`).

```ini
AUTH_MODE=firebase
FIREBASE_PROJECT_ID=hanbit-inni
FIREBASE_API_KEY=AIzaSy....
# FIREBASE_AUTH_DOMAIN 은 비워 두면 hanbit-inni.firebaseapp.com

# 처음 관리자(여러 명이면 쉼표). 이 사람은 승인 없이 바로 관리자
ADMIN_EMAILS=kim@hanbit.hs.kr

# 들어올 수 있는 메일 도메인(쉼표). 비우면 누구나 "승인 대기"로 들어온다
ALLOWED_DOMAINS=hanbit.hs.kr,sen.go.kr

# 로그인 방법(google, email)
LOGIN_METHODS=google,email

# 라벨 QR 에 들어갈 주소(https)
PUBLIC_URL=https://inni.hanbit.synology.me
```

적용: `docker compose up -d` (값만 바꿨으면 다시 빌드할 필요 없음).

## 5. 처음 로그인과 승인 흐름

1. `ADMIN_EMAILS`의 선생님이 먼저 로그인 → 곧바로 **관리자**. 처음 설정 화면(빈 학교/예시 데이터/예전 inni 가져오기)이 뜬다.
2. 다른 선생님이 로그인하면 **승인 대기**로 들어온다(메일 링크 로그인은 처음 한 번 이름을 적는다).
3. 관리자·담당교사가 **승무원(08)** 화면에서 역할을 고르고 승인한다.
   - 미리 알면 **[미리 등록]**으로 메일·이름·역할을 넣어 두면, 그 사람은 로그인하자마자 바로 쓴다.
   - 승무원 → 로그인·권한 정책에서 "허용 도메인 계정은 승인 없이 바로"를 켜면 승인 단계를 없앨 수 있다.
4. 이름은 오른쪽 위 내 동그라미 → **이름 바꾸기**로 스스로 고칠 수 있다(대여 기록에 보이는 이름).

## 6. 문제 해결

| 증상 | 원인·해결 |
|------|-----------|
| "승인된 도메인에 없습니다" | §3에 지금 주소의 호스트 이름 추가 |
| "이 로그인 방법이 꺼져 있습니다" | §2에서 구글 또는 이메일 링크를 켜지 않음. 또는 `LOGIN_METHODS`와 불일치 |
| 메일이 안 온다 | 스팸함 확인. 교육청 메일은 외부 메일이 늦게 오기도 한다(몇 분). `noreply@<프로젝트>.firebaseapp.com`을 허용 목록에 |
| 링크를 눌렀더니 주소를 다시 묻는다 | 링크를 **요청한 기기와 다른 기기**에서 열었다. 같은 메일 주소를 넣으면 된다 |
| "링크가 만료됐거나 이미 쓰였어요" | 링크는 한 번만 쓸 수 있다. 다시 받기 |
| "학교 계정(@…)으로 로그인하세요" | `ALLOWED_DOMAINS` 밖의 메일. 관리자로 쓸 개인 메일이면 `ADMIN_EMAILS`에 넣는다 |
| 팝업이 막혔다 | 주소창 오른쪽의 팝업 차단 해제(구글 로그인) |
| 로그인 후 계속 로그인 화면 | https 역방향 프록시 뒤라면 `TRUST_PROXY` 기본값 유지. 브라우저가 쿠키를 막는지 확인 |
| 컨테이너가 안 뜨고 로그에 "FIREBASE_… 가 비어 있습니다" | `.env` 값 누락. `docker compose logs inni` 확인 |

## 7. 보안 메모

- 서버는 Firebase ID 토큰을 **구글 공개키로 직접 검증**한다(서명 RS256, `aud`=프로젝트, `iss`, 만료, 메일 확인 여부). 외부 라이브러리 없이 Node `crypto`로.
- 검증이 끝나면 Firebase 쪽 로그인은 바로 정리하고, 이 서버의 **HttpOnly 세션 쿠키**만 쓴다(기본 30일, `SESSION_DAYS`). 세션 값은 DB에 해시로만 남고, 사용 중지하면 즉시 끊긴다.
- 로그인 방법은 서버에서도 `LOGIN_METHODS`로 한 번 더 막는다(토큰의 `sign_in_provider`가 `google.com`/`password`인지 확인).
