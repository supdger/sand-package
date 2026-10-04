<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/plugin/sandpackage/tools/system-update-worker.php';
if(PHP_OS_FAMILY!=='Windows')throw new RuntimeException('This test requires an actual Windows host.');
$autoload=getenv('SAND_WORKERMAN_AUTOLOAD')?:'';$wrapper=getenv('SAND_WINDOWS_WRAPPER')?:'';
if(!is_file($autoload)||!is_file($wrapper))throw new RuntimeException('Set existing SAND_WORKERMAN_AUTOLOAD and standard SAND_WINDOWS_WRAPPER; no dependencies or host services are installed.');
$root=SandSystemUpdateRuntime::normalizePath(sys_get_temp_dir()).'/sand-real-windows-host-'.bin2hex(random_bytes(5));mkdir($root,0700);$process=null;$count=0;
function verifyWindows(bool $condition,string $message):void{global $count;if(!$condition)throw new RuntimeException('FAIL: '.$message);$count++;echo 'PASS: '.$message.PHP_EOL;}
function putWindows(string $file,string $text):void{if(!is_dir(dirname($file)))mkdir(dirname($file),0755,true);file_put_contents($file,$text);}
function windowsFacts(string $url):array{$handle=curl_init($url);curl_setopt_array($handle,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_PROXY=>'']);$body=curl_exec($handle);$code=curl_getinfo($handle,CURLINFO_RESPONSE_CODE);curl_close($handle);if($code!==200||!is_string($body))throw new RuntimeException('fixture HTTP '.$code);return json_decode($body,true,128,JSON_THROW_ON_ERROR);}
try{
 $server=$root.'/server';$front=$root.'/sandadmin-artd';$static=$server.'/public/admin';$runtime=$server.'/runtime/system-update';
 $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException($error);$address=stream_socket_get_name($socket,false);fclose($socket);$port=(int)substr(strrchr($address,':'),1);
 mkdir($runtime,0700,true);mkdir($front,0755);
 putWindows($server.'/start.php','<?php // Standard Windows process-file supervisor supplies the HTTP process.');
 copy($wrapper,$server.'/windows.php');
 putWindows($server.'/vendor/autoload.php','<?php define("BASE_PATH",dirname(__DIR__));require '.var_export($autoload,true).';require '.var_export(dirname(__DIR__,2).'/plugin/sandpackage/tools/system-update-worker.php',true).';require '.var_export(dirname(__DIR__,2).'/plugin/sandpackage/app/service/SystemUpdateEnvironment.php',true).';require '.var_export(dirname(__DIR__,2).'/plugin/sandpackage/app/controller/SystemUpdateProbeController.php',true).';require BASE_PATH."/app/process/FixtureHttp.php";');
 putWindows($server.'/support/bootstrap.php','<?php \Webman\Config::clear();\support\App::loadAllConfig(["route"]);\Workerman\Protocols\Http::requestClass(\support\Request::class);');
 putWindows($server.'/config/container.php','<?php return new \Webman\Container;');
 putWindows($server.'/config/app.php','<?php return ["debug"=>false,"error_reporting"=>E_ALL,"default_timezone"=>"UTC","public_path"=>base_path()."/public"];');
 putWindows($server.'/config/server.php','<?php return ["listen"=>"","log_file"=>runtime_path()."/workerman.log","pid_file"=>runtime_path()."/webman.pid"];');
 putWindows($server.'/config/process.php','<?php return ["webman"=>["handler"=>\app\process\FixtureHttp::class,"listen"=>"http://127.0.0.1:'.$port.'","count"=>1],"monitor"=>["handler"=>\app\process\Monitor::class,"constructor"=>["monitorDir"=>[base_path()."/config"],"monitorExtensions"=>["php"],"options"=>["enable_file_monitor"=>false,"enable_memory_monitor"=>false]]]];');
 putWindows($server.'/plugin/sandadmin/config/app.php','<?php return ["version"=>"0.2.1"];');putWindows($server.'/plugin/sandpackage/config/app.php','<?php return ["version"=>"0.2.3"];');
 putWindows($static.'/index.html','<script src="/admin/assets/app.js"></script>');putWindows($static.'/assets/app.js','console.log("Windows ready");');SandSystemUpdateRuntime::writeJson($static.'/'.SandSystemUpdateRuntime::STATIC_MANIFEST,['files'=>SandSystemUpdateRuntime::tree($static)]);
 putWindows($server.'/app/process/FixtureHttp.php', <<<'HANDLER'
<?php
namespace app\process;
use Workerman\Protocols\Http\Response;
final class FixtureHttp {
 private array $environment;
 public function onWorkerStart($worker):void{$this->environment=\plugin\sandpackage\app\service\SystemUpdateEnvironment::resolve(\SandSystemUpdateRuntime::normalizePath(base_path()),[],config('process'),\SandSystemUpdateRuntime::normalizePath(public_path()),\SandSystemUpdateRuntime::normalizePath(runtime_path().'/webman.pid'));}
 public function onMessage($connection,$request):void{
  $request->connection=$connection;$path=$request->path();
  if($path==='/facts'){$connection->send(new Response(200,['Content-Type'=>'application/json'],json_encode(['environment'=>$this->environment,'pid'=>getmypid(),'versions'=>['supdger/sand-core'=>config('plugin.sandadmin.app.version'),'supdger/sand-package'=>config('plugin.sandpackage.app.version')]])));return;}
  if($path==='/admin/index.html'||$path==='/admin/assets/app.js'){$connection->send(new Response(200,[],file_get_contents(public_path().$path)));return;}
  $response=(new \plugin\sandpackage\app\controller\SystemUpdateProbeController)->probe($request);$connection->send($response);
 }
}
HANDLER);
 echo 'Starting own standard Windows supervisor at 127.0.0.1:'.$port.PHP_EOL;
 $process=proc_open([PHP_BINARY,$server.'/windows.php'],[0=>['file','NUL','r'],1=>['file',$root.'/supervisor.log','a'],2=>['file',$root.'/supervisor.log','a']],$pipes,$server,null,['bypass_shell'=>true]);if(!is_resource($process))throw new RuntimeException('cannot create own Windows supervisor');
 $status=proc_get_status($process);$ownedPid=$status['pid'];echo 'Owned supervisor PID '.$ownedPid.'; root '.$root.PHP_EOL;
 $facts=null;$deadline=microtime(true)+60;do{try{$facts=windowsFacts('http://127.0.0.1:'.$port.'/facts');break;}catch(Throwable){usleep(250000);}}while(microtime(true)<$deadline);
 if(!$facts){echo file_get_contents($root.'/supervisor.log');throw new RuntimeException('real Windows fixture not ready');}
 $deployment=$facts['environment']['deployment'];verifyWindows($deployment['reload_mode']==='windows-monitor','actual HTTP worker identifies the standard Windows supervisor adapter');verifyWindows($deployment['supervisor']['pid']===$ownedPid,'supervisor is bound to the actual request ancestor and owned process PID');verifyWindows(SandSystemUpdateRuntime::processActive($deployment['supervisor']),'PID and native process start time identify the live own supervisor');
 $plan=['server'=>$server,'frontend'=>$front,'static'=>$static,'root'=>$runtime,'storage'=>$server.'/storage/sandpackage','php'=>SandSystemUpdateRuntime::normalizePath(PHP_BINARY),'reload'=>[],'health'=>[],'deployment'=>$deployment];$job=$runtime.'/jobs/'.str_repeat('a',32);mkdir($job,0700,true);SandSystemUpdateRuntime::writeJson($job.'/plan.json',$plan);SandSystemUpdateRuntime::writeJson($job.'/task.json',['logs'=>[],'stage'=>'queued','state'=>'running','updated_at'=>time()]);
 $hash=hash_file('sha256',$server.'/config/server.php');putWindows($server.'/plugin/sandpackage/config/app.php','<?php return ["version"=>"0.2.4"];');
 $updater=new SandSystemUpdateRuntime($job);$method=new ReflectionMethod($updater,'reloadAndHealth');$method->invoke($updater,$plan);
 $after=windowsFacts('http://127.0.0.1:'.$port.'/facts');verifyWindows($after['pid']!==$facts['pid'],'mtime trigger really replaces the Windows HTTP worker through its existing supervisor');verifyWindows($after['versions']['supdger/sand-package']==='0.2.4','actual loopback probe verifies the new loaded runtime version');verifyWindows($hash===hash_file('sha256',$server.'/config/server.php'),'reload trigger changes mtime without editing existing config content');verifyWindows(!is_file($runtime.'/health-probe.json'),'successful Windows runtime and asset health removes its challenge');
 putWindows($server.'/plugin/sandpackage/config/app.php','<?php return ["version"=>"0.2.3"];');SandSystemUpdateRuntime::remove($static,[],true);SandSystemUpdateRuntime::writeJson($static.'/'.SandSystemUpdateRuntime::STATIC_MANIFEST,['files'=>[]]);$method->invoke($updater,$plan,true);$restored=windowsFacts('http://127.0.0.1:'.$port.'/facts');verifyWindows($restored['versions']['supdger/sand-package']==='0.2.3','Windows recovery reload actually restores the old runtime version');verifyWindows(!is_file($static.'/index.html'),'original empty static deployment recovers without inventing an index');
 echo 'RESULT: '.$count.' Windows host checks passed'.PHP_EOL;
}finally{
 if(is_resource($process)){$status=proc_get_status($process);if($status['running']){echo 'Stopping owned supervisor tree PID '.$status['pid'].PHP_EOL;SandSystemUpdateRuntime::command([SandSystemUpdateRuntime::normalizePath(getenv('SystemRoot')).'/System32/taskkill.exe','/F','/T','/PID',(string)$status['pid']],$root,static function(string $line):void{echo $line.PHP_EOL;},30);}proc_close($process);}
 SandSystemUpdateRuntime::remove($root);
}
