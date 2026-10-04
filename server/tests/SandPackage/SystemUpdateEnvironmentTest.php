<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/plugin/sandpackage/tools/system-update-worker.php';
require_once dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/SystemUpdateEnvironment.php';
use plugin\sandpackage\app\service\SystemUpdateEnvironment;
$root = SandSystemUpdateRuntime::normalizePath(sys_get_temp_dir()) . '/sand-update-auto-' . bin2hex(random_bytes(5));
mkdir($root, 0700); $count = 0; $process = null;
function check(bool $condition, string $label): void { global $count; if (!$condition) throw new RuntimeException('FAIL: ' . $label); $count++; echo 'PASS: ' . $label . PHP_EOL; }
function rejects(callable $action, string $label): void { $thrown = false; try { $action(); } catch (RuntimeException) { $thrown = true; } check($thrown, $label); }
function filePut(string $file, string $value): void { if (!is_dir(dirname($file))) mkdir(dirname($file), 0755, true); file_put_contents($file, $value); }
try {
    $server = $root . '/server'; $front = $root . '/sandadmin-artd'; $static = $server . '/public/admin'; $runtime = $server . '/runtime/system-update';
    filePut($server . '/start.php', '<?php echo "fixture Workerman reload\\n"; copy(__DIR__."/expected.json", __DIR__."/loaded.json");');
    filePut($server . '/runtime/webman.pid', (string)getmypid());
    filePut($front . '/dist/index.html', '<html>current build</html>');
    $settings = ['frontend' => $front, 'reload' => [PHP_BINARY, $server . '/start.php', 'reload'], 'health' => [PHP_BINARY, '-r', 'echo "health";']];
    $env = SystemUpdateEnvironment::resolve($server, $settings, [], $server . '/public', $server . '/runtime/webman.pid');
    check($env['static'] === $static && $env['deployment'] === [], 'existing explicit deployment commands remain compatible and missing static uses dedicated admin directory');
    $plan = ['server'=>$server,'frontend'=>$front,'static'=>$static,'root'=>$runtime,'storage'=>$server.'/storage/sandpackage'];
    check(SystemUpdateEnvironment::staticFiles($plan) === [] && !file_exists($static), 'read-only readiness accepts first deployment without writing directories');
    SystemUpdateEnvironment::prepareStatic($plan);
    check(SandSystemUpdateRuntime::readJson($static . '/' . SandSystemUpdateRuntime::STATIC_MANIFEST)['files'] === [], 'upgrade inspection creates only an empty dedicated static baseline');
    filePut($static . '/unknown.txt', 'KEEP');
    rejects(fn () => SystemUpdateEnvironment::prepareStatic($plan), 'unknown file in managed static directory blocks preparation');
    check(file_get_contents($static . '/unknown.txt') === 'KEEP', 'preparation preserves unknown file content');
    unlink($static . '/unknown.txt'); unlink($static . '/' . SandSystemUpdateRuntime::STATIC_MANIFEST);
    filePut($static . '/index.html', '<html>current build</html>');
    SystemUpdateEnvironment::prepareStatic($plan);
    check(SandSystemUpdateRuntime::readJson($static . '/' . SandSystemUpdateRuntime::STATIC_MANIFEST)['files'] === SandSystemUpdateRuntime::tree($front . '/dist'), 'matching deployed build is automatically adopted after full path and hash comparison');
    filePut($static . '/index.html', 'local edit');
    rejects(fn () => SystemUpdateEnvironment::prepareStatic($plan), 'local static modification cannot be adopted as a new baseline');
    $bad = $plan; $bad['static'] = $server . '/config';
    rejects(fn () => SystemUpdateEnvironment::staticFiles($bad), 'host configuration directory is never a static target');
    $bad['static'] = $front . '/dist';
    rejects(fn () => SystemUpdateEnvironment::staticFiles($bad), 'front-end dist is never treated as a dedicated static target');
    if (PHP_OS_FAMILY !== 'Windows') {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (!$socket) throw new RuntimeException($error);
        $address = stream_socket_get_name($socket, false); fclose($socket); $port = (int)substr(strrchr($address, ':'), 1);
        $nativeSettings = ['reload'=>[PHP_BINARY,$server.'/start.php','reload']];
        $env = SystemUpdateEnvironment::resolve($server, $nativeSettings, ['webman'=>['listen'=>'http://0.0.0.0:'.$port]], $server.'/public', $server.'/runtime/webman.pid');
        check($env['deployment']['url'] === 'http://127.0.0.1:' . $port && $env['frontend_base'] === '/admin/', 'automatic deployment binds health to the real local listener and framework-controlled static base');
        rejects(fn () => SystemUpdateEnvironment::resolve($server, $nativeSettings, ['webman'=>['listen'=>'http://untrusted.example:80']], $server.'/public', $server.'/runtime/webman.pid'), 'unknown listener cannot redirect framework health checks off host');
        filePut($server . '/plugin/sandadmin/config/app.php', '<?php return ["version"=>"0.2.1"];');
        filePut($server . '/plugin/sandpackage/config/app.php', '<?php return ["version"=>"0.2.2"];');
        $expected=['supdger/sand-core'=>'0.2.1','supdger/sand-package'=>'0.2.2'];
        SandSystemUpdateRuntime::writeJson($server.'/expected.json',$expected);
        SandSystemUpdateRuntime::writeJson($server.'/loaded.json',['supdger/sand-core'=>'old','supdger/sand-package'=>'old']);
        $controller = dirname(__DIR__,2).'/plugin/sandpackage/app/controller/SystemUpdateProbeController.php';
        filePut($root . '/router.php', <<<'ROUTER'
<?php
namespace support {
 class Request { public function header($key,$default=''){return $_SERVER['HTTP_X_SAND_UPDATE_PROBE']??$default;} public function getRemoteIp(){return $_SERVER['REMOTE_ADDR'];} }
 class Response { public function __construct(public $status, public $headers=[], public $body=''){} }
}
namespace {
 function base_path(){return __DIR__.'/server';}
 function config($key){$map=json_decode(file_get_contents(base_path().'/loaded.json'),true);return $key==='plugin.sandadmin.app.version'?$map['supdger/sand-core']:$map['supdger/sand-package'];}
 function json($data){return new \support\Response(200,['Content-Type'=>'application/json'],json_encode($data));}
 $uri=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
 if ($uri==='/admin/index.html'||$uri==='/admin/assets/app.js'){echo file_get_contents(base_path().'/public'.$uri);return;}
 require getenv('SAND_PROBE_CONTROLLER');
 $response=(new \plugin\sandpackage\app\controller\SystemUpdateProbeController)->probe(new \support\Request);
 http_response_code($response->status);foreach($response->headers as $key=>$value)header($key.': '.$value);echo $response->body;
}
ROUTER);
        $environment = getenv(); $environment['SAND_PROBE_CONTROLLER'] = $controller;
        $process = proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,$root.'/router.php'],[0=>['file','/dev/null','r'],1=>['file',$root.'/http.log','a'],2=>['file',$root.'/http.log','a']],$pipes,$root,$environment,['bypass_shell'=>true]);
        if (!is_resource($process)) throw new RuntimeException('fixture HTTP process unavailable');
        $ready = false;
        for($i=0;$i<40;$i++){ $socket=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,0.1);if($socket){fclose($socket);$ready=true;break;}usleep(50000); }
        check($ready,'isolated loopback HTTP fixture is listening');
        $handle=curl_init('http://127.0.0.1:'.$port.'/app/sandpackage/systemUpdate/probe');curl_setopt_array($handle,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>3,CURLOPT_PROXY=>'']);curl_exec($handle);$code=curl_getinfo($handle,CURLINFO_RESPONSE_CODE);curl_close($handle);
        if ($code !== 404) echo file_get_contents($root.'/http.log');
        check($code===404,'probe refuses unauthenticated loopback requests');
        $job=$runtime.'/jobs/'.str_repeat('a',32);mkdir($job,0700,true);
        $full=$plan+['php'=>PHP_BINARY,'reload'=>$nativeSettings['reload'],'health'=>[],'deployment'=>$env['deployment']];
        SandSystemUpdateRuntime::writeJson($job.'/plan.json',$full);
        SandSystemUpdateRuntime::writeJson($job.'/task.json',['logs'=>[],'stage'=>'queued','state'=>'running','updated_at'=>time()]);
        $worker=new SandSystemUpdateRuntime($job);$method=new ReflectionMethod($worker,'reloadAndHealth');
        $method->invoke($worker,$full);
        check(SandSystemUpdateRuntime::readJson($server.'/loaded.json')===$expected,'automatic reload runs the real command and health accepts only the loaded expected versions');
        check(!is_file($runtime.'/health-probe.json'),'health challenge is removed after successful verification');
        $assetCheck = new ReflectionMethod($worker, 'verifyHttpAssets');
        rejects(fn () => $assetCheck->invoke($worker, $full, '<script src="/assets/app.js"></script>'), 'root asset base cannot pass health for an admin subdirectory deployment');
        filePut($static.'/assets/app.js','console.log("ready");');
        $assetCheck->invoke($worker,$full,'<script src="/admin/assets/app.js"></script>');
        check(true,'HTTP entry JavaScript must match the deployed file hash');
        filePut($static.'/index.html','<script src="/assets/app.js"></script>');
        rejects(fn () => $method->invoke($worker,$full), 'index HTTP success with wrong asset base never reports upgrade success');
        check(!is_file($runtime.'/health-probe.json'),'failed asset health also removes its challenge');
        SandSystemUpdateRuntime::remove($static,[],true);
        SandSystemUpdateRuntime::writeJson($static.'/'.SandSystemUpdateRuntime::STATIC_MANIFEST,['files'=>[]]);
        $method->invoke($worker,$full,true);
        check(!is_file($static.'/index.html'),'restoring an originally empty static baseline verifies the old service without fabricating an index');
        $token=bin2hex(random_bytes(32));SandSystemUpdateRuntime::writeJson($runtime.'/health-probe.json',['token'=>$token,'expires_at'=>time()-1]);
        $handle=curl_init('http://127.0.0.1:'.$port.'/app/sandpackage/systemUpdate/probe');curl_setopt_array($handle,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>3,CURLOPT_PROXY=>'',CURLOPT_HTTPHEADER=>['X-Sand-Update-Probe: '.$token]]);curl_exec($handle);$code=curl_getinfo($handle,CURLINFO_RESPONSE_CODE);curl_close($handle);
        check($code===404,'probe refuses expired challenges even with a correct token');
    }
    $tools=$root.'/native-tools';filePut($tools.'/composer.phar','fixture');filePut($tools.'/node.exe','fixture');filePut($tools.'/node_modules/pnpm/bin/pnpm.cjs','fixture');
    check(SystemUpdateEnvironment::nativeWindowsCommand('composer',[$tools],PHP_BINARY)===[PHP_BINARY,$tools.'/composer.phar'],'Windows Composer wrapper resolves to actual PHP and PHAR argv');
    check(SystemUpdateEnvironment::nativeWindowsCommand('pnpm',[$tools],PHP_BINARY)===[$tools.'/node.exe',$tools.'/node_modules/pnpm/bin/pnpm.cjs'],'Windows pnpm wrapper resolves to actual native Node and installed CLI argv');
    rejects(fn()=>SystemUpdateEnvironment::nativeWindowsCommand('pnpm',[$root.'/missing-tools'],PHP_BINARY),'unknown Windows tools are not converted into shell wrappers');
    if(PHP_OS_FAMILY!=='Windows'){
        filePut($server.'/runtime/webman.pid','99999999');
        rejects(fn()=>SystemUpdateEnvironment::resolve($server,[],['webman'=>['listen'=>'http://127.0.0.1:8787']],$server.'/public',$server.'/runtime/webman.pid'),'wrong master PID cannot claim automatic reload readiness');
        filePut($server.'/runtime/webman.pid',(string)posix_getppid());
        rejects(fn()=>SystemUpdateEnvironment::resolve($server,[],['webman'=>['listen'=>'http://127.0.0.1:8787']],$server.'/public',$server.'/runtime/webman.pid'),'an unrelated live parent cannot impersonate this host Workerman master');
    }
    echo 'RESULT: '.$count.' checks passed'.PHP_EOL;
} finally {
    if(is_resource($process)){proc_terminate($process);proc_close($process);}
    SandSystemUpdateRuntime::remove($root);
}
