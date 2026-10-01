// SQLite 스키마. 버전을 올릴 때는 MIGRATIONS 끝에 새 항목을 더한다(앞 항목은 고치지 않는다).
export const MIGRATIONS = [
  {
    version: 1,
    name: "초기 스키마",
    sql: `
CREATE TABLE settings (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

-- API 키처럼 화면에 다시 보이지 않는 값
CREATE TABLE secrets (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

CREATE TABLE users (
  id TEXT PRIMARY KEY,
  email TEXT NOT NULL UNIQUE COLLATE NOCASE,
  name TEXT NOT NULL DEFAULT '',
  photo_url TEXT,
  role TEXT NOT NULL DEFAULT 'teacher' CHECK (role IN ('owner','manager','teacher','student')),
  status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','active','disabled')),
  firebase_uid TEXT UNIQUE,
  note TEXT,
  last_login_at TEXT,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

CREATE TABLE sessions (
  id TEXT PRIMARY KEY,
  user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  created_at TEXT NOT NULL,
  expires_at TEXT NOT NULL,
  last_seen_at TEXT NOT NULL,
  user_agent TEXT,
  ip TEXT
);
CREATE INDEX idx_sessions_user ON sessions(user_id);

CREATE TABLE locations (
  id TEXT PRIMARY KEY,
  parent_id TEXT REFERENCES locations(id) ON DELETE RESTRICT,
  kind TEXT NOT NULL CHECK (kind IN ('building','floor','room','zone','storage','bin')),
  name TEXT NOT NULL,
  code TEXT,
  description TEXT,
  manager_id TEXT REFERENCES users(id) ON DELETE SET NULL,
  image TEXT,
  sort INTEGER NOT NULL DEFAULT 0,
  qr TEXT NOT NULL UNIQUE,
  archived_at TEXT,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);
CREATE INDEX idx_locations_parent ON locations(parent_id);

CREATE TABLE categories (
  id TEXT PRIMARY KEY,
  parent_id TEXT REFERENCES categories(id) ON DELETE SET NULL,
  name TEXT NOT NULL,
  sort INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL
);

-- 품목: "디지털 멀티미터" 같은 종류. 장비는 assets(개체), 수량 품목은 stocks(위치별 수량)
CREATE TABLE items (
  id TEXT PRIMARY KEY,
  name TEXT NOT NULL,
  kind TEXT NOT NULL CHECK (kind IN ('equipment','fixture','consumable','part')),
  category_id TEXT REFERENCES categories(id) ON DELETE SET NULL,
  aliases TEXT NOT NULL DEFAULT '',
  manufacturer TEXT,
  model TEXT,
  spec TEXT,
  description TEXT,
  unit TEXT NOT NULL DEFAULT '개',
  min_stock REAL,
  tags TEXT NOT NULL DEFAULT '[]',
  image TEXT,
  thumb TEXT,
  price REAL,
  vendor TEXT,
  product_url TEXT,
  barcode TEXT,
  edufine_number TEXT,
  class_number TEXT,
  budget_program TEXT,
  budget_year INTEGER,
  useful_life_years INTEGER,
  favorite INTEGER NOT NULL DEFAULT 0,
  qr TEXT NOT NULL UNIQUE,
  notes TEXT,
  archived_at TEXT,
  created_by TEXT,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);
CREATE INDEX idx_items_kind ON items(kind);
CREATE INDEX idx_items_category ON items(category_id);
CREATE INDEX idx_items_barcode ON items(barcode);

CREATE TABLE assets (
  id TEXT PRIMARY KEY,
  item_id TEXT NOT NULL REFERENCES items(id) ON DELETE CASCADE,
  label TEXT,
  management_number TEXT UNIQUE,
  serial_number TEXT,
  edufine_number TEXT,
  status TEXT NOT NULL DEFAULT 'available' CHECK (status IN ('available','on_loan','repair','lost','retired')),
  location_id TEXT REFERENCES locations(id) ON DELETE RESTRICT,
  image TEXT,
  thumb TEXT,
  notes TEXT,
  purchase_date TEXT,
  purchase_price REAL,
  useful_life_years INTEGER,
  budget_program TEXT,
  budget_year INTEGER,
  qr TEXT NOT NULL UNIQUE,
  retired_at TEXT,
  retire_kind TEXT,
  retire_reason TEXT,
  retire_evidence TEXT,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);
CREATE INDEX idx_assets_item ON assets(item_id);
CREATE INDEX idx_assets_location ON assets(location_id);
CREATE INDEX idx_assets_status ON assets(status);

CREATE TABLE stocks (
  id TEXT PRIMARY KEY,
  item_id TEXT NOT NULL REFERENCES items(id) ON DELETE CASCADE,
  location_id TEXT NOT NULL REFERENCES locations(id) ON DELETE RESTRICT,
  quantity REAL NOT NULL DEFAULT 0 CHECK (quantity >= 0),
  lot_code TEXT NOT NULL DEFAULT '',
  expires_at TEXT,
  updated_at TEXT NOT NULL,
  UNIQUE (item_id, location_id, lot_code)
);
CREATE INDEX idx_stocks_location ON stocks(location_id);

CREATE TABLE loans (
  id TEXT PRIMARY KEY,
  item_id TEXT NOT NULL REFERENCES items(id) ON DELETE CASCADE,
  asset_id TEXT REFERENCES assets(id) ON DELETE CASCADE,
  stock_id TEXT,
  quantity REAL NOT NULL DEFAULT 1,
  from_location_id TEXT REFERENCES locations(id) ON DELETE SET NULL,
  borrower_user_id TEXT REFERENCES users(id) ON DELETE SET NULL,
  borrower_name TEXT NOT NULL,
  borrower_note TEXT,
  purpose TEXT,
  due_at TEXT,
  status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','returned')),
  returned_at TEXT,
  return_condition TEXT CHECK (return_condition IS NULL OR return_condition IN ('ok','issue')),
  return_note TEXT,
  return_location_id TEXT,
  created_by TEXT,
  returned_by TEXT,
  created_at TEXT NOT NULL
);
CREATE UNIQUE INDEX idx_loans_open_asset ON loans(asset_id) WHERE asset_id IS NOT NULL AND status = 'active';
CREATE INDEX idx_loans_status ON loans(status, due_at);
CREATE INDEX idx_loans_borrower ON loans(borrower_user_id);

-- 모든 변화의 기록. data 에는 되돌리기에 필요한 전후 값이 들어간다.
CREATE TABLE events (
  id TEXT PRIMARY KEY,
  at TEXT NOT NULL,
  actor_id TEXT,
  actor_name TEXT NOT NULL,
  action TEXT NOT NULL,
  item_id TEXT,
  asset_id TEXT,
  location_id TEXT,
  to_location_id TEXT,
  loan_id TEXT,
  repair_id TEXT,
  qty REAL,
  summary TEXT NOT NULL,
  data TEXT,
  via TEXT NOT NULL DEFAULT 'app',
  undone_at TEXT,
  undone_by TEXT,
  undo_of TEXT
);
CREATE INDEX idx_events_at ON events(at);
CREATE INDEX idx_events_item ON events(item_id, at);
CREATE INDEX idx_events_asset ON events(asset_id, at);
CREATE INDEX idx_events_location ON events(location_id, at);
CREATE INDEX idx_events_actor ON events(actor_id, at);

CREATE TABLE repairs (
  id TEXT PRIMARY KEY,
  target_type TEXT NOT NULL CHECK (target_type IN ('asset','item','location')),
  target_id TEXT NOT NULL,
  title TEXT NOT NULL,
  body TEXT NOT NULL DEFAULT '',
  image TEXT,
  status TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open','in_progress','done','rejected')),
  urgency TEXT NOT NULL DEFAULT 'normal' CHECK (urgency IN ('normal','urgent')),
  reporter_id TEXT,
  reporter_name TEXT NOT NULL,
  resolution TEXT,
  cost_amount REAL,
  cost_vendor TEXT,
  cost_budget TEXT,
  cost_at TEXT,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  closed_at TEXT
);
CREATE INDEX idx_repairs_status ON repairs(status);
CREATE INDEX idx_repairs_target ON repairs(target_type, target_id);

CREATE TABLE audits (
  id TEXT PRIMARY KEY,
  location_id TEXT NOT NULL REFERENCES locations(id) ON DELETE CASCADE,
  title TEXT,
  status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','done','cancelled')),
  started_by TEXT,
  started_by_name TEXT,
  started_at TEXT NOT NULL,
  finished_at TEXT,
  finished_by_name TEXT,
  note TEXT
);

CREATE TABLE audit_lines (
  id TEXT PRIMARY KEY,
  audit_id TEXT NOT NULL REFERENCES audits(id) ON DELETE CASCADE,
  kind TEXT NOT NULL CHECK (kind IN ('asset','stock')),
  item_id TEXT NOT NULL,
  asset_id TEXT,
  stock_id TEXT,
  location_id TEXT,
  name TEXT NOT NULL,
  code TEXT,
  expected_qty REAL NOT NULL DEFAULT 1,
  counted_qty REAL,
  checked_at TEXT,
  checked_by_name TEXT,
  extra INTEGER NOT NULL DEFAULT 0,
  resolution TEXT,
  resolved_at TEXT,
  resolved_by_name TEXT
);
CREATE INDEX idx_audit_lines ON audit_lines(audit_id);

CREATE TABLE alert_dispatches (
  key TEXT PRIMARY KEY,
  sent_at TEXT NOT NULL
);
`,
  },
  {
    // 도면: 층(또는 단층 건물) 하나에 그림 한 장. 그 위에 그린 다각형(shapes)을 실·보관함과 잇는다.
    // shapes = [{ location_id, pts: [[x, y], …] }]  (그림 픽셀 좌표)
    // marks  = [{ type: entrance|stairs|elevator, x, y, label }]
    version: 2,
    sql: `
CREATE TABLE floor_plans (
  id TEXT PRIMARY KEY,
  location_id TEXT NOT NULL UNIQUE REFERENCES locations(id) ON DELETE CASCADE,
  image TEXT,
  width INTEGER NOT NULL DEFAULT 0,
  height INTEGER NOT NULL DEFAULT 0,
  level INTEGER NOT NULL DEFAULT 1,
  meters_per_px REAL,
  shapes TEXT NOT NULL DEFAULT '[]',
  marks TEXT NOT NULL DEFAULT '[]',
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);
`,
  },
];

export const SCHEMA_VERSION = MIGRATIONS[MIGRATIONS.length - 1].version;
