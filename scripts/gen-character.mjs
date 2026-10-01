// 이니 캐릭터 그림을 OpenAI 이미지 API(ChatGPT 이미지 모델)로 그린다.
//   OPENAI_API_KEY=… node scripts/gen-character.mjs            # 모든 표정
//   OPENAI_API_KEY=… node scripts/gen-character.mjs happy think # 일부만(기준 그림이 이미 있을 때)
// 1) 기준 그림(평소)을 만들고 2) 그 그림을 참고로 나머지 표정을 같은 캐릭터로 그린다.
// 결과는 OUT(기본 /tmp/inni-character)에 투명 배경 PNG 로 남는다. 화면용 WebP 로 줄이는 건 docs/character.md 참고.
// 선택: MODEL(기본: chatgpt-image-latest → gpt-image-2 → gpt-image-1.5 → gpt-image-1 순으로 시도), QUALITY(기본 high)
import fs from "node:fs";
import path from "node:path";

const KEY = process.env.OPENAI_API_KEY;
if (!KEY) {
  console.error("OPENAI_API_KEY 가 없습니다");
  process.exit(1);
}
const OUT = process.env.OUT || "/tmp/inni-character";
const QUALITY = process.env.QUALITY || "high";
const MODELS = process.env.MODEL ? [process.env.MODEL] : ["chatgpt-image-latest", "gpt-image-2", "gpt-image-1.5", "gpt-image-1"];
fs.mkdirSync(OUT, { recursive: true });

// 캐릭터 설정(모든 그림에 같이 들어간다)
const CHARACTER = `INNI, the friendly AI quartermaster (supply officer) of a spaceship-themed school equipment "supply bridge".
A cheerful young Korean cadet girl drawn as a chibi (about 2.5 heads tall: big head, small body).
Hair: short navy-blue bob with soft cyan highlights and side-swept bangs; a small amber hexagon hair clip on the right side.
Face: big round amber-brown eyes with bright sparkles, small nose, rosy cheeks, friendly expression.
Outfit: white and pale-blue sci-fi officer jacket with thin glowing cyan trim lines and a navy V collar, a small glowing amber hexagon badge on the left chest,
navy shorts, white socks, navy-and-white short boots. A white wireless headset with a cyan light on the right ear and a tiny boom microphone.
Prop: a small glowing cyan inventory tablet.`;
const STYLE = `Style: soft watercolor anime illustration with clean thin line art and gentle shading, warm and cute, high detail, consistent proportions.
Composition: full body from the top of the head to the shoes, standing, front three-quarter view, centered, generous empty margin on every side.
Background: fully transparent. No text, no letters, no numbers, no logos, no floor, no ground shadow, no frame.`;
const SAME = `Use the reference image as the exact same character: keep the identical face, hairstyle, hair clip, headset, outfit, colors, art style,
body proportions, size in the frame and centered position. Only change the pose and facial expression as described.`;

const MOODS = {
  idle: "Pose: relaxed and ready, holding the tablet against the chest with both hands, gentle closed-mouth smile, looking at the viewer.",
  happy: "Pose: delighted, eyes closed in a big happy smile (curved happy eyes), open-mouth smile, one hand waving high above the shoulder, tablet tucked under the other arm, a few tiny sparkles around.",
  think: "Pose: thinking, index finger touching the chin, eyes looking up and to the side, small pursed mouth, head slightly tilted, tablet held in the other hand, three tiny cyan dots floating near the head.",
  talk: "Pose: explaining cheerfully, mouth open mid-sentence, one hand open and gesturing outward, the other hand holding the tablet, bright eyes looking at the viewer.",
  alert: "Pose: startled and concerned, eyes wide open, eyebrows raised with worry, small open mouth, a small sweat drop on the forehead, holding up the tablet whose screen glows red-orange.",
  error: "Pose: apologetic, eyes squeezed shut like > <, embarrassed wobbly smile, both hands pressed together in front of the chest as if saying sorry, a small sweat drop, tablet tucked under one arm.",
  listen: "Pose: listening carefully, leaning slightly forward, one hand cupped behind the ear next to the headset, curious attentive eyes, the headset light glowing, two small curved sound-wave lines near the ear.",
  scan: "Pose: scanning, holding a small handheld barcode scanner forward with one hand, a thin cyan scanning beam coming out of it, focused determined eyes, tablet in the other hand. Standing with feet together like the reference, absolutely no shadow under the feet.",
  sleep: "Pose: dozing off while standing, eyes closed peacefully, head tilted to one side, hugging the tablet with both arms, a small bubble of floating Z sleep symbols near the head.",
};

async function call(url, init) {
  const r = await fetch(url, { ...init, headers: { Authorization: `Bearer ${KEY}`, ...(init.headers || {}) }, signal: AbortSignal.timeout(360000) });
  const j = await r.json().catch(() => ({}));
  if (!r.ok) {
    const e = new Error((j.error && j.error.message) || `HTTP ${r.status}`);
    e.status = r.status;
    throw e;
  }
  return j;
}

async function generate(model, prompt) {
  return call("https://api.openai.com/v1/images/generations", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ model, prompt, size: "1024x1536", quality: QUALITY, background: "transparent", output_format: "png", n: 1 }),
  });
}

async function edit(model, prompt, ref, { fidelity = true } = {}) {
  const fd = new FormData();
  fd.append("model", model);
  fd.append("prompt", prompt);
  fd.append("size", "1024x1536");
  fd.append("quality", QUALITY);
  fd.append("background", "transparent");
  fd.append("output_format", "png");
  if (fidelity) fd.append("input_fidelity", "high");
  fd.append("image[]", new Blob([ref], { type: "image/png" }), "inni-reference.png");
  try {
    return await call("https://api.openai.com/v1/images/edits", { method: "POST", body: fd });
  } catch (e) {
    // 이 모델이 input_fidelity 를 모르면 빼고 다시
    if (fidelity && /input_fidelity/i.test(e.message)) return edit(model, prompt, ref, { fidelity: false });
    throw e;
  }
}

const usage = { input: 0, output: 0 };
function save(name, j) {
  const b64 = j.data && j.data[0] && j.data[0].b64_json;
  if (!b64) throw new Error("그림이 오지 않았습니다");
  const file = path.join(OUT, `${name}.png`);
  fs.writeFileSync(file, Buffer.from(b64, "base64"));
  if (j.usage) { usage.input += j.usage.input_tokens || 0; usage.output += j.usage.output_tokens || 0; }
  console.log(`  ✓ ${name}.png (${Math.round(fs.statSync(file).size / 1024)}KB)`);
  return file;
}

const wanted = process.argv.slice(2).filter((m) => MOODS[m]);
const todo = wanted.length ? wanted : Object.keys(MOODS);
const basePath = path.join(OUT, "idle.png");
let model = null;

if (todo.includes("idle") || !fs.existsSync(basePath)) {
  console.log("1) 기준 그림(평소)");
  for (const m of MODELS) {
    try {
      const t0 = Date.now();
      const j = await generate(m, `${CHARACTER}\n${STYLE}\n${MOODS.idle}`);
      save("idle", j);
      model = m;
      console.log(`  모델 ${m} · ${Math.round((Date.now() - t0) / 1000)}초`);
      break;
    } catch (e) {
      console.log(`  ${m}: ${e.message.slice(0, 160)}`);
    }
  }
  if (!model) { console.error("어느 모델로도 그리지 못했습니다"); process.exit(1); }
} else {
  model = process.env.MODEL || MODELS[0];
}

const ref = fs.readFileSync(basePath);
const rest = todo.filter((m) => m !== "idle");
console.log(`2) 표정 ${rest.length}가지(기준 그림 참고, 모델 ${model})`);
// 셋씩 동시에
for (let i = 0; i < rest.length; i += 3) {
  await Promise.all(rest.slice(i, i + 3).map(async (mood) => {
    for (let attempt = 1; attempt <= 2; attempt++) {
      try {
        const t0 = Date.now();
        const j = await edit(model, `${SAME}\n${CHARACTER}\n${STYLE}\n${MOODS[mood]}`, ref);
        save(mood, j);
        console.log(`    ${mood} ${Math.round((Date.now() - t0) / 1000)}초`);
        return;
      } catch (e) {
        console.log(`  ${mood} ${attempt}번째 실패: ${e.message.slice(0, 160)}`);
      }
    }
  }));
}
console.log(`끝. 토큰: 입력 ${usage.input} · 출력 ${usage.output}. 파일: ${OUT}`);
