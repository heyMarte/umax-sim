<?php
return [
    // 在线升级使用的 CLI 路径；宝塔 PHP 8.2 默认如下。
    'php_cli' => '/www/server/php/82/bin/php',
    'db_host' => '127.0.0.1',
    'db_port' => 3306,
    'db_name' => 'simmanager',
    'db_user' => 'simmanager',
    'db_password' => '请填写宝塔创建数据库时设置的密码',
    'secure_cookie' => true,
    // 可选：追加平台用途标签；标识1至16位，最多16个平台（含预置三家）。
    // 'platform_catalog' => ['amazon' => ['label' => 'Amazon', 'keywords' => ['amazon']]],
    'backup_dir' => '/var/lib/sim-manager-backups', // 需先创建私有目录并配置宝塔计划任务
];
