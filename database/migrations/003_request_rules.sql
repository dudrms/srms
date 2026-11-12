-- M3: 완료요구일 필수, 단계 처리 회차(round) 추적.

CREATE TRIGGER trg_request_due_ins BEFORE INSERT ON request
WHEN NEW.req_due_date IS NULL OR TRIM(NEW.req_due_date) = ''
BEGIN
  SELECT RAISE(ABORT, 'req_due_date is required');
END;

CREATE TRIGGER trg_request_due_upd BEFORE UPDATE OF req_due_date ON request
WHEN NEW.req_due_date IS NULL OR TRIM(NEW.req_due_date) = ''
BEGIN
  SELECT RAISE(ABORT, 'req_due_date is required');
END;

ALTER TABLE request_step ADD COLUMN round INTEGER NOT NULL DEFAULT 1;
ALTER TABLE approval ADD COLUMN round INTEGER NOT NULL DEFAULT 1;

CREATE INDEX ix_approval_actor ON approval (company_cd, actor_id);
CREATE INDEX ix_comment_request ON comment (company_cd, request_id);
