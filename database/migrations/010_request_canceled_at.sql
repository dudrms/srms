-- 취소일(canceled_at): 월간 현황표가 월별 취소와 월말 진행 건수를 계산하는 데 쓴다. 날짜(YYYY-MM-DD)만 기록한다.
ALTER TABLE request ADD COLUMN canceled_at TEXT;

-- 009 는 임시저장 상태에서 취소된 요청에도 요청일을 채웠다. 제출한 적 없는 취소 건은 요청일을 비운다.
UPDATE request
   SET request_date = NULL
 WHERE status = 'CANCELED' AND reg_by IS NULL AND request_date IS NOT NULL
   AND NOT EXISTS (
         SELECT 1 FROM approval a JOIN request_step rs ON rs.company_cd = a.company_cd AND rs.rstep_id = a.rstep_id
          WHERE rs.company_cd = request.company_cd AND rs.request_id = request.request_id AND a.action = 'SUBMIT');

-- 기존 취소 건: 취소는 종료 상태라 마지막 수정 시각이 곧 취소 시각이다.
UPDATE request SET canceled_at = date(updated_at) WHERE status = 'CANCELED' AND canceled_at IS NULL;
