<?php
declare(strict_types=1);

use App\Core\Repo;
use App\Core\Scope;
use App\Services\MailService;
use App\Services\MailTemplates;

test('http: 메일 관리 화면은 PROCESS_EDIT 권한만', function () {
    $w = rw();
    foreach (['req', 'owner', 'bizit'] as $u) {
        as_user($u);
        eq(403, http('GET', '/admin/mail-templates')->status, $u);
        eq(403, http('GET', '/admin/mail-log')->status, $u);
        eq(403, http('POST', '/admin/mail-templates', ['tmpl_cd' => 'X1'])->status, $u);
    }
    http('GET', '/');
    $_SESSION = [];
    eq(302, http('GET', '/admin/mail-log')->status, '비로그인');
    as_admin();
    $r = http('GET', '/admin/mail-templates');
    eq(200, $r->status);
    foreach (MailTemplates::codes() as $c) {
        ok(str_contains($r->body, '>' . $c . '<'), $c);
    }
    eq(200, http('GET', '/admin/mail-log')->status);
});

test('http: 템플릿 수정/되돌리기/추가/삭제와 검증', function () {
    $w = rw();
    as_admin();
    $r = http('GET', '/admin/mail-templates/STEP_ACTIVE');
    eq(200, $r->status);
    ok(str_contains($r->body, 'name="subject_ko"') && str_contains($r->body, 'name="body_ja"'));
    eq(404, http('GET', '/admin/mail-templates/NO_SUCH')->status);
    eq(404, http('GET', '/admin/mail-templates/bad-code')->status);

    $ok = [];
    foreach (['ko', 'ja', 'en', 'zh'] as $l) {
        $ok['subject_' . $l] = "[$l] 제목\r\n{title}";
        $ok['body_' . $l] = "본문 <script>alert(1)</script> {recipient}\r\n{link}";
    }
    http('POST', '/admin/mail-templates/STEP_ACTIVE', $ok);
    eq(t('common.saved'), last_flash());
    Scope::set('A01');
    $row = Repo::find('mail_template', ['tmpl_cd' => 'STEP_ACTIVE', 'lang' => 'ko']);
    eq('[ko] 제목 {title}', $row['subject'], '제목 개행 제거');
    ok(!str_contains($row['body'], "\r"), 'CRLF 정규화');
    $page = http('GET', '/admin/mail-templates/STEP_ACTIVE')->body;
    ok(!str_contains($page, '<script>alert(1)</script>') && str_contains($page, '&lt;script&gt;alert(1)&lt;/script&gt;'), '본문 이스케이프');

    $bad = $ok;
    $bad['subject_ja'] = '';
    http('POST', '/admin/mail-templates/STEP_ACTIVE', $bad);
    eq(t('mailtpl.err_text'), last_flash());
    Scope::set('A01');
    eq('[ko] 제목 {title}', Repo::find('mail_template', ['tmpl_cd' => 'STEP_ACTIVE', 'lang' => 'ko'])['subject'], '검증 실패 시 변경 없음');
    $bad = $ok;
    $bad['body_en'] = str_repeat('a', 5001);
    http('POST', '/admin/mail-templates/STEP_ACTIVE', $bad);
    eq(t('mailtpl.err_text'), last_flash());
    eq(403, http('POST', '/admin/mail-templates/STEP_ACTIVE', $ok, false)->status, 'CSRF');

    http('POST', '/admin/mail-templates/STEP_ACTIVE/reset');
    eq(t('mailtpl.ok_reset'), last_flash());
    Scope::set('A01');
    eq(MailTemplates::defaults()['STEP_ACTIVE']['ko'][0], Repo::find('mail_template', ['tmpl_cd' => 'STEP_ACTIVE', 'lang' => 'ko'])['subject']);

    // 사용자 정의 템플릿: 추가/중복/삭제/사용 중 삭제 불가
    http('POST', '/admin/mail-templates', ['tmpl_cd' => 'bad code']);
    eq(t('mailtpl.err_code'), last_flash());
    http('POST', '/admin/mail-templates', ['tmpl_cd' => 'step_active']);
    eq(t('mailtpl.err_duplicate'), last_flash());
    $r = http('POST', '/admin/mail-templates', ['tmpl_cd' => 'my_tmpl']);
    eq('/admin/mail-templates/MY_TMPL', $r->headers['Location']);
    Scope::set('A01');
    eq(count(MailTemplates::LANGS), Repo::count('mail_template', ['tmpl_cd' => 'MY_TMPL']));
    http('POST', '/admin/mail-templates/MY_TMPL/reset');
    eq(t('mailtpl.err_builtin'), last_flash());
    http('POST', '/admin/mail-templates/STEP_ACTIVE/delete');
    eq(t('mailtpl.err_builtin_delete'), last_flash());
    Scope::set('A01');
    Repo::update('process_step', ['step_id' => (int)Repo::list('process_step', [], 'step_id')[0]['step_id']], ['notify_tmpl' => 'MY_TMPL']);
    http('POST', '/admin/mail-templates/MY_TMPL/delete');
    eq(t('mailtpl.err_in_use'), last_flash());
    Scope::set('A01');
    Repo::update('process_step', ['notify_tmpl' => 'MY_TMPL'], ['notify_tmpl' => null]);
    http('POST', '/admin/mail-templates/MY_TMPL/delete');
    eq(t('common.deleted'), last_flash());
    Scope::set('A01');
    eq(0, Repo::count('mail_template', ['tmpl_cd' => 'MY_TMPL']));
    // 다른 회사 관리자는 이 회사의 템플릿 변경 불가(회사별 독립)
    Scope::set('B01');
    eq(0, Repo::count('mail_template', ['tmpl_cd' => 'MY_TMPL']));
});

test('http: 메일 발송 이력 목록/필터/다시 보내기', function () {
    $w = rw();
    $rid = live($w);
    Scope::set('A01');
    $rows = Repo::list('notification_log', ['request_id' => $rid], 'noti_id');
    Repo::update('notification_log', ['noti_id' => $rows[0]['noti_id']], ['status' => 'FAILED', 'attempts' => 5, 'err_msg' => 'boom <b>x</b>']);
    Repo::update('notification_log', ['noti_id' => $rows[1]['noti_id']], ['status' => 'SENT', 'sent_at' => now_str()]);
    as_admin();
    $body = http('GET', '/admin/mail-log')->body;
    ok(str_contains($body, $rows[0]['to_email']) && str_contains($body, $rows[1]['to_email']));
    ok(str_contains($body, 'boom &lt;b&gt;x&lt;/b&gt;') && !str_contains($body, '<b>x</b>'), '오류 메시지 이스케이프');
    $failed = http('GET', '/admin/mail-log?status=FAILED')->body;
    ok(str_contains($failed, $rows[0]['to_email']) && !str_contains($failed, $rows[1]['to_email']));
    $junk = http('GET', '/admin/mail-log?status=%27%3B--&page=999');
    eq(200, $junk->status, '잘못된 필터/페이지는 무시');
    http('POST', '/admin/mail-log/' . $rows[1]['noti_id'] . '/requeue');
    eq(t('mailtpl.err_requeue'), last_flash(), 'FAILED 가 아니면 거부');
    http('POST', '/admin/mail-log/' . $rows[0]['noti_id'] . '/requeue');
    eq(t('mailtpl.ok_requeued'), last_flash());
    Scope::set('A01');
    eq('QUEUED', Repo::find('notification_log', ['noti_id' => $rows[0]['noti_id']])['status']);
    eq(404, http('POST', '/admin/mail-log/99999/requeue')->status);
    // 다른 회사 관리자는 이 회사 이력이 보이지 않고 재큐도 404
    as_admin('B01', 'Admin5678');
    $other = http('GET', '/admin/mail-log');
    ok(!str_contains($other->body, $rows[0]['to_email']));
    eq(404, http('POST', '/admin/mail-log/' . $rows[0]['noti_id'] . '/requeue')->status);
});

test('http: 요청 처리 응답 후에는 메일이 자동 발송 시도됨(memory transport)', function () {
    $tr = new App\Services\Mail\MemoryTransport();
    MailService::setTransport($tr);
    App\Core\Env::set('MAIL_DRIVER', 'smtp');
    try {
        $w = rw();
        as_user('req');
        http('POST', '/requests', [
            'service_id' => (string)$w['svc'], 'maint_type' => 'MODIFY', 'title' => '메일 연동', 'content_html' => '<p>본문</p>',
            'priority' => 'NORMAL', 'req_due_date' => day(30), 'submit' => '1',
        ]);
        Scope::set('A01');
        $rid = (int)Repo::find('request', ['title' => '메일 연동'])['request_id'];
        ok(Repo::count('notification_log', ['request_id' => $rid]) >= 1 || rrow($rid)['status'] === 'DRAFT');
        MailService::flushAfterResponse();
        eq(0, Repo::count('notification_log', ['status' => 'QUEUED']), '응답 후 대기열이 비워짐');
        ok(count($tr->sent) >= 1);
    } finally {
        MailService::setTransport(null);
        App\Core\Env::set('MAIL_DRIVER', '');
    }
});
