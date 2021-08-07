<?php

declare(strict_types=1);
/**
 * This file is part of Swoft.
 *
 * @link     https://swoft.org
 * @document https://swoft.org/docs
 * @contact  group@swoft.org
 * @license  https://github.com/swoft-cloud/swoft/blob/master/LICENSE
 */

namespace App\Task\Crontab;

use Exception;
use Swoft\Crontab\Annotaion\Mapping\Cron;
use Swoft\Crontab\Annotaion\Mapping\Scheduled;
use Swoft\Log\Helper\CLog;

/**
 * Class CronTask
 *
 * @since 2.0
 *
 * @Scheduled()
 */
class CronTask
{
    /**
     * @Cron("* * * * * *")
     *
     * @throws Exception
     */
    public function secondTask(): void
    {
        // $user = new User();
        // $user->setAge(mt_rand(1, 100));
        // $user->setUserDesc('desc');
        //
        // $user->save();
        //
        // $id   = $user->getId();
        // $user = User::find($id)->toArray();

        CLog::info('second task run: %s ', date('Y-m-d H:i:s'));
        // CLog::info(JsonHelper::encode($user));
    }

    /**
     * @Cron("0 * * * * *")
     */
    public function minuteTask(): void
    {
        CLog::info('minute task run: %s ', date('Y-m-d H:i:s'));
    }

    /**
     * @Cron("3 * * * * *")
     * 监控服务器
     */
    public function monitorTask(): void
    {
        try {
            $miner_host = env("MINER_HOST");
            $miner_ssh_port = env("MINER_SSH_PORT");
            $miner_user = env("MINER_USER");
            $miner_password = env("MINER_PASSWORD");
            if (!function_exists("ssh2_connect")) {
                throw new \Exception("ssh2 扩展不存在！");
            }
            $conn = \ssh2_connect($miner_host, $miner_ssh_port);
            if (!$conn) {
                throw new \Exception("服务器链接失败！");
            }
            $success = \ssh2_auth_password($conn, $miner_user, $miner_password);
            if (!$success) {
                throw new \Exception("连接服务器密码错误！");
            }
            $shell = "/usr/local/bin/lotus-miner sealing jobs";
            $serverReturn = \ssh2_exec($conn, $shell);
            CLog::debug($serverReturn);
        } catch (\Exception $e) {
            CLog::error("【ERROR】 " . $e->getMessage());
        }
    }
}
