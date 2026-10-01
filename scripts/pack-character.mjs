// gen-character.mjs 가 만든 PNG(1024×1536, 투명)를 화면용 WebP 로 줄인다.
//  - 표정마다 키(머리 꼭대기~발끝)·발끝 높이·다리 중심을 맞춘다 → 표정이 바뀌어도 캐릭터가 들썩이지 않는다
//  - {표정}.webp(600×900) 과 얼굴만 자른 face-{표정}.webp(256×256)
// 브라우저 캔버스로 처리하므로 Playwright 가 있는 곳에서 돌린다(docs/character.md):
//   node scripts/pack-character.mjs /tmp/inni-character web/img/inni
import fs from "node:fs";
import path from "node:path";
import { chromium } from "playwright";

const [IN = "/tmp/inni-character", OUT = "web/img/inni"] = process.argv.slice(2);
const MOODS = ["idle", "happy", "think", "talk", "alert", "error", "listen", "scan", "sleep"];
fs.mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch();
const page = await browser.newPage();
for (const mood of MOODS) {
  const file = path.join(IN, `${mood}.png`);
  if (!fs.existsSync(file)) { console.log(`  - ${mood}: 없음(건너뜀)`); continue; }
  const src = `data:image/png;base64,${fs.readFileSync(file).toString("base64")}`;
  const out = await page.evaluate(async (s) => {
    const W = 600;
    const H = 900;
    const FEET = 872; // 발끝 높이
    const TALL = 770; // 머리 꼭대기~발끝
    const img = new Image();
    img.src = s;
    await img.decode();
    const c = document.createElement("canvas");
    c.width = img.width;
    c.height = img.height;
    const g = c.getContext("2d", { willReadFrequently: true });
    g.drawImage(img, 0, 0);
    const a = g.getImageData(0, 0, c.width, c.height).data;
    const op = (x, y) => a[(y * c.width + x) * 4 + 3] > 40;
    // 작은 효과(반짝이·점·Z·빛줄기)는 빼고 몸통만 재려고 줄마다 충분히 찬 곳만 센다
    const thr = Math.round(c.width * 0.02);
    const rowCount = (y) => { let n = 0; for (let x = 0; x < c.width; x++) if (op(x, y)) n++; return n; };
    let top = 0;
    while (top < c.height && rowCount(top) < thr) top++;
    let bottom = c.height - 1;
    while (bottom > top && rowCount(bottom) < thr) bottom--;
    const centerX = (y0, y1) => {
      let sx = 0;
      let n = 0;
      for (let y = y0; y <= y1; y++) for (let x = 0; x < c.width; x++) if (op(x, y)) { sx += x; n++; }
      return n ? sx / n : c.width / 2;
    };
    const h = bottom - top;
    const legsX = centerX(Math.round(bottom - h * 0.28), bottom);
    const headX = centerX(top, Math.round(top + h * 0.3));
    const k = TALL / h;
    const dx = W / 2 - legsX * k;
    const dy = FEET - bottom * k;
    const body = document.createElement("canvas");
    body.width = W;
    body.height = H;
    body.getContext("2d").drawImage(img, dx, dy, img.width * k, img.height * k);
    // 얼굴: 2.5등신이라 머리가 키의 40% 남짓
    const side = Math.round(TALL * 0.46);
    const fx = Math.round(W / 2 + (headX - legsX) * k - side / 2);
    const fy = Math.round(FEET - TALL - side * 0.04);
    const face = document.createElement("canvas");
    face.width = face.height = 256;
    face.getContext("2d").drawImage(body, fx, fy, side, side, 0, 0, 256, 256);
    return {
      body: body.toDataURL("image/webp", 0.86),
      face: face.toDataURL("image/webp", 0.88),
      info: `키 ${h}px → ${TALL} (×${k.toFixed(3)})`,
    };
  }, src);
  for (const [name, url] of [[`${mood}.webp`, out.body], [`face-${mood}.webp`, out.face]]) {
    fs.writeFileSync(path.join(OUT, name), Buffer.from(url.split(",")[1], "base64"));
  }
  const kb = (f) => Math.round(fs.statSync(path.join(OUT, f)).size / 1024);
  console.log(`  ✓ ${mood}: ${out.info} · ${kb(`${mood}.webp`)}KB + 얼굴 ${kb(`face-${mood}.webp`)}KB`);
}
await browser.close();
