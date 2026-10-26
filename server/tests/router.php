<?php
// 仅用于本机 PHP 开发服务器，不用于生产 Nginx。
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(str_starts_with($path,'/static/') && is_file(dirname(__DIR__).'/public'.$path))return false;
require dirname(__DIR__).'/public/index.php';
