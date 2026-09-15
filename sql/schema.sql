-- inni SQLite schema (single school)
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS settings (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS users (
  id TEXT PRIMARY KEY,
  email TEXT NOT NULL UNIQUE,
  display_name TEXT NOT NULL,
  photo_url TEXT,
  role TEXT NOT NULL DEFAULT 'teacher' CHECK(role IN ('owner','manager','teacher','student')),
  status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','active','disabled')),
  google_sub TEXT UNIQUE,
  telegram_chat_id TEXT,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS locations (
  id TEXT PRIMARY KEY,
  name TEXT NOT NULL,
  kind TEXT NOT NULL CHECK(kind IN ('building','room','zone','storage','bin')),
  parent_id TEXT REFERENCES locations(id) ON DELETE SET NULL,
  code TEXT,
  notes TEXT,
  image_path TEXT,
  sort_order INTEGER NOT NULL DEFAULT 0,
  qr_code TEXT NOT NULL UNIQUE,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS categories (
  id TEXT PRIMARY KEY,
  name TEXT NOT NULL,
  parent_id TEXT REFERENCES categories(id) ON DELETE SET NULL,
  sort_order INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS catalog_items (
  id TEXT PRIMARY KEY,
  name TEXT NOT NULL,
  type TEXT NOT NULL CHECK(type IN ('equipment','fixture','consumable','part')),
  description TEXT,
  tags TEXT NOT NULL DEFAULT '[]',
  unit TEXT NOT NULL DEFAULT 'ea',
  min_stock REAL,
  edufine_number TEXT,
  manufacturer TEXT,
  budget_program TEXT,
  budget_year INTEGER,
  image_path TEXT,
  favorite INTEGER NOT NULL DEFAULT 0,
  qr_code TEXT NOT NULL UNIQUE,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS assets (
  id TEXT PRIMARY KEY,
  catalog_item_id TEXT NOT NULL REFERENCES catalog_items(id),
  name TEXT NOT NULL,
  management_number TEXT NOT NULL UNIQUE,
  serial_number TEXT,
  edufine_number TEXT,
  status TEXT NOT NULL DEFAULT 'available'
    CHECK(status IN ('available','on_loan','repair','moving','lost','retired')),
  location_id TEXT NOT NULL REFERENCES locations(id),
  tags TEXT NOT NULL DEFAULT '[]',
  image_path TEXT,
  purchase_date TEXT,
  useful_life_years INTEGER,
  budget_program TEXT,
  budget_year INTEGER,
  notes TEXT,
  qr_code TEXT NOT NULL UNIQUE,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS stock_lots (
  id TEXT PRIMARY KEY,
  catalog_item_id TEXT NOT NULL REFERENCES catalog_items(id),
  location_id TEXT NOT NULL REFERENCES locations(id),
  quantity REAL NOT NULL DEFAULT 0,
  updated_at TEXT NOT NULL,
  UNIQUE(catalog_item_id, location_id)
);

CREATE TABLE IF NOT EXISTS loans (
  id TEXT PRIMARY KEY,
  kind TEXT NOT NULL CHECK(kind IN ('asset','consumable')),
  asset_id TEXT REFERENCES assets(id),
  catalog_item_id TEXT REFERENCES catalog_items(id),
  quantity REAL NOT NULL DEFAULT 1,
  borrower_user_id TEXT REFERENCES users(id),
  borrower_name TEXT NOT NULL,
  borrower_note TEXT,
  from_location_id TEXT REFERENCES locations(id),
  due_at TEXT,
  returned_at TEXT,
  status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','returned','overdue')),
  purpose TEXT,
  created_at TEXT NOT NULL,
  created_by TEXT NOT NULL REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS activity_logs (
  id TEXT PRIMARY KEY,
  action TEXT NOT NULL,
  entity_type TEXT NOT NULL,
  entity_id TEXT NOT NULL,
  actor_id TEXT,
  actor_name TEXT NOT NULL,
  summary TEXT NOT NULL,
  meta_json TEXT,
  created_at TEXT NOT NULL
);

-- One cancel per usage-issue log. Restores quantity; history stays in activity_logs.
CREATE TABLE IF NOT EXISTS stock_issue_cancels (
  id TEXT PRIMARY KEY,
  issue_log_id TEXT NOT NULL UNIQUE,
  catalog_item_id TEXT NOT NULL REFERENCES catalog_items(id),
  lot_id TEXT NOT NULL,
  location_id TEXT NOT NULL REFERENCES locations(id),
  quantity REAL NOT NULL,
  reason TEXT NOT NULL,
  actor_id TEXT,
  actor_name TEXT NOT NULL,
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS reports (
  id TEXT PRIMARY KEY,
  target_type TEXT NOT NULL CHECK(target_type IN ('room','asset')),
  target_id TEXT NOT NULL,
  reporter_user_id TEXT REFERENCES users(id),
  reporter_name TEXT NOT NULL,
  title TEXT NOT NULL,
  body TEXT NOT NULL,
  image_path TEXT,
  status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open','in_progress','done','rejected')),
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS inventory_checks (
  id TEXT PRIMARY KEY,
  location_id TEXT NOT NULL REFERENCES locations(id),
  location_name TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','done')),
  started_by TEXT NOT NULL REFERENCES users(id),
  started_at TEXT NOT NULL,
  finished_at TEXT
);

CREATE TABLE IF NOT EXISTS alert_dispatches (
  event_key TEXT NOT NULL,
  entity_id TEXT NOT NULL,
  sent_at TEXT NOT NULL,
  PRIMARY KEY (event_key, entity_id)
);

CREATE TABLE IF NOT EXISTS inventory_check_lines (
  id TEXT PRIMARY KEY,
  check_id TEXT NOT NULL REFERENCES inventory_checks(id) ON DELETE CASCADE,
  kind TEXT NOT NULL CHECK(kind IN ('asset','item')),
  asset_id TEXT REFERENCES assets(id),
  catalog_item_id TEXT REFERENCES catalog_items(id),
  stock_lot_id TEXT,
  location_id TEXT NOT NULL REFERENCES locations(id),
  name TEXT NOT NULL,
  code TEXT,
  expected_qty REAL NOT NULL DEFAULT 1,
  unit TEXT,
  confirmed_at TEXT,
  confirmed_by TEXT REFERENCES users(id)
);

CREATE INDEX IF NOT EXISTS idx_assets_location ON assets(location_id);
CREATE INDEX IF NOT EXISTS idx_assets_status ON assets(status);
CREATE INDEX IF NOT EXISTS idx_assets_mgmt ON assets(management_number);
CREATE INDEX IF NOT EXISTS idx_catalog_budget ON catalog_items(budget_year, budget_program);
CREATE INDEX IF NOT EXISTS idx_assets_budget ON assets(budget_year, budget_program);
CREATE INDEX IF NOT EXISTS idx_locations_parent ON locations(parent_id);
CREATE INDEX IF NOT EXISTS idx_locations_kind ON locations(kind);
CREATE INDEX IF NOT EXISTS idx_stock_location ON stock_lots(location_id);
CREATE INDEX IF NOT EXISTS idx_loans_status ON loans(status);
CREATE INDEX IF NOT EXISTS idx_loans_asset ON loans(asset_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_loans_one_open_asset
  ON loans(asset_id)
  WHERE asset_id IS NOT NULL AND status IN ('active','overdue');
CREATE INDEX IF NOT EXISTS idx_logs_entity ON activity_logs(entity_id, created_at);
CREATE INDEX IF NOT EXISTS idx_reports_status ON reports(status);
CREATE INDEX IF NOT EXISTS idx_reports_target ON reports(target_type, target_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_inventory_checks_one_active
  ON inventory_checks(status)
  WHERE status = 'active';
CREATE INDEX IF NOT EXISTS idx_inventory_lines_check
  ON inventory_check_lines(check_id, confirmed_at);
CREATE UNIQUE INDEX IF NOT EXISTS idx_inventory_lines_asset
  ON inventory_check_lines(check_id, asset_id)
  WHERE asset_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_inventory_lines_lot
  ON inventory_check_lines(check_id, stock_lot_id)
  WHERE stock_lot_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_stock_issue_cancels_item ON stock_issue_cancels(catalog_item_id, created_at);
