<?php
// Visual-only Robux gift backend. It does not transfer real Robux.
header('Content-Type: application/json; charset=utf-8');
$allowedOrigin = 'https://dw321.vercel.app';
if (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] === $allowedOrigin) {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
}
header('Vary: Origin');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['error'=>'POST required']); exit; }
function out($data,$status=200){ http_response_code($status); echo json_encode($data,JSON_UNESCAPED_SLASHES); exit; }
function clientId($v){ $v=trim((string)$v); return ($v!=='' && strlen($v)<=100 && preg_match('/^[A-Za-z0-9_-]+$/',$v)) ? $v : ''; }
function amount($v){ $v=preg_replace('/\D+/','',(string)$v) ?? ''; $v=ltrim($v,'0'); return $v===''?'0':$v; }
function cmp($a,$b){ $a=amount($a); $b=amount($b); if(strlen($a)!==strlen($b)) return strlen($a)<strlen($b)?-1:1; return strcmp($a,$b); }
function sub($a,$b){ $a=strrev(amount($a)); $b=strrev(amount($b)); $borrow=0;$o='';$n=max(strlen($a),strlen($b)); for($i=0;$i<$n;$i++){ $x=$i<strlen($a)?intval($a[$i]):0; $y=$i<strlen($b)?intval($b[$i]):0; $d=$x-$y-$borrow; if($d<0){$d+=10;$borrow=1;}else{$borrow=0;} $o.=$d; } return ltrim(strrev($o),'0') ?: '0'; }
$payload=json_decode(file_get_contents('php://input'),true);
if(!is_array($payload)) out(['error'=>'Invalid JSON'],400);
$client=clientId($payload['client']??'');
$gift=amount($payload['amount']??'0');
$recipient=trim((string)($payload['recipient']??''));
if($client==='') out(['error'=>'Missing client'],400);
if($gift==='0') out(['error'=>'Enter a valid amount.'],400);
if($recipient==='') out(['error'=>'Missing recipient'],400);
$file=__DIR__.'/visual_balances.json';
$fp=@fopen($file,'c+');
if(!$fp) out(['error'=>'Cannot open balance storage. Check folder permissions.'],500);
flock($fp,LOCK_EX);
$raw=stream_get_contents($fp);$db=json_decode($raw?:'{}',true);if(!is_array($db))$db=[];
$balance=amount($db[$client]??'1942382');
if(cmp($gift,$balance)>0){flock($fp,LOCK_UN);fclose($fp);out(['error'=>'Insufficient visual balance.','balance'=>$balance],400);}
$newBalance=sub($balance,$gift);$db[$client]=$newBalance;ftruncate($fp,0);rewind($fp);fwrite($fp,json_encode($db,JSON_UNESCAPED_SLASHES));fflush($fp);flock($fp,LOCK_UN);fclose($fp);
out(['success'=>true,'visual'=>true,'balance'=>$newBalance,'recipient'=>$recipient,'amount'=>$gift]);
