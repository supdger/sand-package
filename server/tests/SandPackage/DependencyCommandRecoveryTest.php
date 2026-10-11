<?php

declare(strict_types=1);

namespace think\facade {
    final class Db
    {
        public static array $sql = [];
        public static string $failNeedle = '';
        public static bool $schemaDrift = false;
        public static function connect(string $name): object
        {
            if ($name !== 'pgsql') throw new \RuntimeException('Unexpected database connection');
            return new class {
                public function connect(): object { return $this; }
                public function inTransaction(): bool { return false; }
                public function quote(string $value): string { return "'" . str_replace("'", "''", $value) . "'"; }
                public function query(string $sql): object {
                    return new class($sql) {
                        public function __construct(private string $sql) {}
                        public function fetchAll(int $mode): array {
                            if (str_contains($this->sql, 'SELECT EXISTS(')) {
                                return [['present' => false]];
                            }
                            if (str_contains($this->sql, 'current_database()')) {
                                return [['database' => 'recording-fixture', 'oid' => '1', 'username' => 'fixture', 'address' => null, 'port' => null, 'started' => 'fixed']];
                            }
                            return Db::$schemaDrift && str_contains($this->sql, 'FROM pg_class')
                                ? [['relname' => 'external_schema_change']] : [];
                        }
                    };
                }
                public function exec(string $sql): int {
                    Db::$sql[] = trim($sql);
                    if (Db::$failNeedle !== '' && str_contains($sql, Db::$failNeedle)) {
                        throw new \RuntimeException('recorded SQL failure');
                    }
                    return 1;
                }
            };
        }
    }
}
namespace plugin\sandadmin\app\cache {
    final class UserMenuCache {
        public static bool $failOnce = false;
        public static function clearMenuCache(): void {
            if (self::$failOnce) {
                self::$failOnce = false;
                throw new \RuntimeException('injected cache failure');
            }
        }
    }
}
namespace plugin\sandpackage\app\service {
    function posix_kill(int $pid, int $signal): bool { return !empty($GLOBALS['fixtureProcessPermissionUnknown']) && $signal === 0 ? false : \posix_kill($pid, $signal); }
    function posix_get_last_error(): int { return !empty($GLOBALS['fixtureProcessPermissionUnknown']) ? 1 : \posix_get_last_error(); }
}
namespace plugin\sandadmin\basic {
    class BaseController {
        public static int $actor = 1;
        public int $adminId;
        public function __construct() { $this->adminId = self::$actor; }
        public function success(mixed $data): \support\Response { return new \support\Response(200, [], json_encode(['data'=>$data], JSON_THROW_ON_ERROR)); }
    }
}
namespace plugin\SandIam\app\runtime {
    final class ServiceCatalog {
        public static int $registered = 0;
        public function inspectServiceActions(array $service, array $actions): array { return ['service_code'=>$service['code'],'can_register'=>true]; }
        public function registerServiceActions(array $service, array $actions): void { self::$registered++; }
    }
}
namespace Tinywan\Jwt { final class JwtToken { public static function verify(int $scene, string $token): array { return ['extend'=>['id'=>1,'plat'=>'sandadmin']]; } } }
namespace {
    use plugin\sandpackage\app\logic\InstallLogic;
    use plugin\sandpackage\app\service\HostPayloadOwnership;
    use plugin\sandpackage\app\service\HostPayloadLifecycleJournal;
    use plugin\sandpackage\app\service\HostPayloadPlan;
    use plugin\sandpackage\app\service\PluginStorage;
    use Saithink\Saipackage\service\Server;
    use think\facade\Db;

    $root = sys_get_temp_dir() . '/sandpackage-dependency-recovery-test-' . bin2hex(random_bytes(8));
    mkdir($root . '/server/plugin', 0700, true);
    mkdir($root . '/sandadmin-artd', 0700, true);
    ini_set('error_log', $root . '/expected-errors.log');
    function base_path(string $path = ''): string { global $root; return $root . '/server' . ($path === '' ? '' : '/' . $path); }
    function runtime_path(string $path = ''): string { global $root; return $root . '/runtime' . ($path === '' ? '' : '/' . $path); }
    function env(string $key, mixed $default = null): mixed { return $default; }
    function config(string $key, mixed $default = null): mixed {
        if ($key === 'plugin.sandadmin.app.version') return '0.1.0';
        if ($key === 'plugin.sandpackage.terminal.commands') return ['composer'=>['update'=>['cwd'=>base_path(),'command'=>'composer update --no-interaction']], 'web-install'=>['npm'=>['cwd'=>dirname(base_path()).'/sandadmin-artd','command'=>'npm install']]];
        return $default;
    }
    final class FixtureRequest { public object $connection; public function __construct() { $this->connection = (object)[]; } public function input(string $key, string $default=''): string { return ['command'=>$GLOBALS['fixtureCommand'] ?? 'composer.update','extend'=>'module-install:neutral-host'][$key] ?? $default; } public function header(string $key, string $default=''): string { return $key === 'authorization' ? 'Bearer fixture' : $default; } }
    function request(): FixtureRequest { return $GLOBALS['fixtureRequest'] ??= new FixtureRequest(); }
    function hostInstallWrite(string $path, string $contents): void {
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
        file_put_contents($path, $contents);
    }
    function hostInstallDelete(string $path): void {
        if (!file_exists($path) && !is_link($path)) return;
        if (is_file($path) || is_link($path)) { unlink($path); return; }
        foreach (new \FilesystemIterator($path) as $item) hostInstallDelete($item->getPathname());
        rmdir($path);
    }

    // Reuse the public Server methods, overriding only the reload request with a recorder.
    $serverSource = file_get_contents(dirname(__DIR__, 2) . '/compat/Saithink/Saipackage/service/Server.php');
    $serverSource = str_replace("class Server\n", "class RecordingServerBase\n", $serverSource, $renamed);
    if ($renamed !== 1) throw new \RuntimeException('Public Server fixture mapping changed');
    hostInstallWrite($root . '/recording-server.php', $serverSource);
    require $root . '/recording-server.php';
    require __DIR__ . '/fixtures/DependencyRecordingServer.php';
    require getenv('SANDPACKAGE_TEST_VENDOR') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';
    foreach (['HostPayloadManifest', 'HostPayloadPlan', 'HostPayloadOwnership', 'HostPayloadFreshFiles', 'HostPayloadChangeFiles', 'HostPayloadRuntimeChangeFiles', 'HostPayloadRemovalFiles', 'HostPayloadUninstallFinalization', 'HostPayloadDependencyChange', 'HostPayloadCandidateRollback', 'HostPayloadLifecycleJournal', 'PostgresHostCatalogFingerprint'] as $component) {
        require_once dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/' . $component . '.php';
    }
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/logic/InstallLogic.php';
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/PostgresLifecycleSqlExecutor.php';
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/FreshInstallRecovery.php';


    $checks = 0;
    function jobCheck(bool $condition, string $message): void {
        global $checks;
        if (!$condition) throw new \RuntimeException($message);
        $checks++; echo "[PASS] $message\n";
    }
    function jobReject(callable $action, string $message): void {
        try { $action(); } catch (\Throwable $error) {
            jobCheck($error instanceof \plugin\sandadmin\exception\ApiException, $message);
            return;
        }
        throw new \RuntimeException($message);
    }
    function jobPackage(string $version, bool $extra, bool $npm = false, bool $catalog = false): string {
        global $root;
        $app = 'neutral-host';
        $contents = [
            'info.ini' => "app = neutral-host\ntitle = Neutral\nabout = Fixture\nauthor = Test\nversion = $version\nsupport = \">=0.1.0\"\n",
            'config.json' => $version === '2.0.0' ? json_encode(['require'=>['fixture/dependency'=>'1.0.0'], 'dependencies'=>$npm ? ['fixture-npm'=>'1.0.0'] : []],JSON_THROW_ON_ERROR) : "{}\n",
            'install.sql' => "CREATE TABLE neutral_host (id bigint);\n",
            'update.sql' => "SELECT '$version';\n",
            'uninstall.sql' => "DROP TABLE neutral_host;\n",
            'plugin/' . $app . '/config/app.php' => "<?php return ['version' => '$version'];\n",
            'sandadmin-artd/src/views/plugin/' . $app . '/index.vue' => '<template>' . $version . '</template>',
            'app/Api/Neutral/Probe.php' => "<?php // $version\n",
        ];
        if ($catalog) { $config=json_decode($contents['config.json'],true,32,JSON_THROW_ON_ERROR); $config['service_catalog']=['service'=>['code'=>'neutral_host','name'=>'Neutral'],'actions'=>['neutral_host.probe'=>'Probe']]; $contents['config.json']=json_encode($config,JSON_THROW_ON_ERROR); }
        if ($extra) $contents['app/Api/Neutral/NewMember.php'] = "<?php // new $version\n";
        $files = [];
        foreach ($contents as $path => $body) {
            if (str_starts_with($path, 'app/')) $files[] = ['path' => $path, 'sha256' => hash('sha256', $body)];
        }
        usort($files, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
        $contents['host-payload.json'] = json_encode(['schema' => 1, 'app' => $app, 'files' => $files], JSON_THROW_ON_ERROR);
        $file = $root . '/neutral-' . $version . '.zip';
        if (is_file($file)) unlink($file);
        $zip = new \ZipArchive();
        if ($zip->open($file, \ZipArchive::CREATE) !== true) throw new \RuntimeException('Cannot create fixture ZIP');
        foreach ($contents as $path => $body) $zip->addFromString($path, $body);
        $zip->close();
        return $file;
    }
    function jobRequest(array $values): \support\Request {
        $body=http_build_query($values);
        return new \support\Request("POST / HTTP/1.1\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body);
    }
    function jobBusinessSnapshot(): array {
        return [\plugin\sandpackage\app\service\FreshInstallRecovery::tree(base_path()), Db::$sql];
    }
    function jobScene(bool $restart, bool $npm, bool $catalog = false): InstallLogic {
        global $root;
        foreach (['server','sandadmin-artd','runtime'] as $directory) hostInstallDelete($root.'/'.$directory);
        mkdir(base_path('plugin'),0700,true); mkdir($root.'/sandadmin-artd',0700,true);
        hostInstallWrite(base_path('composer.json'), '{"name":"test/host","require":{}}');
        hostInstallWrite($root.'/sandadmin-artd/package.json', '{"name":"test-host","dependencies":{}}');
        $logic=new InstallLogic('neutral-host');
        $logic->uploadFromPath(jobPackage('1.0.0',false)); $logic->install(false);
        $logic->uploadFromPath(jobPackage('2.0.0',true,$npm,$catalog)); $logic->install($restart,'UPGRADE neutral-host@1.0.0->2.0.0');
        return $logic;
    }
    function jobRun(string $command): array {
        $GLOBALS['fixtureCommand']=$command;
        $frames=[];
        foreach ((new \plugin\sandpackage\app\service\TerminalRunner())->exec() as $frame) $frames[]=json_decode($frame,true,32,JSON_THROW_ON_ERROR)['data'];
        file_put_contents(base_path('fixture-last-frames.json'),json_encode($frames,JSON_THROW_ON_ERROR));
        return $frames;
    }
    $fixtureSucceeded = false;
    try {
        $logic = new InstallLogic('neutral-host');
        hostInstallWrite(base_path('composer.json'), '{"name":"test/host","require":{}}');
        hostInstallWrite($root . '/sandadmin-artd/package.json', '{"name":"test-host","dependencies":{}}');
        $logic->uploadFromPath(jobPackage('1.0.0', false)); $logic->install(false);
        $logic->uploadFromPath(jobPackage('2.0.0', true)); $logic->install(true, 'UPGRADE neutral-host@1.0.0->2.0.0');
        $info = $logic->getInfo();
        jobCheck($info['state'] === InstallLogic::DEPENDENT_WAIT_INSTALL && $info['composer_dependent_wait_install'] === 1, 'Normal upgrade reaches real dependency-wait state');
        jobCheck(!file_exists(base_path('restart-called')), 'Normal install defers reload until the dependency stage completes');
        $before = Db::$sql;
        $oldPath = getenv('PATH');
        hostInstallWrite($root . '/bin/composer', '#!' . PHP_BINARY . "\n<?php echo \"Loading neutral fixture dependencies\\n\"; usleep(300000); exit(7);\n");
        chmod($root . '/bin/composer', 0700);
        putenv('PATH=' . $root . '/bin:' . $oldPath);
        $handler = pcntl_fork(); if ($handler === -1) throw new \RuntimeException('Cannot fork own handler fixture');
        if ($handler === 0) {
            $runner = new \plugin\sandpackage\app\service\TerminalRunner();
            foreach ($runner->exec() as $frame) {
                $data = json_decode($frame, true, 32, JSON_THROW_ON_ERROR)['data'];
                file_put_contents($root . '/sse-frames.jsonl', json_encode(['data'=>$data],JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
                if (str_contains($data, 'Loading neutral fixture')) { posix_kill(getmypid(), SIGKILL); exit(89); } // Abrupt request-owner exit: no generator finally/completion callback.
            }
            exit(90);
        }
        pcntl_waitpid($handler, $status);
        putenv('PATH=' . $oldPath);
        jobCheck(pcntl_wifsignaled($status) && pcntl_wtermsig($status) === SIGKILL, 'Real TerminalRunner began a neutral command before request-owner interruption');
        $storage = (new PluginStorage())->root(); $marker = $storage . '/locks/host-upstream.process.json';
        $record = json_decode(file_get_contents($marker), true, 32, JSON_THROW_ON_ERROR);
        $pids = array_unique(array_merge([$record['process']['pid'], -$record['process']['pgid']], $record['process']['descendants']));
        $deadline = microtime(true)+5; $absent = false;
        do { $absent = true; foreach ($pids as $pid) { if (@posix_kill($pid,0) || posix_get_last_error() !== 3) $absent=false; } if (!$absent) usleep(50000); } while (!$absent && microtime(true)<$deadline);
        jobCheck($absent, 'All recorded own launcher/group/descendant identities are absent (ESRCH)');
        $frames = file_get_contents($root . '/sse-frames.jsonl');
        jobCheck(!str_contains($frames,'exec-success') && !str_contains($frames,'exec-completed'), 'Interrupted official runner has no terminal success/completion receipt');
        $fresh = new InstallLogic('neutral-host'); $info = $fresh->getInfo();
        jobCheck(is_string($info['dependency_command_nonce'] ?? null) && hash_equals($info['dependency_command_nonce'], $record['nonce'])
            && $info['state'] === InstallLogic::DEPENDENT_WAIT_INSTALL, 'Marker and registration retain matching nonce and waiting state after owner loss');
        jobCheck(count($record) === 7 && $record['type'] === 'composer' && is_int($record['started_at']) && !isset($record['exit_code']), 'New durable record retains type/start/phase without inventing successful exit proof');
        $registry = file_get_contents($storage . '/neutral-host/info.ini'); $markerBytes = file_get_contents($marker);
        jobReject(fn() => $fresh->beginDependencyCommand('composer'), 'New official dependency begin is refused by retained host marker');
        jobReject(fn() => $fresh->confirmDependencyProcessReaped('composer', $record['nonce']), 'New request cannot adopt the old callback ownership');
        jobReject(fn() => $fresh->dependentInstallComplete('composer', $record['nonce']), 'Absent processes cannot be mistaken for successful dependency completion');
        require dirname(__DIR__, 2) . '/plugin/sandpackage/app/command/Recover.php';
        $tester = new \Symfony\Component\Console\Tester\CommandTester(new \plugin\sandpackage\app\command\Recover());
        $exit=$tester->execute(['action'=>'inspect-dependency','app'=>'neutral-host']);
        jobCheck($exit === 0, 'Official dependency inspection discovers the interrupted original job');
        $inspection = json_decode(trim($tester->getDisplay()),true,32,JSON_THROW_ON_ERROR);
        jobCheck($inspection['actions'] === ['reset-dependency-waiting'] && !$inspection['command_success_proven'], 'Inspection offers only release to dependency waiting, never success');
        require dirname(__DIR__,2).'/plugin/sandpackage/app/controller/InstallController.php';
        $controller=new \plugin\sandpackage\app\controller\InstallController();
        $api=json_decode($controller->inspectDependencyRecovery(jobRequest(['appName'=>'neutral-host']))->rawBody(),true,32,JSON_THROW_ON_ERROR)['data'];
        jobCheck($api['fingerprint']===$inspection['fingerprint'],'Official POST API and CLI inspect bind the same read-only scene');
        \plugin\sandadmin\basic\BaseController::$actor=2;
        jobReject(fn()=>new \plugin\sandpackage\app\controller\InstallController(),'Dependency recovery API preserves super-admin authorization');
        \plugin\sandadmin\basic\BaseController::$actor=1;
        $rawMarker=file_get_contents($marker); $registry=$storage.'/neutral-host/info.ini'; $rawRegistry=file_get_contents($registry);
        $negativeMarkers=[
            'malformed'=>'{', 'foreign'=>json_encode([...$record,'app'=>'foreign-plugin']),
            'alive-or-reused'=>json_encode([...$record,'process'=>['pid'=>getmypid(),'pgid'=>getmypid(),'descendants'=>[]]]),
            'unknown-pid'=>json_encode([...$record,'process'=>['pid'=>'unknown','pgid'=>'unknown','descendants'=>[]]]),
            'wrong-type'=>json_encode([...$record,'type'=>'npm']),
        ];
        foreach($negativeMarkers as $name=>$bad) {
            hostInstallWrite($marker,$bad); $unchanged=jobBusinessSnapshot();
            jobReject(fn()=>(new InstallLogic('neutral-host'))->inspectDependencyRecovery(),$name.' process evidence fails closed');
            jobCheck(jobBusinessSnapshot()===$unchanged,$name.' inspection makes no business writes or SQL');
            hostInstallWrite($marker,$rawMarker);
        }
        $GLOBALS['fixtureProcessPermissionUnknown']=true;$unchanged=jobBusinessSnapshot();
        jobReject(fn()=>(new InstallLogic('neutral-host'))->inspectDependencyRecovery(),'Permission-unknown process absence never authorizes recovery');
        jobCheck(jobBusinessSnapshot()===$unchanged,'Unknown process permission rejection makes no writes');$GLOBALS['fixtureProcessPermissionUnknown']=false;
        foreach(['lifecycle','rollback','removal'] as $kind) {
            $path=$storage.'/host-payload/neutral-host.'.$kind.'.json'; hostInstallWrite($path,'{}'); $unchanged=jobBusinessSnapshot();
            jobReject(fn()=>(new InstallLogic('neutral-host'))->inspectDependencyRecovery(),$kind.' transaction refuses dependency recovery');
            jobCheck(jobBusinessSnapshot()===$unchanged,$kind.' rejection writes nothing'); unlink($path);
        }
        foreach([$storage.'/neutral-host/plugin/neutral-host/config/app.php',base_path('plugin/neutral-host/config/app.php'),base_path('app/Api/Neutral/Probe.php')] as $path) {
            $contents=file_get_contents($path); hostInstallWrite($path,$contents.'// drift'); $unchanged=jobBusinessSnapshot();
            jobReject(fn()=>(new InstallLogic('neutral-host'))->recoverDependencyCommand('RECOVER DEPENDENCY neutral-host '.$inspection['fingerprint']),'Candidate/runtime/owned drift rejects recovery before writes');
            jobCheck(jobBusinessSnapshot()===$unchanged,'Payload drift rejection retains original marker and registry');hostInstallWrite($path,$contents);
        }
        hostInstallWrite(base_path('composer.lock'),'{"fixture":"drift"}');$unchanged=jobBusinessSnapshot();
        jobReject(fn()=>(new InstallLogic('neutral-host'))->recoverDependencyCommand('RECOVER DEPENDENCY neutral-host '.$inspection['fingerprint']),'Dependency lock change invalidates exact confirmation');
        jobCheck(jobBusinessSnapshot()===$unchanged,'Stale dependency confirmation makes no writes');unlink(base_path('composer.lock'));
        jobReject(fn()=>(new InstallLogic('neutral-host'))->recoverDependencyCommand('RECOVER DEPENDENCY neutral-host '.$inspection['fingerprint'],true),'Interrupted dependency recovery never reloads');
        $recoveryMethod=new \ReflectionMethod(InstallLogic::class,'dependencyRecovery');
        foreach(['prepared','marker-renamed','marker_archived','nonce-cleared','nonce_cleared'] as $window) {
            $freshInspection=(new InstallLogic('neutral-host'))->inspectDependencyRecovery();
            $engine=$recoveryMethod->invoke(new InstallLogic('neutral-host'));
            try { $engine->recover('RECOVER DEPENDENCY neutral-host '.$freshInspection['fingerprint'],static function(string $stage) use($window):void { if($stage===$window) throw new \RuntimeException('Injected own interruption'); }); throw new \RuntimeException('Missing interruption'); }
            catch(\RuntimeException $error) { jobCheck($error->getMessage()==='Injected own interruption',$window.' interruption reached the real recovery write boundary'); }
            $exit=$tester->execute(['action'=>'inspect-dependency','app'=>'neutral-host']);jobCheck($exit===0,$window.' official CLI rediscovers checkpoint/archive');
            $resumed=json_decode(trim($tester->getDisplay()),true,32,JSON_THROW_ON_ERROR);
            jobReject(fn()=>(new InstallLogic('foreign-plugin'))->beginDependencyCommand('composer'),$window.' unfinished checkpoint still blocks all new dependency jobs');
            $unchanged=jobBusinessSnapshot();jobReject(fn()=>(new InstallLogic('neutral-host'))->recoverDependencyCommand('RECOVER DEPENDENCY neutral-host '.$freshInspection['fingerprint']),$window.' stale confirmation is consumed');
            jobCheck(jobBusinessSnapshot()===$unchanged,$window.' stale confirmation writes nothing');
            $controller->recoverDependency(jobRequest(['appName'=>'neutral-host','confirmation'=>'RECOVER DEPENDENCY neutral-host '.$resumed['fingerprint']]));
            jobCheck(!is_file($storage.'/host-payload/neutral-host.dependency-recovery.json') && !isset((new InstallLogic('neutral-host'))->getInfo()['dependency_command_nonce']),$window.' official API finishes only occupation cleanup');
            // Restore only this fixture's interrupted scene to exercise the next crash window.
            foreach(glob($marker.'.*.history')?:[] as $path) unlink($path);
            foreach(glob($storage.'/host-payload/neutral-host.dependency-recovery.json.*.history')?:[] as $path) unlink($path);
            hostInstallWrite($marker,$rawMarker);hostInstallWrite($registry,$rawRegistry);
        }
        // The exact public 0.2.9 marker has no historical type or timestamp.
        hostInstallWrite($marker,json_encode(array_intersect_key($record,array_flip(['app','nonce','process'])),JSON_THROW_ON_ERROR));
        $exit=$tester->execute(['action'=>'inspect-dependency','app'=>'neutral-host']);jobCheck($exit===0,'Public 0.2.9 marker is accepted without invented history/type/timing');
        $inspection=json_decode(trim($tester->getDisplay()),true,32,JSON_THROW_ON_ERROR);
        $exit=$tester->execute(['action'=>'recover-dependency','app'=>'neutral-host','--confirmation'=>'RECOVER DEPENDENCY neutral-host '.$inspection['fingerprint']]);
        jobCheck($exit === 0, 'Official dependency recovery completes');
        $info=(new InstallLogic('neutral-host'))->getInfo();
        jobCheck(!file_exists($marker) && !isset($info['dependency_command_nonce']) && $info['state']===4 && $info['composer_dependent_wait_install']===1 && Db::$sql===$before, 'Recovery preserves waiting state and flags without SQL or command replay');
        hostInstallWrite($root . '/bin/composer', '#!' . PHP_BINARY . "\n<?php echo \"Neutral retry success\\n\"; exit(0);\n"); chmod($root . '/bin/composer',0700);
        putenv('PATH='.$root.'/bin:'.$oldPath);
        $frames=[]; foreach((new \plugin\sandpackage\app\service\TerminalRunner())->exec() as $frame) $frames[]=json_decode($frame,true,32,JSON_THROW_ON_ERROR)['data'];
        putenv('PATH='.$oldPath);
        jobCheck(in_array('exec-success',$frames,true) && (new InstallLogic('neutral-host'))->getInfo()['state']===1, 'Explicit new official dependency command can complete successfully');
        jobCheck(file_get_contents(base_path('restart-called')) === "requested\n" && !isset((new InstallLogic('neutral-host'))->getInfo()['dependency_command_nonce']), 'Successful final dependency commits nonce removal before the deferred reload request');
        jobReject(fn()=>(new InstallLogic('neutral-host'))->recoverDependencyCommand('RECOVER DEPENDENCY neutral-host '.$inspection['fingerprint']),'Completed occupation cleanup cannot replay recovery writes');
        // Both dependency orders retain the original true/false intent; callback defaults cannot replace it.
        hostInstallWrite($root.'/bin/npm','#!'.PHP_BINARY."\n<?php echo \"Neutral npm success\\n\"; exit(0);\n");chmod($root.'/bin/npm',0700);
        foreach([[true,['composer.update','web-install.npm']],[true,['web-install.npm','composer.update']],[false,['composer.update','web-install.npm']]] as [$restart,$order]) {
            $scene=jobScene($restart,true);jobCheck(!file_exists(base_path('restart-called')),'Two-dependency install defers reload');$sql=Db::$sql;
            putenv('PATH='.$root.'/bin:'.$oldPath);
            $frames=jobRun($order[0]);jobCheck(in_array('exec-success',$frames,true) && $scene->getInfo()['state']===4 && !file_exists(base_path('restart-called')),'First completed dependency stays waiting without reload');
            $frames=jobRun($order[1]);putenv('PATH='.$oldPath);
            jobCheck(in_array('exec-success',$frames,true) && $scene->getInfo()['state']===1 && Db::$sql===$sql,'Last dependency completes without lifecycle SQL');
            jobCheck($restart ? file_get_contents(base_path('restart-called'))==="requested\n" : !file_exists(base_path('restart-called')),'Only original true intent reloads after the last dependency');
        }
        $scene=jobScene(true,false,true);jobCheck(!file_exists(base_path('restart-called')) && \plugin\SandIam\app\runtime\ServiceCatalog::$registered===1,'Service catalog outer install retains deferred true intent');
        putenv('PATH='.$root.'/bin:'.$oldPath);$frames=jobRun('composer.update');putenv('PATH='.$oldPath);
        jobCheck(in_array('exec-success',$frames,true) && file_get_contents(base_path('restart-called'))==="requested\n",'Catalog dependency callback completes the original reload intent');
        $scene=jobScene(true,false);Server::$failRestart=true;putenv('PATH='.$root.'/bin:'.$oldPath);$frames=jobRun('composer.update');putenv('PATH='.$oldPath);Server::$failRestart=false;
        $info=$scene->getInfo();jobCheck($info['state']===1 && !isset($info['dependency_command_nonce']) && $info['dependency_restart_pending']===1,'Failed reload cannot undo durable dependency completion');
        $inspection=$scene->inspectDependencyRecovery();jobCheck($inspection['actions']===['restart-only'] && $inspection['reload_result']==='unknown','Interrupted/failed reload exposes only explicit fresh-confirmed restart');
        $unchanged=jobBusinessSnapshot();jobReject(fn()=>$scene->recoverDependencyCommand('RECOVER DEPENDENCY neutral-host '.$inspection['fingerprint']),'Reload-only recovery requires explicit restart switch');jobCheck(jobBusinessSnapshot()===$unchanged,'Missing restart switch writes nothing');
        $scene->recoverDependencyCommand('RECOVER DEPENDENCY neutral-host '.$inspection['fingerprint'],true);
        jobCheck($scene->getInfo()['dependency_restart_status']==='signal_requested','Reload completion records only signal requested, never worker acknowledgement');
        $unchanged=jobBusinessSnapshot();jobReject(fn()=>$scene->recoverDependencyCommand('RECOVER DEPENDENCY neutral-host '.$inspection['fingerprint'],true),'Consumed reload confirmation cannot reissue signals');jobCheck(jobBusinessSnapshot()===$unchanged,'Repeated reload confirmation makes zero writes');
        $scene=jobScene(false,false);
        $legacy=$scene->getInfo();unset($legacy['dependency_restart_pending']);$legacy['dependency_command_nonce']=str_repeat('b',32);$scene->setInfo([],$legacy);
        $legacyMarker=['app'=>'neutral-host','nonce'=>str_repeat('b',32),'process'=>$record['process']];hostInstallWrite((new PluginStorage())->root().'/locks/host-upstream.process.json',json_encode($legacyMarker,JSON_THROW_ON_ERROR));
        $inspection=$scene->inspectDependencyRecovery();$scene->recoverDependencyCommand('RECOVER DEPENDENCY neutral-host '.$inspection['fingerprint']);
        jobCheck($scene->getInfo()['dependency_restart_pending']===2,'Confirmed old-record recovery preserves unknown restart intent');
        putenv('PATH='.$root.'/bin:'.$oldPath);$frames=jobRun('composer.update');putenv('PATH='.$oldPath);
        jobCheck(in_array('exec-success',$frames,true) && $scene->getInfo()['state']===1 && !file_exists(base_path('restart-called')),'Unknown historical intent never reloads automatically');
        $inspection=$scene->inspectDependencyRecovery();jobCheck($inspection['actions']===['restart-only'] && $inspection['restart_intent']==='unknown','Completed old dependency can offer explicit reload without inventing old request choice');
        $scene->recoverDependencyCommand('RECOVER DEPENDENCY neutral-host '.$inspection['fingerprint'],true);
        jobCheck($scene->getInfo()['dependency_restart_pending']===0,'Fresh explicit reload closes unknown historical intent');
        $scene=jobScene(false,false);$nonce=$scene->beginDependencyCommand('composer');
        $scene->recordDependencyProcessStarted('composer',$nonce,$record['process']['pid'],$record['process']['pgid'],$record['process']['descendants'],time());
        $scene->confirmDependencyProcessReaped('composer',$nonce);$scene->releaseDependencyCommand();
        $marker=(new PluginStorage())->root().'/locks/host-upstream.process.json';
        jobCheck(is_file($marker) && json_decode(file_get_contents($marker),true)['phase']==='reaped','Reaped process proof remains until durable callback registration');
        $inspection=$scene->inspectDependencyRecovery();jobCheck($inspection['actions']===['reset-dependency-waiting'],'Reaped-before-durable interruption never proves command success');
        $sql=Db::$sql;$scene->recoverDependencyCommand('RECOVER DEPENDENCY neutral-host '.$inspection['fingerprint']);
        jobCheck($scene->getInfo()['state']===4 && isset($scene->getInfo()['composer_dependent_wait_install']) && Db::$sql===$sql,'Reaped-before-durable recovery preserves waiting state and SQL');
        foreach(['success','failure'] as $outcome) {
            $scene=jobScene(false,false);$nonce=$scene->beginDependencyCommand('composer');
            $scene->recordDependencyProcessStarted('composer',$nonce,$record['process']['pid'],$record['process']['pgid'],$record['process']['descendants'],time());
            $scene->confirmDependencyProcessReaped('composer',$nonce);
            $locks=(new PluginStorage())->root().'/locks';chmod($locks,0555);
            try { jobReject(fn()=>$outcome==='success' ? $scene->dependentInstallComplete('composer',$nonce,true) : $scene->dependencyCommandFailed('composer',$nonce),'Owned '.$outcome.' callback is interrupted after durable registration before marker removal'); }
            finally { chmod($locks,0700);$scene->releaseDependencyCommand(); }
            $info=$scene->getInfo();jobCheck(!isset($info['dependency_command_nonce']) && $info['state']===($outcome==='success'?1:4),'Interrupted callback retains its durably committed state');
            $inspection=$scene->inspectDependencyRecovery();jobCheck($inspection['actions']===['finish-dependency-cleanup'],'Official inspection discovers durable-after-marker cleanup window');
            $registry=(new PluginStorage())->root().'/neutral-host/info.ini';$original=file_get_contents($registry);
            $wrong=$info;$wrong[$outcome==='success'?'dependency_completed_nonce':'dependency_failed_nonce']=str_repeat('c',32);$scene->setInfo([],$wrong);$unchanged=jobBusinessSnapshot();
            jobReject(fn()=>$scene->inspectDependencyRecovery(),'Different nonce receipt cannot retire the original marker');jobCheck(jobBusinessSnapshot()===$unchanged,'Receipt mismatch makes no writes');hostInstallWrite($registry,$original);
            $sql=Db::$sql;$scene->recoverDependencyCommand('RECOVER DEPENDENCY neutral-host '.$inspection['fingerprint']);
            jobCheck(file_get_contents($registry)===$original && !is_file((new PluginStorage())->root().'/locks/host-upstream.process.json') && Db::$sql===$sql,'Confirmed callback cleanup only archives marker and preserves exact registration');
        }
        $scene=jobScene(false,false);$sql=Db::$sql;
        hostInstallWrite($root.'/bin/composer','#!'.PHP_BINARY."\n<?php echo \"Neutral explicit failure\\n\"; exit(7);\n");chmod($root.'/bin/composer',0700);
        putenv('PATH='.$root.'/bin:'.$oldPath);$frames=jobRun('composer.update');putenv('PATH='.$oldPath);
        jobCheck(in_array('exec-error',$frames,true) && !in_array('exec-success',$frames,true) && $scene->getInfo()['state']===4,'Normal command exit7 is never reported successful');
        jobCheck(!isset($scene->getInfo()['dependency_command_nonce']) && !is_file((new PluginStorage())->root().'/locks/host-upstream.process.json') && Db::$sql===$sql && !file_exists(base_path('restart-called')),'Normal failed runner releases occupation, preserves waiting, and never reloads or replays SQL');
        $scene=jobScene(false,false);$sql=Db::$sql;
        hostInstallWrite($root.'/bin/composer','#!'.PHP_BINARY."\n<?php echo \"Neutral transport fixture\\n\"; usleep(300000); exit(0);\n");chmod($root.'/bin/composer',0700);
        $GLOBALS['fixtureCommand']='composer.update';$GLOBALS['fixtureRequest']=new FixtureRequest();putenv('PATH='.$root.'/bin:'.$oldPath);$frames=[];
        foreach((new \plugin\sandpackage\app\service\TerminalRunner())->exec() as $frame) { $data=json_decode($frame,true,32,JSON_THROW_ON_ERROR)['data'];$frames[]=$data;if($data==='connection-success') $GLOBALS['fixtureRequest']->connection->status=16; }
        putenv('PATH='.$oldPath);unset($GLOBALS['fixtureRequest']);
        jobCheck(in_array('exec-error',$frames,true) && !in_array('exec-success',$frames,true),'Normal transport error never reports dependency success');
        jobCheck(!isset($scene->getInfo()['dependency_command_nonce']) && !is_file((new PluginStorage())->root().'/locks/host-upstream.process.json') && $scene->getInfo()['state']===4 && Db::$sql===$sql,'Normal transport error reaps own process and clears occupation while preserving waiting state');
        $scene->setInfo(['dependency_command_nonce'=>str_repeat('d',32)]);$unchanged=jobBusinessSnapshot();
        jobReject(fn()=>$scene->inspectDependencyRecovery(),'Historical nonce without process proof remains unrecoverable');
        jobCheck(jobBusinessSnapshot()===$unchanged,'Missing original process proof cannot cause registration or journal writes');
        $fixtureSucceeded = true;
        echo "Dependency command recovery: $checks checks passed\n";
    } finally {
        if (isset($oldPath)) putenv('PATH=' . $oldPath);
        if (!str_starts_with($root, sys_get_temp_dir() . '/sandpackage-dependency-recovery-test-') || is_link($root)) throw new \RuntimeException('Unsafe fixture cleanup');
        if ($fixtureSucceeded) hostInstallDelete($root);
        else echo "Failed owned fixture retained for diagnosis: $root\n";
    }
}
