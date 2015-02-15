<?php
/**
 * This file is part of openvj project.
 *
 * Copyright 2013-2015 openvj dev team.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace VJ\EventListener;

use VJ\Core\Application;
use VJ\Core\Event\GenericEvent;
use VJ\Util;
use VJ\VJ;

class LoginLogService
{
    public function onEvent(GenericEvent $event, $type, $user)
    {
        $ip = Util::getClientIp();
        $userAgent = Util::getUserAgentSafe();

        if (
            $type == VJ::LOGIN_TYPE_INTERACTIVE
            || $type == VJ::LOGIN_TYPE_COOKIE
            || $type == VJ::LOGIN_TYPE_FAILED_WRONG_PASSWORD
        ) {
            $this->appendLog($user['uid'], $type, $userAgent, $ip);
        }
    }

    /**
     * 插入一条登录记录
     *
     * @param int $uid
     * @param int $type
     * @param string $ua
     * @param string $ip
     * @return bool
     */
    public function appendLog($uid, $type, $ua, $ip)
    {
        if (!is_string($ua) || !mb_check_encoding($ua, 'UTF-8')) {
            $ua = null;
        }

        Application::coll('LoginLog')->insert([
            'uid' => (int)$uid,
            'at' => new \MongoDate(),
            'type' => (int)$type,
            'ua' => $ua,
            'ip' => $ip
        ]);

        return true;
    }
}