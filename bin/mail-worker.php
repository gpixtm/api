<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$once=in_array('--once',$argv,true);
do {try{(new App\Infrastructure\ResetDelivery(App\Infrastructure\Database::connect()))->deliver();}catch(Throwable $e){fwrite(STDERR,"Mail worker unavailable; retrying.\n");}if(!$once)sleep(2);}while(!$once);
