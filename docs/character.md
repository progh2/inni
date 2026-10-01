# 이니 캐릭터 그림

이니는 **ChatGPT 이미지 모델(OpenAI 이미지 API, `chatgpt-image-latest`)** 로 그린 치비(2.5등신) 보급관 견습생이다.
표정마다 그림이 한 장씩 있고(`web/img/inni/{표정}.webp`), 작은 자리용 얼굴 그림(`face-{표정}.webp`)이 따로 있다.
움직임(둥실·통통·흔들림·스캔 빛줄 등)은 CSS(`web/css/character.css`)가 맡는다.

| 표정 | 쓰는 때 |
|------|---------|
| `idle` 평소 | 기본 |
| `happy` 기쁨 | 작업 성공, AI 연결됨 |
| `think` 생각 | 이니가 답을 찾는 중 |
| `talk` 말하기 | 답을 말하는 중 |
| `alert` 경고 | 경보·주의 |
| `error` 오류 | 실패 |
| `listen` 듣기 | 마이크로 듣는 중 |
| `scan` 스캔 | 바코드·QR 스캔 |
| `sleep` 쉬기 | AI 꺼짐 |

## 다시 그리기

1. **그리기**(OpenAI API 키 필요, 표정 9장에 몇 분·몇 달러):

   ```bash
   OPENAI_API_KEY=sk-… node scripts/gen-character.mjs              # 기준 그림 + 모든 표정
   OPENAI_API_KEY=sk-… node scripts/gen-character.mjs scan sleep   # 일부만 다시(기준 그림 idle.png 를 참고로)
   ```

   - 먼저 `idle`(기준 그림)을 만들고, 나머지는 그 그림을 참고 이미지로 넘겨 **같은 캐릭터**로 그린다.
   - 결과는 `/tmp/inni-character/*.png`(1024×1536, 투명 배경). 위치는 `OUT=` 로 바꾼다.
   - 모델은 `chatgpt-image-latest` → `gpt-image-2` → `gpt-image-1.5` → `gpt-image-1` 순으로 시도한다(`MODEL=` 로 고정).
   - 캐릭터 설정·표정 설명은 스크립트 안의 `CHARACTER`·`MOODS` 를 고친다.

2. **화면용으로 줄이기**: 표정마다 키·발끝·다리 중심을 맞추고(표정이 바뀌어도 들썩이지 않게) WebP 로 저장한다.
   브라우저 캔버스로 처리하므로 Playwright 가 있는 곳에서 돌린다.

   ```bash
   docker run --rm -v "$PWD:/repo" -v /tmp/inni-character:/in -w /repo mcr.microsoft.com/playwright:v1.60.0-noble \
     sh -c "npm i --no-save playwright@1.60.0 >/dev/null && node scripts/pack-character.mjs /in web/img/inni"
   ```

3. 화면을 새로 고치면 바뀐 이니가 보인다(빌드 없음).

그림은 OpenAI 이용 약관에 따라 만든 사람(학교)이 쓸 수 있다. 다른 캐릭터로 바꾸고 싶으면 같은 파일 이름으로 그림만 바꿔 넣어도 된다.
