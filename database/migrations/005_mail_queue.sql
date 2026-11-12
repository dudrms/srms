-- M5: 메일 큐. 템플릿 코드/수신 언어/중복 방지 키/다음 시도 시각을 기록하고 발송 대기·재시도를 인덱스로 지원한다.

ALTER TABLE notification_log ADD COLUMN tmpl_cd TEXT;
ALTER TABLE notification_log ADD COLUMN lang TEXT;
ALTER TABLE notification_log ADD COLUMN dedupe_key TEXT;
ALTER TABLE notification_log ADD COLUMN next_try_at TEXT;

CREATE UNIQUE INDEX ux_noti_dedupe ON notification_log (company_cd, dedupe_key) WHERE dedupe_key IS NOT NULL;
CREATE INDEX ix_noti_queue ON notification_log (company_cd, status, next_try_at);
CREATE INDEX ix_noti_user ON notification_log (company_cd, to_user_id, created_at);
