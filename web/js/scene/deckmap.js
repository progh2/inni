// 3D 홀로그램 선내 지도. 가운데 이니 코어, 둘레에 건물(갑판), 갑판 위에 실(화물칸).
// 찾은 물건이 있는 실에는 빛기둥(비콘)이 서고, 누가 물건을 옮기면 실과 실 사이로 빛이 날아간다.
// 모든 정보는 화면 패널에도 있다. 3D 는 "어디쯤인지" 감을 주는 보조 표시다.
import * as THREE from "three";

const C = {
  hud: new THREE.Color("#5fdcff"),
  cyan: new THREE.Color("#2bb8ec"),
  deep: new THREE.Color("#0d3b5c"),
  amber: new THREE.Color("#ffc857"),
  red: new THREE.Color("#ff5a5a"),
  mint: new THREE.Color("#58f0c0"),
  white: new THREE.Color("#e6fbff"),
};

const PRESETS = {
  bridge: { dist: 1.3, polar: 0.92, spin: 0.03, lift: 4 },
  decks: { dist: 1.05, polar: 0.7, spin: 0.012, lift: -6 },
  search: { dist: 1.45, polar: 0.9, spin: 0.02 },
  item: { dist: 1.3, polar: 0.82, spin: 0.015 },
  default: { dist: 1.55, polar: 0.85, spin: 0.018 },
};

function glowTexture() {
  const c = document.createElement("canvas");
  c.width = c.height = 128;
  const g = c.getContext("2d");
  const grd = g.createRadialGradient(64, 64, 0, 64, 64, 64);
  grd.addColorStop(0, "rgba(255,255,255,1)");
  grd.addColorStop(0.25, "rgba(255,255,255,0.55)");
  grd.addColorStop(0.6, "rgba(255,255,255,0.12)");
  grd.addColorStop(1, "rgba(255,255,255,0)");
  g.fillStyle = grd;
  g.fillRect(0, 0, 128, 128);
  const t = new THREE.CanvasTexture(c);
  t.colorSpace = THREE.SRGBColorSpace;
  return t;
}

// 도면 그림 → 홀로그램 텍스처. 검은 선(흰 바탕) 도면이면 선만 청록으로 남기고 바탕은 투명하게.
// 어두운 바탕 도면이면 밝은 선을 쓴다. 같은 그림은 한 번만 만든다.
const blueprintCache = new Map();
function blueprintTexture(url, max) {
  if (blueprintCache.has(url)) return blueprintCache.get(url);
  const pr = new Promise((resolve) => {
    const img = new Image();
    img.onload = () => {
      const s = Math.min(1, max / Math.max(img.width, img.height));
      const c = document.createElement("canvas");
      c.width = Math.max(1, Math.round(img.width * s));
      c.height = Math.max(1, Math.round(img.height * s));
      const g = c.getContext("2d", { willReadFrequently: true });
      g.drawImage(img, 0, 0, c.width, c.height);
      let d;
      try { d = g.getImageData(0, 0, c.width, c.height); } catch { resolve(null); return; }
      const a = d.data;
      let sum = 0;
      for (let i = 0; i < a.length; i += 16) sum += a[i] * 0.299 + a[i + 1] * 0.587 + a[i + 2] * 0.114;
      const dark = sum / (a.length / 16) < 110;
      for (let i = 0; i < a.length; i += 4) {
        const lum = a[i] * 0.299 + a[i + 1] * 0.587 + a[i + 2] * 0.114;
        const v = dark ? (lum - 40) * 1.5 : (232 - lum) * 1.7;
        a[i] = 150; a[i + 1] = 232; a[i + 2] = 255; a[i + 3] = Math.max(0, Math.min(255, v));
      }
      g.putImageData(d, 0, 0);
      const t = new THREE.CanvasTexture(c);
      t.colorSpace = THREE.SRGBColorSpace;
      t.anisotropy = 4;
      resolve(t);
    };
    img.onerror = () => resolve(null);
    img.src = url;
  });
  blueprintCache.set(url, pr);
  return pr;
}

// 다각형 넓이 중심
function centroid(pts) {
  let a = 0;
  let cx = 0;
  let cy = 0;
  for (let i = 0; i < pts.length; i++) {
    const [x0, y0] = pts[i];
    const [x1, y1] = pts[(i + 1) % pts.length];
    const f = x0 * y1 - x1 * y0;
    a += f;
    cx += (x0 + x1) * f;
    cy += (y0 + y1) * f;
  }
  if (Math.abs(a) < 1e-6) {
    const n = pts.length || 1;
    return [pts.reduce((s, p) => s + p[0], 0) / n, pts.reduce((s, p) => s + p[1], 0) / n];
  }
  return [cx / (3 * a), cy / (3 * a)];
}

const MARK_STYLE = { entrance: { color: 0x58f0c0, label: "현관" }, stairs: { color: 0xffc857, label: "계단" }, elevator: { color: 0x9fb8ff, label: "승강기" } };

export function webglAvailable() {
  try {
    const c = document.createElement("canvas");
    return Boolean(window.WebGLRenderingContext && (c.getContext("webgl2") || c.getContext("webgl")));
  } catch { return false; }
}

const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));

export function createDeckMap({ canvas, labels, quality = "auto", showLabels = true, onPick }) {
  if (!webglAvailable()) return null;
  let renderer;
  try {
    renderer = new THREE.WebGLRenderer({ canvas, antialias: quality !== "low", alpha: true, powerPreference: "high-performance" });
  } catch { return null; }
  let lowQ = quality === "low";
  const setPR = () => renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, lowQ ? 1 : 1.6));
  setPR();
  renderer.setClearColor(0x000000, 0);
  renderer.setSize(window.innerWidth, window.innerHeight, false);
  const reduce = () => document.body.classList.contains("reduce-motion");

  const scene = new THREE.Scene();
  scene.fog = new THREE.FogExp2(0x030812, 0.0065);
  const camera = new THREE.PerspectiveCamera(46, window.innerWidth / window.innerHeight, 0.1, 2000);
  const glow = glowTexture();
  scene.add(new THREE.AmbientLight(0x6f8fb3, 0.6));
  scene.add(new THREE.HemisphereLight(0x8fdcff, 0x0a1426, 0.5));
  const coreLight = new THREE.PointLight(0xffd88a, 2.2, 0, 0);
  coreLight.position.set(0, 4, 0);
  scene.add(coreLight);

  // ---------------------------------------------------------------- 별
  const stars = (() => {
    const n = lowQ ? 700 : 1500;
    const pos = new Float32Array(n * 3);
    for (let i = 0; i < n; i++) {
      const r = 160 + Math.random() * 380;
      const th = Math.random() * Math.PI * 2;
      const ph = Math.acos(2 * Math.random() - 1);
      pos[i * 3] = r * Math.sin(ph) * Math.cos(th);
      pos[i * 3 + 1] = r * Math.cos(ph) * 0.6;
      pos[i * 3 + 2] = r * Math.sin(ph) * Math.sin(th);
    }
    const geo = new THREE.BufferGeometry();
    geo.setAttribute("position", new THREE.BufferAttribute(pos, 3));
    const pts = new THREE.Points(geo, new THREE.PointsMaterial({ color: 0xbfe9ff, size: 1.2, sizeAttenuation: false, transparent: true, opacity: 0.75, depthWrite: false }));
    scene.add(pts);
    return pts;
  })();

  // ---------------------------------------------------------------- 바닥 격자(홀로그램)
  const grid = new THREE.Group();
  {
    const mat = new THREE.LineBasicMaterial({ color: 0x2bb8ec, transparent: true, opacity: 0.12 });
    for (let r = 12; r <= 72; r += 12) {
      const pts = [];
      for (let i = 0; i <= 96; i++) { const a = (i / 96) * Math.PI * 2; pts.push(new THREE.Vector3(Math.cos(a) * r, 0, Math.sin(a) * r)); }
      grid.add(new THREE.Line(new THREE.BufferGeometry().setFromPoints(pts), mat));
    }
    for (let i = 0; i < 24; i++) {
      const a = (i / 24) * Math.PI * 2;
      grid.add(new THREE.Line(new THREE.BufferGeometry().setFromPoints([new THREE.Vector3(Math.cos(a) * 6, 0, Math.sin(a) * 6), new THREE.Vector3(Math.cos(a) * 72, 0, Math.sin(a) * 72)]), mat));
    }
    grid.position.y = -0.2;
    scene.add(grid);
  }

  // ---------------------------------------------------------------- 이니 코어
  const core = new THREE.Group();
  const coreShell = new THREE.Mesh(new THREE.IcosahedronGeometry(2.6, 1), new THREE.MeshBasicMaterial({ color: 0x5fdcff, wireframe: true, transparent: true, opacity: 0.55 }));
  const coreInner = new THREE.Mesh(new THREE.OctahedronGeometry(1.4, 0), new THREE.MeshStandardMaterial({ color: 0xffc857, emissive: 0xffa82e, emissiveIntensity: 1.3, metalness: 0.2, roughness: 0.3 }));
  const coreGlow = new THREE.Sprite(new THREE.SpriteMaterial({ map: glow, color: 0x9fe8ff, transparent: true, opacity: 0.55, depthWrite: false, blending: THREE.AdditiveBlending }));
  coreGlow.scale.setScalar(12);
  const rings = [0, 1, 2].map((i) => {
    const r = new THREE.Mesh(new THREE.TorusGeometry(4 + i * 1.1, 0.04, 6, 96), new THREE.MeshBasicMaterial({ color: i === 1 ? 0xffc857 : 0x5fdcff, transparent: true, opacity: 0.5 }));
    r.rotation.x = Math.PI / 2 + (i - 1) * 0.45;
    r.userData.speed = 0.3 + i * 0.15;
    core.add(r);
    return r;
  });
  core.add(coreShell, coreInner, coreGlow);
  core.position.y = 5;
  scene.add(core);
  const coreLabel = document.createElement("div");
  coreLabel.className = "s-label core";
  coreLabel.textContent = "INNI CORE";
  labels.appendChild(coreLabel);

  // ---------------------------------------------------------------- 갑판·화물칸
  // 도면이 있는 건물: 층을 쌓고 도면 그림 위에 그린 실 모양대로 벽을 세운다.
  // 도면이 없는 건물: 실을 바둑판 상자로 늘어놓는다(물건이 많을수록 높게).
  const PLAN_W = 30; // 도면의 긴 변(월드 단위)
  const FLOOR_H = 5.5; // 층 사이 높이
  const WALL_H = 2.2; // 실 벽 높이
  const DIM = 0.16; // 다른 층을 흐리게 할 때
  let decks = [];
  let rooms = new Map(); // id → { box, edges, label, data, deck, hgt, topY, base, floor }
  let floors = []; // { group, plan, mats, deck, level, host, label, labelPos, marks }
  let gen = 0;
  const world = new THREE.Group();
  scene.add(world);
  const roomLabelsEl = [];
  const extraLabels = [];

  function clearWorld() {
    gen += 1;
    world.traverse((o) => { if (o.geometry) o.geometry.dispose(); if (o.material) { if (Array.isArray(o.material)) o.material.forEach((m) => m.dispose()); else o.material.dispose(); } });
    world.clear();
    for (const d of decks) d.label.remove();
    for (const el of roomLabelsEl) el.remove();
    for (const el of extraLabels) el.remove();
    roomLabelsEl.length = 0;
    extraLabels.length = 0;
    decks = [];
    rooms = new Map();
    floors = [];
  }

  const roomMaterial = (color, opacity = 0.38) => new THREE.MeshStandardMaterial({ color: color.clone().multiplyScalar(0.55), emissive: color, emissiveIntensity: 0.35, transparent: true, opacity, metalness: 0.1, roughness: 0.4, depthWrite: false });
  const track = (fl, mat) => { mat.userData.base = mat.opacity; fl.mats.push(mat); return mat; };
  function roomLabel(r, extra = "") {
    const lab = document.createElement("div");
    lab.className = `s-label${r.alerts ? " alert" : ""}`;
    lab.innerHTML = `${esc(r.name)}<small>${r.items || 0}종${r.alerts ? ` · 경보 ${r.alerts}` : ""}${extra}</small>`;
    labels.appendChild(lab);
    roomLabelsEl.push(lab);
    return lab;
  }
  function gridRoom(parent, r, x, z, dk, size = 3.6) {
    const count = r.items || 0;
    const hgt = 0.8 + Math.log2(count + 1) * 0.9;
    const color = r.alerts ? C.amber : C.cyan;
    const box = new THREE.Mesh(new THREE.BoxGeometry(size, hgt, size), roomMaterial(color));
    box.position.set(x, 0.2 + hgt / 2, z);
    const edges = new THREE.LineSegments(new THREE.EdgesGeometry(box.geometry), new THREE.LineBasicMaterial({ color: r.alerts ? 0xffc857 : 0x8fe6ff, transparent: true, opacity: 0.9 }));
    edges.position.copy(box.position);
    parent.add(box, edges);
    rooms.set(r.id, { box, edges, label: roomLabel(r), data: r, deck: dk, hgt, topY: hgt / 2, base: color, beacon: null, floor: null });
  }

  function buildGrid(g, dk) {
    const plate = new THREE.Mesh(new THREE.BoxGeometry(g.w, 0.35, g.d), new THREE.MeshStandardMaterial({ color: 0x0b2238, emissive: 0x0a3050, emissiveIntensity: 0.6, transparent: true, opacity: 0.72, metalness: 0.4, roughness: 0.6 }));
    const plateEdge = new THREE.LineSegments(new THREE.EdgesGeometry(plate.geometry), new THREE.LineBasicMaterial({ color: 0x5fdcff, transparent: true, opacity: 0.75 }));
    dk.group.add(plate, plateEdge);
    const cell = 5.2;
    g.rooms.forEach((r, i) => {
      const cx = (i % g.cols) * cell - ((g.cols - 1) * cell) / 2;
      const cz = Math.floor(i / g.cols) * cell - ((g.rowsN - 1) * cell) / 2;
      gridRoom(dk.group, r, cx, cz, dk);
    });
    dk.labelY = 1.2;
  }

  function buildPlanned(g, dk, myGen) {
    const minL = Math.min(...g.plans.map((p) => p.level));
    const sorted = [...g.plans].sort((a, b) => a.level - b.level);
    let top = 0;
    for (const p of sorted) {
      const k = g.k(p);
      const w = p.width * k;
      const d = p.height * k;
      const fg = new THREE.Group();
      fg.position.y = (p.level - minL) * FLOOR_H;
      top = Math.max(top, fg.position.y);
      dk.group.add(fg);
      const host = byId.get(p.location_id);
      const fl = { group: fg, plan: p, mats: [], deck: dk, level: p.level, host, marks: [], dim: false };
      floors.push(fl);
      dk.floors.push(fl);
      const plate = new THREE.Mesh(new THREE.BoxGeometry(w + 1, 0.16, d + 1), track(fl, new THREE.MeshStandardMaterial({ color: 0x0b2238, emissive: 0x0a3050, emissiveIntensity: 0.5, transparent: true, opacity: 0.5, metalness: 0.3, roughness: 0.7, depthWrite: false })));
      const plateEdge = new THREE.LineSegments(new THREE.EdgesGeometry(plate.geometry), track(fl, new THREE.LineBasicMaterial({ color: 0x5fdcff, transparent: true, opacity: 0.7 })));
      fg.add(plate, plateEdge);
      if (p.image_url) {
        blueprintTexture(p.image_url, lowQ ? 1024 : 2048).then((tex) => {
          if (!tex || myGen !== gen) return;
          const mat = track(fl, new THREE.MeshBasicMaterial({ map: tex, transparent: true, opacity: 0.92, depthWrite: false }));
          if (fl.dim) mat.opacity = mat.userData.base * DIM;
          const plane = new THREE.Mesh(new THREE.PlaneGeometry(w, d), mat);
          plane.rotation.x = -Math.PI / 2;
          plane.position.y = 0.1;
          fg.add(plane);
        });
      }
      const fLabel = document.createElement("div");
      fLabel.className = "s-label floor";
      fLabel.textContent = host && host.kind === "floor" ? host.name : `${p.level}층`;
      labels.appendChild(fLabel);
      extraLabels.push(fLabel);
      fl.label = fLabel;
      fl.labelPos = new THREE.Vector3(-w / 2 - 0.5, 0.2, d / 2 + 0.5);
      for (const sh of p.shapes || []) {
        const loc = byId.get(sh.location_id);
        if (!loc || !sh.pts || sh.pts.length < 3) continue;
        const pts = sh.pts.map(([x, y]) => [(x - p.width / 2) * k, (y - p.height / 2) * k]);
        const [cx, cz] = centroid(pts);
        const shape = new THREE.Shape(pts.map(([x, z]) => new THREE.Vector2(x - cx, -(z - cz))));
        const isRoom = loc.kind === "room" || loc.kind === "zone";
        const h = isRoom ? WALL_H : 1.1;
        const geo = new THREE.ExtrudeGeometry(shape, { depth: h, bevelEnabled: false });
        geo.rotateX(-Math.PI / 2);
        const color = loc.alerts ? C.amber : isRoom ? C.cyan : C.mint;
        const mesh = new THREE.Mesh(geo, track(fl, roomMaterial(color, isRoom ? 0.28 : 0.45)));
        mesh.position.set(cx, 0.12, cz);
        const edges = new THREE.LineSegments(new THREE.EdgesGeometry(geo, 25), track(fl, new THREE.LineBasicMaterial({ color: loc.alerts ? 0xffc857 : isRoom ? 0x8fe6ff : 0x9ff5d8, transparent: true, opacity: 0.9 })));
        edges.position.copy(mesh.position);
        fg.add(mesh, edges);
        rooms.set(loc.id, { box: mesh, edges, label: roomLabel(loc), data: loc, deck: dk, hgt: h, topY: h, base: color, beacon: null, floor: fl });
      }
      for (const m of p.marks || []) {
        const st = MARK_STYLE[m.type];
        if (!st) continue;
        const geo = m.type === "entrance" ? new THREE.ConeGeometry(0.75, 1.6, 4) : m.type === "stairs" ? new THREE.BoxGeometry(1.3, 0.5, 1.3) : new THREE.CylinderGeometry(0.6, 0.6, 1.5, 14);
        const mk = new THREE.Mesh(geo, track(fl, new THREE.MeshBasicMaterial({ color: st.color, transparent: true, opacity: 0.9 })));
        mk.position.set((m.x - p.width / 2) * k, m.type === "stairs" ? 0.5 : 1.0, (m.y - p.height / 2) * k);
        if (m.type === "entrance") mk.rotation.x = Math.PI;
        fg.add(mk);
        const ml = document.createElement("div");
        ml.className = `s-label mark ${m.type}`;
        ml.textContent = m.label || st.label;
        labels.appendChild(ml);
        extraLabels.push(ml);
        fl.marks.push({ mesh: mk, label: ml });
      }
    }
    // 도면에 아직 그리지 않은 실은 앞쪽에 작은 상자로 둔다(빠뜨린 걸 알아보게)
    g.loose.forEach((r, i) => {
      const per = Math.max(1, Math.floor(g.w / 3.4));
      const x = -g.w / 2 + 1.8 + (i % per) * 3.4;
      const z = g.d / 2 - 2 - Math.floor(i / per) * 3.4;
      gridRoom(dk.group, r, x, z, dk, 2.4);
      rooms.get(r.id).label.querySelector("small").insertAdjacentHTML("beforeend", " · 도면 밖");
    });
    dk.labelY = top + WALL_H + 2;
    // 건물 이름은 도면 뒤쪽 가장자리 위에(가운데 실 이름과 겹치지 않게)
    dk.labelPos = new THREE.Vector3(0, top + WALL_H + 1.4, -g.d / 2 + 0.5);
  }

  let byId = new Map();
  function setData(locations, plans = []) {
    clearWorld();
    const myGen = gen;
    byId = new Map(locations.map((l) => [l.id, l]));
    const rootOf = (l) => (l && l.path_ids && l.path_ids.length ? byId.get(l.path_ids[0]) : null) || l;
    // 도면을 건물별로 묶는다
    const planned = new Map();
    const drawn = new Set();
    for (const p of plans || []) {
      const host = byId.get(p.location_id);
      if (!host || !p.width || !p.height) continue;
      const b = host.kind === "building" ? host : rootOf(host);
      if (!b) continue;
      if (!planned.has(b.id)) planned.set(b.id, []);
      planned.get(b.id).push(p);
      for (const sh of p.shapes || []) drawn.add(sh.location_id);
    }
    const groups = new Map();
    for (const r of locations.filter((l) => l.kind === "room")) {
      const root = r.path_ids && r.path_ids[0] !== r.id ? byId.get(r.path_ids[0]) : null;
      const key = root ? root.id : "_none";
      if (!groups.has(key)) groups.set(key, { root, rooms: [] });
      groups.get(key).rooms.push(r);
    }
    for (const bid of planned.keys()) if (!groups.has(bid)) groups.set(bid, { root: byId.get(bid), rooms: [] });
    const list = [...groups.values()];
    for (const g of list) {
      g.plans = g.root ? planned.get(g.root.id) || null : null;
      if (g.plans) {
        // 모든 층에 축척이 있으면 실제 크기(m)로, 아니면 그림 크기로 맞춘다
        const allM = g.plans.every((p) => p.meters_per_px > 0);
        const ext = (p) => Math.max(p.width, p.height) * (allM ? p.meters_per_px : 1);
        const maxE = Math.max(...g.plans.map(ext));
        g.k = (p) => (PLAN_W / maxE) * (allM ? p.meters_per_px : 1);
        g.loose = g.rooms.filter((r) => !drawn.has(r.id));
        g.w = Math.max(...g.plans.map((p) => p.width * g.k(p))) + 2;
        g.d = Math.max(...g.plans.map((p) => p.height * g.k(p))) + 2 + (g.loose.length ? Math.ceil(g.loose.length / Math.max(1, Math.floor(PLAN_W / 3.4))) * 3.4 + 1 : 0);
      } else {
        const n = g.rooms.length;
        g.cols = Math.max(1, Math.ceil(Math.sqrt(n)));
        g.rowsN = Math.max(1, Math.ceil(n / g.cols));
        g.w = g.cols * 5.2 + 2.4;
        g.d = g.rowsN * 5.2 + 2.4;
      }
    }
    const biggest = Math.max(10, ...list.map((g) => Math.max(g.w, g.d)));
    const R = list.length <= 1 ? Math.max(14, 8 + biggest / 2) : Math.max(20, list.length * 7, 10 + biggest * 0.8);
    list.forEach((g, gi) => {
      const a = (gi / Math.max(1, list.length)) * Math.PI * 2 - Math.PI / 2 + 0.35;
      const center = list.length === 1 ? new THREE.Vector3(0, 0, R) : new THREE.Vector3(Math.cos(a) * R, 0, Math.sin(a) * R);
      const deck = new THREE.Group();
      deck.position.copy(center);
      deck.lookAt(0, 0, 0);
      // 도면은 바깥(카메라 쪽)에서 바로 읽히게 반 바퀴 더 돌린다(도면 위쪽이 코어 쪽)
      const flip = g.plans ? -1 : 1;
      if (g.plans) deck.rotateY(Math.PI);
      const beam = new THREE.Line(new THREE.BufferGeometry().setFromPoints([new THREE.Vector3(0, 0.2, 0), new THREE.Vector3(0, 0.2, flip * (center.length() - 5))]), new THREE.LineDashedMaterial({ color: 0x2bb8ec, dashSize: 0.6, gapSize: 0.5, transparent: true, opacity: 0.35 }));
      beam.computeLineDistances();
      deck.add(beam);
      world.add(deck);
      const label = document.createElement("div");
      label.className = "s-label building";
      label.innerHTML = `${esc(g.root ? g.root.name : "기타 구역")}<small>${g.plans ? `${g.plans.length}개 층 · ` : ""}실 ${g.rooms.length}</small>`;
      labels.appendChild(label);
      const dk = { group: deck, label, id: g.root ? g.root.id : null, center, w: g.w, d: g.d, floors: [], labelY: 1.2 };
      decks.push(dk);
      if (g.plans) buildPlanned(g, dk, myGen);
      else buildGrid(g, dk);
    });
    reframe();
  }

  // 한 건물에서 어떤 층만 또렷하게(나머지는 흐리게). keep 이 비면 모두 원래대로
  function dimFloors(keep = new Set()) {
    const decksWithKeep = new Set([...keep].map((fl) => fl.deck));
    for (const fl of floors) {
      const dim = decksWithKeep.has(fl.deck) && !keep.has(fl);
      if (fl.dim === dim) continue;
      fl.dim = dim;
      for (const m of fl.mats) m.opacity = m.userData.base * (dim ? DIM : 1);
      if (fl.label) fl.label.classList.toggle("dim", dim);
      for (const mk of fl.marks) mk.label.classList.toggle("dim", dim);
      for (const r of rooms.values()) if (r.floor === fl) r.label.classList.toggle("dim", dim);
    }
  }

  // ---------------------------------------------------------------- 표시·강조
  const tmpV = new THREE.Vector3();
  function worldPos(room, out = new THREE.Vector3()) {
    room.box.getWorldPosition(out);
    return out;
  }
  function roomFor(id) {
    if (rooms.has(id)) return rooms.get(id);
    const l = byId.get(id);
    if (l && l.room_id && rooms.has(l.room_id)) return rooms.get(l.room_id);
    if (l && l.path_ids) for (const pid of [...l.path_ids].reverse()) if (rooms.has(pid)) return rooms.get(pid);
    return null;
  }

  let highlightUntil = 0;
  const beacons = [];
  function clearHighlight() {
    for (const b of beacons.splice(0)) { world.remove(b.group); b.group.traverse((o) => { if (o.geometry) o.geometry.dispose(); if (o.material) o.material.dispose(); }); }
    for (const r of rooms.values()) { r.label.classList.remove("hot"); r.box.material.emissive.copy(r.base); r.box.material.emissiveIntensity = 0.35; }
    dimFloors();
  }
  function highlight(ids = []) {
    clearHighlight();
    const hit = [...new Set(ids.map(roomFor).filter(Boolean))];
    if (!hit.length) return;
    for (const r of hit) {
      r.label.classList.add("hot");
      r.box.material.emissive.copy(C.amber);
      r.box.material.emissiveIntensity = 1.1;
      const g = new THREE.Group();
      const p = worldPos(r);
      g.position.set(p.x, r.floor ? p.y - 0.1 : 0, p.z);
      const beam = new THREE.Mesh(new THREE.CylinderGeometry(0.35, 0.9, 40, 16, 1, true), new THREE.MeshBasicMaterial({ color: 0xffc857, transparent: true, opacity: 0.28, side: THREE.DoubleSide, depthWrite: false, blending: THREE.AdditiveBlending }));
      beam.position.y = 20;
      const ring = new THREE.Mesh(new THREE.RingGeometry(1.6, 2.1, 48), new THREE.MeshBasicMaterial({ color: 0xffc857, transparent: true, opacity: 0.8, side: THREE.DoubleSide, depthWrite: false }));
      ring.rotation.x = -Math.PI / 2;
      ring.position.y = 0.4;
      const sprite = new THREE.Sprite(new THREE.SpriteMaterial({ map: glow, color: 0xffd88a, transparent: true, opacity: 0.9, depthWrite: false, blending: THREE.AdditiveBlending }));
      sprite.scale.setScalar(6);
      sprite.position.y = (r.floor ? r.topY : r.hgt) + 1.2;
      g.add(beam, ring, sprite);
      world.add(g);
      beacons.push({ group: g, ring, beam, t0: performance.now() });
    }
    dimFloors(new Set(hit.map((r) => r.floor).filter(Boolean)));
    // 첫 실 쪽으로 카메라를 돌린다
    const p = worldPos(hit[0]);
    want.target.set(p.x * 0.55, hit[0].floor ? p.y * 0.85 + 1 : 1, p.z * 0.55);
    want.radius = baseRadius * 0.72;
    userAzimuth = Math.atan2(p.z, p.x);
    focusUntil = performance.now() + 9000;
    highlightUntil = performance.now() + 14000;
  }

  // 옮기기·대여·반납: 빛 알갱이가 날아간다
  const MAX_P = 240;
  const particles = [];
  const pGeo = new THREE.BufferGeometry();
  const pPos = new Float32Array(MAX_P * 3);
  const pCol = new Float32Array(MAX_P * 3);
  pGeo.setAttribute("position", new THREE.BufferAttribute(pPos, 3));
  pGeo.setAttribute("color", new THREE.BufferAttribute(pCol, 3));
  const pPoints = new THREE.Points(pGeo, new THREE.PointsMaterial({ size: 0.9, map: glow, vertexColors: true, transparent: true, depthWrite: false, blending: THREE.AdditiveBlending }));
  scene.add(pPoints);
  const corePos = () => core.position.clone();
  function pulse(ev) {
    if (!ev || reduce()) return;
    const a = ev.location_id ? roomFor(ev.location_id) : null;
    const b = ev.to_location_id ? roomFor(ev.to_location_id) : null;
    let from;
    let to;
    let color = C.mint;
    if (a && b && a !== b) { from = worldPos(a); to = worldPos(b); color = C.hud; }
    else if (a && (ev.action === "loan" || ev.action === "use")) { from = worldPos(a); to = corePos(); color = C.amber; }
    else if ((b || a) && (ev.action === "return" || ev.action === "restock" || ev.action === "create")) { from = corePos(); to = worldPos(b || a); color = C.mint; }
    else return;
    const mid = from.clone().lerp(to, 0.5);
    mid.y += 6 + from.distanceTo(to) * 0.18;
    for (let i = 0; i < 14; i++) particles.push({ from, mid, to, t: -i * 0.035, dur: 1.5, color });
    const target = b || a;
    if (target) {
      target.box.material.emissiveIntensity = 1.4;
      setTimeout(() => { if (!target.label.classList.contains("hot")) target.box.material.emissiveIntensity = 0.35; }, 1400);
    }
  }
  function bez(p, u, out) {
    const iu = 1 - u;
    out.set(
      iu * iu * p.from.x + 2 * iu * u * p.mid.x + u * u * p.to.x,
      iu * iu * p.from.y + 2 * iu * u * p.mid.y + u * u * p.to.y,
      iu * iu * p.from.z + 2 * iu * u * p.mid.z + u * u * p.to.z,
    );
    return out;
  }

  // ---------------------------------------------------------------- 카메라
  let baseRadius = 60;
  const want = { radius: 60, polar: 0.95, target: new THREE.Vector3(0, 1, 0), spin: 0.03 };
  const cam = { radius: 90, polar: 0.9, azimuth: 0.6, target: new THREE.Vector3(0, 1, 0) };
  let userAzimuth = 0.6;
  let focusUntil = 0;
  let station = "bridge";
  function reframe() {
    let maxR = 20;
    for (const d of decks) maxR = Math.max(maxR, d.center.length() + Math.max(d.w, d.d) / 2);
    baseRadius = Math.max(42, maxR * 2.1);
    setStation(station);
  }
  function setStation(id) {
    station = id;
    const p = PRESETS[id] || PRESETS.default;
    want.radius = baseRadius * p.dist;
    want.polar = p.polar;
    want.spin = p.spin;
    want.lift = p.lift || 0;
    if (performance.now() > focusUntil) want.target.set(0, 1 + want.lift, 0);
  }

  // ---------------------------------------------------------------- 고르기·끌기
  const pointer = { x: -1, y: -1, inside: false };
  let hovered = null;
  function project(v) {
    tmpV.copy(v).project(camera);
    return { x: (tmpV.x * 0.5 + 0.5) * window.innerWidth, y: (-tmpV.y * 0.5 + 0.5) * window.innerHeight, z: tmpV.z };
  }
  function pick(x, y) {
    let best = null;
    let bestD = 34;
    for (const r of rooms.values()) {
      const p = project(worldPos(r));
      if (p.z > 1) continue;
      const d = Math.hypot(p.x - x, p.y - y);
      if (d < bestD) { bestD = d; best = r; }
    }
    return best;
  }
  const BLOCK = ".panel, .card, .hud, .rail, .foot, .tabbar, .modal, .sheet, .drawer, .palette, .toast, .scrim, .login-card, .inni-panel, .inni-launch, .inni-bubble, button, a, input, select, textarea, label, table, .void-legend, .icard, .irow, .qa";
  const empty = (t) => !(t && t.closest && t.closest(BLOCK));
  let dragging = false;
  let downAt = null;
  let lastUser = 0;
  const onMove = (ev) => {
    if (dragging && downAt) {
      userAzimuth = downAt.az - (ev.clientX - downAt.x) * 0.006;
      want.polar = Math.min(1.45, Math.max(0.2, downAt.polar - (ev.clientY - downAt.y) * 0.004));
      lastUser = performance.now();
      return;
    }
    pointer.inside = empty(ev.target);
    pointer.x = ev.clientX;
    pointer.y = ev.clientY;
  };
  const onDown = (ev) => {
    if (ev.button !== 0 || !empty(ev.target)) return;
    downAt = { x: ev.clientX, y: ev.clientY, az: userAzimuth, polar: want.polar, t: performance.now() };
    dragging = true;
  };
  const onUp = (ev) => {
    if (!downAt) return;
    const moved = Math.hypot(ev.clientX - downAt.x, ev.clientY - downAt.y);
    const quick = performance.now() - downAt.t < 450;
    dragging = false;
    downAt = null;
    if (moved < 6 && quick && empty(ev.target)) {
      const hit = pick(ev.clientX, ev.clientY);
      if (hit && onPick) onPick(hit.data);
    }
  };
  const onWheel = (ev) => {
    if (!empty(ev.target)) return;
    want.radius = Math.max(baseRadius * 0.35, Math.min(baseRadius * 1.8, want.radius * (ev.deltaY > 0 ? 1.08 : 0.92)));
    lastUser = performance.now();
  };
  window.addEventListener("pointermove", onMove, { passive: true });
  window.addEventListener("pointerdown", onDown, { passive: true });
  window.addEventListener("pointerup", onUp, { passive: true });
  window.addEventListener("wheel", onWheel, { passive: true });

  // ---------------------------------------------------------------- 그리기
  let lastT = performance.now();
  let elapsed = 0;
  let frames = 0;
  let slow = 0;
  let running = true;
  let labelsOn = showLabels;
  const resize = () => {
    camera.aspect = window.innerWidth / window.innerHeight;
    camera.updateProjectionMatrix();
    renderer.setSize(window.innerWidth, window.innerHeight, false);
  };
  window.addEventListener("resize", resize);
  const onVis = () => { if (document.hidden) renderer.setAnimationLoop(null); else if (running) { lastT = performance.now(); renderer.setAnimationLoop(frame); } };
  document.addEventListener("visibilitychange", onVis);

  function place(el, pos, dy = 0) {
    const p = project(pos);
    if (p.z > 1 || p.x < -80 || p.x > window.innerWidth + 80 || p.y < -40 || p.y > window.innerHeight + 40) { el.style.display = "none"; return; }
    el.style.display = "";
    el.style.transform = `translate(${p.x.toFixed(1)}px, ${(p.y - dy).toFixed(1)}px) translate(-50%, -100%)`;
  }

  function frame() {
    if (!running) return;
    const now = performance.now();
    const dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    elapsed += dt;
    frames += 1;
    if (frames > 30 && frames < 150 && dt > 0.034) slow += 1;
    if (frames === 150 && slow > 70 && !lowQ) { lowQ = true; setPR(); }
    const m = reduce() ? 0 : 1;
    stars.rotation.y += dt * 0.004 * m;
    grid.rotation.y -= dt * 0.008 * m;
    coreShell.rotation.y += dt * 0.3 * m;
    coreShell.rotation.x += dt * 0.1 * m;
    coreInner.rotation.y -= dt * 0.8 * m;
    rings.forEach((r) => { r.rotation.z += dt * r.userData.speed * m; });
    core.position.y = 5 + Math.sin(elapsed * 1.2) * 0.35 * m;
    coreGlow.scale.setScalar(12 * (1 + Math.sin(elapsed * 2.2) * 0.06 * m));
    for (const b of beacons) {
      const k = ((now - b.t0) / 1400) % 1;
      b.ring.scale.setScalar(1 + k * 2.4);
      b.ring.material.opacity = 0.8 * (1 - k);
      b.beam.material.opacity = 0.2 + Math.sin(elapsed * 4) * 0.08;
    }
    if (highlightUntil && now > highlightUntil) { highlightUntil = 0; clearHighlight(); }
    // 알갱이
    let n = 0;
    const tp = new THREE.Vector3();
    for (let i = particles.length - 1; i >= 0; i--) {
      const p = particles[i];
      p.t += dt / p.dur;
      if (p.t >= 1) { particles.splice(i, 1); continue; }
      if (p.t < 0) continue;
      bez(p, p.t, tp);
      pPos[n * 3] = tp.x; pPos[n * 3 + 1] = tp.y; pPos[n * 3 + 2] = tp.z;
      pCol[n * 3] = p.color.r; pCol[n * 3 + 1] = p.color.g; pCol[n * 3 + 2] = p.color.b;
      if (++n >= MAX_P) break;
    }
    pGeo.setDrawRange(0, n);
    pGeo.getAttribute("position").needsUpdate = true;
    pGeo.getAttribute("color").needsUpdate = true;
    // 카메라
    const idle = now - lastUser > 8000;
    if (!dragging && idle && now > focusUntil) userAzimuth += dt * want.spin * m;
    if (now > focusUntil && !highlightUntil) want.target.lerp(new THREE.Vector3(0, 1 + (want.lift || 0), 0), 0.02);
    const k = reduce() ? 1 : 1 - Math.pow(0.002, dt);
    cam.radius += (want.radius - cam.radius) * k;
    cam.polar += (want.polar - cam.polar) * k;
    cam.azimuth += (userAzimuth - cam.azimuth) * (dragging ? 1 : k);
    cam.target.lerp(want.target, k);
    camera.position.set(
      cam.target.x + cam.radius * Math.sin(cam.polar) * Math.cos(cam.azimuth),
      cam.target.y + cam.radius * Math.cos(cam.polar),
      cam.target.z + cam.radius * Math.sin(cam.polar) * Math.sin(cam.azimuth),
    );
    camera.lookAt(cam.target);
    // 라벨
    if (labelsOn) {
      place(coreLabel, new THREE.Vector3(0, core.position.y + 4.5, 0));
      for (const d of decks) {
        if (d.labelPos) { place(d.label, d.group.localToWorld(d.labelPos.clone()), 8); continue; }
        d.group.getWorldPosition(tmpV);
        place(d.label, tmpV.clone().setY(d.labelY || 1.2), 8);
      }
      const detailF = station === "decks" || station === "plans" || cam.radius < baseRadius * 0.75;
      for (const fl of floors) {
        if (!detailF) { fl.label.style.display = "none"; for (const mk of fl.marks) mk.label.style.display = "none"; continue; }
        place(fl.label, fl.group.localToWorld(fl.labelPos.clone()), 0);
        for (const mk of fl.marks) { mk.mesh.getWorldPosition(tmpV); place(mk.label, tmpV.clone().setY(tmpV.y + 1.2), 0); }
      }
      // 장소 화면이거나 가까이 볼 때만 실 이름을 모두 보인다(개요에서는 경보·찾은 곳·가리킨 곳만)
      const detail = station === "decks" || rooms.size <= 5 || cam.radius < baseRadius * 0.75;
      for (const r of rooms.values()) {
        const show = detail || r.label.classList.contains("hot") || hovered === r || r.data.alerts > 0;
        if (!show) { r.label.style.display = "none"; continue; }
        const wp = worldPos(r, tmpV);
        place(r.label, wp.clone().setY(wp.y + r.topY + 0.6), 4);
      }
    }
    if (pointer.inside && !dragging && frames % 4 === 0) {
      const hit = pick(pointer.x, pointer.y);
      if (hit !== hovered) {
        if (hovered && !hovered.label.classList.contains("hot")) hovered.box.material.emissiveIntensity = 0.35;
        hovered = hit;
        if (hit) hit.box.material.emissiveIntensity = 0.9;
      }
      document.body.style.cursor = hit ? "pointer" : "";
    } else if (!pointer.inside && hovered) {
      if (!hovered.label.classList.contains("hot")) hovered.box.material.emissiveIntensity = 0.35;
      hovered = null;
      document.body.style.cursor = "";
    }
    renderer.render(scene, camera);
  }
  renderer.setAnimationLoop(frame);
  setStation("bridge");

  return {
    setData,
    highlight,
    pulse,
    setStation: (id) => setStation(id),
    focus: (ids) => highlight(ids),
    // 도면 화면의 [3D로 보기]: 그 층만 또렷하게, 그 건물 쪽으로
    showFloor(planId) {
      const fl = floors.find((f) => f.plan.id === planId);
      if (!fl) return false;
      clearHighlight();
      dimFloors(new Set([fl]));
      fl.group.getWorldPosition(tmpV);
      want.target.set(tmpV.x * 0.7, tmpV.y + 1, tmpV.z * 0.7);
      want.radius = baseRadius * 0.62;
      userAzimuth = Math.atan2(tmpV.z, tmpV.x);
      focusUntil = performance.now() + 12000;
      highlightUntil = performance.now() + 16000;
      return true;
    },
    setLabels(on) { labelsOn = on; labels.style.display = on ? "" : "none"; },
    pause() { running = false; renderer.setAnimationLoop(null); labels.style.display = "none"; },
    resume() { if (!running) { running = true; lastT = performance.now(); renderer.setAnimationLoop(frame); labels.style.display = labelsOn ? "" : "none"; } },
    dispose() {
      running = false;
      renderer.setAnimationLoop(null);
      window.removeEventListener("pointermove", onMove);
      window.removeEventListener("pointerdown", onDown);
      window.removeEventListener("pointerup", onUp);
      window.removeEventListener("wheel", onWheel);
      window.removeEventListener("resize", resize);
      document.removeEventListener("visibilitychange", onVis);
      clearWorld();
      renderer.dispose();
    },
  };
}
