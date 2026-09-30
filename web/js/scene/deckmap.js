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
  let decks = [];
  let rooms = new Map(); // id → { mesh, edges, label, pos, data, deck }
  const world = new THREE.Group();
  scene.add(world);
  const roomLabelsEl = [];

  function clearWorld() {
    world.traverse((o) => { if (o.geometry) o.geometry.dispose(); if (o.material) { if (Array.isArray(o.material)) o.material.forEach((m) => m.dispose()); else o.material.dispose(); } });
    world.clear();
    for (const d of decks) d.label.remove();
    for (const el of roomLabelsEl) el.remove();
    roomLabelsEl.length = 0;
    decks = [];
    rooms = new Map();
  }

  let byId = new Map();
  function setData(locations) {
    clearWorld();
    byId = new Map(locations.map((l) => [l.id, l]));
    const roomList = locations.filter((l) => l.kind === "room");
    const groups = new Map();
    for (const r of roomList) {
      const root = r.path_ids && r.path_ids[0] !== r.id ? byId.get(r.path_ids[0]) : null;
      const key = root ? root.id : "_none";
      if (!groups.has(key)) groups.set(key, { root, rooms: [] });
      groups.get(key).rooms.push(r);
    }
    const list = [...groups.values()];
    const R = list.length <= 1 ? 14 : Math.max(20, list.length * 7);
    list.forEach((g, gi) => {
      const a = (gi / Math.max(1, list.length)) * Math.PI * 2 - Math.PI / 2 + 0.35;
      const center = list.length === 1 ? new THREE.Vector3(0, 0, 14) : new THREE.Vector3(Math.cos(a) * R, 0, Math.sin(a) * R);
      const n = g.rooms.length;
      const cols = Math.ceil(Math.sqrt(n));
      const rows = Math.ceil(n / cols);
      const cell = 5.2;
      const w = cols * cell + 2.4;
      const d = rows * cell + 2.4;
      // 갑판(육각 받침 대신 둥근 모서리 판)
      const deck = new THREE.Group();
      deck.position.copy(center);
      deck.lookAt(0, 0, 0);
      const plate = new THREE.Mesh(new THREE.BoxGeometry(w, 0.35, d), new THREE.MeshStandardMaterial({ color: 0x0b2238, emissive: 0x0a3050, emissiveIntensity: 0.6, transparent: true, opacity: 0.72, metalness: 0.4, roughness: 0.6 }));
      const plateEdge = new THREE.LineSegments(new THREE.EdgesGeometry(plate.geometry), new THREE.LineBasicMaterial({ color: 0x5fdcff, transparent: true, opacity: 0.75 }));
      deck.add(plate, plateEdge);
      const beam = new THREE.Line(new THREE.BufferGeometry().setFromPoints([new THREE.Vector3(0, 0.2, 0), new THREE.Vector3(0, 0.2, center.length() - 5)]), new THREE.LineDashedMaterial({ color: 0x2bb8ec, dashSize: 0.6, gapSize: 0.5, transparent: true, opacity: 0.35 }));
      beam.computeLineDistances();
      deck.add(beam);
      world.add(deck);
      const label = document.createElement("div");
      label.className = "s-label building";
      label.innerHTML = `${esc(g.root ? g.root.name : "기타 구역")}<small>실 ${n}</small>`;
      labels.appendChild(label);
      const dk = { group: deck, label, id: g.root ? g.root.id : null, center, w, d };
      decks.push(dk);
      g.rooms.forEach((r, i) => {
        const cx = (i % cols) * cell - ((cols - 1) * cell) / 2;
        const cz = Math.floor(i / cols) * cell - ((rows - 1) * cell) / 2;
        const count = r.items || 0;
        const hgt = 0.8 + Math.log2(count + 1) * 0.9;
        const color = r.alerts ? C.amber : C.cyan;
        const mat = new THREE.MeshStandardMaterial({ color: color.clone().multiplyScalar(0.55), emissive: color, emissiveIntensity: 0.35, transparent: true, opacity: 0.38, metalness: 0.1, roughness: 0.4, depthWrite: false });
        const box = new THREE.Mesh(new THREE.BoxGeometry(3.6, hgt, 3.6), mat);
        box.position.set(cx, 0.2 + hgt / 2, cz);
        const edges = new THREE.LineSegments(new THREE.EdgesGeometry(box.geometry), new THREE.LineBasicMaterial({ color: r.alerts ? 0xffc857 : 0x8fe6ff, transparent: true, opacity: 0.9 }));
        edges.position.copy(box.position);
        deck.add(box, edges);
        const lab = document.createElement("div");
        lab.className = `s-label${r.alerts ? " alert" : ""}`;
        lab.innerHTML = `${esc(r.name)}<small>${count}종${r.alerts ? ` · 경보 ${r.alerts}` : ""}</small>`;
        labels.appendChild(lab);
        roomLabelsEl.push(lab);
        rooms.set(r.id, { box, edges, label: lab, data: r, deck: dk, hgt, base: color, beacon: null });
      });
    });
    // 선반 같은 하위 장소는 가장 가까운 실로 모은다(표시용)
    reframe();
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
      g.position.set(p.x, 0, p.z);
      const beam = new THREE.Mesh(new THREE.CylinderGeometry(0.35, 0.9, 40, 16, 1, true), new THREE.MeshBasicMaterial({ color: 0xffc857, transparent: true, opacity: 0.28, side: THREE.DoubleSide, depthWrite: false, blending: THREE.AdditiveBlending }));
      beam.position.y = 20;
      const ring = new THREE.Mesh(new THREE.RingGeometry(1.6, 2.1, 48), new THREE.MeshBasicMaterial({ color: 0xffc857, transparent: true, opacity: 0.8, side: THREE.DoubleSide, depthWrite: false }));
      ring.rotation.x = -Math.PI / 2;
      ring.position.y = 0.4;
      const sprite = new THREE.Sprite(new THREE.SpriteMaterial({ map: glow, color: 0xffd88a, transparent: true, opacity: 0.9, depthWrite: false, blending: THREE.AdditiveBlending }));
      sprite.scale.setScalar(6);
      sprite.position.y = r.hgt + 1.2;
      g.add(beam, ring, sprite);
      world.add(g);
      beacons.push({ group: g, ring, beam, t0: performance.now() });
    }
    // 첫 실 쪽으로 카메라를 돌린다
    const p = worldPos(hit[0]);
    want.target.set(p.x * 0.55, 1, p.z * 0.55);
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
        d.group.getWorldPosition(tmpV);
        place(d.label, tmpV.clone().setY(1.2), 8);
      }
      // 장소 화면이거나 가까이 볼 때만 실 이름을 모두 보인다(개요에서는 경보·찾은 곳·가리킨 곳만)
      const detail = station === "decks" || rooms.size <= 5 || cam.radius < baseRadius * 0.75;
      for (const r of rooms.values()) {
        const show = detail || r.label.classList.contains("hot") || hovered === r || r.data.alerts > 0;
        if (!show) { r.label.style.display = "none"; continue; }
        place(r.label, worldPos(r, tmpV).clone().setY(r.hgt + 0.8), 4);
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
