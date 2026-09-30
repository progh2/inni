// 한글 검색 도우미. 브라우저와 서버가 같은 파일을 쓴다.
// - normalize: 띄어쓰기·기호를 빼고 소문자로 ("디지털 멀티미터" → "디지털멀티미터")
// - choseong: 초성만 뽑는다 ("오실로스코프" → "ㅇㅅㄹㅅㅋㅍ")
// - editDistance: 짧은 오타를 봐준다

const CHO = ["ㄱ", "ㄲ", "ㄴ", "ㄷ", "ㄸ", "ㄹ", "ㅁ", "ㅂ", "ㅃ", "ㅅ", "ㅆ", "ㅇ", "ㅈ", "ㅉ", "ㅊ", "ㅋ", "ㅌ", "ㅍ", "ㅎ"];
const STRIP = /[\s\-_.·•,/\\()[\]{}'"`~!@#$%^&*+=|:;<>?…]+/g;

export function normalize(s) {
  return String(s ?? "").normalize("NFC").toLowerCase().replace(STRIP, "");
}

// 낱말 단위로 나눈다. 검색어의 각 낱말은 모두 맞아야 한다(AND).
export function tokens(s) {
  return String(s ?? "")
    .normalize("NFC")
    .toLowerCase()
    .split(/[\s,]+/)
    .map((t) => t.replace(STRIP, ""))
    .filter(Boolean);
}

export function choseong(s) {
  let out = "";
  for (const ch of String(s ?? "").normalize("NFC")) {
    const c = ch.charCodeAt(0);
    if (c >= 0xac00 && c <= 0xd7a3) out += CHO[Math.floor((c - 0xac00) / 588)];
    else if (c >= 0x3131 && c <= 0x314e) out += ch;
    else if (/[a-z0-9]/i.test(ch)) out += ch.toLowerCase();
  }
  return out;
}

// 검색어가 초성만으로 되어 있는지 ("ㅇㅅㄹ")
export function isChoseong(q) {
  return /^[ㄱ-ㅎ]+$/.test(String(q || ""));
}

// 한계를 넘으면 max+1 을 돌려준다(빨리 끝낸다).
export function editDistance(a, b, max = 2) {
  a = String(a);
  b = String(b);
  if (Math.abs(a.length - b.length) > max) return max + 1;
  let prev = Array.from({ length: b.length + 1 }, (_, i) => i);
  for (let i = 1; i <= a.length; i++) {
    const cur = [i];
    let best = cur[0];
    for (let j = 1; j <= b.length; j++) {
      const cost = a[i - 1] === b[j - 1] ? 0 : 1;
      cur[j] = Math.min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + cost);
      if (cur[j] < best) best = cur[j];
    }
    if (best > max) return max + 1;
    prev = cur;
  }
  return prev[b.length];
}

// 받침 유무로 조사를 고른다: josa("드릴", "을", "를") → "드릴을"
export function josa(word, withBatchim, withoutBatchim) {
  const w = String(word ?? "");
  const last = w.charCodeAt(w.length - 1);
  if (!(last >= 0xac00 && last <= 0xd7a3)) return w + withoutBatchim;
  const has = (last - 0xac00) % 28 !== 0;
  return w + (has ? withBatchim : withoutBatchim);
}
