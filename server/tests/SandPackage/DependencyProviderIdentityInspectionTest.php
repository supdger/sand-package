<?php

declare(strict_types=1);

namespace plugin\sandadmin\basic {
    class BaseController {
        public static int $actor=1;
        public int $adminId;
        public function __construct() {$this->adminId=self::$actor;}
        public function success(mixed $data): \support\Response {return new \support\Response(200,[],json_encode(['data'=>$data],JSON_THROW_ON_ERROR));}
    }
}
namespace plugin\sandpackage\app\logic {
    final class InstallLogic {
        public static int $created=0;
        public function __construct() {self::$created++;throw new \RuntimeException('Inspection cannot initialize a lifecycle');}
    }
}
namespace {
    use Composer\InstalledVersions;
    use plugin\sandpackage\app\controller\InstallController;
    use plugin\sandpackage\app\service\HostPayloadDependencyChange;
    use plugin\sandpackage\app\logic\InstallLogic;

    $root=sys_get_temp_dir().'/sandpackage-provider-identity-inspect-test-'.bin2hex(random_bytes(8));
    mkdir($root.'/server/vendor/composer',0700,true);
    function base_path(string $path=''): string {global $root;return $root.'/server'.($path===''?'':'/'.$path);}
    function config(string $key,mixed $default=null): mixed {
        return $key==='plugin.sandpackage.terminal.commands' ? ['composer'=>['update'=>['cwd'=>base_path(),'command'=>'composer update --no-interaction']]] : $default;
    }
    function env(string $key,mixed $default=null): mixed {return $default;}
    $loader=require getenv('SANDPACKAGE_TEST_VENDOR') ?: dirname(__DIR__,2).'/vendor/autoload.php';
    $loader->setPsr4('plugin\\sandpackage\\',[dirname(__DIR__,2).'/plugin/sandpackage']);
    $fixtureLoader=new \Composer\Autoload\ClassLoader(base_path('vendor'));$fixtureLoader->register(true);
    $checks=0;$succeeded=false;
    function identityCheck(bool $pass,string $message): void {global $checks;if(!$pass)throw new \RuntimeException($message);$checks++;echo '[PASS] '.$message."\n";}
    function identityFiles(): array {global $root;$files=[];foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST) as $file)$files[substr($file->getPathname(),strlen($root))]=$file->isDir()?'directory':hash_file('sha256',$file->getPathname());ksort($files);return $files;}
    function identityRecords(string $version='0.2.12'): void {
        $package=['name'=>'supdger/sand-package','version'=>$version,'source'=>['reference'=>'8a7bae08b8fbb330b41cf9e78dd2f79370c850c1','url'=>'https://credential-sentinel@example.test/package'],
            'dist'=>['reference'=>'8a7bae08b8fbb330b41cf9e78dd2f79370c850c1','url'=>'https://credential-sentinel@example.test/archive']];
        file_put_contents(base_path('composer.json'),json_encode(['require'=>['supdger/sand-package'=>$version],'private'=>'raw-json-secret-sentinel'],JSON_THROW_ON_ERROR));
        file_put_contents(base_path('composer.lock'),json_encode(['packages'=>[$package],'packages-dev'=>[]],JSON_THROW_ON_ERROR));
        $from=explode('/',trim(realpath(base_path('vendor/composer')),'/'));$to=explode('/',trim(realpath(dirname(__DIR__,3)),'/'));while($from!==[]&&$to!==[]&&$from[0]===$to[0]){array_shift($from);array_shift($to);}
        $package['install-path']=str_repeat('../',count($from)).implode('/',$to);
        file_put_contents(base_path('vendor/composer/installed.json'),json_encode(['packages'=>[$package]],JSON_THROW_ON_ERROR));
    }
    function identityRuntime(string $version='0.2.12'): array {
        $data=['root'=>['name'=>'test/identity','install_path'=>base_path()], 'versions'=>['supdger/sand-package'=>['pretty_version'=>$version,'version'=>$version.'.0','reference'=>$version==='0.2.11'?'a0ca7d16332c215a7fee26d195e8993a68ffc7c9':'8a7bae08b8fbb330b41cf9e78dd2f79370c850c1','install_path'=>dirname(__DIR__,3),'aliases'=>[],'dev_requirement'=>false]]];
        file_put_contents(base_path('vendor/composer/installed.php'),'<?php return '.var_export($data,true).';');
        InstalledVersions::reload($data);return $data;
    }
    function identityInspect(InstallController $controller): array {
        $before=identityFiles();$runtime=InstalledVersions::getAllRawData();$body='command=composer.update';
        $response=$controller->inspectDependencyStart(new \support\Request("POST / HTTP/1.1\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body));
        $raw=$response->rawBody();$data=json_decode($raw,true,128,JSON_THROW_ON_ERROR)['data'];
        identityCheck(identityFiles()===$before&&InstalledVersions::getAllRawData()===$runtime&&InstallLogic::$created===0,'HTTP identity inspection writes no file/lock/lifecycle and preserves process metadata');
        identityCheck(!str_contains($raw,'credential-sentinel')&&!str_contains($raw,'raw-json-secret-sentinel')&&!str_contains($raw,base_path())&&!str_contains($raw,dirname(__DIR__,3)),'Only public identities and comparison booleans are returned');
        identityCheck($data['readonly_only']===true&&$data['process_launch']==='unknown'&&$data['durable_write']==='unknown'&&$data['lock_acquisition']==='unknown','Identity comparison does not imply lock/write/process success');
        if (($data['provider_identity']['identity_matches']??null)!==true) echo json_encode($data['provider_identity'],JSON_THROW_ON_ERROR)."\n";
        return $data['provider_identity'];
    }
    try {
        identityRecords();$currentRuntime=identityRuntime();$controller=new InstallController();$identity=identityInspect($controller);
        identityCheck($identity['status']==='inspected'&&$identity['identity_matches']===true&&!in_array(false,$identity['conditions'],true),'Matching current worker/lock/installed/require identity passes exact shared conditions');
        identityCheck($identity['runtime']['version']==='0.2.12'&&$identity['runtime']['reference']==='8a7bae08b8fbb330b41cf9e78dd2f79370c850c1','Current worker exposes only validated public version/reference');
        identityRuntime('0.2.11');InstalledVersions::getAllRawData();
        file_put_contents(base_path('vendor/composer/installed.php'),'<?php return '.var_export($currentRuntime,true).';');
        $identity=identityInspect($controller);
        identityCheck($identity['identity_matches']===false&&$identity['runtime']['version']==='0.2.11'&&$identity['lock']['version']==='0.2.12'&&!$identity['conditions']['lock_version_equals_runtime']&&!$identity['conditions']['installed_version_equals_runtime']&&!$identity['conditions']['requirement_matches_runtime'],'Simulated stale process metadata is visible without refreshing it');
        identityRuntime();
        foreach(['missing','corrupt','duplicate','untrusted-type'] as $fault) {
            identityRecords();
            if($fault==='missing')unlink(base_path('composer.lock'));
            elseif($fault==='corrupt')file_put_contents(base_path('vendor/composer/installed.json'),'{raw-json-secret-sentinel');
            elseif($fault==='duplicate'){$data=json_decode(file_get_contents(base_path('composer.lock')),true);$data['packages'][]=$data['packages'][0];file_put_contents(base_path('composer.lock'),json_encode($data));}
            else file_put_contents(base_path('composer.json'),'{}');
            $identity=identityInspect($controller);identityCheck($identity['status']==='unavailable'&&$identity['identity_matches']===null&&$identity['conditions']===null,'Untrusted '.$fault.' records stay unavailable rather than valid');
        }
        foreach(['long-version','version-token','reference-token','reference-url'] as $fault) {
            identityRecords();$runtime=identityRuntime();
            if($fault==='long-version')$runtime['versions']['supdger/sand-package']['pretty_version']=str_repeat('9',4096).'.0.0';
            elseif($fault==='version-token')$runtime['versions']['supdger/sand-package']['pretty_version']='Bearer raw-json-secret-sentinel';
            elseif($fault==='reference-token')$runtime['versions']['supdger/sand-package']['reference']='Bearer raw-json-secret-sentinel';
            else $runtime['versions']['supdger/sand-package']['reference']='https://credential-sentinel@example.test/archive';
            file_put_contents(base_path('vendor/composer/installed.php'),'<?php return '.var_export($runtime,true).';');InstalledVersions::reload($runtime);
            $identity=identityInspect($controller);$field=str_starts_with($fault,'reference')?'reference':'version';
            identityCheck($identity['runtime'][$field]===null,'HTTP diagnostic safely suppresses '.$fault.' rather than truncating');
            identityCheck(strlen(json_encode($identity,JSON_THROW_ON_ERROR))<2048,'HTTP identity output remains bounded for '.$fault);
        }
        identityRecords();identityRuntime();$identity=identityInspect($controller);
        identityCheck($identity['runtime']['version']==='0.2.12'&&$identity['runtime']['reference']==='8a7bae08b8fbb330b41cf9e78dd2f79370c850c1','Valid public version and commit projection remain unchanged');
        $succeeded=true;echo 'Provider HTTP identity inspection: '.$checks." checks passed\n";
    } finally {
        $fixtureLoader->unregister();
        if(!str_starts_with($root,sys_get_temp_dir().'/sandpackage-provider-identity-inspect-test-')||is_link($root))throw new \RuntimeException('Unsafe identity fixture cleanup');
        if($succeeded){foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST) as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($root);}
        else echo 'Failed owned identity fixture retained: '.$root."\n";
    }
}
