<?php

declare(strict_types=1);

namespace Tinywan\Jwt {
    final class JwtToken {
        public static function verify(int $scene, string $token): array { return ['extend'=>['id'=>1,'plat'=>'sandadmin']]; }
    }
}
namespace plugin\sandadmin\basic {
    class BaseController {
        public static int $actor = 1;
        public int $adminId;
        public function __construct() { $this->adminId=self::$actor; }
        public function success(mixed $data): \support\Response { return new \support\Response(200,[],json_encode(['data'=>$data],JSON_THROW_ON_ERROR)); }
    }
}
namespace plugin\sandpackage\app\logic {
    final class InstallLogic {
        public static int $begun = 0;
        public static int $completed = 0;
        public static int $failed = 0;
        public static int $released = 0;
        public static int $constructed = 0;
        public static string $fault = '';
        public function __construct(string $app) { self::$constructed++; }
        public function beginDependencyCommand(string $type): string {
            if (self::$fault === 'anonymous') throw new class('raw-secret-sentinel') extends \RuntimeException {};
            if (self::$fault === 'begin') $this->rejectStart('raw-secret-sentinel');
            self::$begun++; return str_repeat('a',32);
        }
        private function rejectStart(string $credential): never { throw new \RuntimeException('Bearer '.$credential.' password='.$credential); }
        public function acquireDependencyExecutionLock(string $type,string $nonce): void {}
        public function recordDependencyProcessStarted(string $type,string $nonce,int $pid,int $pgid,array $descendants,int $startedAt): void {}
        public function updateDependencyProcessJournal(string $type,string $nonce,array $descendants,string $event,?int $failedAt=null): void {}
        public function confirmDependencyProcessReaped(string $type,string $nonce): void {}
        public function dependentInstallComplete(string $type,?string $nonce=null,bool $restart=false): array { self::$completed++;return ['advanced'=>true,'completed'=>true]; }
        public function dependencyCommandFailed(string $type,?string $nonce=null): bool { self::$failed++;return true; }
        public function releaseDependencyExecutionLock(): void {}
        public function releaseDependencyCommand(): void {self::$released++;}
    }
}
namespace plugin\sandpackage\app\service {
    function function_exists(string $name): bool { return !($GLOBALS['diagnosticFault']==='runtime' && $name==='posix_setsid') && \function_exists($name); }
    function proc_open(array|string $command,array $descriptors,?array &$pipes,?string $cwd=null,?array $env=null): mixed {
        $GLOBALS['diagnosticSpawnCalls']=($GLOBALS['diagnosticSpawnCalls']??0)+1;
        if ($GLOBALS['diagnosticFault']==='spawn') return false;
        return \proc_open($command,$descriptors,$pipes,$cwd,$env);
    }
    function error_log(string $message): bool {
        if ($GLOBALS['diagnosticFault']==='fallback-fails') throw new \RuntimeException('raw-secret-sentinel');
        return \error_log($message);
    }
}
namespace {
    use plugin\sandpackage\app\logic\InstallLogic;
    use plugin\sandpackage\app\service\TerminalRunner;

    $root=sys_get_temp_dir().'/sandpackage-terminal-diagnostics-test-'.bin2hex(random_bytes(8));
    mkdir($root.'/server',0700,true);mkdir($root.'/sandadmin-artd',0700,true);
    $GLOBALS['diagnosticFault']='';$GLOBALS['diagnosticLoggerFails']=false;
    ini_set('error_log',$root.'/php-errors.log');
    function base_path(string $path=''): string {global $root;return $root.'/server'.($path===''?'':'/'.$path);}
    function config(string $key,mixed $default=null): mixed {
        global $root;
        if($key==='plugin.sandpackage.terminal.commands')return ['composer'=>['update'=>['cwd'=>base_path(),'command'=>($GLOBALS['diagnosticInvalidConfig']??false)?'untrusted-config-secret':'composer update --no-interaction']]];
        if($key==='log') {
            if($GLOBALS['diagnosticLoggerFails'])throw new \RuntimeException('raw-secret-sentinel');
            return ['default'=>['handlers'=>[['class'=>\Monolog\Handler\StreamHandler::class,'constructor'=>[$root.'/webman.log',\Monolog\Logger::DEBUG],
                'formatter'=>['class'=>\Monolog\Formatter\JsonFormatter::class,'constructor'=>[]]]]]];
        }
        return $default;
    }
    function env(string $key,mixed $default=null): mixed {return $default;}
    final class DiagnosticRequest {
        public object $connection;
        public function __construct(){ $this->connection=(object)[]; }
        public function input(string $key,string $default=''): string {return ['command'=>'composer.update','extend'=>'module-install:neutral-plugin','incident'=>str_repeat('b',32),'nonce'=>str_repeat('a',32)][$key]??$default;}
        public function header(string $key,string $default=''): string {return $key==='authorization'?'Bearer raw-secret-sentinel':$default;}
    }
    function request(): DiagnosticRequest {static $request;return $request??=new DiagnosticRequest();}
    require getenv('SANDPACKAGE_TEST_VENDOR') ?: dirname(__DIR__,2).'/vendor/autoload.php';
    require dirname(__DIR__,2).'/plugin/sandpackage/app/service/TerminalRunner.php';
    $checks=0;$succeeded=false;$baseline=($argv[1]??null)==='--baseline';
    function diagnosticCheck(bool $condition,string $message): void {global $checks;if(!$condition)throw new \RuntimeException($message);$checks++;echo '[PASS] '.$message."\n";}
    function diagnosticInventory(): array {
        global $root;$files=[];
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST) as $file) {
            $files[substr($file->getPathname(),strlen($root)+1)]=$file->isDir()?'directory':hash_file('sha256',$file->getPathname());
        }
        ksort($files);return $files;
    }
    function diagnosticRun(string $fault): array {
        global $root;
        $GLOBALS['diagnosticFault']=$fault;InstallLogic::$fault=$fault==='anonymous'?'anonymous':($fault==='begin'||in_array($fault,['logger-fails','fallback-fails'],true)?'begin':'');
        $GLOBALS['diagnosticLoggerFails']=in_array($fault,['logger-fails','fallback-fails'],true);
        (new \ReflectionProperty(\support\Log::class,'instance'))->setValue(null,[]);
        foreach(['webman.log','php-errors.log'] as $name)if(is_file($root.'/'.$name))unlink($root.'/'.$name);
        InstallLogic::$begun=InstallLogic::$completed=InstallLogic::$failed=InstallLogic::$released=0;
        $runner=new TerminalRunner();$frames=[];
        foreach($runner->exec() as $frame)$frames[]=json_decode($frame,true,64,JSON_THROW_ON_ERROR)['data'];
        return [$runner,$frames];
    }
    try {
        $incidents=[];
        foreach(['runtime'=>'runtime_support','begin'=>'dependency_begin','spawn'=>'process_spawn','anonymous'=>'dependency_begin'] as $fault=>$stage) {
            [$runner,$frames]=diagnosticRun($fault);$text=implode("\n",$frames);
            diagnosticCheck(in_array('exec-error',$frames,true)&&end($frames)==='exec-completed'&&!in_array('connection-success',$frames,true)&&!in_array('exec-success',$frames,true),'Startup '.$fault.' preserves terminal failure/completion semantics');
            diagnosticCheck($runner->isTerminalCompleted()&&!$runner->isBusinessFinalized()&&InstallLogic::$completed===0,'Startup '.$fault.' cannot declare business success');
            diagnosticCheck(InstallLogic::$failed===($fault==='spawn'?1:0)&&InstallLogic::$released===1,'Startup '.$fault.' preserves original nonce compensation and lock release');
            if($baseline) {
                diagnosticCheck(!is_file($root.'/webman.log')&&!is_file($root.'/php-errors.log')&&!str_contains($text,'记录号'),'Public baseline loses startup exception diagnostics: '.$fault);continue;
            }
            $events=array_filter(explode("\n",trim(file_get_contents($root.'/webman.log'))));$event=json_decode(end($events),true,64,JSON_THROW_ON_ERROR);$context=$event['context'];
            diagnosticCheck($context['stage']===$stage&&preg_match('/^[a-f0-9]{32}$/D',$context['incident'])===1,'Real Webman logger records startup stage and incident: '.$fault);
            diagnosticCheck(str_contains($text,$context['incident'])&&str_contains($text,$stage),'SSE and private log share the generated incident: '.$fault);
            diagnosticCheck($context['incident']!==str_repeat('a',32)&&$context['incident']!==str_repeat('b',32),'Incident is independent from nonce and request-supplied value: '.$fault);
            diagnosticCheck(array_keys($context)===['incident','stage','app','command_key','worker_pid','elapsed_ms','exception_type','source','source_line'],'Private diagnostic uses only fixed safe fields: '.$fault);
            $combined=$text.file_get_contents($root.'/webman.log');
            diagnosticCheck(!str_contains($combined,'raw-secret-sentinel')&&!str_contains($combined,str_repeat('a',32))&&!str_contains($combined,'composer update --no-interaction')&&!str_contains($combined,'/private/')&&!str_contains($combined,'/Users/')&&!str_contains($combined,$root)&&!str_contains($combined,'trace'),'Diagnostic excludes secret messages, nonce, raw command, paths and trace arguments: '.$fault);
            $incidents[]=$context['incident'];
        }
        if(!$baseline) {
            diagnosticCheck(count(array_unique($incidents))===4,'Separate startup failures have distinct incident IDs');
            foreach(['logger-fails','fallback-fails'] as $fault) {
                [$runner,$frames]=diagnosticRun($fault);
                diagnosticCheck(in_array('exec-error',$frames,true)&&end($frames)==='exec-completed'&&InstallLogic::$failed===0&&InstallLogic::$released===1,'Diagnostic sink failure preserves original startup failure and finally: '.$fault);
                diagnosticCheck(!str_contains(implode("\n",$frames),'raw-secret-sentinel'),'Logger exception does not replace safe SSE: '.$fault);
                if($fault==='logger-fails') {
                    $log=file_get_contents($root.'/php-errors.log');preg_match('/记录号：([a-f0-9]{32})/',implode("\n",$frames),$match);
                    diagnosticCheck(isset($match[1])&&str_contains($log,$match[1])&&str_contains($log,'dependency_begin')&&!str_contains($log,'raw-secret-sentinel'),'PHP fallback preserves incident correlation without exposing either exception');
                }
            }
            require dirname(__DIR__,2).'/plugin/sandpackage/app/controller/InstallController.php';
            $GLOBALS['diagnosticFault']='';$GLOBALS['diagnosticLoggerFails']=false;
            $before=diagnosticInventory();$begun=InstallLogic::$begun;$failed=InstallLogic::$failed;$released=InstallLogic::$released;
            $constructed=InstallLogic::$constructed;$spawned=$GLOBALS['diagnosticSpawnCalls']??0;
            $controller=new \plugin\sandpackage\app\controller\InstallController();
            $body='command=composer.update';
            $request=new \support\Request("POST / HTTP/1.1\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body);
            $result=json_decode($controller->inspectDependencyStart($request)->rawBody(),true,64,JSON_THROW_ON_ERROR)['data'];
            diagnosticCheck($result['readonly_only']===true&&$result['configuration_valid']===true&&$result['worker_pid']===getmypid(),'Authenticated controller reports this worker capability/configuration hints');
            diagnosticCheck($result['functions']['proc_open']===\function_exists('proc_open')&&$result['functions']['posix_setsid']===\function_exists('posix_setsid'),'Capability list measures actual runner functions');
            diagnosticCheck($result['lock_acquisition']==='unknown'&&$result['durable_write']==='unknown'&&$result['process_launch']==='unknown'&&$result['child_runtime_support']==='unknown','Read-only inspection does not claim lock/write/launch or child capability proof');
            diagnosticCheck(diagnosticInventory()===$before&&InstallLogic::$begun===$begun&&InstallLogic::$failed===$failed&&InstallLogic::$released===$released&&InstallLogic::$constructed===$constructed&&($GLOBALS['diagnosticSpawnCalls']??0)===$spawned,'Read-only endpoint creates no locks, nonce, journal, logic instance or process');
            diagnosticCheck(!str_contains(json_encode($result),$root)&&!str_contains(json_encode($result),'untrusted-config-secret'),'Inspection returns no local path or command contents');
            $GLOBALS['diagnosticFault']='runtime';$limited=TerminalRunner::inspectStart('composer.update');diagnosticCheck($limited['functions']['posix_setsid']===false&&$limited['process_launch']==='unknown','Unavailable runtime function is visible without starting a command');
            $GLOBALS['diagnosticFault']='';$GLOBALS['diagnosticInvalidConfig']=true;$invalid=TerminalRunner::inspectStart('composer.update');diagnosticCheck($invalid['configuration_valid']===false&&$invalid['workdir_readable']===null,'Invalid configured command is reported without its contents');$GLOBALS['diagnosticInvalidConfig']=false;
            foreach(['','composer.update;raw-secret-sentinel','../composer.update'] as $invalidKey) {
                try{TerminalRunner::inspectStart($invalidKey);throw new \RuntimeException('Invalid command accepted');}
                catch(\plugin\sandadmin\exception\ApiException $e){diagnosticCheck(!str_contains($e->getMessage(),'raw-secret-sentinel'),'Unknown command is rejected safely');}
            }
            try{$controller->inspectDependencyStart(new \support\Request("GET / HTTP/1.1\r\n\r\n"));throw new \RuntimeException('GET accepted');}
            catch(\plugin\sandadmin\exception\ApiException){diagnosticCheck(true,'Read-only diagnostic route keeps explicit POST contract');}
            \plugin\sandadmin\basic\BaseController::$actor=2;
            try{new \plugin\sandpackage\app\controller\InstallController();throw new \RuntimeException('Nonadmin accepted');}
            catch(\plugin\sandadmin\exception\ApiException){diagnosticCheck(true,'Capability route keeps existing superadmin guard');}
        }
        $succeeded=true;echo 'Terminal diagnostics: '.$checks." checks passed\n";
    } finally {
        if(!str_starts_with($root,sys_get_temp_dir().'/sandpackage-terminal-diagnostics-test-')||is_link($root))throw new \RuntimeException('Unsafe diagnostic fixture cleanup');
        if($succeeded){foreach(['webman.log','php-errors.log'] as $name)if(is_file($root.'/'.$name))unlink($root.'/'.$name);rmdir($root.'/server');rmdir($root.'/sandadmin-artd');rmdir($root);}
        else echo 'Failed owned diagnostic fixture retained: '.$root."\n";
    }
}
