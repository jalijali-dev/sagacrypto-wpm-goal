-- =============================================================================
-- Growth Agent + GSC — Referensi skema database
-- =============================================================================
-- Dibuat: 28 Juli 2026, ditranskrip LANGSUNG dari kode PHP yang berjalan
-- (cms-admin/includes/gsc-api.php § cms_gsc_ensure_schema() dan
-- cms-admin/includes/growth-agent-service.php § cms_growth_agent_ensure_schema()),
-- bukan hasil ingatan/tebakan.
--
-- PENTING: file ini murni DOKUMENTASI/REFERENSI/DISASTER-RECOVERY.
-- Semua tabel & kolom di bawah ini SUDAH otomatis dibuat sendiri oleh
-- aplikasi (auto-migration lazy via cms_ensure_table()/cms_ensure_column())
-- begitu halaman cms-admin/pages/growth-agent.php pertama kali dibuka di
-- admin panel. TIDAK WAJIB dijalankan manual — cukup pastikan halaman
-- Growth Agent pernah diakses sekali di production.
--
-- Jalankan file ini manual HANYA kalau: (a) mau setup fresh install/staging
-- tanpa lewat UI dulu, atau (b) disaster recovery. Semua statement di bawah
-- idempotent — aman dijalankan berkali-kali.
-- =============================================================================


-- -----------------------------------------------------------------------------
-- 1. GSC Collector — gsc_settings, gsc_query_data, gsc_opportunities
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS gsc_settings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  service_account_email VARCHAR(255) DEFAULT NULL,
  service_account_json_enc LONGTEXT DEFAULT NULL,
  site_url VARCHAR(255) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 0,
  fetch_lookback_days INT UNSIGNED NOT NULL DEFAULT 14,
  fetch_window_days INT UNSIGNED NOT NULL DEFAULT 90,
  opportunity_thresholds_json LONGTEXT DEFAULT NULL,
  memory_thresholds_json LONGTEXT DEFAULT NULL,
  last_memory_detection_at TIMESTAMP NULL DEFAULT NULL,
  last_performance_snapshot_at TIMESTAMP NULL DEFAULT NULL,
  last_fetch_status VARCHAR(20) DEFAULT NULL,
  last_fetch_message VARCHAR(255) DEFAULT NULL,
  last_fetch_rows INT UNSIGNED DEFAULT NULL,
  last_fetch_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Baris tunggal, dibuat otomatis kalau tabel baru dibuat (is_active=0 default)
INSERT INTO gsc_settings (is_active)
SELECT 0 WHERE NOT EXISTS (SELECT 1 FROM gsc_settings);

CREATE TABLE IF NOT EXISTS gsc_query_data (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  query VARCHAR(255) NOT NULL,
  page_url VARCHAR(500) NOT NULL,
  matched_page_id INT UNSIGNED DEFAULT NULL,
  clicks INT UNSIGNED NOT NULL DEFAULT 0,
  impressions INT UNSIGNED NOT NULL DEFAULT 0,
  ctr DECIMAL(7,4) DEFAULT NULL,
  position DECIMAL(6,2) DEFAULT NULL,
  data_date DATE NOT NULL,
  dedupe_hash CHAR(32) NOT NULL,
  fetched_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_gsc_dedupe_hash (dedupe_hash),
  KEY idx_gsc_page (matched_page_id),
  KEY idx_gsc_date (data_date),
  KEY idx_gsc_query (query(100))
);

CREATE TABLE IF NOT EXISTS gsc_opportunities (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  item_type ENUM('page','query') NOT NULL,
  matched_page_id INT UNSIGNED DEFAULT NULL,
  query_text VARCHAR(255) DEFAULT NULL,
  matched_categories VARCHAR(255) NOT NULL DEFAULT '',
  impact_score TINYINT UNSIGNED NOT NULL,
  effort_score TINYINT UNSIGNED NOT NULL,
  priority ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
  recommended_agent VARCHAR(50) NOT NULL,
  recommended_action ENUM('seo_recommendation','gsc_content_optimization','gsc_article_idea','cannibalization_review') NOT NULL,
  reason TEXT DEFAULT NULL,
  metrics_json TEXT DEFAULT NULL,
  status ENUM('open','actioned') NOT NULL DEFAULT 'open',
  linked_job_id INT UNSIGNED DEFAULT NULL,
  dedupe_key CHAR(32) NOT NULL,
  computed_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_gsc_opp_dedupe (dedupe_key),
  KEY idx_gsc_opp_status_priority (status, priority),
  KEY idx_gsc_opp_page (matched_page_id)
);
-- Catatan: kalau tabel gsc_opportunities sudah ada dari sebelumnya TANPA
-- 'cannibalization_review' di kolom recommended_action, jalankan ini sekali:
-- ALTER TABLE gsc_opportunities MODIFY COLUMN recommended_action
--   ENUM('seo_recommendation','gsc_content_optimization','gsc_article_idea','cannibalization_review') NOT NULL;

CREATE TABLE IF NOT EXISTS api_error_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source VARCHAR(30) NOT NULL,
  message TEXT NOT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_api_error_source (source)
);


-- -----------------------------------------------------------------------------
-- 2. Indexing Workflow (gap #2) — gsc_url_inspections
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS gsc_url_inspections (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  page_id INT UNSIGNED NOT NULL,
  url VARCHAR(500) NOT NULL,
  verdict VARCHAR(30) DEFAULT NULL,
  coverage_state VARCHAR(255) DEFAULT NULL,
  robots_txt_state VARCHAR(30) DEFAULT NULL,
  indexing_state VARCHAR(30) DEFAULT NULL,
  page_fetch_state VARCHAR(30) DEFAULT NULL,
  last_crawl_time DATETIME DEFAULT NULL,
  google_canonical VARCHAR(500) DEFAULT NULL,
  user_canonical VARCHAR(500) DEFAULT NULL,
  sitemap VARCHAR(500) DEFAULT NULL,
  raw_response_json TEXT DEFAULT NULL,
  error_message VARCHAR(255) DEFAULT NULL,
  inspected_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_gsc_url_inspection_page (page_id),
  KEY idx_gsc_url_inspection_verdict (verdict)
);


-- -----------------------------------------------------------------------------
-- 3. Growth Agent core — growth_agent_jobs, growth_agent_feedback,
--    growth_agent_style_rules, growth_agent_performance
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS growth_agent_jobs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  job_type VARCHAR(50) NOT NULL COMMENT 'e.g. seo_meta, article_draft',
  agent_key VARCHAR(50) NOT NULL COMMENT 'matches ai_agent_settings.agent_key',
  page_id INT UNSIGNED DEFAULT NULL COMMENT 'pages.page_id — null if not saved yet',
  status ENUM('ready','running','succeeded','failed','manual_action','closed_as_legacy') NOT NULL DEFAULT 'running',
  priority ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
  input_brief TEXT DEFAULT NULL COMMENT 'JSON snapshot of what was sent to the agent',
  output_json TEXT DEFAULT NULL COMMENT 'JSON snapshot of the parsed result',
  model_used VARCHAR(100) DEFAULT NULL,
  tokens_in INT UNSIGNED DEFAULT NULL,
  tokens_out INT UNSIGNED DEFAULT NULL,
  latency_ms INT UNSIGNED DEFAULT NULL,
  error_message TEXT DEFAULT NULL,
  created_by INT UNSIGNED DEFAULT NULL COMMENT 'admins.admin_id, null = system',
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_gaj_status (status),
  KEY idx_gaj_page (page_id),
  KEY idx_gaj_agent_key (agent_key)
);
-- Kalau tabel ini sudah ada dari sebelumnya TANPA 'closed_as_legacy' di status,
-- atau tanpa kolom `priority`, jalankan ini sekali:
-- ALTER TABLE growth_agent_jobs MODIFY COLUMN status
--   ENUM('ready','running','succeeded','failed','manual_action','closed_as_legacy') NOT NULL DEFAULT 'running';
-- ALTER TABLE growth_agent_jobs ADD COLUMN priority
--   ENUM('low','medium','high') NOT NULL DEFAULT 'medium' AFTER status;

CREATE TABLE IF NOT EXISTS growth_agent_feedback (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  job_id INT UNSIGNED NOT NULL,
  action ENUM('approved_as_is','approved_with_edits','rejected','closed_as_legacy') NOT NULL,
  notes TEXT DEFAULT NULL,
  reviewed_by INT UNSIGNED DEFAULT NULL COMMENT 'admins.admin_id',
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_gaf_job (job_id)
);
-- Kalau tabel ini sudah ada dari sebelumnya TANPA 'closed_as_legacy' di action:
-- ALTER TABLE growth_agent_feedback MODIFY COLUMN action
--   ENUM('approved_as_is','approved_with_edits','rejected','closed_as_legacy') NOT NULL;

CREATE TABLE IF NOT EXISTS growth_agent_style_rules (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  rule_text TEXT NOT NULL,
  source ENUM('manual','auto_extracted') NOT NULL DEFAULT 'manual',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_gasr_active (is_active)
);

CREATE TABLE IF NOT EXISTS growth_agent_performance (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  page_id INT UNSIGNED NOT NULL,
  metric_date DATE NOT NULL,
  pageviews INT UNSIGNED NOT NULL DEFAULT 0,
  impressions INT UNSIGNED NOT NULL DEFAULT 0,
  avg_ranking_position DECIMAL(6,2) DEFAULT NULL,
  clicks INT UNSIGNED NOT NULL DEFAULT 0,
  ctr DECIMAL(6,4) DEFAULT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_gap_page_date (page_id, metric_date)
);
-- Kalau tabel ini sudah ada dari sebelumnya (schema-only, sebelum Feedback
-- Loop gap #4) TANPA kolom `impressions`:
-- ALTER TABLE growth_agent_performance ADD COLUMN impressions
--   INT UNSIGNED NOT NULL DEFAULT 0 AFTER pageviews;


-- -----------------------------------------------------------------------------
-- 4. Agent Memory (gap #3) — growth_agent_memory
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS growth_agent_memory (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  pattern_type ENUM('winning_pattern','content_gap') NOT NULL,
  scope_type ENUM('page','query') NOT NULL,
  matched_page_id INT UNSIGNED DEFAULT NULL,
  query_text VARCHAR(255) DEFAULT NULL,
  status ENUM('pending_review','active','stale') NOT NULL DEFAULT 'pending_review',
  evidence_json TEXT DEFAULT NULL,
  distinct_weeks_seen INT UNSIGNED NOT NULL DEFAULT 0,
  dedupe_key CHAR(32) NOT NULL,
  first_detected_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  last_confirmed_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_gam_dedupe (dedupe_key),
  KEY idx_gam_status (status),
  KEY idx_gam_page (matched_page_id)
);

-- =============================================================================
-- Selesai. Total: 9 tabel baru (gsc_settings, gsc_query_data, gsc_opportunities,
-- api_error_log, gsc_url_inspections, growth_agent_jobs, growth_agent_feedback,
-- growth_agent_style_rules, growth_agent_performance, growth_agent_memory —
-- catatan: api_error_log kadang sudah ada duluan dari modul lain, aman
-- di-CREATE IF NOT EXISTS ulang di sini).
-- =============================================================================
