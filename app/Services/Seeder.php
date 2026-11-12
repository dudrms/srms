<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\I18n;
use App\Core\Repo;
use App\Core\Scope;

final class Seeder
{
    /** 권한코드 => 한국어 명칭 (번역은 lang 파일의 perm.* 키) */
    public const PERMISSIONS = [
        'REQUEST_CREATE' => '요청 등록',
        'REQUEST_VIEW_OWN' => '본인 건 조회',
        'REQUEST_VIEW_DEPT' => '주관부서 건 조회',
        'REQUEST_VIEW_ALL' => '전체 건 조회',
        'REQUEST_VIEW_CC' => '참조 건 조회',
        'STEP_ACT' => '단계 처리',
        'SCHEDULE_PROPOSE' => '일정 변경 제안/합의',
        'REPORT_VIEW' => '리포트 조회',
        'PROCESS_EDIT' => '프로세스 마스터 수정',
        'CODE_EDIT' => '공통코드 관리',
        'SERVICE_EDIT' => '서비스 관리',
        'USER_EDIT' => '사용자/롤/권한 관리',
        'WORKLOG_REG' => '작업일지 등록·관리',
        'REQUEST_SPLIT' => '요청 분할·담당자 지정',
    ];

    /** 기본 롤: 코드 => [ko, ja, en, 권한 목록] */
    public const ROLES = [
        'REQUESTER' => ['현업 사용자', '現業ユーザー', 'Business User',
            ['REQUEST_CREATE', 'REQUEST_VIEW_OWN', 'REQUEST_VIEW_CC', 'STEP_ACT', 'SCHEDULE_PROPOSE']],
        'BIZ_IT' => ['현업 전산담당', '現業IT担当', 'Business IT Contact',
            ['REQUEST_CREATE', 'REQUEST_VIEW_OWN', 'REQUEST_VIEW_DEPT', 'REQUEST_VIEW_CC', 'STEP_ACT', 'SCHEDULE_PROPOSE', 'REPORT_VIEW']],
        'IT_TEAM' => ['전산팀', 'IT部門', 'IT Team',
            ['REQUEST_CREATE', 'REQUEST_VIEW_OWN', 'REQUEST_VIEW_DEPT', 'REQUEST_VIEW_ALL', 'REQUEST_VIEW_CC', 'STEP_ACT', 'SCHEDULE_PROPOSE', 'REPORT_VIEW', 'WORKLOG_REG', 'REQUEST_SPLIT']],
        'SYS_ADMIN' => ['시스템관리자', 'システム管理者', 'System Administrator', 'ALL'],
    ];

    /** 코드그룹 => [ko, ja, en, [코드 => [ko, ja, en]]] */
    public const CODES = [
        'MAINT_TYPE' => ['유지보수 타입', '保守タイプ', 'Maintenance Type', [
            'NEW_DEV' => ['신규개발', '新規開発', 'New Development'],
            'MODIFY' => ['기능수정', '機能修正', 'Modification'],
            'BUGFIX' => ['장애처리', '障害対応', 'Incident Fix'],
            'DATA' => ['데이터작업', 'データ作業', 'Data Task'],
            'ETC' => ['기타', 'その他', 'Other'],
        ]],
        'PRIORITY' => ['우선순위', '優先度', 'Priority', [
            'LOW' => ['낮음', '低', 'Low'],
            'NORMAL' => ['보통', '中', 'Normal'],
            'HIGH' => ['높음', '高', 'High'],
            'URGENT' => ['긴급', '緊急', 'Urgent'],
        ]],
        'SCHEDULE_REASON' => ['일정변경 사유', '日程変更理由', 'Schedule Change Reason', [
            'SCOPE_CHANGE' => ['요구범위 변경', '要件範囲の変更', 'Scope change'],
            'BIZ_REQUEST' => ['현업 요청', '現業からの依頼', 'Business request'],
            'PRED_DELAY' => ['선행 작업 지연', '先行作業の遅延', 'Predecessor delay'],
            'RESOURCE' => ['리소스 부족', 'リソース不足', 'Resource shortage'],
            'TECH_ISSUE' => ['기술 이슈', '技術的課題', 'Technical issue'],
            'TEST_ISSUE' => ['테스트 이슈', 'テスト課題', 'Test issue'],
            'ETC' => ['기타', 'その他', 'Other'],
        ]],
        'SERVICE_GROUP' => ['서비스구분', 'サービス区分', 'Service Group', []],
    ];

    public static function seedPermissions(): void
    {
        foreach (self::PERMISSIONS as $cd => $nm) {
            Db::exec('INSERT OR IGNORE INTO permission (perm_cd, perm_nm) VALUES (:c, :n)', ['c' => $cd, 'n' => $nm]);
        }
    }

    /** 신규 회사 생성 및 기본 데이터 시드. 이미 있으면 예외. */
    public static function createCompany(string $companyCd, string $companyNm, string $lang = 'ko'): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,20}$/', $companyCd) !== 1) {
            throw new \InvalidArgumentException('company code must match [A-Za-z0-9_-]{1,20}');
        }
        if (Db::one('SELECT 1 FROM company WHERE company_cd = :c', ['c' => $companyCd]) !== null) {
            throw new \InvalidArgumentException('company already exists: ' . $companyCd);
        }
        self::seedPermissions();
        Db::tx(static function () use ($companyCd, $companyNm, $lang): void {
            Db::exec(
                'INSERT INTO company (company_cd, company_nm, default_lang, use_yn, created_at) VALUES (:c, :n, :l, 1, :t)',
                ['c' => $companyCd, 'n' => $companyNm, 'l' => $lang, 't' => now_str()]
            );
            Scope::set($companyCd);
            self::seedRoles();
            self::seedCodes();
            Repo::insert('dept', ['dept_nm' => '전산팀', 'is_it' => 1]);
            self::seedProcesses();
            MailTemplates::seed();
            Repo::insert('app_setting', ['setting_key' => 'seed.mail', 'setting_value' => '1']);
            self::seedChinese();
            Repo::insert('app_setting', ['setting_key' => 'seed.zh', 'setting_value' => '1']);
        });
    }

    /** 이미 존재하는 회사에 후속 마일스톤의 기본 데이터를 보강한다(멱등). */
    public static function ensureDefaults(string $companyCd): void
    {
        Scope::set($companyCd);
        self::seedPermissions();
        Db::tx(static function (): void {
            foreach (['seed.worklog_perm' => 'WORKLOG_REG', 'seed.split_perm' => 'REQUEST_SPLIT'] as $flag => $perm) {
                if (Repo::find('app_setting', ['setting_key' => $flag]) !== null) {
                    continue;
                }
                foreach (['IT_TEAM', 'SYS_ADMIN'] as $role) {
                    if (Repo::find('role', ['role_cd' => $role]) !== null && Repo::find('role_permission', ['role_cd' => $role, 'perm_cd' => $perm]) === null) {
                        Repo::insert('role_permission', ['role_cd' => $role, 'perm_cd' => $perm]);
                    }
                }
                Repo::insert('app_setting', ['setting_key' => $flag, 'setting_value' => '1']);
            }
            if (Repo::find('app_setting', ['setting_key' => 'seed.processes']) === null) {
                self::seedProcesses();
            }
            if (Repo::find('app_setting', ['setting_key' => 'seed.biz_owner_actor']) === null) {
                self::upgradeBizOwnerActor();
                Repo::insert('app_setting', ['setting_key' => 'seed.biz_owner_actor', 'setting_value' => '1']);
            }
            if (Repo::find('app_setting', ['setting_key' => 'seed.worklog_actor']) === null) {
                WorklogService::upgradeProcess();
                Repo::insert('app_setting', ['setting_key' => 'seed.worklog_actor', 'setting_value' => '1']);
            }
            // 행이 없는 (코드, 언어)만 채우므로 매번 실행해도 안전하다(새 언어 추가 포함).
            MailTemplates::seed();
            if (Repo::find('app_setting', ['setting_key' => 'seed.mail']) === null) {
                Repo::insert('app_setting', ['setting_key' => 'seed.mail', 'setting_value' => '1']);
            }
            if (Repo::find('app_setting', ['setting_key' => 'seed.zh']) === null) {
                self::seedChinese();
                Repo::insert('app_setting', ['setting_key' => 'seed.zh', 'setting_value' => '1']);
            }
            self::repairChinese();
        });
    }

    /**
     * 기존 회사의 기본 프로세스(GENERAL, URGENT)에서 주관부서 전원이 처리하던 단계를 현업 IT 담당자 유형으로 바꾼다(한 번).
     * 지정이 없으면 주관부서 전원으로 폴백되어 동작이 같으므로 활성 버전도 제자리에서 갱신한다(활성 버전 불변 규칙을 의도적으로 우회).
     * 관리자가 다른 유형으로 바꾼 단계와 직접 만든 프로세스는 건드리지 않는다.
     */
    public static function upgradeBizOwnerActor(): void
    {
        foreach (['GENERAL', 'URGENT'] as $code) {
            $m = Repo::find('process_master', ['process_cd' => $code]);
            if ($m === null) {
                continue;
            }
            foreach (Repo::list('process_version', ['process_id' => (int)$m['process_id']]) as $v) {
                foreach (Repo::list('process_step', ['version_id' => (int)$v['version_id'], 'actor_type' => 'SERVICE_BIZ_DEPT']) as $s) {
                    Repo::update('process_step', ['step_id' => (int)$s['step_id']], ['actor_type' => 'SERVICE_BIZ_OWNER']);
                }
            }
        }
    }

    /** 기본 롤/공통코드/프로세스/단계 이름의 중국어 번역. 이미 있는 번역은 건드리지 않는다. */
    private const ZH = [
        'ROLE' => ['REQUESTER' => '业务用户', 'BIZ_IT' => '业务IT负责人', 'IT_TEAM' => 'IT团队', 'SYS_ADMIN' => '系统管理员'],
        'CODE_GROUP' => ['MAINT_TYPE' => '运维类型', 'PRIORITY' => '优先级', 'SCHEDULE_REASON' => '日程变更原因', 'SERVICE_GROUP' => '服务分类'],
        'CODE' => [
            'MAINT_TYPE:NEW_DEV' => '新开发', 'MAINT_TYPE:MODIFY' => '功能修改', 'MAINT_TYPE:BUGFIX' => '故障处理',
            'MAINT_TYPE:DATA' => '数据作业', 'MAINT_TYPE:ETC' => '其他',
            'PRIORITY:LOW' => '低', 'PRIORITY:NORMAL' => '普通', 'PRIORITY:HIGH' => '高', 'PRIORITY:URGENT' => '紧急',
            'SCHEDULE_REASON:SCOPE_CHANGE' => '需求范围变更', 'SCHEDULE_REASON:BIZ_REQUEST' => '业务部门要求',
            'SCHEDULE_REASON:PRED_DELAY' => '前置工作延迟', 'SCHEDULE_REASON:RESOURCE' => '资源不足',
            'SCHEDULE_REASON:TECH_ISSUE' => '技术问题', 'SCHEDULE_REASON:TEST_ISSUE' => '测试问题', 'SCHEDULE_REASON:ETC' => '其他',
        ],
        'PROCESS' => ['GENERAL' => '常规运维', 'URGENT' => '紧急故障'],
    ];
    private const ZH_STEPS = [
        '접수' => '受理', '검토' => '审查', '합의' => '协商', '승인' => '审批', '수행' => '执行', '검수' => '验收', '종료' => '结束', '사후합의' => '事后协商',
    ];
    /** 과거 시드에 한글이 섞여 들어간 중국어 단계명 → 올바른 표기 */
    private const ZH_FIX = ['验수' => '验收', '事후协商' => '事后协商'];

    private static function repairChinese(): void
    {
        foreach (Repo::list('translation', ['entity' => 'STEP', 'lang' => 'zh']) as $t) {
            $fixed = self::ZH_FIX[(string)$t['text']] ?? null;
            if ($fixed !== null) {
                Repo::update('translation', ['entity' => 'STEP', 'entity_key' => $t['entity_key'], 'lang' => 'zh'], ['text' => $fixed]);
            }
        }
        I18n::clearMasterCache();
    }

    private static function seedChinese(): void
    {
        $exists = [
            'ROLE' => static fn(string $k): bool => Repo::find('role', ['role_cd' => $k]) !== null,
            'CODE_GROUP' => static fn(string $k): bool => Repo::find('code_group', ['group_cd' => $k]) !== null,
            'CODE' => static fn(string $k): bool => Repo::find('code', ['group_cd' => explode(':', $k)[0], 'code_cd' => explode(':', $k)[1] ?? '']) !== null,
            'PROCESS' => static fn(string $k): bool => Repo::find('process_master', ['process_cd' => $k]) !== null,
        ];
        foreach (self::ZH as $entity => $map) {
            foreach ($map as $key => $text) {
                if ($exists[$entity]($key)) {
                    Translations::addIfMissing($entity, $key, 'zh', $text);
                }
            }
        }
        foreach (Repo::list('process_step') as $s) {
            if (isset(self::ZH_STEPS[(string)$s['step_nm']])) {
                Translations::addIfMissing('STEP', (string)$s['step_id'], 'zh', self::ZH_STEPS[(string)$s['step_nm']]);
            }
        }
    }

    /**
     * 기본 프로세스: 일반, 긴급. 각각 v1 활성 버전으로 생성.
     * 단계: [ko, ja, en, 유형, 처리자유형, 처리자값, 반려이동, 기한일, 첨부]
     */
    private static function seedProcesses(): void
    {
        $specs = [
            'GENERAL' => [['일반 유지보수', '一般保守', 'General Maintenance'], '접수→검토→승인→수행→검수→종료', [
                ['접수', '受付', 'Submission', 'SUBMIT', 'REQUESTER', null, null, null, 'OPTIONAL'],
                ['검토', 'レビュー', 'Review', 'REVIEW', 'ROLE', 'IT_TEAM', 1, 2, 'NONE'],
                ['승인', '承認', 'Approval', 'APPROVE', 'SERVICE_BIZ_OWNER', null, 2, 2, 'NONE'],
                ['수행', '実施', 'Execution', 'WORK', 'SERVICE_IT_OWNER', null, null, null, 'OPTIONAL'],
                ['검수', '検収', 'Acceptance', 'VERIFY', 'REQUESTER', null, 4, 3, 'OPTIONAL'],
                ['종료', '完了', 'Close', 'CLOSE', 'ROLE', 'IT_TEAM', null, null, 'NONE'],
            ]],
            'URGENT' => [['긴급 장애', '緊急障害', 'Urgent Incident'], '접수→승인→수행→사후합의→종료', [
                ['접수', '受付', 'Submission', 'SUBMIT', 'REQUESTER', null, null, null, 'OPTIONAL'],
                ['승인', '承認', 'Approval', 'APPROVE', 'ROLE', 'IT_TEAM', null, 1, 'NONE'],
                ['수행', '実施', 'Execution', 'WORK', 'SERVICE_IT_OWNER', null, null, null, 'OPTIONAL'],
                ['사후합의', '事後合意', 'Post-agreement', 'AGREE', 'SERVICE_BIZ_OWNER', null, null, 3, 'NONE'],
                ['종료', '完了', 'Close', 'CLOSE', 'ROLE', 'IT_TEAM', null, null, 'NONE'],
            ]],
        ];
        $today = date('Y-m-d');
        foreach ($specs as $code => [$names, $desc, $steps]) {
            $pid = Repo::insert('process_master', [
                'process_cd' => $code, 'process_nm' => $names[0], 'description' => $desc,
            ]);
            Translations::save('PROCESS', $code, ['ja' => $names[1], 'en' => $names[2]]);
            $vid = Repo::insert('process_version', [
                'process_id' => $pid, 'version_no' => 1, 'valid_from' => $today, 'valid_to' => null,
                'status' => 'ACTIVE', 'created_by' => null, 'created_at' => now_str(),
            ]);
            foreach ($steps as $i => [$ko, $ja, $en, $type, $actor, $actorVal, $rejectTo, $due, $attach]) {
                $sid = ProcessService::insertStepRaw(
                    $vid, $i + 1, $ko, $type, $actor, $actorVal, 'ANY',
                    in_array($type, ['REVIEW', 'AGREE', 'APPROVE', 'VERIFY'], true) ? 1 : 0, $rejectTo, $due, $attach
                );
                Translations::save('STEP', (string)$sid, ['ja' => $ja, 'en' => $en]);
            }
        }
        Repo::insert('app_setting', ['setting_key' => 'seed.processes', 'setting_value' => '1']);
    }

    private static function seedRoles(): void
    {
        foreach (self::ROLES as $cd => [$ko, $ja, $en, $perms]) {
            Repo::insert('role', ['role_cd' => $cd, 'role_nm' => $ko]);
            Translations::save('ROLE', $cd, ['ja' => $ja, 'en' => $en]);
            $list = $perms === 'ALL' ? array_keys(self::PERMISSIONS) : $perms;
            foreach ($list as $p) {
                Repo::insert('role_permission', ['role_cd' => $cd, 'perm_cd' => $p]);
            }
        }
    }

    private static function seedCodes(): void
    {
        foreach (self::CODES as $group => [$ko, $ja, $en, $codes]) {
            Repo::insert('code_group', ['group_cd' => $group, 'group_nm' => $ko, 'use_yn' => 1]);
            Translations::save('CODE_GROUP', $group, ['ja' => $ja, 'en' => $en]);
            $i = 0;
            foreach ($codes as $cd => [$cko, $cja, $cen]) {
                Repo::insert('code', ['group_cd' => $group, 'code_cd' => $cd, 'code_nm' => $cko, 'sort_no' => ++$i, 'use_yn' => 1]);
                Translations::save('CODE', $group . ':' . $cd, ['ja' => $cja, 'en' => $cen]);
            }
        }
    }
}
