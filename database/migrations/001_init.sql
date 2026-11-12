-- SRMS 초기 스키마. company 와 permission 을 제외한 모든 테이블의 첫 컬럼은 company_cd.

CREATE TABLE company (
  company_cd   TEXT PRIMARY KEY,
  company_nm   TEXT NOT NULL,
  default_lang TEXT NOT NULL DEFAULT 'ko',
  use_yn       INTEGER NOT NULL DEFAULT 1,
  created_at   TEXT NOT NULL
);

CREATE TABLE permission (
  perm_cd    TEXT PRIMARY KEY,
  perm_nm    TEXT NOT NULL
);

CREATE TABLE code_group (
  company_cd TEXT NOT NULL REFERENCES company(company_cd),
  group_cd   TEXT NOT NULL,
  group_nm   TEXT NOT NULL,
  use_yn     INTEGER NOT NULL DEFAULT 1,
  PRIMARY KEY (company_cd, group_cd)
);
CREATE TABLE code (
  company_cd TEXT NOT NULL,
  group_cd   TEXT NOT NULL,
  code_cd    TEXT NOT NULL,
  code_nm    TEXT NOT NULL,
  sort_no    INTEGER NOT NULL DEFAULT 0,
  use_yn     INTEGER NOT NULL DEFAULT 1,
  PRIMARY KEY (company_cd, group_cd, code_cd),
  FOREIGN KEY (company_cd, group_cd) REFERENCES code_group(company_cd, group_cd)
);

CREATE TABLE dept (
  company_cd TEXT NOT NULL REFERENCES company(company_cd),
  dept_id    INTEGER PRIMARY KEY,
  dept_nm    TEXT NOT NULL,
  is_it      INTEGER NOT NULL DEFAULT 0,
  use_yn     INTEGER NOT NULL DEFAULT 1,
  UNIQUE (company_cd, dept_id)
);
CREATE TABLE user (
  company_cd TEXT NOT NULL REFERENCES company(company_cd),
  user_id    INTEGER PRIMARY KEY,
  login_id   TEXT NOT NULL,
  user_nm    TEXT NOT NULL,
  email      TEXT NOT NULL,
  dept_id    INTEGER,
  pw_hash    TEXT,
  use_yn     INTEGER NOT NULL DEFAULT 1,
  lang       TEXT NOT NULL DEFAULT 'ko',
  last_login TEXT,
  created_at TEXT NOT NULL,
  UNIQUE (company_cd, login_id),
  UNIQUE (company_cd, user_id),
  FOREIGN KEY (company_cd, dept_id) REFERENCES dept(company_cd, dept_id)
);
CREATE TABLE role (
  company_cd TEXT NOT NULL REFERENCES company(company_cd),
  role_cd    TEXT NOT NULL,
  role_nm    TEXT NOT NULL,
  PRIMARY KEY (company_cd, role_cd)
);
CREATE TABLE user_role (
  company_cd TEXT NOT NULL,
  user_id    INTEGER NOT NULL,
  role_cd    TEXT NOT NULL,
  PRIMARY KEY (company_cd, user_id, role_cd),
  FOREIGN KEY (company_cd, user_id) REFERENCES user(company_cd, user_id),
  FOREIGN KEY (company_cd, role_cd) REFERENCES role(company_cd, role_cd)
);
CREATE TABLE role_permission (
  company_cd TEXT NOT NULL,
  role_cd    TEXT NOT NULL,
  perm_cd    TEXT NOT NULL REFERENCES permission(perm_cd),
  PRIMARY KEY (company_cd, role_cd, perm_cd),
  FOREIGN KEY (company_cd, role_cd) REFERENCES role(company_cd, role_cd)
);

CREATE TABLE translation (
  company_cd TEXT NOT NULL REFERENCES company(company_cd),
  entity     TEXT NOT NULL,
  entity_key TEXT NOT NULL,
  lang       TEXT NOT NULL,
  text       TEXT NOT NULL,
  PRIMARY KEY (company_cd, entity, entity_key, lang)
);
CREATE TABLE mail_template (
  company_cd TEXT NOT NULL REFERENCES company(company_cd),
  tmpl_cd    TEXT NOT NULL,
  lang       TEXT NOT NULL,
  subject    TEXT NOT NULL,
  body       TEXT NOT NULL,
  PRIMARY KEY (company_cd, tmpl_cd, lang)
);
CREATE TABLE app_setting (
  company_cd    TEXT NOT NULL REFERENCES company(company_cd),
  setting_key   TEXT NOT NULL,
  setting_value TEXT,
  PRIMARY KEY (company_cd, setting_key)
);
CREATE TABLE sso_binding (
  company_cd  TEXT NOT NULL,
  user_id     INTEGER NOT NULL,
  sso_subject TEXT NOT NULL,
  created_at  TEXT NOT NULL,
  PRIMARY KEY (company_cd, user_id),
  UNIQUE (company_cd, sso_subject),
  FOREIGN KEY (company_cd, user_id) REFERENCES user(company_cd, user_id)
);
CREATE TABLE login_fail (
  company_cd TEXT NOT NULL REFERENCES company(company_cd),
  fail_id    INTEGER PRIMARY KEY,
  login_id   TEXT NOT NULL,
  ip_addr    TEXT,
  failed_at  TEXT NOT NULL
);

CREATE TABLE service (
  company_cd   TEXT NOT NULL REFERENCES company(company_cd),
  service_id   INTEGER PRIMARY KEY,
  service_cd   TEXT NOT NULL,
  service_nm   TEXT NOT NULL,
  biz_dept_id  INTEGER,
  it_owner_id  INTEGER,
  use_yn       INTEGER NOT NULL DEFAULT 1,
  UNIQUE (company_cd, service_cd),
  UNIQUE (company_cd, service_id),
  FOREIGN KEY (company_cd, biz_dept_id) REFERENCES dept(company_cd, dept_id),
  FOREIGN KEY (company_cd, it_owner_id) REFERENCES user(company_cd, user_id)
);

CREATE TABLE process_master (
  company_cd   TEXT NOT NULL REFERENCES company(company_cd),
  process_id   INTEGER PRIMARY KEY,
  process_cd   TEXT NOT NULL,
  process_nm   TEXT NOT NULL,
  is_system    INTEGER NOT NULL DEFAULT 0,
  description  TEXT,
  UNIQUE (company_cd, process_cd),
  UNIQUE (company_cd, process_id)
);
CREATE TABLE process_version (
  company_cd   TEXT NOT NULL REFERENCES company(company_cd),
  version_id   INTEGER PRIMARY KEY,
  process_id   INTEGER NOT NULL,
  version_no   INTEGER NOT NULL,
  valid_from   TEXT NOT NULL,
  valid_to     TEXT,
  status       TEXT NOT NULL DEFAULT 'DRAFT',
  created_by   INTEGER,
  created_at   TEXT NOT NULL,
  UNIQUE (company_cd, process_id, version_no),
  UNIQUE (company_cd, version_id),
  FOREIGN KEY (company_cd, process_id) REFERENCES process_master(company_cd, process_id),
  FOREIGN KEY (company_cd, created_by) REFERENCES user(company_cd, user_id)
);
CREATE TABLE process_step (
  company_cd     TEXT NOT NULL REFERENCES company(company_cd),
  step_id        INTEGER PRIMARY KEY,
  version_id     INTEGER NOT NULL,
  step_no        INTEGER NOT NULL,
  step_nm        TEXT NOT NULL,
  step_type      TEXT NOT NULL,
  actor_type     TEXT NOT NULL,
  actor_value    TEXT,
  approval_mode  TEXT NOT NULL DEFAULT 'ANY',
  allow_reject   INTEGER NOT NULL DEFAULT 1,
  reject_to_step INTEGER,
  due_days       INTEGER,
  notify_tmpl    TEXT,
  attach_mode    TEXT NOT NULL DEFAULT 'NONE',
  attach_max_cnt INTEGER,
  attach_max_mb  INTEGER,
  attach_exts    TEXT,
  UNIQUE (company_cd, version_id, step_no),
  UNIQUE (company_cd, step_id),
  FOREIGN KEY (company_cd, version_id) REFERENCES process_version(company_cd, version_id)
);
CREATE TABLE process_step_cc (
  company_cd   TEXT NOT NULL REFERENCES company(company_cd),
  cc_id        INTEGER PRIMARY KEY,
  step_id      INTEGER NOT NULL,
  cc_type      TEXT NOT NULL,
  cc_value     TEXT,
  notify_on    TEXT NOT NULL DEFAULT 'NONE',
  FOREIGN KEY (company_cd, step_id) REFERENCES process_step(company_cd, step_id)
);
CREATE TABLE process_map (
  company_cd   TEXT NOT NULL REFERENCES company(company_cd),
  map_id       INTEGER PRIMARY KEY,
  service_id   INTEGER NOT NULL,
  maint_type   TEXT NOT NULL,
  process_id   INTEGER NOT NULL,
  UNIQUE (company_cd, service_id, maint_type),
  FOREIGN KEY (company_cd, service_id) REFERENCES service(company_cd, service_id),
  FOREIGN KEY (company_cd, process_id) REFERENCES process_master(company_cd, process_id)
);

CREATE TABLE request_seq (
  company_cd TEXT NOT NULL REFERENCES company(company_cd),
  ym         TEXT NOT NULL,
  last_no    INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (company_cd, ym)
);
CREATE TABLE request (
  company_cd      TEXT NOT NULL REFERENCES company(company_cd),
  request_id      INTEGER PRIMARY KEY,
  request_no      TEXT NOT NULL,
  service_id      INTEGER NOT NULL,
  maint_type      TEXT NOT NULL,
  title           TEXT NOT NULL,
  content_html    TEXT NOT NULL,
  priority        TEXT,
  version_id      INTEGER NOT NULL,
  requester_id    INTEGER NOT NULL,
  it_assignee_id  INTEGER,
  status          TEXT NOT NULL,
  current_step_no INTEGER,
  req_due_date    TEXT,
  plan_start      TEXT,
  plan_end        TEXT,
  actual_end      TEXT,
  effort_md       REAL,
  created_at      TEXT NOT NULL,
  updated_at      TEXT NOT NULL,
  UNIQUE (company_cd, request_no),
  UNIQUE (company_cd, request_id),
  FOREIGN KEY (company_cd, service_id) REFERENCES service(company_cd, service_id),
  FOREIGN KEY (company_cd, version_id) REFERENCES process_version(company_cd, version_id),
  FOREIGN KEY (company_cd, requester_id) REFERENCES user(company_cd, user_id),
  FOREIGN KEY (company_cd, it_assignee_id) REFERENCES user(company_cd, user_id)
);
CREATE TABLE attachment (
  company_cd   TEXT NOT NULL REFERENCES company(company_cd),
  attach_id    INTEGER PRIMARY KEY,
  request_id   INTEGER NOT NULL,
  step_no      INTEGER,
  orig_name    TEXT NOT NULL,
  stored_name  TEXT NOT NULL,
  mime_type    TEXT,
  size_byte    INTEGER,
  sha256       TEXT,
  uploaded_by  INTEGER NOT NULL,
  uploaded_at  TEXT NOT NULL,
  FOREIGN KEY (company_cd, request_id) REFERENCES request(company_cd, request_id),
  FOREIGN KEY (company_cd, uploaded_by) REFERENCES user(company_cd, user_id)
);
CREATE TABLE request_step (
  company_cd   TEXT NOT NULL REFERENCES company(company_cd),
  rstep_id     INTEGER PRIMARY KEY,
  request_id   INTEGER NOT NULL,
  step_no      INTEGER NOT NULL,
  step_nm      TEXT NOT NULL,
  status       TEXT NOT NULL,
  started_at   TEXT,
  due_at       TEXT,
  ended_at     TEXT,
  UNIQUE (company_cd, request_id, step_no),
  UNIQUE (company_cd, rstep_id),
  FOREIGN KEY (company_cd, request_id) REFERENCES request(company_cd, request_id)
);
CREATE TABLE approval (
  company_cd   TEXT NOT NULL REFERENCES company(company_cd),
  approval_id  INTEGER PRIMARY KEY,
  rstep_id     INTEGER NOT NULL,
  actor_id     INTEGER NOT NULL,
  action       TEXT NOT NULL,
  opinion      TEXT,
  acted_at     TEXT NOT NULL,
  FOREIGN KEY (company_cd, rstep_id) REFERENCES request_step(company_cd, rstep_id),
  FOREIGN KEY (company_cd, actor_id) REFERENCES user(company_cd, user_id)
);
CREATE TABLE comment (
  company_cd TEXT NOT NULL REFERENCES company(company_cd),
  comment_id INTEGER PRIMARY KEY,
  request_id INTEGER NOT NULL,
  user_id    INTEGER NOT NULL,
  body       TEXT NOT NULL,
  created_at TEXT NOT NULL,
  FOREIGN KEY (company_cd, request_id) REFERENCES request(company_cd, request_id),
  FOREIGN KEY (company_cd, user_id) REFERENCES user(company_cd, user_id)
);

CREATE TABLE schedule_change (
  company_cd    TEXT NOT NULL REFERENCES company(company_cd),
  change_id     INTEGER PRIMARY KEY,
  request_id    INTEGER NOT NULL,
  step_no       INTEGER,
  prev_start    TEXT,
  prev_end      TEXT,
  new_start     TEXT,
  new_end       TEXT,
  reason_cd     TEXT NOT NULL,
  reason_text   TEXT NOT NULL,
  proposer_id   INTEGER NOT NULL,
  proposer_side TEXT NOT NULL,
  status        TEXT NOT NULL,
  proposed_at   TEXT NOT NULL,
  resolved_at   TEXT,
  UNIQUE (company_cd, change_id),
  FOREIGN KEY (company_cd, request_id) REFERENCES request(company_cd, request_id),
  FOREIGN KEY (company_cd, proposer_id) REFERENCES user(company_cd, user_id)
);
CREATE TABLE schedule_agreement (
  company_cd   TEXT NOT NULL REFERENCES company(company_cd),
  agreement_id INTEGER PRIMARY KEY,
  change_id    INTEGER NOT NULL,
  side         TEXT NOT NULL,
  user_id      INTEGER NOT NULL,
  decision     TEXT NOT NULL,
  comment      TEXT,
  decided_at   TEXT NOT NULL,
  FOREIGN KEY (company_cd, change_id) REFERENCES schedule_change(company_cd, change_id),
  FOREIGN KEY (company_cd, user_id) REFERENCES user(company_cd, user_id)
);

CREATE TABLE notification_log (
  company_cd   TEXT NOT NULL REFERENCES company(company_cd),
  noti_id      INTEGER PRIMARY KEY,
  request_id   INTEGER,
  to_user_id   INTEGER,
  to_email     TEXT NOT NULL,
  rcpt_kind    TEXT NOT NULL DEFAULT 'TO',
  subject      TEXT NOT NULL,
  body         TEXT,
  status       TEXT NOT NULL,
  attempts     INTEGER NOT NULL DEFAULT 0,
  err_msg      TEXT,
  created_at   TEXT NOT NULL,
  sent_at      TEXT,
  FOREIGN KEY (company_cd, request_id) REFERENCES request(company_cd, request_id)
);
CREATE TABLE audit_log (
  company_cd   TEXT NOT NULL REFERENCES company(company_cd),
  audit_id     INTEGER PRIMARY KEY,
  user_id      INTEGER,
  action       TEXT NOT NULL,
  target       TEXT,
  detail       TEXT,
  ip_addr      TEXT,
  created_at   TEXT NOT NULL
);

CREATE INDEX ix_user_dept        ON user (company_cd, dept_id);
CREATE INDEX ix_login_fail       ON login_fail (company_cd, login_id, failed_at);
CREATE INDEX ix_login_fail_ip    ON login_fail (company_cd, ip_addr, failed_at);
CREATE INDEX ix_service_dept     ON service (company_cd, biz_dept_id);
CREATE INDEX ix_pversion_proc    ON process_version (company_cd, process_id, status);
CREATE INDEX ix_request_status   ON request (company_cd, status);
CREATE INDEX ix_request_service  ON request (company_cd, service_id);
CREATE INDEX ix_request_req      ON request (company_cd, requester_id);
CREATE INDEX ix_request_created  ON request (company_cd, created_at);
CREATE INDEX ix_rstep_status     ON request_step (company_cd, status);
CREATE INDEX ix_approval_rstep   ON approval (company_cd, rstep_id);
CREATE INDEX ix_attach_request   ON attachment (company_cd, request_id);
CREATE INDEX ix_schedule_request ON schedule_change (company_cd, request_id);
CREATE INDEX ix_noti_status      ON notification_log (company_cd, status);
CREATE INDEX ix_audit_created    ON audit_log (company_cd, created_at);
