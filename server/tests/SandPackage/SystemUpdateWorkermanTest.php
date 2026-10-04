<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/plugin/sandpackage/tools/system-update-worker.php';
$autoload=getenv('SAND_WORKERMAN_AUTOLOAD')?:'';
if(!is_file($autoload))throw new RuntimeException('Set SAND_WORKERMAN_AUTOLOAD to an existing Composer autoload containing Workerman; no dependency install is performed.');
$root=SandSystemUpdateRuntime::normalizePath(sys_get_temp_dir()).'/sand-real-workerman-'.bin2hex(random_bytes(5));mkdir($root,0700);
$process=null;$count=0;
function verify(bool $value,string $message):void{global $count;if(!$value)throw new RuntimeException('FAIL: '.$message);$count++;echo 'PASS: '.$message.PHP_EOL;}
function putFixture(string $path,string $text):void{if(!is_dir(dirname($path)))mkdir(dirname($path),0755,true);file_put_contents($path,$text);}
function httpFixture(string $url):array{$handle=curl_init($url);curl_setopt_array($handle,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>3,CURLOPT_PROXY=>'']);$text=curl_exec($handle);$code=curl_getinfo($handle,CURLINFO_RESPONSE_CODE);curl_close($handle);if($code!==200||!is_string($text))throw new RuntimeException('fixture HTTP failed '.$code);return json_decode($text,true,128,JSON_THROW_ON_ERROR);}
try{
 $server=$root.'/server';$front=$root.'/sandadmin-artd';$static=$server.'/public/admin';$runtime=$server.'/runtime/system-update';
 $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException($error);$address=stream_socket_get_name($socket,false);fclose($socket);$port=(int)substr(strrchr($address,':'),1);
 putFixture($server.'/plugin/sandadmin/config/app.php','<?php return ["version"=>"0.2.1"];');
 putFixture($server.'/plugin/sandpackage/config/app.php','<?php return ["version"=>"0.2.2"];');
 putFixture($static.'/index.html','<script src="/admin/assets/app.js"></script>');putFixture($static.'/assets/app.js','console.log("ready");');
 mkdir($runtime,0700,true);mkdir($front,0755);SandSystemUpdateRuntime::writeJson($static.'/'.SandSystemUpdateRuntime::STATIC_MANIFEST,['files'=>SandSystemUpdateRuntime::tree($static)]);
 putFixture($server.'/start.php', <<<'WORKER'
<?php
require getenv('SAND_WORKERMAN_AUTOLOAD');
require getenv('SAND_UPDATE_RUNTIME');require getenv('SAND_UPDATE_ENVIRONMENT');
use Workerman\Worker;
use Workerman\Protocols\Http\Response;
use plugin\sandpackage\app\service\SystemUpdateEnvironment;
Worker::$pidFile=__DIR__.'/runtime/webman.pid';Worker::$logFile=__DIR__.'/runtime/workerman.log';
$port=(int)getenv('SAND_FIXTURE_PORT');$worker=new Worker('http://127.0.0.1:'.$port);$worker->count=1;
$worker->onWorkerStart=function($worker)use($port){
 $core=require __DIR__.'/plugin/sandadmin/config/app.php';$package=require __DIR__.'/plugin/sandpackage/config/app.php';
 $loaded=['supdger/sand-core'=>$core['version'],'supdger/sand-package'=>$package['version']];
 $environment=SystemUpdateEnvironment::resolve(__DIR__,[],['webman'=>['listen'=>'http://127.0.0.1:'.$port]],__DIR__.'/public',__DIR__.'/runtime/webman.pid');
 $worker->onMessage=function($connection,$request)use($loaded,$environment){
  $path=$request->path();
  if($path==='/facts'){$connection->send(new Response(200,['Content-Type'=>'application/json'],json_encode(['environment'=>$environment,'pid'=>getmypid(),'versions'=>$loaded])));return;}
  if($path==='/admin/index.html'||$path==='/admin/assets/app.js'){$connection->send(new Response(200,[],file_get_contents(__DIR__.'/public'.$path)));return;}
  $probeFile=__DIR__.'/runtime/system-update/health-probe.json';$probe=is_file($probeFile)?json_decode(file_get_contents($probeFile),true):null;
  if($path!=='/app/sandpackage/systemUpdate/probe'||!$probe||$probe['expires_at']<time()||!hash_equals($probe['token'],$request->header('x-sand-update-probe',''))){$connection->send(new Response(404,[],'Not Found'));return;}
  $connection->send(new Response(200,['Content-Type'=>'application/json'],json_encode(['token'=>$probe['token'],'versions'=>$loaded])));
 };
};
Worker::runAll();
WORKER);
 $environment=getenv();$environment['SAND_WORKERMAN_AUTOLOAD']=$autoload;$environment['SAND_FIXTURE_PORT']=(string)$port;$environment['SAND_UPDATE_RUNTIME']=dirname(__DIR__,2).'/plugin/sandpackage/tools/system-update-worker.php';$environment['SAND_UPDATE_ENVIRONMENT']=dirname(__DIR__,2).'/plugin/sandpackage/app/service/SystemUpdateEnvironment.php';
 echo 'Starting isolated Workerman at 127.0.0.1:'.$port.PHP_EOL;
 $process=proc_open([PHP_BINARY,$server.'/start.php','start'],[0=>['file','/dev/null','r'],1=>['file',$root.'/workerman-output.log','a'],2=>['file',$root.'/workerman-output.log','a']],$pipes,$server,$environment,['bypass_shell'=>true]);if(!is_resource($process))throw new RuntimeException('cannot start own fixture master');
 $facts=null;for($i=0;$i<60;$i++){try{$facts=httpFixture('http://127.0.0.1:'.$port.'/facts');break;}catch(Throwable){usleep(100000);}}
 if(!$facts){echo file_get_contents($root.'/workerman-output.log');throw new RuntimeException('fixture HTTP not ready');}
 verify($facts['environment']['deployment']['master']['pid']!==$facts['pid'],'standard real Workerman HTTP worker identifies its own master');
 verify(str_contains($facts['environment']['deployment']['master']['identity'],'start_file='.$server.'/start.php'),'master identity binds the exact isolated host start file');
 $deployment=$facts['environment']['deployment'];$job=$runtime.'/jobs/'.str_repeat('a',32);mkdir($job,0700,true);
 $plan=['server'=>$server,'frontend'=>$front,'static'=>$static,'root'=>$runtime,'storage'=>$server.'/storage/sandpackage','php'=>PHP_BINARY,'reload'=>[],'health'=>[],'deployment'=>$deployment];
 SandSystemUpdateRuntime::writeJson($job.'/plan.json',$plan);SandSystemUpdateRuntime::writeJson($job.'/task.json',['logs'=>[],'stage'=>'queued','state'=>'running','updated_at'=>time()]);
 putFixture($server.'/plugin/sandpackage/config/app.php','<?php return ["version"=>"0.2.3"];');
 $updater=new SandSystemUpdateRuntime($job);$method=new ReflectionMethod($updater,'reloadAndHealth');$method->invoke($updater,$plan);
 $after=httpFixture('http://127.0.0.1:'.$port.'/facts');verify($after['pid']!==$facts['pid'],'framework SIGUSR1 actually replaces the isolated HTTP worker');verify($after['versions']['supdger/sand-package']==='0.2.3','HTTP health verifies the newly loaded version after native reload');verify($after['environment']['deployment']['master']===$deployment['master'],'native reload preserves the bound master identity');
 verify(!is_file($runtime.'/health-probe.json'),'real reload health challenge is cleaned');
 echo 'RESULT: '.$count.' checks passed'.PHP_EOL;
}finally{
 if(is_resource($process)){$status=proc_get_status($process);echo 'Stopping own fixture master PID '.$status['pid'].PHP_EOL;proc_terminate($process,SIGINT);$deadline=microtime(true)+5;do{$status=proc_get_status($process);if(!$status['running'])break;usleep(100000);}while(microtime(true)<$deadline);if($status['running'])throw new RuntimeException('Own fixture master has not stopped; preserve '.$root);proc_close($process);}
 SandSystemUpdateRuntime::remove($root);
}
