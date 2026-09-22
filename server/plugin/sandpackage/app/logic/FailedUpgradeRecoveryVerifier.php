<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\logic;

use InvalidArgumentException;
use JsonException;
use Throwable;

/** Candidate-owned v2 recovery profile; this class never evaluates SQL or code. */
final class FailedUpgradeRecoveryVerifier
{
    private const SCHEMA = 'sandpackage.failed-upgrade-recovery/v2';
    private const PROFILE_SCHEMA = 'sandpackage.failed-upgrade-recovery-profile/v2';
    private const PAYLOAD_ALGORITHM = 'sandpackage-normalized-package-manifest/v1';
    private const BLOCKED = '当前数据库状态不属于候选包声明的可重试状态；未替换候选包、未执行脚本，也未修改运行文件。';
    private const SAFE = '候选包声明的恢复条件已通过核验；仍需使用已预检的替换候选完成后续操作。';
    private const RELATIONS_SQL = "SELECT cls.relname AS name FROM pg_catalog.pg_class cls JOIN pg_catalog.pg_namespace ns ON ns.oid=cls.relnamespace WHERE ns.nspname=current_schema() AND cls.relkind IN ('r','p') AND cls.relname LIKE :prefix ORDER BY cls.relname";
    private const RELATION_SQL = "SELECT pg_catalog.to_regclass(current_schema() || '.' || :name) IS NOT NULL AS present";
    private const COLUMN_SQL = "SELECT data_type,is_nullable,column_default,is_identity,identity_generation,ordinal_position FROM information_schema.columns WHERE table_schema=current_schema() AND table_name=:table AND column_name=:column";
    private const CONSTRAINT_SQL = "SELECT con.contype AS type,con.convalidated AS validated,pg_catalog.pg_get_constraintdef(con.oid,true) AS definition FROM pg_catalog.pg_constraint con JOIN pg_catalog.pg_class cls ON cls.oid=con.conrelid JOIN pg_catalog.pg_namespace ns ON ns.oid=cls.relnamespace WHERE ns.nspname=current_schema() AND cls.relname=:table AND con.conname=:name";
    private const INDEX_SQL = "SELECT idx.indisunique AS unique_value,idx.indisvalid AS valid_value,idx.indisready AS ready_value,pg_catalog.pg_get_indexdef(idx.indexrelid) AS definition FROM pg_catalog.pg_index idx JOIN pg_catalog.pg_class name_cls ON name_cls.oid=idx.indexrelid JOIN pg_catalog.pg_class table_cls ON table_cls.oid=idx.indrelid JOIN pg_catalog.pg_namespace ns ON ns.oid=table_cls.relnamespace WHERE ns.nspname=current_schema() AND table_cls.relname=:table AND name_cls.relname=:name";
    private const MENU_ROW_SQL = "SELECT menu.code,parent.code AS parent_code,menu.name,menu.slug,menu.type,menu.path,menu.component,menu.icon,menu.sort,menu.is_hidden,menu.status FROM sand_system_menu menu JOIN sand_system_menu parent ON parent.id=menu.parent_id WHERE menu.code=:code";
    /** @var list<array{id:string,result_digest:string,passed:bool}> */ private array $assertions = [];

    /** @return array<string,mixed> */
    public function parseDescriptor(string $raw): array
    {
        try { $d = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); } catch (JsonException $e) { throw new InvalidArgumentException('恢复描述不是合法 JSON', 0, $e); }
        if (!is_array($d) || array_is_list($d) || $this->hasFloat($d) || !hash_equals(self::canonicalJson($d), $raw)) throw new InvalidArgumentException('恢复描述必须是严格 canonical JSON');
        $this->keys($d, ['app','candidate_payload','from_version','profile','schema','to_version','update_lifecycle']);
        if (($d['schema'] ?? null) !== self::SCHEMA || !$this->app($d['app'] ?? null) || !$this->version($d['from_version'] ?? null) || !$this->version($d['to_version'] ?? null) || version_compare($d['to_version'], $d['from_version'], '<=')) throw new InvalidArgumentException('恢复描述身份不合法');
        if (!is_array($d['candidate_payload']) || !is_array($d['update_lifecycle'])) throw new InvalidArgumentException('恢复描述摘要不合法');
        $this->keys($d['candidate_payload'], ['algorithm','digest']); $this->keys($d['update_lifecycle'], ['path','sha256']);
        if (($d['candidate_payload']['algorithm'] ?? null) !== self::PAYLOAD_ALGORITHM || !self::digest($d['candidate_payload']['digest'] ?? null) || ($d['update_lifecycle']['path'] ?? null) !== 'update.sql' || !self::digest($d['update_lifecycle']['sha256'] ?? null)) throw new InvalidArgumentException('恢复描述摘要不合法');
        if (!is_array($d['profile']) || array_is_list($d['profile'])) throw new InvalidArgumentException('恢复 profile 不合法');
        $this->profile($d['profile'], $d['app'], $d['from_version'], $d['to_version']);
        return $d;
    }

    /** @return array{status:string,state:string,evidence_fingerprint:?string,profile_hash:?string,message:string,connection_reusable:bool} */
    public function verify(string $raw, object $pdo, FailedUpgradeIdentityBinding $binding): array
    {
        $this->assertions = [];
        try { $d = $this->parseDescriptor($raw); $this->binding($d, $binding, hash('sha256', $raw)); } catch (Throwable) { return $this->blocked(true); }
        $begun = false; $safe = false;
        try {
            $this->exec($pdo, 'BEGIN READ ONLY'); $begun = true; $this->exec($pdo, 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            foreach ($d['profile']['assertions'] as $n => $a) $this->assertions[] = $this->check((string) $n, fn(): bool => $this->assertion($pdo, $a, $d['app']));
            $safe = !array_filter($this->assertions, static fn(array $x): bool => !$x['passed']);
        } catch (Throwable) { $safe = false; }
        if ($begun) { try { $this->exec($pdo, 'ROLLBACK'); } catch (Throwable) { return $this->unusable(); } }
        if (!$safe) return $this->blocked(true);
        $profileHash = hash('sha256', self::canonicalJson($d['profile']));
        return ['status'=>'retry_safe','state'=>$d['profile']['state'],'evidence_fingerprint'=>hash('sha256', self::canonicalJson(['descriptor_sha256'=>hash('sha256',$raw),'profile_hash'=>$profileHash,'binding'=>$binding->values(),'assertions'=>array_column($this->assertions,'result_digest')])),'profile_hash'=>$profileHash,'message'=>self::SAFE,'connection_reusable'=>true];
    }

    /** @return list<array{id:string,result_digest:string,passed:bool}> */ public function internalAssertionLog(): array { return $this->assertions; }

    /** @param array<string,mixed> $p */
    private function profile(array $p, string $app, string $fromVersion, string $toVersion): void
    {
        $this->keys($p, ['app','assertions','from_version','id','schema','state','to_version']);
        if (($p['schema'] ?? null) !== self::PROFILE_SCHEMA || ($p['app'] ?? null) !== $app || ($p['from_version'] ?? null) !== $fromVersion || ($p['to_version'] ?? null) !== $toVersion || !is_string($p['id'] ?? null) || preg_match('/^[a-z][a-z0-9_-]{1,63}$/D',$p['id'])!==1 || !is_string($p['state'] ?? null) || preg_match('/^[a-z][a-z0-9_-]{1,63}$/D',$p['state'])!==1 || !is_array($p['assertions']) || !array_is_list($p['assertions']) || count($p['assertions'])<1 || count($p['assertions'])>256) throw new InvalidArgumentException('恢复 profile 不合法');
        foreach ($p['assertions'] as $a) { if (!is_array($a) || array_is_list($a)) throw new InvalidArgumentException('恢复断言不合法'); $this->assertionSchema($a, $app); }
    }

    /** @param array<string,mixed> $a */
    private function assertionSchema(array $a, string $app): void
    {
        $prefix = str_replace('-', '_', $app) . '_'; $type = $a['type'] ?? null;
        $sets = ['relations_exact'=>['relations','type'],'relation_absent'=>['name','type'],'column_exact'=>['column','data_type','default_expression','identity_generation','is_identity','nullable','ordinal_position','table','type'],'constraint_exact'=>['constraint_type','definition','name','table','type','validated'],'index_exact'=>['definition','name','table','type','unique'],'menu_rows_exact'=>['rows','type'],'ledger_absent'=>['name','type']];
        if (!is_string($type) || !isset($sets[$type])) throw new InvalidArgumentException('恢复断言词汇不合法');
        $this->keys($a, $sets[$type]);
        if ($type === 'relations_exact') $this->identifiers($a['relations'] ?? null, $prefix, 128);
        elseif ($type === 'relation_absent') $this->identifier($a['name'] ?? null, $prefix);
        elseif ($type === 'column_exact') { $this->identifier($a['table'] ?? null,$prefix); $this->identifier($a['column'] ?? null,''); if (!in_array($a['data_type'] ?? null,['bigint','boolean','character','character varying','integer','jsonb','smallint','text','timestamp without time zone','uuid'],true) || !in_array($a['nullable'] ?? null,['YES','NO'],true) || !is_string($a['default_expression'] ?? null) || strlen($a['default_expression'])>512 || !in_array($a['is_identity'] ?? null,['YES','NO'],true) || !in_array($a['identity_generation'] ?? null,[null,'ALWAYS','BY DEFAULT'],true) || !is_int($a['ordinal_position'] ?? null) || $a['ordinal_position']<1||$a['ordinal_position']>512) throw new InvalidArgumentException('恢复列断言不合法'); }
        elseif ($type === 'constraint_exact') { $this->identifier($a['table'] ?? null,$prefix); $constraintType=$a['constraint_type']??null; if (!in_array($constraintType,['c','f','p','u'],true)) throw new InvalidArgumentException('恢复约束断言不合法'); $this->objectIdentifier($a['name'] ?? null,$prefix,match($constraintType){'c'=>['ck_'],'f'=>['fk_'],'p'=>['pk_'],'u'=>['uk_','uq_','ux_']}); if (!is_bool($a['validated'] ?? null) || !is_string($a['definition'] ?? null) || strlen($a['definition'])>2048) throw new InvalidArgumentException('恢复约束断言不合法'); }
        elseif ($type === 'index_exact') { $this->identifier($a['table'] ?? null,$prefix); $this->objectIdentifier($a['name'] ?? null,$prefix,['idx_','ix_','uk_','uq_','ux_']); if (!is_bool($a['unique'] ?? null)||!is_string($a['definition'] ?? null)||strlen($a['definition'])>2048) throw new InvalidArgumentException('恢复索引断言不合法'); }
        elseif ($type === 'ledger_absent') $this->identifier($a['name'] ?? null,$prefix);
        else { $rows=$a['rows']??null; if(!is_array($rows)||!array_is_list($rows)||count($rows)<1||count($rows)>128) throw new InvalidArgumentException('恢复菜单断言不合法'); $stem=str_replace('-','_',$app).':'; $codes=[]; foreach($rows as $row){if(!is_array($row))throw new InvalidArgumentException('恢复菜单断言不合法');$this->keys($row,['code','component','hidden','icon','name','parent_code','path','slug','sort','status','type']);foreach(['code','parent_code','name','slug','path','component','icon'] as $field)if(!is_string($row[$field])||strlen($row[$field])>255)throw new InvalidArgumentException('恢复菜单断言不合法');if(!str_starts_with($row['code'],$stem)||isset($codes[$row['code']])||!is_int($row['sort'])||!is_int($row['type'])||!is_int($row['hidden'])||!is_int($row['status']))throw new InvalidArgumentException('恢复菜单断言不合法');$codes[$row['code']]=true;}}
    }

    /** @param array<string,mixed> $a */
    private function assertion(object $pdo, array $a, string $app): bool
    {
        return match($a['type']) {
            'relations_exact'=>$this->relations($pdo,str_replace('-','_',$app).'_%',$a['relations']),
            'relation_absent'=>!$this->flag($this->one($pdo,self::RELATION_SQL,[':name'=>$a['name']])['present']??false),
            'column_exact'=>($r=$this->one($pdo,self::COLUMN_SQL,[':table'=>$a['table'],':column'=>$a['column']]))!==[] && [$r['data_type']??null,$r['is_nullable']??null,$this->canon($r['column_default']??''),$r['is_identity']??null,$r['identity_generation']??null,(int)($r['ordinal_position']??0)]===[$a['data_type'],$a['nullable'],$this->canon($a['default_expression']),$a['is_identity'],$a['identity_generation'],$a['ordinal_position']],
            'constraint_exact'=>($r=$this->one($pdo,self::CONSTRAINT_SQL,[':table'=>$a['table'],':name'=>$a['name']]))!==[] && ($r['type']??null)===$a['constraint_type'] && $this->flag($r['validated']??false)===$a['validated'] && $this->canon((string)($r['definition']??''))===$this->canon($a['definition']),
            'index_exact'=>($r=$this->one($pdo,self::INDEX_SQL,[':table'=>$a['table'],':name'=>$a['name']]))!==[] && $this->flag($r['valid_value']??false) && $this->flag($r['ready_value']??false) && $this->flag($r['unique_value']??false)===$a['unique'] && $this->canon((string)($r['definition']??''))===$this->canon($a['definition']),
            'menu_rows_exact'=>$this->menus($pdo,$a['rows']),
            'ledger_absent'=>!$this->flag($this->one($pdo,self::RELATION_SQL,[':name'=>$a['name']])['present']??false),
        };
    }
    /** @param list<string> $expected */ private function relations(object $pdo,string $prefix,array $expected): bool { $actual=array_column($this->rows($pdo,self::RELATIONS_SQL,[':prefix'=>$prefix]),'name'); sort($actual,SORT_STRING); sort($expected,SORT_STRING); return $actual===$expected; }
    /** @param list<array<string,mixed>> $expected */ private function menus(object $pdo,array $expected): bool { foreach($expected as $row){$actualRows=$this->rows($pdo,self::MENU_ROW_SQL,[':code'=>$row['code']]);if(count($actualRows)!==1)return false;$actual=$actualRows[0];if([$actual['parent_code']??null,$actual['name']??null,$actual['slug']??null,(int)($actual['type']??0),$actual['path']??null,$actual['component']??null,$actual['icon']??null,(int)($actual['sort']??0),(int)($actual['is_hidden']??0),(int)($actual['status']??0)]!==[$row['parent_code'],$row['name'],$row['slug'],$row['type'],$row['path'],$row['component'],$row['icon'],$row['sort'],$row['hidden'],$row['status']])return false;}return true; }

    /** @param array<string,mixed> $d */ private function binding(array $d, FailedUpgradeIdentityBinding $b, string $sha): void { foreach($b->values() as $name=>$value) if(($name==='backup_id' && (!is_string($value)||preg_match('/^[a-z][a-z0-9-]{1,63}-[A-Za-z0-9._-]{1,96}$/D',$value)!==1)) || ($name!=='backup_id'&&!self::digest($value))) throw new InvalidArgumentException('恢复身份绑定不合法'); $v=$b->values(); if(!hash_equals($v['descriptor_sha256'],$sha)||!hash_equals($v['candidate_payload_manifest_sha256'],$d['candidate_payload']['digest'])||!hash_equals($v['update_sql_sha256'],$d['update_lifecycle']['sha256'])) throw new InvalidArgumentException('恢复身份绑定不匹配'); }
    private function identifier(mixed $value,string $prefix): void { if(!is_string($value)||preg_match('/^[a-z][a-z0-9_]{0,62}$/D',$value)!==1||($prefix!==''&&!str_starts_with($value,$prefix))) throw new InvalidArgumentException('恢复标识符不合法'); }
    /** @param list<string> $leadingPrefixes */
    private function objectIdentifier(mixed $value, string $appPrefix, array $leadingPrefixes): void
    {
        $this->identifier($value, '');
        if (str_starts_with($value, $appPrefix)) return;
        foreach ($leadingPrefixes as $leadingPrefix) if (str_starts_with($value, $leadingPrefix . $appPrefix)) return;
        throw new InvalidArgumentException('恢复对象标识符不合法');
    }
    private function identifiers(mixed $values,string $prefix,int $max): void { if(!is_array($values)||!array_is_list($values)||count($values)<1||count($values)>$max||count($values)!==count(array_unique($values,SORT_STRING))) throw new InvalidArgumentException('恢复标识符列表不合法'); foreach($values as $value)$this->identifier($value,$prefix); }
    /** @param array<string,mixed> $value */ private function keys(array $value,array $keys): void { $actual=array_keys($value);sort($actual,SORT_STRING);sort($keys,SORT_STRING);if($actual!==$keys)throw new InvalidArgumentException('恢复 JSON 字段不合法'); }
    private function app(mixed $value):bool{return is_string($value)&&preg_match('/^[a-z][a-z0-9-]{1,63}$/D',$value)===1;} private function version(mixed $value):bool{return is_string($value)&&preg_match('/^[0-9]+\\.[0-9]+\\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?$/D',$value)===1;} private static function digest(mixed $value):bool{return is_string($value)&&preg_match('/^[a-f0-9]{64}$/D',$value)===1;} private function flag(mixed $value):bool{return $value===true||$value===1||$value==='1'||$value==='t'||$value==='true';}
    private function hasFloat(mixed $value):bool{if(is_float($value))return true;if(!is_array($value))return false;foreach($value as $item)if($this->hasFloat($item))return true;return false;} private function exec(object $pdo,string $sql):void{if(!method_exists($pdo,'exec')||$pdo->exec($sql)===false)throw new InvalidArgumentException('恢复只读事务不可用');}
    private function canon(string $value): string { return trim($value); }
    /** @return list<array<string,mixed>> */ private function rows(object $pdo,string $sql,array $parameters):array{$s=$pdo->prepare($sql);foreach($parameters as $name=>$value)$s->bindValue($name,$value,is_int($value)?\PDO::PARAM_INT:\PDO::PARAM_STR);if($s->execute()===false)throw new InvalidArgumentException('恢复 catalog 查询失败');$rows=$s->fetchAll();return is_array($rows)?$rows:[];} /** @return array<string,mixed> */ private function one(object $pdo,string $sql,array $p):array{return $this->rows($pdo,$sql,$p)[0]??[];}
    /** @return array{id:string,result_digest:string,passed:bool} */ private function check(string $id,callable $fn):array{try{$passed=$fn();}catch(Throwable){$passed=false;}return ['id'=>$id,'result_digest'=>hash('sha256',self::canonicalJson(['id'=>$id,'result'=>$passed?'passed':'blocked'])),'passed'=>$passed];}
    /** @return array{status:string,state:string,evidence_fingerprint:null,profile_hash:null,message:string,connection_reusable:bool} */ private function blocked(bool $reusable):array{return ['status'=>'FAILED_UPGRADE_RECOVERY_BLOCKED','state'=>'blocked_partial_or_unknown','evidence_fingerprint'=>null,'profile_hash'=>null,'message'=>self::BLOCKED,'connection_reusable'=>$reusable];} /** @return array{status:string,state:string,evidence_fingerprint:null,profile_hash:null,message:string,connection_reusable:false} */ private function unusable():array{return ['status'=>'FAILED_UPGRADE_RECOVERY_CONNECTION_UNUSABLE','state'=>'blocked_partial_or_unknown','evidence_fingerprint'=>null,'profile_hash'=>null,'message'=>self::BLOCKED,'connection_reusable'=>false];}
    /** @param array<string,mixed> $value */ public static function canonicalJson(array $value):string{$normalize=static function(mixed $item)use(&$normalize):mixed{if(!is_array($item))return $item;if(!array_is_list($item))ksort($item,SORT_STRING);foreach($item as $key=>$child)$item[$key]=$normalize($child);return $item;};return json_encode($normalize($value),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
}
