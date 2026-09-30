// AI 없이도 알아듣는 짧은 명령(규칙). 검색창·마이크·이니가 같이 쓴다.
// 예: "멀티미터 어디 있어?" "공구실에 뭐 있어" "드릴 2대 전자실로 옮겨" "납땜 3롤 썼어" "재고 부족 보여줘"

const NUM_WORDS = { 한: 1, 하나: 1, 두: 2, 둘: 2, 세: 3, 셋: 3, 네: 4, 넷: 4, 다섯: 5, 여섯: 6, 일곱: 7, 여덟: 8, 아홉: 9, 열: 10, 스무: 20, 스물: 20 };
const COUNTER = "(?:개|대|롤|박스|상자|m|미터|kg|킬로|장|세트|권|병|통|봉|묶음|팩|ea|EA)";
// 숫자는 단위가 없어도 되지만(뒤가 빈칸·끝), 한글 수(한·두·세…)는 단위가 있어야 한다("인두기"의 "두"를 수로 보지 않게)
const QTY_RE = new RegExp(`(?:^|\\s)(?:(\\d+(?:\\.\\d+)?)\\s*(?:${COUNTER})?|(${Object.keys(NUM_WORDS).join("|")})\\s*${COUNTER})(?=\\s|$)`);
const PARTICLE = /(?:\s*(?:을|를|은|는|이|가|좀|도|만|의))+$/;

const clean = (s) => String(s || "").replace(/[?!.。,~]+$/g, "").replace(/\s+/g, " ").trim();
const strip = (s) => clean(s).replace(PARTICLE, "").trim();

function toNum(w) {
  if (w === undefined || w === null) return null;
  if (/^\d/.test(w)) return Number(w);
  return NUM_WORDS[w] ?? null;
}

function takeQty(text) {
  const m = QTY_RE.exec(text);
  if (!m) return { rest: text, qty: null };
  return { rest: (text.slice(0, m.index) + " " + text.slice(m.index + m[0].length)).replace(/\s+/g, " ").trim(), qty: toNum(m[1] ?? m[2]) };
}

const NAV = [
  [/재고\s*(가\s*)?(부족|모자란|떨어진)|부족한\s*(거|것|재고)/, { station: "search", params: { status: "low" }, label: "재고 부족" }],
  [/연체|늦은\s*(거|것|대여)|반납\s*(안|못)\s*(한|된)/, { station: "dock", params: { tab: "loans", filter: "overdue" }, label: "연체" }],
  [/내가\s*빌린|내\s*대여/, { station: "dock", params: { tab: "mine" }, label: "내가 빌린 것" }],
  [/대여\s*(중|현황|목록)|빌려\s*간\s*(거|것)/, { station: "dock", params: { tab: "loans" }, label: "대여 중" }],
  [/(고장|수리|정비)\s*(신고|목록|현황|중)?/, { station: "repair", params: {}, label: "정비" }],
  [/실사|재물\s*조사/, { station: "audit", params: {}, label: "실사" }],
  [/유통\s*기한/, { station: "search", params: { status: "expiring" }, label: "유통기한" }],
  [/노후|내용\s*연수|오래된\s*장비/, { station: "search", params: { status: "aging" }, label: "노후 장비" }],
  [/즐겨\s*찾기/, { station: "search", params: { status: "favorite" }, label: "즐겨찾기" }],
  [/(기록|이력|로그)\s*(보여|열어)?/, { station: "log", params: {}, label: "기록" }],
  [/(설정|시스템)\s*(보여|열어)?/, { station: "systems", params: {}, label: "시스템" }],
  [/(사용자|승무원|선생님\s*목록)/, { station: "crew", params: {}, label: "승무원" }],
  [/(함교|홈|처음\s*화면|첫\s*화면)/, { station: "bridge", params: {}, label: "함교" }],
  [/(장소|선내\s*지도|지도|실\s*목록)/, { station: "decks", params: {}, label: "장소" }],
];

const DUE_RE = /(오늘|내일|모레|다음\s*주|일주일|\d+\s*일|\d{1,2}\s*[월/.-]\s*\d{1,2}\s*일?)\s*(?:까지|동안)?/;

/**
 * @returns {{ type: string, ... }}
 */
export function parseIntent(input) {
  const raw = clean(input);
  if (!raw) return { type: "empty" };
  let t = raw.replace(/^(이니|inni)(야|아)?[,\s]+/i, "").replace(/\s*(해\s*줘|해\s*주세요|해\s*줄래|해\s*봐|좀)\s*$/g, "").trim();

  if (/^(스캔|바코드|qr|큐알)(\s*(해|찍어|열어|켜))?/i.test(t)) return { type: "scan" };
  if (/^(새\s*)?(물건|물품|품목)?\s*등록$/.test(t)) return { type: "add", name: "" };
  let m = /^(.+?)\s*(새로\s*)?(등록|추가\s*등록)(?:\s*(?:해|할래|하자))?/.exec(t);
  if (m && !/입고/.test(t)) {
    const q = takeQty(strip(m[1]));
    return { type: "add", name: strip(q.rest), qty: q.qty };
  }

  // 화면 이동
  if (/(보여|열어|가자|이동|띄워|보고\s*싶)/.test(t) || t.length <= 8) {
    for (const [re, nav] of NAV) if (re.test(t) && !/어디/.test(t)) return { type: "nav", ...nav };
  }

  // 장소에 뭐 있어
  m = /^(.+?)(?:에|에는|엔|안에)\s*(?:뭐|무엇|뭔가|어떤\s*(?:게|것))\s*(?:있|들어)/.exec(t);
  if (m) return { type: "contents", place: strip(m[1]) };

  // 찾기
  m = /^(.+?)\s*(?:어디(?:에)?\s*(?:있|두었|뒀|놨|갔)|어딨|어디야|어디지|어디에요|위치|찾아|찾기|있어\??$|있나|있니|몇\s*(?:개|대))/.exec(t);
  if (m) return { type: "find", query: strip(m[1]) };

  // 옮기기: X(를) Y(으)로 옮겨
  // 옮길 곳은 "(으)로" 바로 앞 낱말, 나머지는 물건(과 수량)
  m = /^(.+)\s+(\S+?)(?:으로|로)\s*(?:옮겨|이동|가져다|갖다|보내)/.exec(t);
  if (m) {
    const q = takeQty(strip(m[1]).replace(/\s*(?:을|를)$/, ""));
    return { type: "move", item: strip(q.rest), count: q.qty, to: strip(m[2]) };
  }

  // 빌려주기: X(를) 누구(에게/한테) (기한까지) 빌려줘
  m = /^(.+?)\s*(?:을|를)?\s+(.+?)(?:에게|한테|께)\s*(.*?)\s*(?:빌려|대여|내줘|내 줘)/.exec(t);
  if (m) {
    const due = DUE_RE.exec(m[3] || "");
    const q = takeQty(strip(m[1]));
    return { type: "loan", item: strip(q.rest), count: q.qty, borrower: strip(m[2]), due: due ? due[1] : "" };
  }
  m = /^(.+?)\s*(?:을|를)?(?:\s+((?:오늘|내일|모레|다음\s*주|일주일|\d+\s*일|\d{1,2}\s*[월/.-]\s*\d{1,2}\s*일?)\s*(?:까지|동안)?))?\s*(?:빌려\s*줘|빌려\s*갈게|빌릴게|대여)/.exec(t);
  if (m) {
    const due = DUE_RE.exec(m[2] || "");
    const q = takeQty(strip(m[1]));
    return { type: "loan", item: strip(q.rest), count: q.qty, borrower: "", due: due ? due[1] : "" };
  }

  // 반납
  m = /^(.+?)\s*(?:이|가)?\s*빌린\s*(?:거|것)?\s*(?:을|를)?\s*반납/.exec(t);
  if (m) return { type: "return", borrower: strip(m[1]), item: "" };
  m = /^(.+?)\s*(?:을|를)?\s*(?:반납|돌려\s*받|돌려\s*줬|가져왔)/.exec(t);
  if (m) return { type: "return", item: strip(m[1]), borrower: "" };

  // 사용·입고
  m = /^(.+?)\s*(?:을|를)?\s*(?:썼|사용|출고|소모|꺼내\s*썼|다\s*썼)/.exec(t);
  if (m) {
    const q = takeQty(strip(m[1]));
    return { type: "use", item: strip(q.rest), qty: q.qty };
  }
  m = /^(.+?)\s*(?:을|를|이|가)?\s*(?:입고|들어왔|채워|채웠|보충|샀어|구입)/.exec(t);
  if (m) {
    const q = takeQty(strip(m[1]));
    return { type: "restock", item: strip(q.rest), qty: q.qty };
  }

  for (const [re, nav] of NAV) if (re.test(t) && t.length <= 14) return { type: "nav", ...nav };

  // 명령이 아니면 찾기
  return { type: "search", query: raw };
}

// 사람이 말한 것처럼 보이는지(검색창에서 "이니에게 맡기기"를 먼저 보여 줄지)
export function looksLikeCommand(input) {
  const t = clean(input);
  if (t.length < 4) return false;
  return /(어디|어딨|찾아|있어|있나|있니|옮겨|이동|빌려|대여|반납|썼|사용|입고|채워|들어왔|등록|보여|열어|알려|뭐\s*있|몇\s*(개|대)|누가|언제|해\s*줘|할래|\?)/.test(t);
}
