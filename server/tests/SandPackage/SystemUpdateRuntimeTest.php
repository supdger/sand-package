<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/plugin/sandpackage/tools/system-update-worker.php';
$workspace = SandSystemUpdateRuntime::normalizePath(sys_get_temp_dir()) . '/sand system update test 中文 ' . bin2hex(random_bytes(5));
mkdir($workspace, 0700);
$checks = 0;
function expect(bool $condition, string $message): void { global $checks; if (!$condition) throw new RuntimeException('FAIL: ' . $message); $checks++; echo 'PASS: ' . $message . PHP_EOL; }
function put(string $file, string $text): void { if (!is_dir(dirname($file))) mkdir(dirname($file), 0755, true); file_put_contents($file, $text); }
function j(string $file, array $data): void { put($file, json_encode($data, JSON_THROW_ON_ERROR)); }
function fixture(string $root, string $case): array {
    mkdir($root, 0700);
    $server = $root . '/server'; $front = $root . '/sandadmin-artd'; $static = $server . '/public/admin'; $job = $server . '/runtime/system-update/jobs/' . str_repeat('a', 32);
    mkdir($job, 0700, true); copy(dirname(__DIR__, 2) . '/plugin/sandpackage/tools/system-update-worker.php', $job . '/worker.php'); mkdir($static, 0755, true); chmod($static, 0755);
    $old = []; $pins = []; $newPackage = $root . '/candidate/sand-package';
    foreach (SandSystemUpdateRuntime::PACKAGES as $name => $plugin) {
        $package = $server . '/vendor/' . $name;
        j($package . '/composer.json', ['name' => $name]);
        put($package . '/server/plugin/' . $plugin . '/config/app.php', '<?php return ["version"=>"0.1.0"];');
        put($package . '/sandadmin-artd/src/' . $plugin . '.txt', 'old-' . $plugin);
        put($package . '/tools/publish-frontend.php', '<?php $source=dirname(__DIR__)."/sandadmin-artd/src"; foreach(glob($source."/*") as $file) copy($file,$argv[1]."/src/".basename($file)); $m=basename(dirname(__DIR__))==="sand-core"?".sand-core-source-manifest.json":".sand-package-source-manifest.json"; $map=[]; foreach(glob($source."/*") as $file)$map["src/".basename($file)]=hash_file("sha256",$file); file_put_contents($argv[1]."/".$m,json_encode(["version"=>"0.2.0","files"=>$map])); echo "published\\n";');
        if ($plugin === 'sandadmin') {
            // Current official Core uses its public publisher API and has no tools directory.
            SandSystemUpdateRuntime::remove($package . '/tools');
            put($package . '/server/FrontendPublisher.php', '<?php namespace SandAdmin\Core; final class FrontendPublisher { public static function publish(string $target): void { $source=dirname(__DIR__)."/sandadmin-artd/src"; foreach(glob($source."/*") as $file)copy($file,$target."/src/".basename($file)); $map=[]; foreach(glob($source."/*") as $file)$map["src/".basename($file)]=hash_file("sha256",$file); file_put_contents($target."/.sand-core-source-manifest.json",json_encode(["version"=>"0.1.0","files"=>$map])); echo "published Core API\n"; } }');
        }
        SandSystemUpdateRuntime::copy($package . '/server/plugin/' . $plugin, $server . '/plugin/' . $plugin);
        put($front . '/src/' . $plugin . '.txt', 'old-' . $plugin);
        j($front . '/' . ($plugin === 'sandadmin' ? '.sand-core-source-manifest.json' : '.sand-package-source-manifest.json'), ['version' => '0.1.0', 'files' => ['src/' . $plugin . '.txt' => hash('sha256', 'old-' . $plugin)]]);
        if ($plugin === 'sandpackage') { put($package . '/sandadmin-artd/src/obsolete.txt', 'old-page'); put($front . '/src/obsolete.txt', 'old-page'); $m=SandSystemUpdateRuntime::readJson($front . '/.sand-package-source-manifest.json'); $m['files']['src/obsolete.txt']=hash('sha256','old-page'); j($front . '/.sand-package-source-manifest.json',$m); }
        $old[] = ['name' => $name, 'version' => '0.1.0', 'source' => ['reference' => str_repeat('1', 40)], 'dist' => ['url' => 'https://api.github.com/repos/' . $name . '/zipball/' . str_repeat('1', 40), 'reference' => str_repeat('1', 40)]];
        $pins[$name] = ['version' => '0.1.0', 'reference' => str_repeat('1', 40), 'files' => SandSystemUpdateRuntime::tree($package)];
    }
    // Host autoload is deliberately invalid: independent updater must never load it.
    put($server . '/vendor/autoload.php', '<?php throw new RuntimeException("host autoload must not load");');
    SandSystemUpdateRuntime::copy($server . '/vendor/supdger/sand-package', $newPackage);
    put($newPackage . '/server/plugin/sandpackage/config/app.php', '<?php return ["version"=>"0.2.0"];');
    put($newPackage . '/sandadmin-artd/src/sandpackage.txt', 'new-sandpackage'); unlink($newPackage . '/sandadmin-artd/src/obsolete.txt');
    $files = []; foreach (['server', 'sandadmin-artd', 'tools'] as $scope) foreach (SandSystemUpdateRuntime::tree($newPackage . '/' . $scope) as $relative => $hash) $files[$scope . '/' . $relative] = $hash;
    ksort($files);
    $target = ['format' => 1, 'package' => 'supdger/sand-package', 'name' => '插件管理器', 'version' => '0.2.0', 'reference' => str_repeat('2', 40), 'host_min' => '0.1.0', 'schema_changes' => false, 'skeleton_changes' => false, 'files' => $files];
    j($server . '/composer.json', ['require' => ['supdger/sand-core' => '^0.1', 'supdger/sand-package' => '^0.1']]);
    j($server . '/composer.lock', ['packages' => $old]);
    put($front . '/pnpm-lock.yaml', 'lockfileVersion: 9');
    put($front . '/custom.txt', 'user-owned');
    put($static . '/index.html', 'old-static');
    j($static . '/' . SandSystemUpdateRuntime::STATIC_MANIFEST, ['files' => SandSystemUpdateRuntime::tree($static)]);
    put($root . '/composer.php', <<<'SCRIPT'
<?php
require $argv[1];
$fixture = json_decode(file_get_contents($argv[2]), true);
if (!in_array('--no-scripts',$argv,true) || !in_array('--no-plugins',$argv,true) || !in_array('supdger/sand-core:0.1.0',$argv,true)) exit(9);
if (in_array('--dry-run',$argv,true)) {echo "solver passed\n"; exit(0);}
$server=$fixture['server']; SandSystemUpdateRuntime::remove($server.'/vendor/supdger/sand-package'); SandSystemUpdateRuntime::copy($fixture['candidate'],$server.'/vendor/supdger/sand-package');
$lock=SandSystemUpdateRuntime::readJson($server.'/composer.lock');foreach($lock['packages'] as &$p) {if($p['name']==='supdger/sand-package'){$p['version']='0.2.0';$p['source']['reference']=str_repeat('2',40);$p['dist']['reference']=str_repeat('2',40);$p['dist']['url']='https://api.github.com/repos/supdger/sand-package/zipball/'.str_repeat('2',40);} if($fixture['case']==='hidden-core' && $p['name']==='supdger/sand-core')$p['version']='0.2.0';}unset($p);SandSystemUpdateRuntime::writeJson($server.'/composer.lock',$lock); echo "installed\n";
SCRIPT);
    put($root . '/pnpm.php', '<?php if(in_array("build",$argv,true)){if(!is_dir(getcwd()."/dist"))mkdir(getcwd()."/dist",0755); file_put_contents(getcwd()."/dist/index.html","new-static");} echo "frontend ok\\n";');
    put($root . '/health.php', '<?php if(is_file(__DIR__."/fail-health")) exit(5); echo "health passed\\n";');
    put($root . '/reload.php', '<?php echo "reload passed\\n";');
    j($root . '/fixture.json', ['server' => $server, 'candidate' => $newPackage, 'case' => $case]);
    $plan = ['server' => $server, 'root' => $server . '/runtime/system-update', 'frontend' => $front, 'static' => $static, 'storage' => $server . '/storage/sandpackage', 'php' => PHP_BINARY, 'composer' => [PHP_BINARY, $root . '/composer.php', dirname(__DIR__, 2) . '/plugin/sandpackage/tools/system-update-worker.php', $root . '/fixture.json'], 'pnpm' => [PHP_BINARY, $root . '/pnpm.php'], 'reload' => [PHP_BINARY, $root . '/reload.php'], 'health' => [PHP_BINARY, $root . '/health.php'], 'targets' => [$target], 'pinned' => $pins];
    foreach (['server', 'root', 'frontend', 'static', 'storage', 'php'] as $key) $plan[$key] = SandSystemUpdateRuntime::normalizePath($plan[$key]);
    $plan['fingerprint'] = SandSystemUpdateRuntime::fingerprint(SandSystemUpdateRuntime::scopes($plan));
    j($job . '/plan.json', $plan); j($job . '/task.json', ['id' => str_repeat('a', 32), 'state' => 'queued', 'stage' => 'queued', 'targets' => [$target], 'created_at' => time(), 'updated_at' => time(), 'logs' => [], 'error' => '', 'recovery_available' => false, 'mutated' => false]);
    return [$plan, $job];
}
try {
    foreach (['C:/Sand Admin/server', 'D:\\Sand Admin\\中文\\server'] as $path) {
        SandSystemUpdateRuntime::safePath($path);
        expect(true, 'Windows drive path accepts spaces, Unicode and native separators: ' . $path);
    }
    foreach (['C:relative', '//server/share/path', '\\\\server\\share\\path', '\\\\?\\C:\\server', 'C:/server/../config', 'C:/server/NUL.txt', 'C:/server/file:stream', 'C:/server/alias.', "C:/server/\0bad", 'C:/server//app', 'C:\\server\\\\app'] as $path) {
        $rejected = false;
        try { SandSystemUpdateRuntime::safePath($path); }
        catch (RuntimeException) { $rejected = true; }
        expect($rejected, 'unsafe Windows path rejected');
    }
    expect(SandSystemUpdateRuntime::overlaps('C:\\Sand\\server\\public', 'c:/sand/SERVER/public/admin'), 'Windows overlap comparison ignores case and separator spelling');
    $arguments = ['a b', '中文', 'double"quote', "single'quote", '$($literal)&`text', "trailing\\", 'back\\"quote'];
    $echoed = SandSystemUpdateRuntime::command([PHP_BINARY, '-r', 'echo json_encode(array_slice($argv,1), JSON_UNESCAPED_UNICODE);', ...$arguments], $workspace, static function (string $line): void {});
    expect(json_decode($echoed, true, 128, JSON_THROW_ON_ERROR) === $arguments, 'native argv preserves quotes, spaces, Unicode, shell punctuation and trailing backslashes');
    j($workspace . '/atomic.json', ['state' => 'first']); SandSystemUpdateRuntime::writeJson($workspace . '/atomic.json', ['state' => 'second']);
    expect(SandSystemUpdateRuntime::readJson($workspace . '/atomic.json')['state'] === 'second', 'task JSON atomically replaces an existing record after handles close');
    if (PHP_OS_FAMILY === 'Windows') {
        $payloadBefore = glob(SandSystemUpdateRuntime::normalizePath(sys_get_temp_dir()) . '/sand-update-argv-*') ?: [];
        $longArgument = str_repeat('native-argv-', 400);
        $length = SandSystemUpdateRuntime::command([PHP_BINARY, '-r', 'echo strlen($argv[1]);', $longArgument], $workspace, static function (string $line): void {});
        expect((int)$length === strlen($longArgument), 'Windows long argv travels through a private payload without overflowing the launcher command line');
        $payloadAfter = glob(SandSystemUpdateRuntime::normalizePath(sys_get_temp_dir()) . '/sand-update-argv-*') ?: [];
        sort($payloadBefore); sort($payloadAfter);
        expect($payloadAfter === $payloadBefore, 'Windows argv payload is cleaned after the native command finishes');
        put($workspace . '/held-record-writer.php', '<?php require $argv[1];file_put_contents(__DIR__."/".$argv[2].".ready","ready");$started=microtime(true);SandSystemUpdateRuntime::writeJson($argv[3],["state"=>"new"]);echo microtime(true)-$started;');
        foreach (['released', 'held'] as $case) {
            $record = $workspace . '/held-' . $case . '.json'; j($record, ['state' => 'original']);
            $originalHash = hash_file('sha256', $record);
            $reader = fopen($record, 'rb');
            if (!is_resource($reader)) throw new RuntimeException('cannot hold task record reader');
            try {
                $writer = proc_open([PHP_BINARY, $workspace . '/held-record-writer.php', dirname(__DIR__, 2) . '/plugin/sandpackage/tools/system-update-worker.php', $case, $record],
                    [0 => ['file', 'NUL', 'r'], 1 => ['file', $workspace . '/' . $case . '-writer.log', 'a'], 2 => ['file', $workspace . '/' . $case . '-writer-error.log', 'a']], $pipes, $workspace, null, ['bypass_shell' => true]);
                if (!is_resource($writer)) throw new RuntimeException('cannot create held-reader task writer');
                $deadline = microtime(true) + 10;
                while (!is_file($workspace . '/' . $case . '.ready') && microtime(true) < $deadline) usleep(1000);
                if (!is_file($workspace . '/' . $case . '.ready')) throw new RuntimeException('task writer handshake did not complete');
                if ($case === 'released') { usleep(250000); fclose($reader); }
                $writerCode = proc_close($writer);
                $snapshot = SandSystemUpdateRuntime::readJson($record);
                if ($case === 'released') {
                    $elapsed = (float) file_get_contents($workspace . '/' . $case . '-writer.log');
                    expect($writerCode === 0 && $snapshot['state'] === 'new' && $elapsed >= 0.2, 'Windows atomic replacement retries while reader is held and completes after release');
                } else {
                    expect($writerCode !== 0 && $snapshot['state'] === 'original' && hash_file('sha256', $record) === $originalHash
                        && (glob($record . '.*.tmp') ?: []) === [] && str_contains((string) file_get_contents($workspace . '/' . $case . '-writer-error.log'), '无法原子保存更新记录'),
                        'Windows exhausted replacement keeps original JSON and removes only its own temporary record');
                }
            } finally { if (is_resource($reader)) fclose($reader); }
        }
        j($workspace . '/concurrent-record.json', ['sequence' => 0, 'payload' => str_repeat('x', 65536)]);
        put($workspace . '/record-writer.php', '<?php require $argv[1];for($i=1;$i<=100;$i++)SandSystemUpdateRuntime::writeJson(__DIR__."/concurrent-record.json",["sequence"=>$i,"payload"=>str_repeat("x",65536)]);');
        $writer = proc_open([PHP_BINARY, $workspace . '/record-writer.php', dirname(__DIR__, 2) . '/plugin/sandpackage/tools/system-update-worker.php'],
            [0 => ['file', 'NUL', 'r'], 1 => ['file', $workspace . '/record-writer.log', 'a'], 2 => ['file', $workspace . '/record-writer.log', 'a']], $pipes, $workspace, null, ['bypass_shell' => true]);
        if (!is_resource($writer)) throw new RuntimeException('cannot create concurrent task record writer');
        $recordValid = true; $writerCode = -1;
        do {
            $snapshot = SandSystemUpdateRuntime::readJson($workspace . '/concurrent-record.json');
            $recordValid = $recordValid && is_int($snapshot['sequence'] ?? null) && strlen($snapshot['payload'] ?? '') === 65536;
            $writerStatus = proc_get_status($writer);
            if (!$writerStatus['running']) { $writerCode = $writerStatus['exitcode']; break; }
            usleep(1000);
        } while (true);
        $closed = proc_close($writer); if ($writerCode < 0) $writerCode = $closed;
        expect($recordValid && $writerCode === 0 && SandSystemUpdateRuntime::readJson($workspace . '/concurrent-record.json')['sequence'] === 100, 'Windows high-frequency readers observe complete records while atomic writer replaces 100 versions');
        $marker = static function (string $line) use ($workspace): void {
            if (preg_match('/^@system-windows-process:([0-9]+):([0-9]+)$/D', $line, $match)) j($workspace . '/process.json', ['pid' => (int) $match[1], 'started' => $match[2]]);
        };
        put($workspace . '/heartbeat.php', '<?php file_put_contents($argv[1].".pid",(string)getmypid()); while(true){file_put_contents($argv[1],(string)microtime(true));usleep(50000);}');
        put($workspace . '/spawn.php', '<?php $child=proc_open([PHP_BINARY,__DIR__."/heartbeat.php",$argv[1]],[0=>["file","NUL","r"],1=>["file",$argv[1].".log","a"],2=>["file",$argv[1].".log","a"]],$pipes,null,null,["bypass_shell"=>true]);while(true)usleep(50000);');
        try {
            SandSystemUpdateRuntime::command([PHP_BINARY, $workspace . '/spawn.php', $workspace . '/timeout-beat'], $workspace, $marker, 5);
            expect(false, 'Windows command timeout terminates its job');
        } catch (RuntimeException $error) { expect(str_contains($error->getMessage(), '超时'), 'Windows command timeout terminates its job'); }
        expect(is_file($workspace . '/timeout-beat'), 'timeout fixture created a live grandchild before cancellation');
        $identity = SandSystemUpdateRuntime::readJson($workspace . '/process.json');
        expect(!SandSystemUpdateRuntime::processActive($identity), 'timed-out Job launcher identity no longer exists');
        $last = file_get_contents($workspace . '/timeout-beat'); usleep(500000);
        expect(file_get_contents($workspace . '/timeout-beat') === $last, 'timed-out grandchild cannot keep writing');
        put($workspace . '/owner.php', <<<'OWNER'
<?php
require $argv[1];
SandSystemUpdateRuntime::command([PHP_BINARY,__DIR__.'/spawn.php',__DIR__.'/owner-beat'],__DIR__,static function(string $line):void {
    if(preg_match('/^@system-windows-process:([0-9]+):([0-9]+)$/D',$line,$m))file_put_contents(__DIR__.'/owner-process.json',json_encode(['pid'=>(int)$m[1],'started'=>$m[2]]));
});
OWNER);
        $owner = proc_open([PHP_BINARY, '-d', 'sys_temp_dir=' . $workspace, $workspace . '/owner.php', dirname(__DIR__, 2) . '/plugin/sandpackage/tools/system-update-worker.php'],
            [0 => ['file', 'NUL', 'r'], 1 => ['file', $workspace . '/owner.log', 'a'], 2 => ['file', $workspace . '/owner.log', 'a']], $pipes, $workspace, null, ['bypass_shell' => true]);
        if (!is_resource($owner)) throw new RuntimeException('cannot create interruption fixture');
        $deadline = microtime(true) + 20;
        while (!is_file($workspace . '/owner-beat') && microtime(true) < $deadline) usleep(50000);
        expect(is_file($workspace . '/owner-beat'), 'owner interruption fixture created a live grandchild');
        expect(proc_terminate($owner), 'fixture hard-terminates its own PHP command owner'); proc_close($owner);
        usleep(500000);
        expect(!SandSystemUpdateRuntime::processActive(SandSystemUpdateRuntime::readJson($workspace . '/owner-process.json')), 'owner interruption closes its Job launcher');
        $last = file_get_contents($workspace . '/owner-beat'); usleep(500000);
        expect(file_get_contents($workspace . '/owner-beat') === $last, 'owner interruption terminates grandchildren');
        $stale = $identity; $stale['pid'] = getmypid(); $stale['started'] = '1';
        expect(!SandSystemUpdateRuntime::processActive($stale), 'reused PID with a different start time cannot match the previous command');
        put($workspace . '/junction-target/keep.txt', 'KEEP'); mkdir($workspace . '/junction-tree');
        $junction = $workspace . '/junction-tree/link';
        $junctionPayload = base64_encode(json_encode([$junction, $workspace . '/junction-target'], JSON_THROW_ON_ERROR));
        SandSystemUpdateRuntime::command([SandSystemUpdateRuntime::powershell(), '-NoProfile', '-NonInteractive', '-Command', '$paths=[Text.Encoding]::UTF8.GetString([Convert]::FromBase64String("' . $junctionPayload . '"))|ConvertFrom-Json; New-Item -ItemType Junction -Path $paths[0] -Target $paths[1] | Out-Null'], $workspace, static function (string $line): void {});
        try {
            foreach (['tree', 'remove'] as $operation) {
                $rejected = false;
                try { SandSystemUpdateRuntime::$operation($workspace . '/junction-tree'); }
                catch (RuntimeException) { $rejected = true; }
                expect($rejected, 'nested Windows junction rejected by ' . $operation);
            }
            expect(file_get_contents($workspace . '/junction-target/keep.txt') === 'KEEP', 'junction refusal preserves files outside the traversed tree');
        } finally { rmdir($junction); }
        put($workspace . '/shell-wrapper.cmd', '@echo unsafe');
        $rejected = false;
        try { SandSystemUpdateRuntime::command([$workspace . '/shell-wrapper.cmd'], $workspace, static function (string $line): void {}); }
        catch (RuntimeException) { $rejected = true; }
        expect($rejected, 'batch wrappers rejected before command execution');
    }
    [$plan, $job] = fixture($workspace . '/success', 'success');
    (new SandSystemUpdateRuntime($job))->run();
    $task = SandSystemUpdateRuntime::readJson($job . '/task.json');
    expect($task['state'] === 'succeeded', 'full native process pipeline succeeds without host autoload');
    expect(file_get_contents($plan['static'] . '/index.html') === 'new-static', 'frontend build deployed');
    expect(PHP_OS_FAMILY === 'Windows' ? is_readable($plan['static']) : (fileperms($plan['static']) & 0777) === 0755, 'static directory remains readable to web server');
    expect(!file_exists($plan['frontend'] . '/src/obsolete.txt'), 'removed managed frontend page does not survive new release');
    expect(file_get_contents($plan['frontend'] . '/custom.txt') === 'user-owned', 'unmanaged frontend user file preserved');
    expect(file_get_contents($plan['server'] . '/plugin/sandpackage/config/app.php') === '<?php return ["version"=>"0.2.0"];', 'manager updates its own runtime');
    [$plan, $job] = fixture($workspace . '/failure', 'failure'); put($workspace . '/failure/fail-health', '1');
    (new SandSystemUpdateRuntime($job))->run();
    expect(SandSystemUpdateRuntime::readJson($job . '/task.json')['state'] === 'failed', 'health failure never reported as success');
    unlink($workspace . '/failure/fail-health');
    (new SandSystemUpdateRuntime($job))->run(true);
    expect(SandSystemUpdateRuntime::readJson($job . '/task.json')['state'] === 'recovered', 'verified backup restored and reloaded with health gate');
    expect(file_get_contents($plan['static'] . '/index.html') === 'old-static', 'original static files restored');
    expect(PHP_OS_FAMILY === 'Windows' ? is_readable($plan['static']) : (fileperms($plan['static']) & 0777) === 0755, 'static permission restored');
    [$plan, $job] = fixture($workspace . '/conflict', 'conflict'); put($workspace . '/conflict/fail-health', '1');
    (new SandSystemUpdateRuntime($job))->run();
    $before = SandSystemUpdateRuntime::readJson($job . '/task.json')['failure_fingerprint'];
    put($plan['static'] . '/external.txt', 'KEEP');
    (new SandSystemUpdateRuntime($job))->run(true); (new SandSystemUpdateRuntime($job))->run(true);
    expect(file_get_contents($plan['static'] . '/external.txt') === 'KEEP', 'two recovery attempts preserve external edits');
    expect(SandSystemUpdateRuntime::readJson($job . '/task.json')['failure_fingerprint'] === $before, 'recovery refusal never adopts external fingerprint');
    $inspection = (new SandSystemUpdateRuntime($job))->manualInspect();
    expect(in_array($plan['static'] . '/external.txt', $inspection['changed_files'], true), 'manual interruption inspection lists changed files');
    unlink($workspace . '/conflict/fail-health');
    (new SandSystemUpdateRuntime($job))->manualRecover($inspection['confirmation'], $inspection['fingerprint']);
    $manual = SandSystemUpdateRuntime::readJson($job . '/task.json');
    expect($manual['state'] === 'recovered', 'explicit manual recovery has executable exit');
    expect(file_get_contents($manual['rescue'] . '/6/external.txt') === 'KEEP', 'manual recovery preserves changed current scene in rescue backup');
    [$plan, $job] = fixture($workspace . '/hidden-core', 'hidden-core');
    (new SandSystemUpdateRuntime($job))->run();
    $task = SandSystemUpdateRuntime::readJson($job . '/task.json');
    expect($task['state'] === 'failed' && str_contains($task['error'], '未选择'), 'dependency solver cannot silently upgrade unselected core');
    expect(file_get_contents($plan['server'] . '/plugin/sandadmin/config/app.php') === '<?php return ["version"=>"0.1.0"];', 'unselected core never published');
    $equal = $plan; $equal['static'] = $plan['frontend'];
    try { SandSystemUpdateRuntime::assertManaged($equal); expect(false, 'equal static/frontend rejected'); } catch (RuntimeException $e) { expect(str_contains($e->getMessage(), '重叠'), 'equal static/frontend rejected'); }
    $lock = SandSystemUpdateRuntime::locks($plan);
    try { SandSystemUpdateRuntime::locks($plan); expect(false, 'concurrent lifecycle lock rejected'); } catch (RuntimeException $e) { expect(str_contains($e->getMessage(), '已有'), 'concurrent lifecycle lock rejected'); }
    SandSystemUpdateRuntime::unlock($lock);
    [$plan, $job] = fixture($workspace . '/api', 'success');
    $GLOBALS['apiServer'] = $plan['server'];
    $GLOBALS['apiSettings'] = array_intersect_key($plan, array_flip(['php','frontend','static','composer','pnpm','reload','health']));
    eval('namespace plugin\\sandadmin\\exception; class ApiException extends \\RuntimeException {}');
    function base_path(): string { return $GLOBALS['apiServer']; }
    function runtime_path(): string { return $GLOBALS['apiServer'] . '/runtime'; }
    function config(string $name, mixed $default=null): mixed { return $name === 'plugin.sandpackage.system_update' ? $GLOBALS['apiSettings'] : $default; }
    require_once dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/PluginStorage.php';
    require_once dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/SystemUpdate.php';
    SandSystemUpdateRuntime::remove($job);
    mkdir($plan['root'] . '/plans',0700);
    $token=bin2hex(random_bytes(32));
    j($plan['root'] . '/plans/' . hash('sha256',$token) . '.json',['expires_at'=>time()+600,'plan'=>$plan]);
    $api=new \plugin\sandpackage\app\service\SystemUpdate();
    $started=$api->start($token);
    expect($started['state']==='queued','API launches independent detached job and immediately returns');
    $rejected = false;
    try {$api->start($token);} catch (RuntimeException $e){$rejected = true;}
    expect($rejected,'confirmation consumed once');
    $deadline=microtime(true)+60;
    do {$finished=$api->task($started['id']);if(in_array($finished['state'],['succeeded','failed'],true))break;usleep(100000);}while(microtime(true)<$deadline);
    expect($finished['state']==='succeeded','detached runner survives parent lock handoff and completes');
    $cachedReleases = [];
    foreach (['0.1.9', '0.2.0', '0.2.1'] as $version) {
        $release = $plan['targets'][0];
        $release['version'] = $version;
        $cachedReleases[] = $release;
    }
    j($plan['root'] . '/releases.json', ['time' => time(), 'releases' => $cachedReleases]);
    $available = $api->status()['releases'];
    expect(count($available) === 1 && $available[0]['version'] === '0.2.1',
        'real status excludes equal and older cached releases and preserves the newer release');
    j($plan['root'] . '/releases.json',['time'=>time(),'releases'=>[]]);
    expect($api->status()['active_task']['state']==='succeeded','status preserves latest terminal task after refresh');
    [$plan, $job] = fixture($workspace . '/recover-api', 'failure');
    put($workspace . '/recover-api/fail-health','1'); (new SandSystemUpdateRuntime($job))->run(); unlink($workspace . '/recover-api/fail-health');
    $GLOBALS['apiServer']=$plan['server']; $GLOBALS['apiSettings']=array_intersect_key($plan,array_flip(['php','frontend','static','composer','pnpm','reload','health']));
    $children=[];
    $requestScript = <<<'REQUEST'
require $argv[1] . '/tools/system-update-worker.php';
$GLOBALS['apiServer']=$argv[2]; $GLOBALS['apiSettings']=json_decode(base64_decode($argv[3]),true,128,JSON_THROW_ON_ERROR);
eval('namespace plugin\\sandadmin\\exception; class ApiException extends \\RuntimeException {}');
function base_path(): string { return $GLOBALS['apiServer']; }
function runtime_path(): string { return $GLOBALS['apiServer'] . '/runtime'; }
function config(string $name, mixed $default=null): mixed { return $name==='plugin.sandpackage.system_update'?$GLOBALS['apiSettings']:$default; }
require $argv[1] . '/app/service/PluginStorage.php'; require $argv[1] . '/app/service/SystemUpdate.php';
while(!is_file($argv[4].'/go'))usleep(1000);
try{(new \plugin\sandpackage\app\service\SystemUpdate())->recover(str_repeat('a',32));file_put_contents($argv[4].'/request-'.$argv[5],'dispatched');}
catch(Throwable $e){file_put_contents($argv[4].'/request-'.$argv[5],'rejected');}
REQUEST;
    for($i=0;$i<2;$i++) {
        $child=proc_open([PHP_BINARY,'-r',$requestScript,dirname(__DIR__,2).'/plugin/sandpackage',$plan['server'],base64_encode(json_encode($GLOBALS['apiSettings'],JSON_THROW_ON_ERROR)),$workspace,(string)$i],
            [0=>['file',SandSystemUpdateRuntime::nullDevice(),'r'],1=>['file',$workspace.'/request-'.$i.'.log','a'],2=>['file',$workspace.'/request-'.$i.'.log','a']],$pipes,$workspace,null,['bypass_shell'=>true]);
        if(!is_resource($child))throw new RuntimeException('cannot create concurrent recovery request');
        $children[]=$child;
    }
    put($workspace.'/go','1');foreach($children as $child)if(proc_close($child)!==0)throw new RuntimeException('concurrent recovery request failed');
    $results=[file_get_contents($workspace.'/request-0'),file_get_contents($workspace.'/request-1')];
    expect(count(array_filter($results,static fn(string $r):bool=>$r==='dispatched'))===1,'two concurrent recover requests dispatch only one worker');
    $deadline=microtime(true)+60;
    do{$raw=SandSystemUpdateRuntime::readJson($job.'/task.json');if($raw['state']==='recovered')break;usleep(100000);}while(microtime(true)<$deadline);
    expect($raw['state']==='recovered','concurrent recovery finishes without corrupting terminal state');
    $starts=array_filter($raw['logs'],static fn(array $log):bool=>$log['stage']==='restore'&&$log['message']==='开始');
    expect(count($starts)===1,'only one restore phase executes across concurrent API calls');
    echo 'RESULT: ' . $checks . " checks passed\n";
} finally { SandSystemUpdateRuntime::remove($workspace); }
