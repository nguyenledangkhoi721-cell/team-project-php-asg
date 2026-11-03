<?php
// Run on the temporary admin EC2 with the RDS application config. Uses fake data.
declare(strict_types=1);
$db=require dirname(__DIR__).'/src/database.php';
require dirname(__DIR__).'/src/DatabaseSessionHandler.php';
$id='labtest'.bin2hex(random_bytes(12));
$payload='csrf|s:4:"test";';
$first=new DatabaseSessionHandler($db);
$first->open('','PHPSESSID'); $first->read($id);
if (!$first->write($id,$payload)) throw new RuntimeException('Write failed');
$first->close();
$db2=require dirname(__DIR__).'/src/database.php';
$second=new DatabaseSessionHandler($db2);
if (!$second->validateId($id)) throw new RuntimeException('Session ID not shared');
if ($second->read($id)!==$payload) throw new RuntimeException('Shared read failed');
$second->destroy($id); $second->close();
echo "PASS session read and write across two database connections\n";
