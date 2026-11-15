<?php
declare(strict_types=1);

namespace JobSearch\Domain\Resume;

/** Исход чтения списка резюме с UI hh.ru. */
enum ResumeListKind: string
{
    case Ok = 'ok';
    case LoginRequired = 'login_required';
    case CaptchaOrActionRequired = 'captcha_or_action_required';
    case PermissionBlocked = 'permission_blocked';
    case Invalid = 'invalid';
}
