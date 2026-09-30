// 시연용 데이터. 개발 모드(SEED_DEMO=1)나 첫 설정에서 "예시로 시작"을 고르면 넣는다.
import { nowIso, newId } from "./util.js";
import { setSetting } from "./db.js";
import { createLocation, createItem, createCategory } from "./inventory.js";
import { loan, move } from "./actions.js";
import { createRepair } from "./repairs.js";

const SYSTEM = { id: null, name: "시스템", role: "owner", via: "import" };

export function seedDemo(ctx, { users = true } = {}) {
  const db = ctx.db;
  if (db.prepare("SELECT 1 FROM items LIMIT 1").get()) return false;
  const t = nowIso();
  if (users) {
    const ins = db.prepare("INSERT OR IGNORE INTO users(id, email, name, role, status, created_at, updated_at) VALUES(?, ?, ?, ?, 'active', ?, ?)");
    ins.run(newId(), "owner@demo.school", "김담당", "owner", t, t);
    ins.run(newId(), "manager@demo.school", "박실무", "manager", t, t);
    ins.run(newId(), "teacher@demo.school", "이수업", "teacher", t, t);
    ins.run(newId(), "student@demo.school", "최학생", "student", t, t);
  }
  setSetting(db, "school", { name: "inni 시연 마이스터고", short_name: "시연고" });
  const teacher = db.prepare("SELECT id, name FROM users WHERE email = 'teacher@demo.school'").get() || { id: null, name: "이수업" };

  const L = {};
  const loc = (key, name, kind, parent, code) => { L[key] = createLocation(ctx, SYSTEM, { name, kind, parent_id: parent ? L[parent] : null, code }).id; };
  loc("lab", "실습동", "building");
  loc("main", "본관", "building");
  loc("elec", "전자실습실", "room", "lab", "E-201");
  loc("elecCab", "계측기 캐비닛", "storage", "elec");
  loc("weld", "용접실", "room", "lab", "W-103");
  loc("auto", "자동차엔진실", "room", "lab", "A-110");
  loc("tool", "공구실", "room", "lab", "T-001");
  loc("toolA", "선반 A", "storage", "tool");
  loc("toolB", "선반 B", "storage", "tool");
  loc("maker", "3D프린팅실", "room", "lab", "M-204");
  loc("store", "기자재창고", "room", "lab", "S-B1");
  loc("rack", "랙 1", "storage", "store");
  loc("office", "교무실", "room", "main", "1-12");
  loc("av", "시청각실", "room", "main", "2-05");

  const C = {};
  for (const n of ["계측기", "공구", "가공장비", "용접", "정보기기", "전자부품", "소모품", "가구"]) C[n] = createCategory(ctx, SYSTEM, { name: n }).id;

  const I = {};
  const eq = (key, f, count, at, prefix) => {
    I[key] = createItem(ctx, SYSTEM, { ...f, kind: "equipment", location_id: L[at], unit_count: count, number_prefix: prefix });
  };
  const qty = (key, f, q, at, extra = {}) => {
    I[key] = createItem(ctx, SYSTEM, { ...f, location_id: L[at], quantity: q, ...extra });
  };
  eq("scope", { name: "디지털 오실로스코프", category_id: C["계측기"], manufacturer: "RIGOL", model: "DS1054Z", aliases: "오실로, 스코프, oscilloscope", spec: "50MHz 4채널 1GSa/s", useful_life_years: 8, price: 590000, unit_defaults: { purchase_date: "2021-03-15", budget_program: "마이스터고 기자재", budget_year: 2021 } }, 3, "elecCab", "전장-2021-");
  eq("dmm", { name: "디지털 멀티미터", category_id: C["계측기"], manufacturer: "Fluke", model: "87V", aliases: "멀티테스터, 테스터기, multimeter", useful_life_years: 7, price: 780000, unit_defaults: { purchase_date: "2024-04-02", budget_year: 2024 } }, 5, "elecCab", "전장-2024-");
  eq("cnc", { name: "CNC 밀링머신", category_id: C["가공장비"], manufacturer: "화천기계", model: "VESTA-660", useful_life_years: 10, unit_defaults: { purchase_date: "2014-02-20" } }, 1, "store", "기공-2014-");
  eq("welder", { name: "CO2 용접기", category_id: C["용접"], manufacturer: "현대웰딩", model: "HW-350", useful_life_years: 8 }, 2, "weld", "용접-2022-");
  eq("torque", { name: "토크렌치", category_id: C["공구"], manufacturer: "TONE", model: "T4MN100", aliases: "토크 렌치", spec: '1/2" 20~100N·m' }, 2, "toolB", "자차-2024-");
  eq("drill", { name: "충전드릴", category_id: C["공구"], manufacturer: "BOSCH", model: "GSR 18V-50", aliases: "전동드릴, 드라이버드릴" }, 4, "toolA", "공통-2024-");
  eq("laptop", { name: "노트북", category_id: C["정보기기"], manufacturer: "LG", model: "그램 15Z90S", aliases: "랩탑, 그램", useful_life_years: 5, unit_defaults: { purchase_date: "2019-08-30" } }, 10, "store", "정보-2019-");
  eq("printer3d", { name: "3D 프린터", category_id: C["가공장비"], manufacturer: "Bambu Lab", model: "P1S", aliases: "쓰리디프린터, 3d printer" }, 2, "maker", "메이커-2025-");
  eq("projector", { name: "빔프로젝터", category_id: C["정보기기"], manufacturer: "EPSON", model: "EB-X51", aliases: "프로젝터, 빔" }, 1, "av", "정보-2023-");

  qty("chair", { name: "실습 의자", kind: "fixture", category_id: C["가구"], unit: "개" }, 24, "elec");
  qty("bench", { name: "작업대", kind: "fixture", category_id: C["가구"], unit: "개" }, 8, "weld");
  qty("solder", { name: "납땜 실납", kind: "consumable", category_id: C["소모품"], unit: "롤", min_stock: 5, manufacturer: "희성소재", spec: "Sn63/Pb37 0.8mm", aliases: "실납, 땜납" }, 18, "elec", { lot_code: "SN-2025-11", expires_at: "2028-11-30" });
  qty("wire", { name: "전선 1.5sq", kind: "consumable", category_id: C["소모품"], unit: "m", min_stock: 20, aliases: "전선, 케이블" }, 12, "store");
  qty("bits", { name: "드릴비트 세트", kind: "consumable", category_id: C["공구"], unit: "세트", min_stock: 2 }, 1, "toolA");
  qty("pla", { name: "PLA 필라멘트", kind: "consumable", category_id: C["소모품"], unit: "롤", min_stock: 4, spec: "1.75mm 1kg", aliases: "필라멘트, pla" }, 6, "maker", { expires_at: new Date(Date.now() + 20 * 86400000).toISOString().slice(0, 10) });
  qty("tape", { name: "절연테이프", kind: "consumable", category_id: C["소모품"], unit: "개", min_stock: 10 }, 30, "elec");
  qty("rod", { name: "용접봉", kind: "consumable", category_id: C["용접"], unit: "kg", min_stock: 10, spec: "E4313 3.2mm" }, 8, "weld");
  qty("paper", { name: "A4 복사용지", kind: "consumable", category_id: C["소모품"], unit: "박스", min_stock: 3 }, 5, "office");
  qty("res", { name: "저항 키트", kind: "part", category_id: C["전자부품"], unit: "세트", min_stock: 1, spec: "1/4W 30종" }, 4, "elecCab");
  qty("uno", { name: "아두이노 우노", kind: "part", category_id: C["전자부품"], unit: "개", min_stock: 10, manufacturer: "Arduino", model: "UNO R4", aliases: "아두이노, arduino" }, 25, "elec");
  qty("bread", { name: "브레드보드", kind: "part", category_id: C["전자부품"], unit: "개", min_stock: 10 }, 30, "elec");

  // 몇 가지 움직임
  const unit = (key, i) => I[key].units[i].id;
  const inHours = (h) => new Date(Date.now() + h * 3600000).toISOString();
  move(ctx, SYSTEM, { targets: [{ asset_id: unit("scope", 2) }, { asset_id: unit("dmm", 4) }], to_location_id: L.elec });
  loan(ctx, { ...SYSTEM, id: teacher.id, name: teacher.name }, { asset_ids: [unit("scope", 1)], borrower_user_id: teacher.id, due_at: inHours(3), purpose: "5교시 전자회로 실습" });
  loan(ctx, SYSTEM, { asset_ids: [unit("drill", 1)], borrower_name: "박학생", borrower_note: "3-2 / 졸업작품", due_at: inHours(-30), purpose: "야간 프로젝트" });
  loan(ctx, SYSTEM, { asset_ids: [unit("laptop", 0), unit("laptop", 1)], borrower_name: "정교사", borrower_note: "2학년 코딩반", due_at: inHours(26), purpose: "방과후 수업" });
  loan(ctx, SYSTEM, { stock_id: I.uno.stock.id, qty: 6, borrower_name: "로봇동아리", borrower_note: "동아리실", due_at: inHours(24 * 6), purpose: "대회 준비" });
  createRepair(ctx, { ...SYSTEM, name: "박실무" }, { target_type: "asset", target_id: unit("welder", 1), title: "와이어 송급 불량", body: "용접 중 와이어 송급이 끊깁니다. 롤러 점검 필요.", urgency: "urgent", mark_repair: true });
  db.prepare("UPDATE assets SET status = 'repair' WHERE id = ?").run(unit("welder", 1));
  ctx.changed({ kind: "seed" });
  return true;
}
