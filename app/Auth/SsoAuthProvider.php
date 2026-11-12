<?php
declare(strict_types=1);

namespace App\Auth;

/** SSO 연동 자리표시. SSO 문서/라이브러리를 받으면 이 클래스를 구현한다(미연동 상태에서는 항상 거부). */
final class SsoAuthProvider implements AuthProviderInterface
{
    public function name(): string
    {
        return 'SSO';
    }

    public function loginUrl(string $state): ?string
    {
        return null;
    }

    public function identify(array $query, string $expectedState): ?array
    {
        return null;
    }
}
