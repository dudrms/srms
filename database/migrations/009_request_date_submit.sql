-- 일반 요청의 요청일(request_date)을 최초 제출일로 채운다. 작업일지(reg_by 있음)는 입력한 요청일을 그대로 둔다.
-- 최초 제출 시각은 접수 단계(1단계)의 started_at(재제출해도 유지)이며, 없으면 ended_at, created_at 순으로 쓴다.
UPDATE request
   SET request_date = COALESCE(
         (SELECT COALESCE(date(rs.started_at), date(rs.ended_at))
            FROM request_step rs
           WHERE rs.company_cd = request.company_cd AND rs.request_id = request.request_id AND rs.step_no = 1
             AND COALESCE(rs.started_at, rs.ended_at) IS NOT NULL),
         date(created_at))
 WHERE request_date IS NULL AND reg_by IS NULL AND status <> 'DRAFT';
