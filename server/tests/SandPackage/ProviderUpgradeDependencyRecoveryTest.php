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

    $providerProjection=in_array($argv[1]??null,['--projection','--foreign-source'],true);
    $root = sys_get_temp_dir() . '/sandpackage-provider-recovery-test-' . bin2hex(random_bytes(8));
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
    $providerSource=dirname(__DIR__,2).'/plugin/sandpackage';
    if($providerProjection) {
        $projection=($argv[1]??null)==='--foreign-source'?$root.'/foreign/sandpackage':base_path('plugin/sandpackage');
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($providerSource,\FilesystemIterator::SKIP_DOTS)) as $file) {
            if($file->isLink())throw new \RuntimeException('Source projection fixture contains a link');
            if($file->isFile())hostInstallWrite($projection.substr($file->getPathname(),strlen($providerSource)),file_get_contents($file->getPathname()));
        }
        $providerSource=$projection;
    }
    $loader=require getenv('SANDPACKAGE_TEST_VENDOR') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';
    if($providerProjection)$loader->setPsr4('plugin\\sandpackage\\',[$providerSource]);

    foreach (['HostPayloadManifest', 'HostPayloadPlan', 'HostPayloadOwnership', 'HostPayloadFreshFiles', 'HostPayloadChangeFiles', 'HostPayloadRuntimeChangeFiles', 'HostPayloadRemovalFiles', 'HostPayloadUninstallFinalization', 'HostPayloadDependencyChange', 'HostPayloadCandidateRollback', 'HostPayloadLifecycleJournal', 'PostgresHostCatalogFingerprint'] as $component) {
        require_once $providerSource . '/app/service/' . $component . '.php';
    }
    require $providerSource . '/app/logic/InstallLogic.php';
    require $providerSource . '/app/service/PostgresLifecycleSqlExecutor.php';
    require $providerSource . '/app/service/FreshInstallRecovery.php';


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

    require $providerSource . '/app/command/Recover.php';
    function jobRequest(array $values): \support\Request {
        $body=http_build_query($values);
        return new \support\Request("POST / HTTP/1.1\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body);
    }
    $roots = [$root];
    $loaders = [];
    function providerScene(string $requirement = '0.2.10'): array {
        global $root, $roots, $loaders, $providerProjection;
        if(!$providerProjection){
            $root = sys_get_temp_dir().'/sandpackage-provider-recovery-test-'.bin2hex(random_bytes(8));
            $roots[]=$root;
            mkdir(base_path('plugin'),0700,true);mkdir($root.'/sandadmin-artd',0700,true);
        }
        ini_set('error_log',$root.'/expected-errors.log');
        hostInstallWrite(base_path('composer.json'),json_encode(['name'=>'test/host','require'=>['supdger/sand-package'=>'0.2.9','supdger/sand-core'=>'0.2.4','fixture/dependency'=>'1.0.0'],'autoload'=>['psr-4'=>['app\\'=>'app']]],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
        hostInstallWrite($root.'/sandadmin-artd/package.json','{"name":"test-host","dependencies":{}}');
        $logic=new InstallLogic('neutral-host');
        $logic->uploadFromPath(jobPackage('1.0.0',false));$logic->install(false);
        $logic->uploadFromPath(jobPackage('2.0.0',true));$logic->install(false,'UPGRADE neutral-host@1.0.0->2.0.0');
        $nonce=$logic->beginDependencyCommand('composer');
        $pid=pcntl_fork();if($pid===-1)throw new \RuntimeException('Cannot create own process identity');
        if($pid===0)exit(0);pcntl_waitpid($pid,$status);
        if(@posix_kill($pid,0)||posix_get_last_error()!==3)throw new \RuntimeException('Own process identity not absent');
        $logic->recordDependencyProcessStarted('composer',$nonce,$pid,$pid,[],time());
        $logic->releaseDependencyCommand();
        $storage=(new PluginStorage())->root();
        $marker=$storage.'/locks/host-upstream.process.json';
        $record=json_decode(file_get_contents($marker),true,32,JSON_THROW_ON_ERROR);
        // Public 0.2.9 marker shape: no later timing/type fields are invented as old exit proof.
        hostInstallWrite($marker,json_encode(['app'=>$record['app'],'nonce'=>$record['nonce'],'process'=>$record['process']],JSON_THROW_ON_ERROR));
        $old=file_get_contents(base_path('composer.json'));
        $new=str_replace('"supdger/sand-package": "0.2.9"','"supdger/sand-package": '.json_encode($requirement),$old,$replaced);
        if($replaced!==1)throw new \RuntimeException('Provider fixture token not unique');
        hostInstallWrite(base_path('composer.json'),$new);
        $packageRoot=dirname(__DIR__,3);
        $reference='fb74978c40021357b79e4ebd5d9afa6ea39e6493';
        $package=['name'=>'supdger/sand-package','version'=>'0.2.10','source'=>['type'=>'git','url'=>'https://github.com/supdger/sand-package.git','reference'=>$reference]];
        $packages=[$package,['name'=>'supdger/sand-core','version'=>'0.2.4'],['name'=>'fixture/dependency','version'=>'1.0.0']];
        hostInstallWrite(base_path('composer.lock'),json_encode(['content-hash'=>'current','packages'=>$packages,'packages-dev'=>[]],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
        // Composer installed.json stores install-path relative to its composer directory.
        $from=explode('/',trim(base_path('vendor/composer'),'/'));$to=explode('/',trim($packageRoot,'/'));
        while($from!==[] && $to!==[] && $from[0]===$to[0]) {array_shift($from);array_shift($to);}
        $packages[0]['install-path']=str_repeat('../',count($from)).implode('/',$to);
        hostInstallWrite(base_path('vendor/composer/installed.json'),json_encode(['packages'=>$packages,'dev'=>true,'dev-package-names'=>[]],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
        $data=['root'=>['name'=>'test/host','pretty_version'=>'1.0.0','version'=>'1.0.0.0','reference'=>null,'type'=>'project','install_path'=>base_path(),'aliases'=>[],'dev'=>true],
            'versions'=>['supdger/sand-package'=>['pretty_version'=>'0.2.10','version'=>'0.2.10.0','reference'=>$reference,'type'=>'library','install_path'=>$packageRoot,'aliases'=>[],'dev_requirement'=>false]]];
        hostInstallWrite(base_path('vendor/composer/installed.php'),'<?php return '.var_export($data,true).';');
        $loader=new \Composer\Autoload\ClassLoader(base_path('vendor'));$loader->register(true);$loaders[]=$loader;
        \Composer\InstalledVersions::reload($data);
        Db::$sql=[];
        return [new InstallLogic('neutral-host'),new \Symfony\Component\Console\Tester\CommandTester(new \plugin\sandpackage\app\command\Recover()),$storage,$old];
    }
    function providerInspect($tester): array {
        $exit=$tester->execute(['action'=>'inspect-dependency','app'=>'neutral-host']);
        if($exit!==0)echo $tester->getDisplay();
        jobCheck($exit===0,'Official inspection verifies provider-only update');
        return json_decode(trim($tester->getDisplay()),true,64,JSON_THROW_ON_ERROR);
    }
    function providerFiles(): array {
        return [\plugin\sandpackage\app\service\FreshInstallRecovery::tree(base_path()),Db::$sql];
    }
    function providerReject($tester,string $name): void {
        $before=providerFiles();
        jobCheck($tester->execute(['action'=>'inspect-dependency','app'=>'neutral-host'])===1,$name.' is rejected');
        jobCheck(providerFiles()===$before,$name.' rejection writes nothing');
    }

    function providerExplicitRetry(int $exit = 0): void {
        global $root;
        $oldPath=getenv('PATH');hostInstallWrite($root.'/bin/composer','#!'.PHP_BINARY."\n<?php echo \"Explicit neutral dependency retry\\n\"; exit($exit);\n");chmod($root.'/bin/composer',0700);putenv('PATH='.$root.'/bin:'.$oldPath);
        $GLOBALS['fixtureCommand']='composer.update';$frames=[];
        try {foreach((new \plugin\sandpackage\app\service\TerminalRunner())->exec() as $frame)$frames[]=json_decode($frame,true,32,JSON_THROW_ON_ERROR)['data'];}
        finally {putenv('PATH='.$oldPath);file_put_contents(base_path('retry-frames.json'),json_encode($frames,JSON_THROW_ON_ERROR));}
        jobCheck(in_array($exit===0?'exec-success':'exec-error',$frames,true)&&in_array('exec-completed',$frames,true),'Explicit native runner retry completes with exit '.$exit);
    }
    function providerConfirmedReset($tester): void {
        $inspection=providerInspect($tester);
        jobCheck($tester->execute(['action'=>'recover-dependency','app'=>'neutral-host','--confirmation'=>'RECOVER DEPENDENCY neutral-host '.$inspection['fingerprint']])===0,'Official confirmed provider recovery closes');
    }
    function providerToolUpdate(string $version): void {
        $path=base_path('composer.json');$raw=file_get_contents($path);
        $raw=preg_replace('/("supdger\/sand-package": )"[^"]+"/', '$1'.json_encode($version),$raw,1,$count);
        if($count!==1)throw new \RuntimeException('Provider fixture token missing');hostInstallWrite($path,$raw);
        foreach(['composer.lock','vendor/composer/installed.json'] as $file) {
            $data=json_decode(file_get_contents(base_path($file)),true,128,JSON_THROW_ON_ERROR);
            foreach($data['packages'] as &$package)if($package['name']==='supdger/sand-package')$package['version']=$version;
            unset($package);hostInstallWrite(base_path($file),json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
        }
        $data=require base_path('vendor/composer/installed.php');
        $data['versions']['supdger/sand-package']['pretty_version']=$version;$data['versions']['supdger/sand-package']['version']=$version.'.0';
        hostInstallWrite(base_path('vendor/composer/installed.php'),'<?php return '.var_export($data,true).';');\Composer\InstalledVersions::reload($data);
    }
    $fixtureSucceeded=false;
    try {

        if(($argv[1]??null)==='--provider-binding') {
            // Candidate Composer metadata simulation; source/ref provenance stays the fixture's public base.
            [$logic,$tester,$storage]=providerScene();providerConfirmedReset($tester);providerToolUpdate('0.2.11');
            $before=providerFiles();$inspection=providerInspect($tester);
            jobCheck($inspection['actions']===['refresh-provider-binding']&&providerFiles()===$before,'Tool update offers read-only explicit binding refresh');
            $origin=$storage.'/host-payload/neutral-host.provider-dependency-transition.json';$originRaw=file_get_contents($origin);
            $journal=$storage.'/host-payload/neutral-host.dependencies.json';$journalRaw=file_get_contents($journal);
            jobCheck($tester->execute(['action'=>'recover-dependency','app'=>'neutral-host','--confirmation'=>'wrong'])===1&&providerFiles()===$before,'Wrong refresh confirmation writes nothing');
            $controller=new \plugin\sandpackage\app\controller\InstallController();
            $api=json_decode($controller->inspectDependencyRecovery(jobRequest(['appName'=>'neutral-host']))->rawBody(),true,128,JSON_THROW_ON_ERROR)['data'];
            jobCheck($api['fingerprint']===$inspection['fingerprint'],'CLI/API tool refresh fingerprint is stable');
            jobCheck($tester->execute(['action'=>'recover-dependency','app'=>'neutral-host','--confirmation'=>'RECOVER DEPENDENCY neutral-host '.$api['fingerprint']])===0,'Confirmed tool refresh succeeds');
            $binding=$storage.'/host-payload/neutral-host.provider-dependency-binding.json';
            jobCheck(is_file($binding)&&file_get_contents($origin)===$originRaw&&file_get_contents($journal)===$journalRaw,'Refresh preserves original transition and dependency journal bytes');
            $info=(new InstallLogic('neutral-host'))->getInfo();
            jobCheck($info['state']===4&&empty($info['dependency_command_nonce'])&&!file_exists($storage.'/locks/host-upstream.process.json')&&Db::$sql===[],'Refresh creates no job, SQL or success proof');
            $after=providerFiles();jobCheck($tester->execute(['action'=>'recover-dependency','app'=>'neutral-host','--confirmation'=>'RECOVER DEPENDENCY neutral-host '.$api['fingerprint']])===1&&providerFiles()===$after,'Committed refresh response loss cannot replay writes');
            providerExplicitRetry();$info=(new InstallLogic('neutral-host'))->getInfo();
            jobCheck($info['state']===1&&empty($info['composer_dependent_wait_install'])&&empty($info['dependency_command_nonce'])&&Db::$sql===[],'Real Runner completes confirmed target without lifecycle SQL');
            (new InstallLogic('neutral-host'))->uploadFromPath(jobPackage('3.0.0',true));
            jobCheck(!file_exists($binding)&&!file_exists($origin)&&!file_exists($journal),'Normal upload archives confirmed target and original dependency proof');
            jobCheck(count(glob($binding.'.*.history')?:[])===1&&file_get_contents($journal.'.'.hash('sha256',$journalRaw).'.history')===$journalRaw,'Archived binding and original journal remain exact');
            foreach(['composer.lock','composer.json','candidate','nonce','origin','sidecar'] as $drift) {
                [$logic,$tester,$storage]=providerScene();providerConfirmedReset($tester);providerToolUpdate('0.2.11');$inspection=providerInspect($tester);
                $binding=$storage.'/host-payload/neutral-host.provider-dependency-binding.json';
                if($drift==='composer.lock'||$drift==='composer.json')hostInstallWrite(base_path($drift),file_get_contents(base_path($drift))."\n");
                elseif($drift==='candidate')hostInstallWrite($storage.'/neutral-host/config.json',file_get_contents($storage.'/neutral-host/config.json')."\n");
                elseif($drift==='nonce'){$info=$logic->getInfo();$info['dependency_command_nonce']=str_repeat('a',32);$logic->setInfo($info);}
                elseif($drift==='origin'){$path=$storage.'/host-payload/neutral-host.provider-dependency-transition.json';hostInstallWrite($path,file_get_contents($path)."\n");}
                else hostInstallWrite($binding,'{}');
                $before=providerFiles();
                jobCheck($tester->execute(['action'=>'recover-dependency','app'=>'neutral-host','--confirmation'=>'RECOVER DEPENDENCY neutral-host '.$inspection['fingerprint']])===1&&providerFiles()===$before,'Refresh rejects inspected scene drift without writes: '.$drift);
            }
            foreach(['inputs','confirmation','missing-origin'] as $drift) {
                [$logic,$tester,$storage]=providerScene();providerConfirmedReset($tester);providerToolUpdate('0.2.11');$inspection=providerInspect($tester);
                jobCheck($tester->execute(['action'=>'recover-dependency','app'=>'neutral-host','--confirmation'=>'RECOVER DEPENDENCY neutral-host '.$inspection['fingerprint']])===0,'Fresh binding saved for begin drift: '.$drift);
                $binding=$storage.'/host-payload/neutral-host.provider-dependency-binding.json';
                if($drift==='inputs')hostInstallWrite(base_path('composer.lock'),file_get_contents(base_path('composer.lock'))."\n");
                elseif($drift==='confirmation'){$data=json_decode(file_get_contents($binding),true,128,JSON_THROW_ON_ERROR);$data['confirmation_fingerprint']=str_repeat('a',64);hostInstallWrite($binding,json_encode($data,JSON_THROW_ON_ERROR));}
                else unlink($storage.'/host-payload/neutral-host.provider-dependency-transition.json');
                $before=providerFiles();
                try{(new InstallLogic('neutral-host'))->beginDependencyCommand('composer');throw new \RuntimeException('Unexpected changed binding begin');}
                catch(\Throwable $e){jobCheck($e->getMessage()!=='Unexpected changed binding begin','Begin rejects changed confirmed binding: '.$drift);}
                jobCheck(providerFiles()===$before,'Begin refusal writes no nonce or marker: '.$drift);
            }
            [$logic,$tester,$storage]=providerScene();providerConfirmedReset($tester);providerToolUpdate('0.2.11');$inspection=providerInspect($tester);
            $tester->execute(['action'=>'recover-dependency','app'=>'neutral-host','--confirmation'=>'RECOVER DEPENDENCY neutral-host '.$inspection['fingerprint']]);
            $owner=new InstallLogic('neutral-host');$nonce=$owner->beginDependencyCommand('composer');
            $pid=pcntl_fork();if($pid===0)exit(0);pcntl_waitpid($pid,$status);
            $owner->recordDependencyProcessStarted('composer',$nonce,$pid,$pid,[],time());$owner->confirmDependencyProcessReaped('composer',$nonce);
            chmod($storage.'/host-payload',0555);
            try{$owner->dependentInstallComplete('composer',$nonce);throw new \RuntimeException('Missing target completion interruption');}
            catch(\Throwable $e){jobCheck($e->getMessage()!=='Missing target completion interruption','Confirmed target completion keeps reaped proof on persistence failure');}
            finally{chmod($storage.'/host-payload',0700);$owner->releaseDependencyCommand();}
            $cleanup=providerInspect($tester);jobCheck($cleanup['actions']===['finish-dependency-cleanup'],'Confirmed target durable completion remains officially discoverable');
            jobCheck($tester->execute(['action'=>'recover-dependency','app'=>'neutral-host','--confirmation'=>'RECOVER DEPENDENCY neutral-host '.$cleanup['fingerprint']])===0&&Db::$sql===[],'Official cleanup resumes target proof without replay');
            [$logic,$tester,$storage]=providerScene();providerConfirmedReset($tester);providerToolUpdate('0.2.11');$inspection=providerInspect($tester);
            $tester->execute(['action'=>'recover-dependency','app'=>'neutral-host','--confirmation'=>'RECOVER DEPENDENCY neutral-host '.$inspection['fingerprint']]);
            providerExplicitRetry(7);$info=(new InstallLogic('neutral-host'))->getInfo();
            jobCheck($info['state']===4&&!empty($info['dependency_failed_nonce'])&&empty($info['dependency_command_nonce'])&&!file_exists($storage.'/locks/host-upstream.process.json')&&Db::$sql===[],'Failed confirmed target preserves waiting and clears only its reaped job');
            providerExplicitRetry();jobCheck((new InstallLogic('neutral-host'))->getInfo()['state']===1&&Db::$sql===[],'Explicit retry after failure completes same confirmed target');
            foreach(['dependency-journal-archived','provider-binding-archived'] as $window) {
                [$logic,$tester,$storage]=providerScene();providerConfirmedReset($tester);providerToolUpdate('0.2.11');$inspection=providerInspect($tester);
                $tester->execute(['action'=>'recover-dependency','app'=>'neutral-host','--confirmation'=>'RECOVER DEPENDENCY neutral-host '.$inspection['fingerprint']]);providerExplicitRetry();
                $current=new InstallLogic('neutral-host');$snapshot=(new \ReflectionMethod(InstallLogic::class,'dependencyRecoverySnapshot'))->invoke($current);$change=(new \ReflectionMethod(InstallLogic::class,'hostPayloadDependencyChange'))->invoke($current);
                try{$change->archiveRecoveredProviderChange($snapshot,static function(string $stage)use($window){if($stage===$window)throw new \RuntimeException('Owned binding archive interruption');});throw new \RuntimeException('Missing archive interruption');}
                catch(\Throwable $e){jobCheck($e->getMessage()==='Owned binding archive interruption','Binding archive interruption after '.$window);}
                (new InstallLogic('neutral-host'))->uploadFromPath(jobPackage('3.0.0',true));
                jobCheck(!file_exists($storage.'/host-payload/neutral-host.provider-dependency-transition.json'),'Normal upload resumes confirmed target archive: '.$window);
            }
            [$logic,$tester,$storage]=providerScene();$inspection=providerInspect($tester);
            $engine=(new \ReflectionMethod(InstallLogic::class,'dependencyRecovery'))->invoke($logic);
            try{$engine->recover('RECOVER DEPENDENCY neutral-host '.$inspection['fingerprint'],static function(string $stage){if($stage==='provider-transition-written')throw new \RuntimeException('Owned original checkpoint interruption');});}
            catch(\RuntimeException $e){jobCheck($e->getMessage()==='Owned original checkpoint interruption','Original recovery transition write window interrupted');}
            $binding=$storage.'/host-payload/neutral-host.provider-dependency-binding.json';hostInstallWrite($binding,'{}');providerReject($tester,'Malformed binding cannot bypass original active checkpoint');unlink($binding);
            $fresh=providerInspect($tester);jobCheck($fresh['actions']!==['refresh-provider-binding'],'Active original checkpoint stays with original recovery protocol');
            jobCheck($tester->execute(['action'=>'recover-dependency','app'=>'neutral-host','--confirmation'=>'RECOVER DEPENDENCY neutral-host '.$fresh['fingerprint']])===0&&Db::$sql===[],'Original transition-before-history checkpoint formally resumes');
        } elseif(($argv[1]??null)==='--ordinary-checkpoint') {
            foreach(['prepared','marker-renamed','nonce-cleared'] as $window) {
                [$logic,$tester,$storage]=providerScene('0.2.9');
                $snapshot=(new \ReflectionMethod(InstallLogic::class,'dependencyRecoverySnapshot'))->invoke($logic);
                jobCheck(array_keys($snapshot['transactions']['runtime'])===['phase','fingerprint','expected'],'Ordinary runtime snapshot retains public 0.2.10 shape: '.$window);
                jobCheck(!isset($snapshot['transactions']['dependencies']['provider_requirement_update']),'Unchanged provider declaration has no compatibility exception: '.$window);
                $initial=providerInspect($tester);
                $engine=(new \ReflectionMethod(InstallLogic::class,'dependencyRecovery'))->invoke($logic);
                try {
                    $engine->recover('RECOVER DEPENDENCY neutral-host '.$initial['fingerprint'],static function(string $stage)use($window){if($stage===$window)throw new \RuntimeException('Owned ordinary checkpoint interruption');});
                    throw new \RuntimeException('Missing ordinary checkpoint interruption');
                } catch(\RuntimeException $e) {
                    jobCheck($e->getMessage()==='Owned ordinary checkpoint interruption','Ordinary checkpoint interrupted after '.$window);
                }
                $checkpoint=$storage.'/host-payload/neutral-host.dependency-recovery.json';
                $raw=file_get_contents($checkpoint);
                foreach(['extra-field','fingerprint'] as $drift) {
                    $record=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
                    $record['before']['transactions']['runtime'][$drift==='extra-field'?'journal_sha256':'fingerprint']=str_repeat('a',64);
                    hostInstallWrite($checkpoint,json_encode($record,JSON_THROW_ON_ERROR));
                    providerReject($tester,'Ordinary checkpoint runtime '.$drift.' drift: '.$window);
                    hostInstallWrite($checkpoint,$raw);
                }
                $fresh=providerInspect($tester);$before=providerFiles();
                jobCheck($tester->execute(['action'=>'recover-dependency','app'=>'neutral-host','--confirmation'=>'RECOVER DEPENDENCY neutral-host '.$initial['fingerprint']])===1 && providerFiles()===$before,'Ordinary interruption consumes previous confirmation: '.$window);
                jobCheck($tester->execute(['action'=>'recover-dependency','app'=>'neutral-host','--confirmation'=>'RECOVER DEPENDENCY neutral-host '.$fresh['fingerprint']])===0 && Db::$sql===[],'Fresh official confirmation resumes ordinary checkpoint: '.$window);
                jobCheck($logic->getInfo()['state']===InstallLogic::DEPENDENT_WAIT_INSTALL && empty($logic->getInfo()['dependency_command_nonce']) && !is_file($checkpoint),'Ordinary resume preserves waiting and closes occupation: '.$window);
            }
        } elseif($providerProjection) {
            [$logic,$tester,$storage]=providerScene();
            if(($argv[1]??null)==='--foreign-source')providerReject($tester,'Noncanonical identical source projection');
            else {
                $active=(new \ReflectionClass(InstallLogic::class))->getFileName();$originalCode=file_get_contents($active);hostInstallWrite($active,$originalCode."\n");
                providerReject($tester,'Canonical projection byte drift');hostInstallWrite($active,$originalCode);
                $inspection=providerInspect($tester);

            jobCheck(str_starts_with((new \ReflectionClass(InstallLogic::class))->getFileName(),base_path('plugin/sandpackage/')),'Actual loaded logic uses canonical host projection');
            $controller=new \plugin\sandpackage\app\controller\InstallController();
            $api=json_decode($controller->inspectDependencyRecovery(jobRequest(['appName'=>'neutral-host']))->rawBody(),true,64,JSON_THROW_ON_ERROR)['data'];
            jobCheck($api['fingerprint']===$inspection['fingerprint'],'Projected CLI/API share deterministic provider proof');
            jobCheck($tester->execute(['action'=>'recover-dependency','app'=>'neutral-host','--confirmation'=>'RECOVER DEPENDENCY neutral-host '.$api['fingerprint']])===0 && Db::$sql===[],'Canonical projection completes exact recovery without SQL');
            }
        } else {
        [$logic,$tester,$storage]=$scene=providerScene();$before=providerFiles();$inspection=providerInspect($tester);
        jobCheck(providerFiles()===$before,'Official inspection is read-only');
        jobCheck($inspection['provider_requirement_update_verified']===true && $inspection['other_lock_history_verified']===false && !$inspection['command_success_proven'],'Output limits proof to provider declaration and denies historical/success proof');
        $method=new \ReflectionMethod(InstallLogic::class,'hostPayloadDependencyChange');$strict=$method->invoke($logic);
        foreach(['inspectSnapshot','apply','restore','archiveFinished'] as $operation) {
            try {$strict->$operation();throw new \RuntimeException('Ordinary operation accepted provider drift');}
            catch(\RuntimeException $e){jobCheck($e->getMessage()==='宿主依赖清单被外部改写','Ordinary '.$operation.' retains exact raw guard');}
            jobCheck(providerFiles()===$before,'Ordinary '.$operation.' rejection writes nothing');
        }
        $dependencyPaths=[base_path('composer.json'),base_path('composer.lock'),base_path('vendor/composer/installed.json'),base_path('vendor/composer/installed.php'),$storage.'/host-payload/neutral-host.dependencies.json'];
        $raw=[];foreach($dependencyPaths as $path)$raw[$path]=file_get_contents($path);
        $markerRaw=file_get_contents($storage.'/locks/host-upstream.process.json');
        jobCheck($tester->execute(['action'=>'recover-dependency','app'=>'neutral-host','--confirmation'=>'RECOVER DEPENDENCY neutral-host '.$inspection['fingerprint']])===0,'Exact official confirmation retires only dead job occupation');
        $info=(new InstallLogic('neutral-host'))->getInfo();
        jobCheck($info['state']===4 && !empty($info['composer_dependent_wait_install']) && empty($info['dependency_command_nonce']) && Db::$sql===[],'Recovery retains dependency waiting state and executes zero SQL');
        jobCheck(!file_exists($storage.'/locks/host-upstream.process.json') && file_get_contents($storage.'/locks/host-upstream.process.json.'.hash('sha256',$markerRaw).'.history')===$markerRaw,'Original marker is preserved in exact audit archive');
        foreach($raw as $path=>$bytes)jobCheck(file_get_contents($path)===$bytes,'Recovery preserves input/old dependency journal '.basename($path));
        $after=providerFiles();jobCheck($tester->execute(['action'=>'recover-dependency','app'=>'neutral-host','--confirmation'=>'RECOVER DEPENDENCY neutral-host '.$inspection['fingerprint']])===1 && providerFiles()===$after,'Completed confirmation cannot replay any write');
        $oldPath=getenv('PATH');hostInstallWrite($root.'/bin/composer','#!'.PHP_BINARY."\n<?php echo \"Neutral explicit retry\\n\"; exit(0);\n");chmod($root.'/bin/composer',0700);putenv('PATH='.$root.'/bin:'.$oldPath);
        $GLOBALS['fixtureCommand']='composer.update';$frames=[];
        foreach((new \plugin\sandpackage\app\service\TerminalRunner())->exec() as $frame)$frames[]=json_decode($frame,true,32,JSON_THROW_ON_ERROR)['data'];
        putenv('PATH='.$oldPath);file_put_contents(base_path('retry-frames.json'),json_encode($frames,JSON_THROW_ON_ERROR));
        jobCheck(in_array('exec-success',$frames,true)&&in_array('exec-completed',$frames,true),'Real official TerminalRunner retry completes without lifecycle SQL');
        $info=(new InstallLogic('neutral-host'))->getInfo();jobCheck($info['state']===1 && empty($info['dependency_command_nonce']) && empty($info['composer_dependent_wait_install']) && Db::$sql===[],'Successful retry durably installs the same candidate and cleans original command ownership');
        jobCheck($tester->execute(['action'=>'inspect-dependency','app'=>'neutral-host'])===1,'Fresh inspection finds no interrupted dependency job after explicit successful retry');
        $strict=$method->invoke(new InstallLogic('neutral-host'));
        try{$strict->archiveFinished();throw new \RuntimeException('Ordinary archive unexpectedly accepted changed manifest');}catch(\RuntimeException $e){jobCheck($e->getMessage()==='宿主依赖清单被外部改写','Future ordinary dependency archive remains strict without a new transition');}


        $next=new InstallLogic('neutral-host');$next->uploadFromPath(jobPackage('3.0.0',true));
        jobCheck($next->getInfo()['state']===2 && !file_exists($storage.'/host-payload/neutral-host.provider-dependency-transition.json'),'Normal next upload retires only completed confirmed provider transition');
        $next->install(false,'UPGRADE neutral-host@2.0.0->3.0.0');
        jobCheck($next->getInfo()['state']===1 && Db::$sql===['BEGIN',"SELECT '3.0.0'",'COMMIT'],'Normal next install runs only its authorized fixture update SQL');
        jobCheck(is_file($storage.'/host-payload/neutral-host.dependencies.json.'.hash('sha256',$raw[$storage.'/host-payload/neutral-host.dependencies.json']).'.history'),'Original dependency transaction archive preserves exact bytes');


        [$logic,$tester,$storage]=providerScene();providerConfirmedReset($tester);providerExplicitRetry();
        (new InstallLogic('neutral-host'))->uninstall(false);
        jobCheck(!is_dir($storage.'/neutral-host') && Db::$sql===['BEGIN','DROP TABLE neutral_host','COMMIT'],'Normal uninstall after dependency retry retires proof and executes only new fixture uninstall SQL');

        [$logic,$tester,$storage]=providerScene();providerConfirmedReset($tester);
        $owner=new InstallLogic('neutral-host');$nonce=$owner->beginDependencyCommand('composer');
        $pid=pcntl_fork();if($pid===0)exit(0);pcntl_waitpid($pid,$status);
        $owner->recordDependencyProcessStarted('composer',$nonce,$pid,$pid,[],time());$owner->confirmDependencyProcessReaped('composer',$nonce);
        chmod($storage.'/host-payload',0555);
        try{$owner->dependentInstallComplete('composer',$nonce);throw new \RuntimeException('Missing proof write failure');}
        catch(\Throwable $e){jobCheck($e->getMessage()!=='Missing proof write failure','Completion proof persistence fails after durable success');}
        finally{chmod($storage.'/host-payload',0700);$owner->releaseDependencyCommand();}
        jobCheck((new InstallLogic('neutral-host'))->getInfo()['state']===1 && is_file($storage.'/locks/host-upstream.process.json'),'Proof failure preserves durable state and reaped marker');
        $cleanup=providerInspect($tester);jobCheck($cleanup['actions']===['finish-dependency-cleanup'],'Formal inspection finds proof completion gap');
        jobCheck($tester->execute(['action'=>'recover-dependency','app'=>'neutral-host','--confirmation'=>'RECOVER DEPENDENCY neutral-host '.$cleanup['fingerprint']])===0 && Db::$sql===[],'Formal cleanup finalizes same nonce proof without command or SQL replay');
        (new InstallLogic('neutral-host'))->uploadFromPath(jobPackage('3.0.0',true));jobCheck(!file_exists($storage.'/host-payload/neutral-host.provider-dependency-transition.json'),'Normal upload retires cleanup-completed transition');

        [$logic,$tester,$storage]=providerScene();providerConfirmedReset($tester);providerExplicitRetry();
        $current=new InstallLogic('neutral-host');$snapshot=(new \ReflectionMethod(InstallLogic::class,'dependencyRecoverySnapshot'))->invoke($current);$change=(new \ReflectionMethod(InstallLogic::class,'hostPayloadDependencyChange'))->invoke($current);
        try{$change->archiveRecoveredProviderChange($snapshot,static function(string $stage){throw new \RuntimeException('Owned archive interruption');});throw new \RuntimeException('Missing archive interruption');}
        catch(\RuntimeException $e){jobCheck($e->getMessage()==='Owned archive interruption','Old journal archive completes before transition close interruption');}
        jobCheck(!file_exists($storage.'/host-payload/neutral-host.dependencies.json') && is_file($storage.'/host-payload/neutral-host.provider-dependency-transition.json'),'Archive interruption preserves exact pending proof');
        (new InstallLogic('neutral-host'))->uploadFromPath(jobPackage('3.0.0',true));jobCheck(!file_exists($storage.'/host-payload/neutral-host.provider-dependency-transition.json'),'Normal upload resumes exact old journal archive and closes transition');

        foreach(['proof','completed-input','original-archive'] as $drift) {
            [$logic,$tester,$storage]=providerScene();providerConfirmedReset($tester);providerExplicitRetry();
            if($drift==='proof') {$path=$storage.'/host-payload/neutral-host.provider-dependency-transition.json';$r=json_decode(file_get_contents($path),true);$r['completed']['nonce']=str_repeat('a',32);hostInstallWrite($path,json_encode($r));}
            elseif($drift==='completed-input')hostInstallWrite(base_path('composer.lock'),file_get_contents(base_path('composer.lock'))."\n");
            else {$r=json_decode(file_get_contents($storage.'/host-payload/neutral-host.provider-dependency-transition.json'),true);$path=$storage.'/host-payload/neutral-host.dependency-recovery.json.'.$r['recovery_sha256'].'.history';hostInstallWrite($path,file_get_contents($path)."\n");}
            $before=providerFiles();try{(new InstallLogic('neutral-host'))->uploadFromPath(jobPackage('3.0.0',true));throw new \RuntimeException('Retirement accepted drift');}catch(\Throwable $e){jobCheck($e->getMessage()!=='Retirement accepted drift','Normal retirement rejects '.$drift);}
            jobCheck(file_exists($storage.'/host-payload/neutral-host.dependencies.json'),'Retirement drift does not archive dependency journal');
        }
        foreach(['other-require','format','duplicate-provider','escaped-provider','candidate','backup','lock-reference','installed-version','installed-path','loaded-identity','alias-identity','provider-dev','require-dev','escaped-root'] as $scenario) {
            [$logic,$tester,$storage]=providerScene();
            if($scenario==='other-require')hostInstallWrite(base_path('composer.json'),str_replace('"supdger/sand-core": "0.2.4"','"supdger/sand-core": "0.2.5"',file_get_contents(base_path('composer.json'))));
            elseif($scenario==='format')hostInstallWrite(base_path('composer.json'),str_replace('    "require"','  "require"',file_get_contents(base_path('composer.json'))));
            elseif($scenario==='duplicate-provider')hostInstallWrite(base_path('composer.json'),str_replace('"supdger/sand-package": "0.2.10"','"supdger/sand-package": "0.2.10", "supdger/sand-package": "0.2.10"',file_get_contents(base_path('composer.json'))));
            elseif($scenario==='escaped-provider')hostInstallWrite(base_path('composer.json'),str_replace('"supdger/sand-package"','"supdger\\/sand-package"',file_get_contents(base_path('composer.json'))));
            elseif($scenario==='candidate')hostInstallWrite($storage.'/neutral-host/config.json',file_get_contents($storage.'/neutral-host/config.json')."\n");
            elseif($scenario==='backup') { $j=json_decode(file_get_contents($storage.'/host-payload/neutral-host.dependencies.json'),true);hostInstallWrite($storage.'/host-payload/neutral-host.dependency-backup-'.$j['id'].'/extra','foreign'); }
            elseif($scenario==='lock-reference')hostInstallWrite(base_path('composer.lock'),str_replace('fb74978c40021357b79e4ebd5d9afa6ea39e6493',str_repeat('a',40),file_get_contents(base_path('composer.lock'))));
            elseif($scenario==='installed-version')hostInstallWrite(base_path('vendor/composer/installed.json'),str_replace('"0.2.10"','"0.2.9"',file_get_contents(base_path('vendor/composer/installed.json'))));
            elseif($scenario==='installed-path') { $j=json_decode(file_get_contents(base_path('vendor/composer/installed.json')),true);$j['packages'][0]['install-path']='../';hostInstallWrite(base_path('vendor/composer/installed.json'),json_encode($j)); }
            elseif($scenario==='require-dev')hostInstallWrite(base_path('composer.json'),str_replace('    "autoload"','    "require-dev": {"other/dev": "1.0.0"},'."\n".'    "autoload"',file_get_contents(base_path('composer.json'))));
            elseif($scenario==='escaped-root')hostInstallWrite(base_path('composer.json'),str_replace('"require"','"\\u0072equire"',file_get_contents(base_path('composer.json'))));
            elseif($scenario==='alias-identity') { $d=require base_path('vendor/composer/installed.php');$d['versions']['supdger/sand-package']['pretty_version']='dev-main';hostInstallWrite(base_path('vendor/composer/installed.php'),'<?php return '.var_export($d,true).';');\Composer\InstalledVersions::reload($d); }
            elseif($scenario==='loaded-identity') { $d=require base_path('vendor/composer/installed.php');$d['versions']['supdger/sand-package']['install_path']=base_path();hostInstallWrite(base_path('vendor/composer/installed.php'),'<?php return '.var_export($d,true).';');\Composer\InstalledVersions::reload($d); }
            else { $j=json_decode(file_get_contents(base_path('composer.lock')),true);$j['packages-dev']=[$j['packages'][0]];hostInstallWrite(base_path('composer.lock'),json_encode($j)); }
            providerReject($tester,$scenario);
        }
        foreach(['^0.2.0','~0.2.9','>=0.2.9 <0.3.0'] as $constraint){[$logic,$tester]=providerScene($constraint);providerInspect($tester);}
        [$logic,$tester]=providerScene('0.3.0');providerReject($tester,'Incompatible current requirement');
        foreach(['composer.lock','vendor/composer/installed.php','composer.json'] as $path) {
            [$logic,$tester]=providerScene();$inspection=providerInspect($tester);hostInstallWrite(base_path($path),file_get_contents(base_path($path))."\n");$before=providerFiles();
            jobCheck($tester->execute(['action'=>'recover-dependency','app'=>'neutral-host','--confirmation'=>'RECOVER DEPENDENCY neutral-host '.$inspection['fingerprint']])===1 && providerFiles()===$before,'Fresh input drift invalidates old confirmation before writes: '.$path);
        }
        foreach(['prepared','marker-renamed','nonce-cleared','provider-transition-written'] as $window) {
            [$logic,$tester,$storage]=providerScene();$inspection=providerInspect($tester);
            $engine=(new \ReflectionMethod(InstallLogic::class,'dependencyRecovery'))->invoke($logic);
            try {$engine->recover('RECOVER DEPENDENCY neutral-host '.$inspection['fingerprint'],static function(string $stage)use($window){if($stage===$window)throw new \RuntimeException('Owned recovery interruption');});throw new \RuntimeException('Missing interruption');}
            catch(\RuntimeException $e){jobCheck($e->getMessage()==='Owned recovery interruption','Checkpoint interrupted after '.$window);}
            $fresh=providerInspect($tester);$before=providerFiles();
            jobCheck($tester->execute(['action'=>'recover-dependency','app'=>'neutral-host','--confirmation'=>'RECOVER DEPENDENCY neutral-host '.$inspection['fingerprint']])===1 && providerFiles()===$before,'Checkpoint change consumes old confirmation: '.$window);
            jobCheck($tester->execute(['action'=>'recover-dependency','app'=>'neutral-host','--confirmation'=>'RECOVER DEPENDENCY neutral-host '.$fresh['fingerprint']])===0 && Db::$sql===[],'Fresh formal inspection resumes checkpoint: '.$window);
        }

        foreach(['cli-to-api','api-to-cli'] as $direction) {
            [$logic,$tester,$storage]=providerScene();
            $beforeLoading=providerInspect($tester);
            $controller=new \plugin\sandpackage\app\controller\InstallController();
            $api=json_decode($controller->inspectDependencyRecovery(jobRequest(['appName'=>'neutral-host']))->rawBody(),true,64,JSON_THROW_ON_ERROR)['data'];
            jobCheck($api['fingerprint']===$beforeLoading['fingerprint'],'Loading API does not change CLI provider fingerprint');
            $initial=$direction==='cli-to-api'?$beforeLoading:$api;
            $engine=(new \ReflectionMethod(InstallLogic::class,'dependencyRecovery'))->invoke($logic);
            try{$engine->recover('RECOVER DEPENDENCY neutral-host '.$initial['fingerprint'],static function(string $stage){if($stage==='prepared')throw new \RuntimeException('Owned cross-entry interruption');});throw new \RuntimeException('Missing interruption');}
            catch(\RuntimeException $e){jobCheck($e->getMessage()==='Owned cross-entry interruption','Cross-entry checkpoint prepared: '.$direction);}
            if($direction==='cli-to-api') {
                $fresh=json_decode($controller->inspectDependencyRecovery(jobRequest(['appName'=>'neutral-host']))->rawBody(),true,64,JSON_THROW_ON_ERROR)['data'];
                $reply=json_decode($controller->recoverDependency(jobRequest(['appName'=>'neutral-host','confirmation'=>'RECOVER DEPENDENCY neutral-host '.$fresh['fingerprint']]))->rawBody(),true,64,JSON_THROW_ON_ERROR)['data'];
                jobCheck($reply['state']===4&&!$reply['sql_replayed'],'Prepared CLI checkpoint resumes through formal API');
            } else {
                $fresh=providerInspect($tester);
                jobCheck($tester->execute(['action'=>'recover-dependency','app'=>'neutral-host','--confirmation'=>'RECOVER DEPENDENCY neutral-host '.$fresh['fingerprint']])===0 && Db::$sql===[],'Prepared API checkpoint resumes through formal CLI');
            }
        }
        }
        $fixtureSucceeded=true;echo "Provider upgrade dependency recovery: $checks checks passed\n";
    } finally {
        foreach($loaders as $loader)$loader->unregister();
        foreach($roots as $owned) {
            if(!str_starts_with($owned,sys_get_temp_dir().'/sandpackage-provider-recovery-test-')||is_link($owned))throw new \RuntimeException('Unsafe provider fixture cleanup');
            if($fixtureSucceeded)hostInstallDelete($owned);else echo "Failed owned fixture retained: $owned\n";
        }
    }
}
