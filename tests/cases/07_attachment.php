<?php
declare(strict_types=1);

use App\Core\Env;
use App\Core\Repo;
use App\Core\Scope;
use App\Services\AttachmentService as AS_;
use App\Services\ProcessService as PS;
use App\Services\WorkflowService as WF;

const PDF_BODY = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
const PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

function rm_rf(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

/** 임시 저장소를 쓰는 환경에서 $fn 을 실행하고 정리한다. */
function with_storage(callable $fn): void
{
    $dir = sys_get_temp_dir() . '/srms_att_' . bin2hex(random_bytes(4));
    Env::set('STORAGE_PATH', $dir);
    try {
        $fn($dir);
    } finally {
        Env::set('STORAGE_PATH', 'storage');
        rm_rf($dir);
    }
}

function tmpf(string $content): string
{
    $p = tempnam(sys_get_temp_dir(), 'srmsup');
    file_put_contents($p, $content);
    return $p;
}

/** 첨부 필수(접수/작업) 프로세스를 DATA 타입에 매핑한다. 작업 단계: 최대 2개, 1MB, txt/pdf 만. */
function att_process(array $w): void
{
    $pid = PS::createProcess('ATT', 'att', '', []);
    $vid = latest_vid($pid);
    $first = (int)PS::steps($vid)[0]['step_id'];
    PS::updateStep($pid, $vid, $first, step_in([
        'step_nm' => '접수', 'step_type' => 'SUBMIT', 'actor_type' => 'REQUESTER', 'actor_value' => '', 'attach_mode' => 'REQUIRED',
        'due_days' => '', 'allow_reject' => '0',
    ]));
    $work = PS::addStep($pid, $vid, step_in([
        'step_nm' => '작업', 'step_type' => 'WORK', 'actor_type' => 'SERVICE_IT_OWNER', 'actor_value' => '', 'attach_mode' => 'REQUIRED',
        'attach_max_cnt' => '2', 'attach_max_mb' => '1', 'attach_exts' => 'txt,pdf', 'due_days' => '', 'allow_reject' => '0',
    ]));
    $steps = PS::steps($vid);
    PS::reorder($pid, $vid, [(int)$steps[0]['step_id'], $work, (int)$steps[1]['step_id']]);
    PS::activate($pid, $vid, '2020-01-01', '');
    PS::saveMapping([$w['svc'] => ['DATA' => (string)$pid]]);
}

function att_req(array $w): int
{
    return mk_req($w, ['maint_type' => 'DATA']);
}

test('attach: 정책 해석(기본값, 차단 확장자 제거)', function () {
    $p = AS_::policy(['attach_mode' => 'OPTIONAL', 'attach_exts' => 'pdf,php,exe,png', 'attach_max_cnt' => null, 'attach_max_mb' => null]);
    eq(['pdf', 'png'], $p['exts']);
    eq(20, $p['maxMb']);
    eq(10, $p['maxCnt']);
    $d = AS_::policy(['attach_mode' => 'OPTIONAL', 'attach_exts' => null, 'attach_max_cnt' => 3, 'attach_max_mb' => 5]);
    ok(in_array('xlsx', $d['exts'], true) && !in_array('php', $d['exts'], true) && !in_array('svg', $d['exts'], true));
    eq([3, 5], [$d['maxCnt'], $d['maxMb']]);
});

test('attach: 정상 저장 - 난수 파일명/웹루트 밖/체크섬/원본명 정화', function () {
    with_storage(function (string $dir) {
        $w = rw();
        $rid = mk_req($w);
        $req = rrow($rid);
        $tmp = tmpf("첫번째 첨부 내용\n");
        $aid = AS_::ingest($req, 1, $w['req'], $tmp, '../../etc/보고서 최종.txt');
        $a = Repo::find('attachment', ['attach_id' => $aid]);
        eq('보고서 최종.txt', $a['orig_name'], '경로 제거');
        eq(1, (int)$a['step_no']);
        ok(preg_match('~^\d{4}/\d{2}/[a-f0-9]{32}$~', $a['stored_name']) === 1);
        $path = AS_::fullPath('A01', $a['stored_name']);
        ok(str_starts_with(str_replace('\\', '/', $path), str_replace('\\', '/', $dir) . '/attachments/A01/'), '회사별 분리 저장');
        ok(is_file($path));
        eq(hash_file('sha256', $path), $a['sha256']);
        eq((int)filesize($path), (int)$a['size_byte']);
        ok(!str_contains($path, 'public'), '웹루트 밖');
        ok(!is_file($tmp), '임시 파일 정리');
        [$row, $located] = AS_::locate($aid);
        eq($path, $located);
        eq($aid, (int)$row['attach_id']);
        // 같은 내용을 다시 올려도 서로 다른 저장명
        $aid2 = AS_::ingest($req, 1, $w['req'], tmpf("첫번째 첨부 내용\n"), 'a.txt');
        ok(Repo::find('attachment', ['attach_id' => $aid2])['stored_name'] !== $a['stored_name']);
        // 파일명 정화
        eq('file', AS_::cleanName(''));
        eq('evil.txt', AS_::cleanName('C:\\x\\y\\evil.txt'));
        eq('ab.txt', AS_::cleanName("a\x00b.txt"));
        ok(mb_strlen(AS_::cleanName(str_repeat('가', 300) . '.pdf')) <= 145);
    });
});

test('attach: 업로드 권한 - 요청자(접수 시)/현재 단계 처리자만', function () {
    with_storage(function () {
        $w = rw();
        $rid = mk_req($w);
        $f = fn() => tmpf('x');
        perr_p(fn() => AS_::ingest(rrow($rid), 1, $w['other'], $f(), 'a.txt'), 'attach.err_forbidden');
        perr_p(fn() => AS_::ingest(rrow($rid), 1, $w['owner'], $f(), 'a.txt'), 'attach.err_forbidden');
        perr_p(fn() => AS_::ingest(rrow($rid), 2, $w['req'], $f(), 'a.txt'), 'attach.err_forbidden', []);
        AS_::ingest(rrow($rid), 1, $w['req'], $f(), 'a.txt');
        WF::submit($rid, $w['req']);                     // 현재 단계 2(검토 - IT_TEAM, 첨부 불가 설정)
        perr_p(fn() => AS_::ingest(rrow($rid), 1, $w['req'], $f(), 'b.txt'), 'attach.err_forbidden');   // 요청자는 이미 접수 단계 종료
        perr_p(fn() => AS_::ingest(rrow($rid), 2, $w['bizit'], $f(), 'b.txt'), 'attach.err_forbidden');  // 처리자 아님
        eq(null, AS_::uploadStep(rrow($rid), $w['req']));
        eq(2, AS_::uploadStep(rrow($rid), $w['owner']));
        perr_p(fn() => AS_::ingest(rrow($rid), 2, $w['owner'], $f(), 'b.txt'), 'attach.err_not_allowed');  // 이 단계는 첨부 NONE
        WF::cancel($rid, $w['req']);
        eq(null, AS_::uploadStep(rrow($rid), $w['req']), '취소된 요청');
    });
});

test('attach: 확장자/내용(MIME) 검증', function () {
    with_storage(function () {
        $w = rw();
        $req = rrow(mk_req($w));
        $up = fn(string $content, string $name) => fn() => AS_::ingest($req, 1, $w['req'], tmpf($content), $name);
        foreach (['shell.php', 'run.exe', 'a.txt.php', 'noext', 'x.html', 'p.svg', 'x.js', '.htaccess', 'a.PHP'] as $bad) {
            try {
                $up('x', $bad)();
                throw new RuntimeException("허용되면 안 됨: $bad");
            } catch (App\Services\ProcessError $e) {
                eq('attach.err_ext', $e->key, $bad);
            }
        }
        perr_p($up('', 'empty.txt'), 'attach.err_empty');
        perr_p($up('이건 PDF 가 아닙니다', 'fake.pdf'), 'attach.err_type');
        // 위험한 내용(HTML/스크립트/실행파일 헤더)은 내용 검사로 거부. 일부 백신이 임시 파일 읽기를 막으면 업로드 오류로 거부된다.
        $rejected = static function (callable $fn): void {
            try {
                $fn();
            } catch (App\Services\ProcessError $e) {
                ok(in_array($e->key, ['attach.err_type', 'attach.err_upload'], true), $e->key);
                return;
            }
            throw new RuntimeException('위험한 내용이 허용됨');
        };
        $rejected($up('<html><script>alert(1)</script></html>', 'page.txt'));
        $rejected($up('<?php system($_GET["c"]); ?>', 'code.txt'));
        $rejected($up('MZ' . str_repeat("\0", 64), 'prog.pdf'));
        perr_p($up((string)base64_decode(PNG_B64), 'image.txt'), 'attach.err_type');
        perr_p($up('이건 이미지가 아닙니다', 'pic.png'), 'attach.err_type');
        // 정상 파일
        ok($up(PDF_BODY, 'ok.pdf')() > 0);
        ok($up((string)base64_decode(PNG_B64), 'ok.png')() > 0);
        ok($up("a,b\n1,2\n", 'ok.csv')() > 0);
    });
});

test('attach: 단계별 제한(개수/크기/확장자)과 필수 첨부', function () {
    with_storage(function () {
        $w = rw();
        att_process($w);
        $rid = att_req($w);
        // 접수 단계 필수 첨부: 없으면 제출 불가
        perr_p(fn() => WF::submit($rid, $w['req']), 'request.err_attach_required');
        eq('DRAFT', rrow($rid)['status']);
        AS_::ingest(rrow($rid), 1, $w['req'], tmpf('자료'), 'spec.txt');
        WF::submit($rid, $w['req']);
        eq(2, (int)rrow($rid)['current_step_no']);
        // 작업 단계(서비스 전산 담당자): 첨부 필수 → 없으면 처리 불가
        perr_p(fn() => WF::act($rid, $w['owner'], 'PASS', ''), 'request.err_attach_required');
        // 단계 허용 확장자(txt, pdf)만
        perr_p(fn() => AS_::ingest(rrow($rid), 2, $w['owner'], tmpf('x'), 'sheet.xlsx'), 'attach.err_ext', ['ext' => 'xlsx']);
        // 크기 제한 1MB
        perr_p(fn() => AS_::ingest(rrow($rid), 2, $w['owner'], tmpf(str_repeat('a', 1048577)), 'big.txt'), 'attach.err_size', ['mb' => 1]);
        $ok = AS_::ingest(rrow($rid), 2, $w['owner'], tmpf(str_repeat('a', 1048576)), 'edge.txt');
        ok($ok > 0, '정확히 1MB 는 허용');
        // 개수 제한 2개
        AS_::ingest(rrow($rid), 2, $w['owner'], tmpf('b'), 'two.txt');
        perr_p(fn() => AS_::ingest(rrow($rid), 2, $w['owner'], tmpf('c'), 'three.txt'), 'attach.err_count', ['n' => 2]);
        eq(2, Repo::count('attachment', ['request_id' => $rid, 'step_no' => 2]));
        eq(1, Repo::count('attachment', ['request_id' => $rid, 'step_no' => 1]), '단계별로 분리 집계');
        // 첨부 후 처리 → 종료 단계(IT_TEAM, 첨부 불가)에서 완료
        eq('ADVANCED', WF::act($rid, $w['owner'], 'PASS', ''));
        eq('COMPLETED', WF::act($rid, $w['it2'], 'PASS', ''));
    });
});

test('attach: 삭제 - 업로드한 본인이 업로드 가능 구간에서만, 파일도 제거', function () {
    with_storage(function () {
        $w = rw();
        $rid = mk_req($w);
        $req = rrow($rid);
        $aid = AS_::ingest($req, 1, $w['req'], tmpf('삭제 대상'), 'del.txt');
        $path = AS_::locate($aid)[1];
        perr_p(fn() => AS_::remove($req, $aid, $w['other']), 'attach.err_forbidden');
        perr_p(fn() => AS_::remove($req, 99999, $w['req']), 'process.err_not_found');
        AS_::remove($req, $aid, $w['req']);
        eq(null, Repo::find('attachment', ['attach_id' => $aid]));
        ok(!is_file($path), '디스크 파일 삭제');
        // 제출 후에는(요청자의 업로드 구간이 끝남) 삭제 불가
        $aid2 = AS_::ingest($req, 1, $w['req'], tmpf('유지'), 'keep.txt');
        WF::submit($rid, $w['req']);
        perr_p(fn() => AS_::remove(rrow($rid), $aid2, $w['req']), 'attach.err_forbidden');
        ok(Repo::find('attachment', ['attach_id' => $aid2]) !== null);
    });
});

test('attach: 저장 경로 조작 차단', function () {
    with_storage(function () {
        $w = rw();
        $req = rrow(mk_req($w));
        $aid = AS_::ingest($req, 1, $w['req'], tmpf('x'), 'a.txt');
        foreach (['../../../etc/passwd', '2026/11/../../secret', 'abc', '2026/11/' . str_repeat('g', 32), "2026/11/" . str_repeat('a', 32) . "\n"] as $bad) {
            throws(fn() => AS_::fullPath('A01', $bad), InvalidArgumentException::class);
        }
        Repo::update('attachment', ['attach_id' => $aid], ['stored_name' => '../../../x']);
        throws(fn() => AS_::locate($aid), InvalidArgumentException::class);
    });
});

test('attach: 요청 총 용량 제한(100MB)은 상수로 고정, 저장소 경로 설정', function () {
    eq(100, AS_::REQUEST_TOTAL_MB);
    Env::set('STORAGE_PATH', '/var/srms');
    eq('/var/srms', AS_::baseDir());
    Env::set('STORAGE_PATH', 'storage');
    eq(APP_ROOT . '/storage', AS_::baseDir());
});

test('http: 첨부 다운로드 - 열람 권한자만, 헤더/내용, 다른 회사 차단', function () {
    with_storage(function () {
        $w = rw();
        $rid = live($w);
        // 제출 후 2단계(첨부 NONE)이므로 제출 전에 업로드한 것처럼 DB 로 직접 구성
        $dir = AS_::baseDir();
        $rel = date('Y') . '/' . date('m') . '/' . bin2hex(random_bytes(16));
        $full = AS_::fullPath('A01', $rel);
        mkdir(dirname($full), 0750, true);
        file_put_contents($full, "비밀 문서\n");
        $aid = Repo::insert('attachment', [
            'request_id' => $rid, 'step_no' => 1, 'orig_name' => '한글 문서 (최종).txt', 'stored_name' => $rel, 'mime_type' => 'text/plain',
            'size_byte' => filesize($full), 'sha256' => hash_file('sha256', $full), 'uploaded_by' => $w['req'], 'uploaded_at' => now_str(),
        ]);
        as_user('req');
        $r = http('GET', "/attachments/$aid");
        eq(200, $r->status);
        eq($full, $r->filePath);
        ok(str_contains($r->headers['Content-Disposition'], 'attachment; filename="'), 'attachment 로 강제');
        ok(str_contains($r->headers['Content-Disposition'], rawurlencode('한글 문서 (최종).txt')), 'UTF-8 파일명');
        eq('application/octet-stream', $r->headers['Content-Type']);
        eq((string)filesize($full), $r->headers['Content-Length']);
        // 상세 화면에 링크가 보이고 타임라인에 기록
        $page = http('GET', "/requests/$rid")->body;
        ok(str_contains($page, "/attachments/$aid") && str_contains($page, '한글 문서 (최종).txt'));
        // 같은 회사지만 열람 권한이 없는 사용자
        as_user('other');
        eq(404, http('GET', "/attachments/$aid")->status);
        // 열람 권한이 있는 사용자(전산팀)
        as_user('owner');
        eq(200, http('GET', "/attachments/$aid")->status);
        // 다른 회사
        as_user('admin', 'B01', 'Admin5678');
        eq(404, http('GET', "/attachments/$aid")->status);
        // 비로그인
        $_SESSION = [];
        eq('/login', http('GET', "/attachments/$aid")->headers['Location']);
        // 존재하지 않는 첨부/비숫자
        as_user('req');
        eq(404, http('GET', '/attachments/99999')->status);
        eq(404, http('GET', '/attachments/abc')->status);
        // 파일이 사라진 경우
        unlink($full);
        eq(404, http('GET', "/attachments/$aid")->status);
    });
});

test('http: 업로드 요청 - 서버가 인정하지 않은 업로드는 거부, 권한/CSRF 검사', function () {
    with_storage(function () {
        $w = rw();
        $rid = live($w);
        as_user('bizit');
        $_FILES = ['files' => ['name' => ['x.txt'], 'type' => ['text/plain'], 'tmp_name' => [tmpf('x')], 'error' => [UPLOAD_ERR_OK], 'size' => [1]]];
        http('POST', "/requests/$rid/attachments");
        eq(t('attach.err_forbidden'), last_flash(), '처리자가 아니면 업로드 불가');
        as_user('owner');
        $_FILES = ['files' => ['name' => ['x.txt'], 'type' => ['text/plain'], 'tmp_name' => [tmpf('x')], 'error' => [UPLOAD_ERR_OK], 'size' => [1]]];
        eq(403, http('POST', "/requests/$rid/attachments", [], false)->status, 'CSRF');
        http('POST', "/requests/$rid/attachments");
        ok(str_contains(last_flash(), t('attach.err_not_allowed')) || str_contains(last_flash(), t('attach.err_upload')), '첨부 불가 단계/비정상 업로드');
        $_FILES = [];
        eq(0, Repo::count('attachment', ['request_id' => $rid]));
    });
});
