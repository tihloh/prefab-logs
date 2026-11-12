<?php
declare(strict_types=1);

// Run: php tests/audit_trail_presenter_smoke.php
require_once dirname(__DIR__).'/src/Presenters/AuditTrailPresenter.php';

use Tihloh\Prefab\Logs\Presenters\AuditTrailPresenter;

$check=static function(bool $ok,string $why):void{
    if(!$ok)throw new RuntimeException($why);
};
$references=['program'=>['p1'=>'General Administration'],
    'object'=>['o1'=>'Traveling Expenses - Local']];
$presenter=new AuditTrailPresenter(
    static fn(string $type,string $id):?string=>$references[$type][$id]??null,
    static fn(string $field):string=>ucfirst(str_replace('_',' ',$field)),
    static fn(string $field,mixed $value):string=>$field==='amount' && is_numeric($value)
        ?'₱'.number_format((float)$value,2):(string)$value,
);
$event=[
    'source'=>'system',
    'action'=>'expense.adjusted',
    'title'=>'Expense Adjusted',
    'actor'=>'Budget staff',
    'metadata'=>['objid'=>'o1','progid'=>'p1','amount'=>'-106','date'=>'2026-10-30',
        'entry_id'=>'private-ledger-id','remarks'=>'Reference only'],
    'changes'=>['amount'=>['before'=>'0','now'=>'-106']],
    'message'=>'Raw system event',
];
$normal=$presenter->present($event,false);
$check(str_contains($normal['summary'],'Traveling Expenses - Local')
    && str_contains($normal['summary'],'−₱106.00')
    && str_contains($normal['summary'],'Oct 30, 2026'), 'Readable summary missing');
$check(!isset($normal['metadata']) && !isset($normal['changes'])
    && !isset($normal['original_message']) && !isset($normal['technical']),
    'Nontechnical audience must not receive raw data');
$admin=$presenter->present($event,true);
$check(count($admin['changes'])===1 && $admin['changes'][0]['current']==='₱-106.00',
    'Authorized changes must use application value resolver');
$check(count($admin['metadata'])===5 && $admin['original_message']==='Raw system event',
    'Authorized audit evidence must remain available without modifying originals');
$check($event['metadata']['entry_id']==='private-ledger-id','Original evidence modified');
$bulk=$presenter->many([$event,$event],false);
$check(count($bulk)===2 && !isset($bulk[0]['metadata']), 'Bulk presentation must enforce visibility');

echo "Prefab Logs audit presentation smoke checks passed.\n";
