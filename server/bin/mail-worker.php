#!/usr/bin/env php
<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/src/bootstrap.php';
if(count($argv)!==2||!in_array($argv[1],['--once','--loop'],true)){fwrite(STDERR,"Usage: php bin/mail-worker.php --once|--loop\n");exit(2);}
$loop=$argv[1]==='--loop';
try{if($loop&&!mailLock('daemon')){echo "Mail worker already running.\n";exit(0);}}catch(Throwable){fwrite(STDERR,"Mail worker unavailable; check database and migration.\n");exit(1);}
$signature=static fn()=>hash_file('sha256',__FILE__).hash_file('sha256',__DIR__.'/../src/mail.php').hash_file('sha256',__DIR__.'/../src/mail_worker.php').hash_file('sha256',__DIR__.'/../src/mail_transport.php').hash_file('sha256',__DIR__.'/../src/version.php');$started=$signature();$first=true;
do{try{$r=mailRun($loop?5:100);if(!$loop||$first||$r['processed']>0)echo json_encode(['at'=>gmdate('c')]+$r)."\n";$first=false;}catch(Throwable){fwrite(STDERR,"Mail worker unavailable; check configuration/keyring and restart.\n");exit(1);}if($loop){sleep(3);if($signature()!==$started){echo "Application updated; restart mail worker.\n";break;}}}while($loop);
