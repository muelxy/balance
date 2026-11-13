<?php
date_default_timezone_set('Asia/Jakarta');
@include __DIR__ . '/config.php';
defined('LK_URL') || define('LK_URL',''); defined('LK_KEY') || define('LK_KEY',''); defined('LK_SECRET') || define('LK_SECRET','');
defined('ADMIN_USERS') || define('ADMIN_USERS',''); // contoh di config.php: define('ADMIN_USERS','admin,budi');
const GIFTS=[['🎁',10],['🌹',20],['👑',100],['🚀',500]];
// Game bawaan berupa tautan langsung (muncul sebagai kartu di menu Game). Contoh: [['title'=>'Tebak Kata','url'=>'https://contoh.com/game','icon'=>'🧩']]
const GAME_PRESETS=[];
// Teks pantauan saat masuk room / memulai siaran (kosongkan '' untuk mematikan)
const MONITOR_TEXT='Siaran ini dipantau. Dilarang menyiarkan konten pornografi, SARA, kekerasan, judi, atau melanggar hak cipta. Pelanggaran dapat membuat siaran dihentikan dan akun diblokir.';
const RULES=['Dilarang konten pornografi dan seksual','Dilarang ujaran kebencian, SARA, dan perundungan','Dilarang kekerasan, ancaman, dan konten berbahaya','Dilarang judi, penipuan, dan promosi ilegal','Hormati hak cipta musik dan konten milik orang lain','Dilarang membagikan data pribadi orang lain'];
$UP=__DIR__.'/uploads'; if(!is_dir($UP)) @mkdir($UP,0755,true);
$db=new PDO('sqlite:'.__DIR__.'/room_v4.sqlite'); $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach([
"CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY, username TEXT UNIQUE, pass TEXT, name TEXT, bio TEXT DEFAULT '', photo TEXT DEFAULT '', coins INT DEFAULT 100, claim INT DEFAULT 0, seen INT DEFAULT 0, room_id INT DEFAULT 0, mic INT DEFAULT 0)",
"CREATE TABLE IF NOT EXISTS tokens(tok TEXT PRIMARY KEY, uid INT)",
"CREATE TABLE IF NOT EXISTS rooms(id INTEGER PRIMARY KEY, host INT, title TEXT, cover TEXT DEFAULT '', likes INT DEFAULT 0, frozen INT DEFAULT 0, live INT DEFAULT 1, started INT)",
"CREATE TABLE IF NOT EXISTS msgs(id INTEGER PRIMARY KEY, room INT, uid INT, kind TEXT, text TEXT, ts INT)",
"CREATE TABLE IF NOT EXISTS mgr(room INT, uid INT, PRIMARY KEY(room,uid))",
"CREATE TABLE IF NOT EXISTS bans(room INT, uid INT, until INT, PRIMARY KEY(room,uid))",
"CREATE TABLE IF NOT EXISTS visits(room INT, uid INT, PRIMARY KEY(room,uid))",
"CREATE TABLE IF NOT EXISTS casts(id INTEGER PRIMARY KEY, uid INT, title TEXT, file TEXT, duration INT DEFAULT 0, ts INT)",
"CREATE TABLE IF NOT EXISTS talks(id INTEGER PRIMARY KEY, uid INT, text TEXT, ts INT)",
"CREATE TABLE IF NOT EXISTS gifts(id INTEGER PRIMARY KEY, room INT, uid INT, host INT, coins INT, ts INT)",
"CREATE TABLE IF NOT EXISTS notifs(id INTEGER PRIMARY KEY, uid INT, text TEXT, ts INT, rd INT DEFAULT 0)",
"CREATE TABLE IF NOT EXISTS dms(id INTEGER PRIMARY KEY, a INT, b INT, text TEXT, ts INT, rd INT DEFAULT 0)",
"CREATE TABLE IF NOT EXISTS follows(a INT, b INT, ts INT, PRIMARY KEY(a,b))",
"CREATE TABLE IF NOT EXISTS notices(id INTEGER PRIMARY KEY, uid INT, text TEXT, ts INT)",
"CREATE TABLE IF NOT EXISTS tracks(id INTEGER PRIMARY KEY, uid INT, title TEXT, artist TEXT DEFAULT '', file TEXT, dur INT DEFAULT 0, plays INT DEFAULT 0, ts INT)",
"CREATE TABLE IF NOT EXISTS rmusic(id INTEGER PRIMARY KEY, room INT, track INT, pos INT)",
"CREATE TABLE IF NOT EXISTS admlog(id INTEGER PRIMARY KEY, admin INT, act TEXT, target INT DEFAULT 0, detail TEXT DEFAULT '', ts INT)",
"CREATE TABLE IF NOT EXISTS games(id INTEGER PRIMARY KEY, room INT, kind TEXT, uid INT, title TEXT, data TEXT, state TEXT DEFAULT 'live', created INT, ends INT DEFAULT 0)",
"CREATE TABLE IF NOT EXISTS gplay(game INT, uid INT, val INT DEFAULT 0, ts INT, PRIMARY KEY(game,uid))"] as $q) $db->exec($q);

try{$db->exec("ALTER TABLE rooms ADD COLUMN welcome TEXT DEFAULT ''");}catch(Exception $e){}
try{$db->exec("ALTER TABLE rooms ADD COLUMN ended INT DEFAULT 0");}catch(Exception $e){}
foreach(["ALTER TABLE rooms ADD COLUMN mcur INT DEFAULT 0","ALTER TABLE rooms ADD COLUMN mstart INT DEFAULT 0","ALTER TABLE rooms ADD COLUMN mpaused INT DEFAULT 0","ALTER TABLE rooms ADD COLUMN moff INT DEFAULT 0","ALTER TABLE dms ADD COLUMN img TEXT DEFAULT ''"] as $q){try{$db->exec($q);}catch(Exception $e){}}
foreach(["ALTER TABLE users ADD COLUMN choice INT DEFAULT 0","ALTER TABLE users ADD COLUMN banned INT DEFAULT 0","ALTER TABLE rooms ADD COLUMN clr INT DEFAULT 0"] as $q){try{$db->exec($q);}catch(Exception $e){}}
// EXP, level, dan VIP
foreach(["ALTER TABLE users ADD COLUMN exp INT DEFAULT 0","ALTER TABLE users ADD COLUMN vip INT DEFAULT 0","ALTER TABLE users ADD COLUMN expat INT DEFAULT 0","ALTER TABLE users ADD COLUMN chatat INT DEFAULT 0"] as $q){try{$db->exec($q);}catch(Exception $e){}}
// status online (VIP bisa menyembunyikan) & target live
foreach(["ALTER TABLE users ADD COLUMN hide_online INT DEFAULT 0","ALTER TABLE rooms ADD COLUMN goal_t TEXT DEFAULT ''","ALTER TABLE rooms ADD COLUMN goal_n INT DEFAULT 0","ALTER TABLE rooms ADD COLUMN goal_b INT DEFAULT 0"] as $q){try{$db->exec($q);}catch(Exception $e){}}
// kolom role baru dibuat sekali: akun dengan username 'admin' (jika sudah ada) otomatis jadi ADMIN
try{$db->exec("ALTER TABLE users ADD COLUMN role TEXT DEFAULT 'user'");$db->exec("UPDATE users SET role='admin' WHERE username='admin'");}catch(Exception $e){}
// simpan foto (chat room / DM)
function saveImg($f,$UP,$pre,$max=3000000){
  if(!$f||$f['error'])return [null,($f&&in_array($f['error'],[1,2]))?'Foto melebihi batas upload server':'Upload gagal'];
  if($f['size']>$max)return [null,'Foto terlalu besar'];
  $i=@getimagesize($f['tmp_name']);$ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$i['mime']??'']??null;
  if(!$ext)return [null,'Format harus JPG, PNG, atau WebP'];
  $fn=$pre.bin2hex(random_bytes(8)).'.'.$ext;
  return move_uploaded_file($f['tmp_name'],"$UP/$fn")?[$fn,null]:[null,'Gagal menyimpan foto'];
}
// status musik room: lanjut otomatis ke lagu berikutnya (antrian berputar)
function mstate($db,$room,$nowms){
  $R=(int)$room['id'];
  $q=rows($db,"SELECT q.id,q.track,t.title,t.artist,t.file,t.dur FROM rmusic q JOIN tracks t ON t.id=q.track WHERE q.room=? ORDER BY q.pos,q.id",[$R]);
  $cur=(int)($room['mcur']??0);$start=(int)($room['mstart']??0);$paused=(int)($room['mpaused']??0);$moff=(int)($room['moff']??0);
  $idx=-1;foreach($q as $i=>$x)if((int)$x['id']===$cur){$idx=$i;break;}
  if($cur&&$idx<0){$cur=0;$paused=0;run($db,"UPDATE rooms SET mcur=0,mpaused=0,moff=0 WHERE id=?",[$R]);}
  $g=0;
  while($cur&&!$paused&&$idx>=0&&(int)$q[$idx]['dur']>0&&$nowms-$start>=(int)$q[$idx]['dur']*1000&&$g++<50){
    $start+=(int)$q[$idx]['dur']*1000;$idx=($idx+1)%count($q);$cur=(int)$q[$idx]['id'];
    run($db,"UPDATE rooms SET mcur=?,mstart=? WHERE id=?",[$cur,$start,$R]);
  }
  $off=$cur?($paused?$moff:max(0,$nowms-$start)):0;
  return ['cur'=>$cur?$q[$idx]:null,'curid'=>$cur,'idx'=>$idx,'start'=>$start,'offset'=>$off,'paused'=>$paused,'queue'=>$q];
}
function run($db,$s,$p=[]){$db->prepare($s)->execute($p);}
function one($db,$s,$p=[]){$q=$db->prepare($s);$q->execute($p);return $q->fetch(PDO::FETCH_ASSOC)?:null;}
function rows($db,$s,$p=[]){$q=$db->prepare($s);$q->execute($p);return $q->fetchAll(PDO::FETCH_ASSOC);}
function val($db,$s,$p=[]){$q=$db->prepare($s);$q->execute($p);return $q->fetchColumn();}
function out($d){header('Content-Type: application/json');echo json_encode($d);exit;}
// ---- admin & badge ----
function isAdmin($u){return $u&&($u['role']??'')==='admin';}
function cfgAdmins(){return array_values(array_filter(array_map('trim',explode(',',strtolower(ADMIN_USERS)))));}
function bsql($p='u.'){return "(CASE WHEN {$p}role='admin' THEN 'admin' WHEN {$p}choice=1 THEN 'choice' ELSE '' END)";}
function ubadge($u){return ($u['role']??'')==='admin'?'admin':((int)($u['choice']??0)===1?'choice':'');}
function alog($db,$adm,$act,$target=0,$detail=''){run($db,"INSERT INTO admlog(admin,act,target,detail,ts) VALUES(?,?,?,?,?)",[$adm,$act,(int)$target,mb_substr((string)$detail,0,200),time()]);}
// hapus semua chat sebuah room (termasuk file foto chat); counter clr memberi tahu klien agar ikut mengosongkan layar
function clearChat($db,$UP,$R){
  foreach(rows($db,"SELECT text FROM msgs WHERE room=? AND kind='img'",[$R]) as $x){$f=basename((string)$x['text']);if(strpos($f,'chat_')===0)@unlink("$UP/$f");}
  run($db,"DELETE FROM msgs WHERE room=?",[$R]);
  run($db,"UPDATE rooms SET clr=clr+1 WHERE id=?",[$R]);
}
// ---- game live: selesai otomatis, hadiah dibagikan, sisa dikembalikan ----
// EXP: VIP mendapat bonus 1,5x
function addexp($db,$uid,$n){
  $n=(int)$n;if($n<=0)return;
  if((int)val($db,"SELECT vip FROM users WHERE id=?",[$uid])>time())$n=(int)ceil($n*1.5);
  run($db,"UPDATE users SET exp=exp+? WHERE id=?",[$n,$uid]);
}
function gdata($g){return json_decode($g['data']??'{}',true)?:[];}
function gfinish($db,$g,$now){
  $st=$db->prepare("UPDATE games SET state='done' WHERE id=? AND state='live'");$st->execute([$g['id']]);
  if($st->rowCount()<1)return;
  $d=gdata($g);$R=(int)$g['room'];
  if($g['kind']==='lucky'){
    $left=(int)($d['left']??0);
    if($left>0)run($db,"UPDATE users SET coins=coins+? WHERE id=?",[$left,$g['uid']]);
    addmsg($db,$R,$g['uid'],'game','🎁 Lucky Box selesai.'.($left>0?" Sisa $left koin dikembalikan.":''));
  }elseif($g['kind']==='bag'){
    $left=(int)($d['left']??0);
    if($left>0){run($db,"UPDATE users SET coins=coins+? WHERE id=?",[$left,$g['uid']]);run($db,"INSERT INTO notifs(uid,text,ts) VALUES(?,?,?)",[$g['uid'],"Lucky Bag selesai. Sisa $left koin dikembalikan.",$now]);}
  }elseif($g['kind']==='quiz'){
    $c=(int)$d['correct'];
    $w=rows($db,"SELECT p.uid,u.name FROM gplay p JOIN users u ON u.id=p.uid WHERE p.game=? AND p.val=? ORDER BY p.ts",[$g['id'],$c]);
    $prize=(int)($d['prize']??0);$each=0;$rest=$prize;
    if($prize>0&&$w){
      $each=intdiv($prize,count($w));
      if($each>0){foreach($w as $x){run($db,"UPDATE users SET coins=coins+? WHERE id=?",[$each,$x['uid']]);run($db,"INSERT INTO notifs(uid,text,ts) VALUES(?,?,?)",[$x['uid'],"Kamu menjawab benar di quiz dan mendapat $each koin",$now]);}}
      $rest=$prize-$each*count($w);
    }
    if($rest>0)run($db,"UPDATE users SET coins=coins+? WHERE id=?",[$rest,$g['uid']]);
    $d['winners']=array_map(function($x){return ['id'=>(int)$x['uid'],'name'=>$x['name']];},array_slice($w,0,30));$d['wcount']=count($w);$d['each']=$each;
    run($db,"UPDATE games SET data=? WHERE id=?",[json_encode($d),$g['id']]);
    $L=['A','B','C','D'];
    addmsg($db,$R,$g['uid'],'game','✅ Jawaban benar: '.($L[$c]??'?').'. '.(count($w)?count($w).' orang menjawab benar'.($each>0?", masing-masing +$each koin":'').'.':'Tidak ada yang menjawab benar.'));
  }
}
function gsweep($db,$R,$now){
  foreach(rows($db,"SELECT * FROM games WHERE room=? AND state='live' AND ends>0 AND ends<=?",[$R,$now]) as $g)gfinish($db,$g,$now);
}
// status online: akun dianggap online bila aktif <60 dtk. VIP yang menyembunyikan status tampil offline, kecuali bagi admin.
function onsql($viewerAdmin,$p='u.'){$n=time();return $viewerAdmin?"({$p}seen>".($n-60).")":"({$p}seen>".($n-60)." AND ({$p}hide_online=0 OR {$p}vip<=$n))";}
function me($db){$t=$_GET['t']??'';if(strlen($t)<20)return null;
  $r=one($db,"SELECT u.* FROM tokens t JOIN users u ON u.id=t.uid WHERE t.tok=? AND u.banned=0",[$t]);
  if($r&&($r['role']??'')!=='admin'&&in_array($r['username'],cfgAdmins(),true)){run($db,"UPDATE users SET role='admin' WHERE id=?",[$r['id']]);$r['role']='admin';}
  return $r;}
function newtok($db,$uid){$t=bin2hex(random_bytes(16));run($db,"INSERT INTO tokens VALUES(?,?)",[$t,$uid]);return $t;}
function addmsg($db,$room,$uid,$kind,$text){run($db,"INSERT INTO msgs(room,uid,kind,text,ts) VALUES(?,?,?,?,?)",[$room,$uid,$kind,mb_substr($text,0,300),time()]);}
function roleOf($db,$room,$uid){if((int)$room['host']===(int)$uid)return 'penyiar';return val($db,"SELECT 1 FROM mgr WHERE room=? AND uid=?",[$room['id'],$uid])?'manajer':'member';}
function b64($s){return rtrim(strtr(base64_encode($s),'+/','-_'),'=');}
function jwt($grant,$id,$name,$ttl=21600){$h=b64(json_encode(['alg'=>'HS256','typ'=>'JWT']));
  $p=b64(json_encode(['iss'=>LK_KEY,'sub'=>(string)$id,'name'=>$name,'nbf'=>time()-10,'exp'=>time()+$ttl,'video'=>$grant]));
  return "$h.$p.".b64(hash_hmac('sha256',"$h.$p",LK_SECRET,true));}
function lkperm($rid,$id,$pub){
  if(!LK_URL)return true; $lr='r'.$rid;
  $body=json_encode(['room'=>$lr,'identity'=>(string)$id,'permission'=>['can_subscribe'=>true,'can_publish'=>$pub,'can_publish_data'=>true]]);
  $url=preg_replace('#^ws#','http',LK_URL).'/twirp/livekit.RoomService/UpdateParticipant';
  $auth='Authorization: Bearer '.jwt(['roomAdmin'=>true,'room'=>$lr],'srv','srv',60);
  if(function_exists('curl_init')){$c=curl_init($url);
    curl_setopt_array($c,[CURLOPT_POST=>1,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>['Content-Type: application/json',$auth],CURLOPT_RETURNTRANSFER=>1,CURLOPT_TIMEOUT=>5]);
    curl_exec($c);return curl_getinfo($c,CURLINFO_HTTP_CODE)===200;}
  @file_get_contents($url,false,stream_context_create(['http'=>['method'=>'POST','timeout'=>5,'ignore_errors'=>1,'content'=>$body,'header'=>"Content-Type: application/json\r\n$auth"]]));
  return isset($http_response_header[0])&&strpos($http_response_header[0],'200')!==false;}

if(isset($_GET['a'])){
  $a=$_GET['a'];$in=$_POST;$now=time();$live=$now-20;$nowms=(int)round(microtime(true)*1000);
  if($a==='reg'||$a==='login'){
    $un=strtolower(trim($in['username']??''));$pw=$in['password']??'';
    if(!preg_match('/^[a-z0-9_]{3,20}$/',$un))out(['err'=>'Username 3-20 karakter: huruf, angka, _']);
    if($a==='reg'){
      if(strlen($pw)<6)out(['err'=>'Password minimal 6 karakter']);
      if(val($db,"SELECT 1 FROM users WHERE username=?",[$un]))out(['err'=>'Username sudah dipakai']);
      $nm=trim(mb_substr($in['name']??'',0,24))?:$un;
      run($db,"INSERT INTO users(username,pass,name) VALUES(?,?,?)",[$un,password_hash($pw,PASSWORD_DEFAULT),$nm]);
      out(['t'=>newtok($db,$db->lastInsertId())]);
    }
    $r=one($db,"SELECT * FROM users WHERE username=?",[$un]);
    if(!$r||!password_verify($pw,$r['pass']))out(['err'=>'Username atau password salah']);
    if(!empty($r['banned']))out(['err'=>'Akun ini diblokir oleh admin']);
    out(['t'=>newtok($db,$r['id'])]);
  }
  $u=me($db);if(!$u)out(['err'=>'login']);
  run($db,"UPDATE users SET seen=? WHERE id=?",[$now,$u['id']]);

  // Heartbeat native Android foreground service (WebView timers can be throttled in background).
  if($a==='heartbeat')out(['ok'=>1]);

  // ---------- PANEL ADMIN (semua aksi adm_* hanya untuk akun ADMIN) ----------
  if(strncmp($a,'adm_',4)===0){
    if(!isAdmin($u))out(['err'=>'Akses ditolak: khusus admin']);
    $uid=(int)($in['uid']??0);
    $tgt=function($id)use($db){$t=one($db,"SELECT * FROM users WHERE id=?",[$id]);if(!$t)out(['err'=>'Akun tidak ditemukan']);return $t;};
    if($a==='adm_stats'){
      $c=function($q,$p=[])use($db){return (int)val($db,$q,$p);};
      out(['s'=>['users'=>$c("SELECT COUNT(*) FROM users"),'online'=>$c("SELECT COUNT(*) FROM users WHERE seen>?",[$now-60]),
        'admins'=>$c("SELECT COUNT(*) FROM users WHERE role='admin'"),'choice'=>$c("SELECT COUNT(*) FROM users WHERE choice=1"),
        'banned'=>$c("SELECT COUNT(*) FROM users WHERE banned=1"),'live'=>$c("SELECT COUNT(*) FROM rooms WHERE live=1"),
        'coins'=>$c("SELECT COALESCE(SUM(coins),0) FROM users"),'gifts'=>$c("SELECT COALESCE(SUM(coins),0) FROM gifts"),
        'msgs'=>$c("SELECT COUNT(*) FROM msgs"),'talks'=>$c("SELECT COUNT(*) FROM talks"),'casts'=>$c("SELECT COUNT(*) FROM casts")]]);
    }
    if($a==='adm_users'){
      $q=trim(str_replace(['%','_'],'',mb_substr($in['q']??'',0,30)));$like='%'.$q.'%';$id=ctype_digit($q)?(int)$q:0;$off=max(0,(int)($in['off']??0));
      $onlyOn=(int)($in['online']??0);
      $rs=rows($db,"SELECT id,username,name,photo,coins,role,choice,banned,seen,(seen>".($now-60).") AS onl,(hide_online=1 AND vip>$now) AS hid FROM users WHERE (?='' OR id=? OR username LIKE ? OR name LIKE ?) AND (?=0 OR seen>?) ORDER BY ".($onlyOn?"seen DESC":"id DESC")." LIMIT 31 OFFSET $off",[$q,$id,$like,$like,$onlyOn,$now-60]);
      $more=count($rs)>30;if($more)array_pop($rs);
      out(['rows'=>$rs,'more'=>$more]);
    }
    if($a==='adm_user'){$t=$tgt($uid);unset($t['pass']);$t['onl']=((int)$t['seen']>$now-60)?1:0;$t['hid']=((int)($t['hide_online']??0)===1&&(int)$t['vip']>$now)?1:0;out(['u'=>$t]);}
    if($a==='adm_coin'){
      $t=$tgt($uid);$m=$in['mode']??'';$amt=(int)($in['amount']??0);
      if(!in_array($m,['add','sub','set'],true)||$amt<0||$amt>1000000000)out(['err'=>'Jumlah koin tidak valid (0 - 1.000.000.000)']);
      $n=$m==='add'?(int)$t['coins']+$amt:($m==='sub'?max(0,(int)$t['coins']-$amt):$amt);$n=min($n,2000000000);
      run($db,"UPDATE users SET coins=? WHERE id=?",[$n,$t['id']]);
      if($m!=='set'&&$amt>0)run($db,"INSERT INTO notifs(uid,text,ts) VALUES(?,?,?)",[$t['id'],'Admin '.($m==='add'?'menambahkan ':'mengurangi ').$amt.' koin pada akunmu',$now]);
      alog($db,$u['id'],'koin '.$m,$t['id'],$t['coins'].' -> '.$n);
      out(['ok'=>1,'coins'=>$n]);
    }
    if($a==='adm_edit'){
      $t=$tgt($uid);$un=strtolower(trim($in['username']??''));
      if(!preg_match('/^[a-z0-9_]{3,20}$/',$un))out(['err'=>'Username 3-20 karakter: huruf, angka, _']);
      if($un!==$t['username']&&val($db,"SELECT 1 FROM users WHERE username=? AND id<>?",[$un,$t['id']]))out(['err'=>'Username sudah dipakai']);
      $nm=trim(mb_substr($in['name']??'',0,24))?:$un;
      run($db,"UPDATE users SET username=?,name=?,bio=? WHERE id=?",[$un,$nm,trim(mb_substr($in['bio']??'',0,120)),$t['id']]);
      alog($db,$u['id'],'edit profil',$t['id'],'@'.$t['username'].' -> @'.$un.', nama: '.$nm);
      out(['ok'=>1]);
    }
    if($a==='adm_setid'){
      $t=$tgt($uid);$old=(int)$t['id'];$new=(int)($in['newid']??0);
      if($new<1||$new>2000000000)out(['err'=>'ID baru harus angka 1 - 2.000.000.000']);
      if($new===$old)out(['err'=>'ID baru sama dengan ID lama']);
      if(val($db,"SELECT 1 FROM users WHERE id=?",[$new]))out(['err'=>'ID tersebut sudah dipakai akun lain']);
      $db->beginTransaction();
      try{
        foreach(['users'=>['id'],'tokens'=>['uid'],'rooms'=>['host'],'msgs'=>['uid'],'mgr'=>['uid'],'bans'=>['uid'],'visits'=>['uid'],'casts'=>['uid'],'talks'=>['uid'],'gifts'=>['uid','host'],'notifs'=>['uid'],'dms'=>['a','b'],'follows'=>['a','b'],'notices'=>['uid'],'tracks'=>['uid'],'games'=>['uid'],'gplay'=>['uid'],'admlog'=>['admin','target']] as $tb=>$cols)
          foreach($cols as $col)$db->exec("UPDATE $tb SET $col=$new WHERE $col=$old");
        $db->commit();
      }catch(Exception $e){$db->rollBack();out(['err'=>'Gagal mengganti ID (data bentrok), tidak ada yang diubah']);}
      alog($db,$u['id'],'ganti ID',$new,'ID '.$old.' -> '.$new.' (@'.$t['username'].')');
      out(['ok'=>1,'id'=>$new]);
    }
    if($a==='adm_pass'){
      $t=$tgt($uid);$pw=(string)($in['password']??'');if(strlen($pw)<6)out(['err'=>'Password minimal 6 karakter']);
      run($db,"UPDATE users SET pass=? WHERE id=?",[password_hash($pw,PASSWORD_DEFAULT),$t['id']]);
      if((int)$t['id']!==(int)$u['id'])run($db,"DELETE FROM tokens WHERE uid=?",[$t['id']]);
      alog($db,$u['id'],'reset password',$t['id'],'@'.$t['username']);
      out(['ok'=>1]);
    }
    if($a==='adm_badge'){
      $t=$tgt($uid);$v=($in['choice']??'')==='1'?1:0;
      run($db,"UPDATE users SET choice=? WHERE id=?",[$v,$t['id']]);
      if($v)run($db,"INSERT INTO notifs(uid,text,ts) VALUES(?,?,?)",[$t['id'],'Selamat! Akunmu mendapat badge CHOICE',$now]);
      alog($db,$u['id'],$v?'beri CHOICE':'cabut CHOICE',$t['id'],'@'.$t['username']);
      out(['ok'=>1]);
    }
    if($a==='adm_role'){
      $t=$tgt($uid);$r=($in['role']??'')==='admin'?'admin':'user';
      if((int)$t['id']===(int)$u['id'])out(['err'=>'Tidak bisa mengubah peran akun sendiri']);
      if($r==='user'&&in_array($t['username'],cfgAdmins(),true))out(['err'=>'Admin ini diatur lewat ADMIN_USERS di config.php']);
      run($db,"UPDATE users SET role=? WHERE id=?",[$r,$t['id']]);
      if($r==='admin')run($db,"INSERT INTO notifs(uid,text,ts) VALUES(?,?,?)",[$t['id'],'Kamu sekarang menjadi ADMIN',$now]);
      alog($db,$u['id'],$r==='admin'?'jadikan admin':'cabut admin',$t['id'],'@'.$t['username']);
      out(['ok'=>1]);
    }
    if($a==='adm_ban'){
      $t=$tgt($uid);
      if((int)$t['id']===(int)$u['id']||isAdmin($t))out(['err'=>'Akun admin tidak bisa diblokir']);
      $on=($in['on']??'')==='1'?1:0;
      run($db,"UPDATE users SET banned=? WHERE id=?",[$on,$t['id']]);
      if($on){
        run($db,"DELETE FROM tokens WHERE uid=?",[$t['id']]);
        run($db,"UPDATE users SET room_id=0,mic=0 WHERE id=?",[$t['id']]);
        run($db,"UPDATE rooms SET live=0,ended=? WHERE host=? AND live=1",[$now,$t['id']]);
      }
      alog($db,$u['id'],$on?'blokir akun':'buka blokir',$t['id'],'@'.$t['username']);
      out(['ok'=>1]);
    }
    if($a==='adm_deluser'){
      $t=$tgt($uid);$id=(int)$t['id'];
      if($id===(int)$u['id']||isAdmin($t))out(['err'=>'Akun admin tidak bisa dihapus']);
      $files=[];
      foreach(["SELECT file AS f FROM casts WHERE uid=$id","SELECT file AS f FROM tracks WHERE uid=$id","SELECT img AS f FROM dms WHERE (a=$id OR b=$id) AND img<>''","SELECT text AS f FROM msgs WHERE uid=$id AND kind='img'","SELECT cover AS f FROM rooms WHERE host=$id AND cover<>''"] as $q)
        foreach(rows($db,$q) as $x)$files[]=$x['f'];
      if((string)$t['photo']!=='')$files[]=$t['photo'];
      $db->beginTransaction();
      try{
        $rs="(SELECT id FROM rooms WHERE host=$id)";
        foreach(["DELETE FROM gplay WHERE game IN (SELECT id FROM games WHERE room IN $rs OR uid=$id)","DELETE FROM games WHERE room IN $rs OR uid=$id","DELETE FROM gplay WHERE uid=$id","DELETE FROM msgs WHERE room IN $rs","DELETE FROM mgr WHERE room IN $rs","DELETE FROM bans WHERE room IN $rs","DELETE FROM visits WHERE room IN $rs","DELETE FROM rmusic WHERE room IN $rs",
          "UPDATE users SET room_id=0,mic=0 WHERE room_id IN $rs","DELETE FROM rmusic WHERE track IN (SELECT id FROM tracks WHERE uid=$id)","DELETE FROM rooms WHERE host=$id",
          "DELETE FROM msgs WHERE uid=$id","DELETE FROM mgr WHERE uid=$id","DELETE FROM bans WHERE uid=$id","DELETE FROM visits WHERE uid=$id","DELETE FROM casts WHERE uid=$id",
          "DELETE FROM talks WHERE uid=$id","DELETE FROM gifts WHERE uid=$id OR host=$id","DELETE FROM notifs WHERE uid=$id","DELETE FROM dms WHERE a=$id OR b=$id",
          "DELETE FROM follows WHERE a=$id OR b=$id","DELETE FROM notices WHERE uid=$id","DELETE FROM tracks WHERE uid=$id","DELETE FROM tokens WHERE uid=$id","DELETE FROM users WHERE id=$id"] as $q)$db->exec($q);
        $db->commit();
      }catch(Exception $e){$db->rollBack();out(['err'=>'Gagal menghapus akun']);}
      foreach($files as $f){$f=basename((string)$f);if($f!=='')@unlink("$UP/$f");}
      alog($db,$u['id'],'hapus akun',$id,'@'.$t['username'].' ('.$t['name'].')');
      out(['ok'=>1]);
    }
    if($a==='adm_rooms'){
      out(['rows'=>rows($db,"SELECT r.id,r.title,r.likes,r.frozen,r.started,u.name AS host,(SELECT COUNT(*) FROM users x WHERE x.room_id=r.id AND x.seen>?) AS listeners FROM rooms r JOIN users u ON u.id=r.host WHERE r.live=1 ORDER BY r.id DESC",[$live])]);
    }
    if($a==='adm_endroom'||$a==='adm_clearroom'){
      $r=one($db,"SELECT * FROM rooms WHERE id=?",[(int)($in['id']??0)]);if(!$r)out(['err'=>'Room tidak ditemukan']);
      if($a==='adm_endroom'){run($db,"UPDATE rooms SET live=0,ended=? WHERE id=?",[$now,$r['id']]);alog($db,$u['id'],'akhiri live',$r['id'],$r['title']);}
      else{clearChat($db,$UP,(int)$r['id']);addmsg($db,(int)$r['id'],$u['id'],'join','Chat dibersihkan oleh admin.');alog($db,$u['id'],'clear chat room',$r['id'],$r['title']);}
      out(['ok'=>1]);
    }
    if($a==='adm_talks')out(['rows'=>rows($db,"SELECT t.id,t.text,t.ts,u.id AS uid,u.name FROM talks t JOIN users u ON u.id=t.uid ORDER BY t.id DESC LIMIT 60")]);
    if($a==='adm_talkdel'){
      $x=one($db,"SELECT * FROM talks WHERE id=?",[(int)($in['id']??0)]);if(!$x)out(['err'=>'Konten tidak ditemukan']);
      run($db,"DELETE FROM talks WHERE id=?",[$x['id']]);alog($db,$u['id'],'hapus TALK',$x['uid'],mb_substr((string)$x['text'],0,80));out(['ok'=>1]);
    }
    if($a==='adm_casts')out(['rows'=>rows($db,"SELECT c.id,c.title,c.file,c.ts,u.id AS uid,u.name FROM casts c JOIN users u ON u.id=c.uid ORDER BY c.id DESC LIMIT 60")]);
    if($a==='adm_castdel'){
      $x=one($db,"SELECT * FROM casts WHERE id=?",[(int)($in['id']??0)]);if(!$x)out(['err'=>'Konten tidak ditemukan']);
      @unlink("$UP/".basename((string)$x['file']));run($db,"DELETE FROM casts WHERE id=?",[$x['id']]);alog($db,$u['id'],'hapus CAST',$x['uid'],(string)$x['title']);out(['ok'=>1]);
    }
    if($a==='adm_broadcast'){
      $t=trim(mb_substr($in['text']??'',0,200));if($t==='')out(['err'=>'Tulis pengumuman terlebih dahulu']);
      run($db,"INSERT INTO notifs(uid,text,ts) SELECT id,?,? FROM users WHERE banned=0",['Admin: '.$t,$now]);
      alog($db,$u['id'],'siaran',0,$t);
      out(['ok'=>1,'n'=>(int)val($db,"SELECT COUNT(*) FROM users WHERE banned=0")]);
    }
    if($a==='adm_log')out(['rows'=>rows($db,"SELECT l.act,l.target,l.detail,l.ts,u.name AS admin FROM admlog l LEFT JOIN users u ON u.id=l.admin ORDER BY l.id DESC LIMIT 100")]);
    out(['err'=>'Aksi admin tidak dikenal']);
  }

  // ---------- akun & beranda ----------
  if($a==='me')out(['me'=>['id'=>$u['id'],'username'=>$u['username'],'name'=>$u['name'],'bio'=>$u['bio'],'photo'=>$u['photo'],'coins'=>(int)$u['coins'],'exp'=>(int)$u['exp'],'vip'=>(int)$u['vip']>$now?(int)$u['vip']:0,'hide'=>(int)($u['hide_online']??0),'admin'=>isAdmin($u)?1:0,'badge'=>ubadge($u)]]);
  if($a==='hideon'){
    if((int)$u['vip']<=$now)out(['err'=>'Fitur sembunyikan online khusus VIP']);
    run($db,"UPDATE users SET hide_online=? WHERE id=?",[($in['on']??'')==='1'?1:0,$u['id']]);out(['ok'=>1]);
  }
  if($a==='upd'){run($db,"UPDATE users SET name=?, bio=? WHERE id=?",[trim(mb_substr($in['name']??'',0,24))?:$u['username'],trim(mb_substr($in['bio']??'',0,120)),$u['id']]);out(['ok'=>1]);}
  if($a==='photo'||$a==='cover'){
    $f=$_FILES['f']??null;if(!$f||$f['error'])out(['err'=>'Upload gagal']);
    if($f['size']>1500000)out(['err'=>'Maksimal 1,5 MB']);
    $i=@getimagesize($f['tmp_name']);$ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$i['mime']??'']??null;
    if(!$ext)out(['err'=>'Format harus JPG, PNG, atau WebP']);
    $fn=bin2hex(random_bytes(8)).'.'.$ext;move_uploaded_file($f['tmp_name'],"$UP/$fn");
    if($a==='photo')run($db,"UPDATE users SET photo=? WHERE id=?",[$fn,$u['id']]);
    else run($db,"UPDATE rooms SET cover=? WHERE id=? AND host=?",[$fn,(int)($in['room']??0),$u['id']]);
    out(['ok'=>1,'file'=>$fn]);
  }
  if($a==='claim'){if($u['claim']>$now-86400)out(['err'=>'Klaim harian sudah diambil, coba lagi besok']);
    $bn=((int)$u['vip']>$now)?100:50;run($db,"UPDATE users SET coins=coins+?, claim=? WHERE id=?",[$bn,$now,$u['id']]);addexp($db,$u['id'],10);out(['ok'=>1,'bonus'=>$bn]);}
  // ---------- CAST: rekaman suara pendek ----------
  if($a==='casts'){
    out(['casts'=>rows($db,"SELECT c.id,c.title,c.file,c.duration,c.ts,u.id AS uid,u.name,u.photo,".bsql()." AS badge FROM casts c JOIN users u ON u.id=c.uid ORDER BY c.id DESC LIMIT 60")]);
  }
  if($a==='castadd'){
    $f=$_FILES['f']??null;$title=trim(mb_substr($in['title']??'Rekaman suara',0,60));
    if(!$f||$f['error'])out(['err'=>'File rekaman tidak ditemukan']);
    if($f['size']>12000000)out(['err'=>'Maksimal rekaman 12 MB']);
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $types=['audio/webm'=>'webm','video/webm'=>'webm','audio/ogg'=>'ogg','application/ogg'=>'ogg','audio/mpeg'=>'mp3','audio/mp4'=>'m4a','video/mp4'=>'m4a','audio/wav'=>'wav','audio/x-wav'=>'wav'];
    $ext=$types[$mime]??null;if(!$ext)out(['err'=>'Format audio tidak didukung']);
    $fn='cast_'.bin2hex(random_bytes(10)).'.'.$ext;
    if(!move_uploaded_file($f['tmp_name'],"$UP/$fn"))out(['err'=>'Gagal menyimpan rekaman']);
    run($db,"INSERT INTO casts(uid,title,file,duration,ts) VALUES(?,?,?,?,?)",[$u['id'],$title?:'Rekaman suara',$fn,max(0,min(3600,(int)($in['duration']??0))),$now]);
    out(['ok'=>1]);
  }
  if($a==='castdel'){
    $c=one($db,"SELECT * FROM casts WHERE id=? AND uid=?",[(int)($in['id']??0),$u['id']]);
    if(!$c)out(['err'=>'Rekaman tidak ditemukan']);
    @unlink("$UP/".$c['file']);run($db,"DELETE FROM casts WHERE id=?",[$c['id']]);out(['ok'=>1]);
  }
  // ---------- TALK: feed obrolan publik ----------
  if($a==='talks'){
    out(['talks'=>rows($db,"SELECT t.id,t.text,t.ts,u.id AS uid,u.name,u.photo,".bsql()." AS badge FROM talks t JOIN users u ON u.id=t.uid ORDER BY t.id DESC LIMIT 80")]);
  }
  if($a==='talkadd'){
    $t=trim(mb_substr($in['text']??'',0,500));if($t==='')out(['err'=>'Tulis pesan terlebih dahulu']);
    run($db,"INSERT INTO talks(uid,text,ts) VALUES(?,?,?)",[$u['id'],$t,$now]);out(['ok'=>1]);
  }
  if($a==='talkdel'){
    run($db,"DELETE FROM talks WHERE id=? AND uid=?",[(int)($in['id']??0),$u['id']]);out(['ok'=>1]);
  }
  if($a==='rooms'){
    run($db,"UPDATE rooms SET live=0,ended=? WHERE live=1 AND host IN (SELECT id FROM users WHERE seen<?)",[$now,$now-180]);
    foreach(rows($db,"SELECT * FROM games WHERE state='live' AND ends>0 AND ends<=?",[$now]) as $gx)gfinish($db,$gx,$now);
    out(['rooms'=>rows($db,"SELECT r.id,r.title,r.cover,r.likes,u.name AS host,u.photo AS hphoto,u.id AS hid,".bsql()." AS badge,(SELECT COUNT(*) FROM games gg WHERE gg.room=r.id AND gg.kind='bag' AND gg.state='live' AND gg.ends>$now) AS bags,
      (SELECT COUNT(*) FROM users x WHERE x.room_id=r.id AND x.seen>?) AS listeners,
      (SELECT COUNT(*) FROM users x WHERE x.room_id=r.id AND x.mic=2 AND x.seen>?) AS speakers
      FROM rooms r JOIN users u ON u.id=r.host WHERE r.live=1 ORDER BY (u.choice=1) DESC,listeners DESC,r.id DESC",[$live,$live])]);
  }
  if($a==='create'){
    $t=trim(mb_substr($in['title']??'',0,40));if($t==='')out(['err'=>'Isi judul live']);
    run($db,"UPDATE rooms SET live=0,ended=? WHERE live=1 AND host=?",[$now,$u['id']]);
    run($db,"INSERT INTO rooms(host,title,started,welcome) VALUES(?,?,?,?)",[$u['id'],$t,$now,trim(mb_substr($in['welcome']??'',0,200))]);
    $nid=(int)$db->lastInsertId();
    run($db,"INSERT INTO notifs(uid,text,ts) SELECT a,?,? FROM follows WHERE b=? LIMIT 500",[$u['name'].' sedang live: '.$t,$now,$u['id']]);
    out(['id'=>$nid]);
  }
  if($a==='enter'){
    $r=one($db,"SELECT * FROM rooms WHERE id=? AND live=1",[(int)($in['room']??0)]);if(!$r)out(['err'=>'Live sudah berakhir']);
    if(!isAdmin($u)&&val($db,"SELECT 1 FROM bans WHERE room=? AND uid=? AND until>?",[$r['id'],$u['id'],$now]))out(['err'=>'Kamu diblokir dari live ini']);
    $was=(int)$u['room_id']===(int)$r['id'];
    run($db,"UPDATE users SET room_id=?, mic=? WHERE id=?",[$r['id'],$r['host']==$u['id']?2:0,$u['id']]);
    run($db,"INSERT OR IGNORE INTO visits VALUES(?,?)",[$r['id'],$u['id']]);
    if(!$was)addmsg($db,$r['id'],$u['id'],'join',((int)$u['vip']>$now?'⭐ VIP ':'').$u['name'].' bergabung.');
    out(['ok'=>1]);
  }
  if($a==='exit'){run($db,"UPDATE users SET room_id=0, mic=0 WHERE id=?",[$u['id']]);out(['ok'=>1]);}

  // ---------- pencarian, profil, peringkat, notifikasi, DM ----------
  if($a==='vipbuy'){
    $plans=[[7,150],[30,500],[90,1200]];$p=$plans[(int)($in['plan']??-1)]??null;if(!$p)out(['err'=>'Paket tidak valid']);
    if((int)$u['coins']<$p[1])out(['err'=>'Koin tidak cukup']);
    $until=max($now,(int)$u['vip'])+$p[0]*86400;
    run($db,"UPDATE users SET coins=coins-?,vip=? WHERE id=?",[$p[1],$until,$u['id']]);
    run($db,"INSERT INTO notifs(uid,text,ts) VALUES(?,?,?)",[$u['id'],'VIP aktif sampai '.date('d/m/Y',$until),$now]);
    out(['ok'=>1,'until'=>$until]);
  }
  if($a==='badges')out(['n'=>(int)val($db,"SELECT COUNT(*) FROM notifs WHERE uid=? AND rd=0",[$u['id']]),'d'=>(int)val($db,"SELECT COUNT(*) FROM dms WHERE b=? AND rd=0",[$u['id']])]);
  if($a==='search'){
    $q=trim(str_replace(['%','_'],'',mb_substr($in['q']??'',0,30)));if($q==='')out(['rows'=>[]]);
    $id=ctype_digit($q)?(int)$q:0;$like='%'.$q.'%';
    out(['rows'=>rows($db,"SELECT u.id,u.username,u.name,u.photo,u.bio,u.exp,(u.vip>$now) AS vip,".bsql()." AS badge,".onsql(isAdmin($u))." AS onl,(SELECT r.id FROM rooms r WHERE r.host=u.id AND r.live=1 AND u.seen>? ORDER BY r.id DESC LIMIT 1) AS live FROM users u WHERE u.id=? OR u.username LIKE ? OR u.name LIKE ? ORDER BY (u.id=?) DESC,u.name LIMIT 30",[$now-60,$id,$like,$like,$id])]);
  }
  if($a==='profile'){
    $id=(int)($in['uid']??0);
    $p=one($db,"SELECT id,username,name,bio,photo,exp,vip,".bsql('')." AS badge FROM users WHERE id=?",[$id]);if(!$p)out(['err'=>'Akun tidak ditemukan']);
    $p['exp']=(int)$p['exp'];$p['vip']=((int)$p['vip']>$now)?(int)$p['vip']:0;
    $ox=one($db,"SELECT ".onsql(isAdmin($u),'')." AS onl,(hide_online=1 AND vip>$now) AS hid FROM users WHERE id=?",[$id]);
    $p['onl']=($id===(int)$u['id'])?1:(int)$ox['onl'];$p['hid']=isAdmin($u)?(int)$ox['hid']:0;
    $p['live']=(int)val($db,"SELECT r.id FROM rooms r JOIN users x ON x.id=r.host WHERE r.host=? AND r.live=1 AND x.seen>? ORDER BY r.id DESC LIMIT 1",[$p['id'],$now-60]);
    $p['fans']=(int)val($db,"SELECT COUNT(*) FROM follows WHERE b=?",[$id]);
    $p['following']=(int)val($db,"SELECT COUNT(*) FROM follows WHERE a=?",[$id]);
    $p['isfan']=val($db,"SELECT 1 FROM follows WHERE a=? AND b=?",[$u['id'],$id])?1:0;
    $p['notice']=(string)val($db,"SELECT text FROM notices WHERE uid=? ORDER BY id DESC LIMIT 1",[$id]);
    $p['topfans']=rows($db,"SELECT x.id,x.name,x.photo,SUM(g.coins) AS pts FROM gifts g JOIN users x ON x.id=g.uid WHERE g.host=? GROUP BY g.uid ORDER BY pts DESC,x.id LIMIT 3",[$id]);
    if($id===(int)$u['id'])$p['coins']=(int)$u['coins'];
    out(['p'=>$p]);
  }
  if($a==='follow'){
    $o=(int)($in['uid']??0);
    if($o===(int)$u['id']||!val($db,"SELECT 1 FROM users WHERE id=?",[$o]))out(['err'=>'Akun tidak valid']);
    if(($in['on']??'1')==='1'){
      if(!val($db,"SELECT 1 FROM follows WHERE a=? AND b=?",[$u['id'],$o])){
        run($db,"INSERT INTO follows(a,b,ts) VALUES(?,?,?)",[$u['id'],$o,$now]);
        run($db,"INSERT INTO notifs(uid,text,ts) VALUES(?,?,?)",[$o,$u['name'].' mulai mengikutimu',$now]);
      }
    }else run($db,"DELETE FROM follows WHERE a=? AND b=?",[$u['id'],$o]);
    out(['ok'=>1,'fans'=>(int)val($db,"SELECT COUNT(*) FROM follows WHERE b=?",[$o])]);
  }
  if($a==='fanlist'){
    $o=(int)($in['uid']??0);$fans=($in['kind']??'fans')==='fans';
    $rs=rows($db,"SELECT u.id,u.name,u.username,u.photo,".onsql(isAdmin($u))." AS onl FROM follows f JOIN users u ON u.id=".($fans?'f.a':'f.b')." WHERE ".($fans?'f.b':'f.a')."=? ORDER BY f.ts DESC LIMIT 100",[$o]);
    out(['rows'=>$rs]);
  }
  if($a==='notices')out(['rows'=>rows($db,"SELECT id,text,ts FROM notices WHERE uid=? ORDER BY id DESC LIMIT 50",[(int)($in['uid']??0)])]);
  if($a==='noticeadd'){
    $t=trim(mb_substr($in['text']??'',0,300));if($t==='')out(['err'=>'Tulis pengumuman terlebih dahulu']);
    run($db,"INSERT INTO notices(uid,text,ts) VALUES(?,?,?)",[$u['id'],$t,$now]);out(['ok'=>1]);
  }
  if($a==='noticedel'){run($db,"DELETE FROM notices WHERE id=? AND uid=?",[(int)($in['id']??0),$u['id']]);out(['ok'=>1]);}
  if($a==='ucasts')out(['casts'=>rows($db,"SELECT id,title,file,duration,ts,uid FROM casts WHERE uid=? ORDER BY id DESC LIMIT 40",[(int)($in['uid']??0)])]);
  if($a==='utalks')out(['talks'=>rows($db,"SELECT id,text,ts,uid FROM talks WHERE uid=? ORDER BY id DESC LIMIT 40",[(int)($in['uid']??0)])]);
  if($a==='dmimg'){
    $o=(int)($in['uid']??0);
    if($o===(int)$u['id']||!val($db,"SELECT 1 FROM users WHERE id=?",[$o]))out(['err'=>'Akun tidak valid']);
    [$fn,$er]=saveImg($_FILES['f']??null,$UP,'dm_');if(!$fn)out(['err'=>$er]);
    run($db,"INSERT INTO dms(a,b,text,img,ts) VALUES(?,?,?,?,?)",[$u['id'],$o,'',$fn,$now]);out(['ok'=>1]);
  }
  if($a==='board'||$a==='boardhub'||$a==='homeinfo'){
    $cats=['likes_gifts','likes','time','cast','spend','chat'];
    $rng=function($per)use($now){
      $day=mktime(0,0,0,(int)date('n',$now),(int)date('j',$now),(int)date('Y',$now));
      if($per==='week'){$st=$day-((int)date('N',$now)-1)*86400;return [$st,$now+1,$st-7*86400,$st];}
      if($per==='month'){$st=mktime(0,0,0,(int)date('n',$now),1,(int)date('Y',$now));return [$st,$now+1,mktime(0,0,0,(int)date('n',$now)-1,1,(int)date('Y',$now)),$st];}
      return [$day,$now+1,$day-86400,$day];
    };
    $top=function($cat,$f,$t,$lim)use($db,$now){
      $lim=(int)$lim;$sel="SELECT u.id,u.name,u.username,u.photo,".bsql()." AS badge,";
      switch($cat){
        case 'likes_gifts':return rows($db,"$sel t.v AS pts FROM (SELECT host AS id,SUM(v) AS v FROM (SELECT host,likes AS v FROM rooms WHERE started>=? AND started<? UNION ALL SELECT host,coins AS v FROM gifts WHERE ts>=? AND ts<?) GROUP BY host) t JOIN users u ON u.id=t.id WHERE t.v>0 ORDER BY pts DESC,u.id LIMIT $lim",[$f,$t,$f,$t]);
        case 'likes':return rows($db,"$sel SUM(r.likes) AS pts FROM rooms r JOIN users u ON u.id=r.host WHERE r.started>=? AND r.started<? GROUP BY u.id HAVING pts>0 ORDER BY pts DESC,u.id LIMIT $lim",[$f,$t]);
        case 'time':return rows($db,"$sel SUM(MAX(0,(CASE WHEN r.ended>0 THEN r.ended WHEN r.live=1 THEN ? ELSE r.started END)-r.started)) AS pts FROM rooms r JOIN users u ON u.id=r.host WHERE r.started>=? AND r.started<? GROUP BY u.id HAVING pts>0 ORDER BY pts DESC,u.id LIMIT $lim",[$now,$f,$t]);
        case 'cast':return rows($db,"$sel COUNT(*) AS pts FROM casts c JOIN users u ON u.id=c.uid WHERE c.ts>=? AND c.ts<? GROUP BY u.id ORDER BY pts DESC,u.id LIMIT $lim",[$f,$t]);
        case 'spend':return rows($db,"$sel SUM(g.coins) AS pts FROM gifts g JOIN users u ON u.id=g.uid WHERE g.ts>=? AND g.ts<? GROUP BY u.id ORDER BY pts DESC,u.id LIMIT $lim",[$f,$t]);
        case 'chat':return rows($db,"$sel COUNT(*) AS pts FROM msgs m JOIN users u ON u.id=m.uid WHERE m.kind='chat' AND m.ts>=? AND m.ts<? GROUP BY u.id ORDER BY pts DESC,u.id LIMIT $lim",[$f,$t]);
      }
      return [];
    };
    if($a==='boardhub'){
      [$f,$t]=$rng('day');$o=[];
      foreach($cats as $c){$o[$c]=$top($c,$f,$t,3);foreach($o[$c] as &$r)$r['pts']=(int)$r['pts'];unset($r);}
      out(['top'=>$o]);
    }
    if($a==='homeinfo'){
      $ban=rows($db,"SELECT r.id,r.title,r.cover,u.name AS host,u.photo AS hphoto,r.likes,(SELECT COUNT(*) FROM games gg WHERE gg.room=r.id AND gg.kind='bag' AND gg.state='live' AND gg.ends>$now) AS bags,(SELECT COUNT(*) FROM users x WHERE x.room_id=r.id AND x.seen>?) AS listeners FROM rooms r JOIN users u ON u.id=r.host WHERE r.live=1 AND u.seen>? ORDER BY r.likes DESC,listeners DESC,r.id DESC LIMIT 5",[$live,$now-60]);
      $dj=[];foreach(['day','week','month'] as $pp){[$f,$t]=$rng($pp);$dj=$top('likes_gifts',$f,$t,3);if($dj)break;}
      foreach($dj as &$r)$r['pts']=(int)$r['pts'];unset($r);
      out(['banners'=>$ban,'dj'=>$dj,'bags'=>(int)val($db,"SELECT COUNT(*) FROM games WHERE kind='bag' AND state='live' AND ends>?",[$now])]);
    }
    $cat=$in['cat']??'';if(!in_array($cat,$cats,true))out(['err'=>'Kategori tidak dikenal']);
    [$f,$t,$pf,$pt]=$rng($in['per']??'day');
    $rs=$top($cat,$f,$t,50);$pr=[];
    foreach($top($cat,$pf,$pt,200) as $i=>$r)$pr[(int)$r['id']]=$i+1;
    foreach($rs as $i=>&$r){$r['pts']=(int)$r['pts'];$r['chg']=isset($pr[(int)$r['id']])?$pr[(int)$r['id']]-($i+1):null;}unset($r);
    out(['rows'=>$rs]);
  }
  if($a==='notifs'){
    $rs=rows($db,"SELECT id,text,ts,rd FROM notifs WHERE uid=? ORDER BY id DESC LIMIT 50",[$u['id']]);
    run($db,"UPDATE notifs SET rd=1 WHERE uid=? AND rd=0",[$u['id']]);out(['rows'=>$rs]);
  }
  if($a==='dmlist'){
    $rs=rows($db,"SELECT CASE WHEN a=? THEN b ELSE a END AS other,MAX(id) AS mid,SUM(b=? AND rd=0) AS unread FROM dms WHERE a=? OR b=? GROUP BY other ORDER BY mid DESC LIMIT 50",[$u['id'],$u['id'],$u['id'],$u['id']]);
    $res=[];
    foreach($rs as $r){
      $m=one($db,"SELECT text,img,ts,a FROM dms WHERE id=?",[$r['mid']]);$o=one($db,"SELECT id,name,username,photo FROM users WHERE id=?",[$r['other']]);
      if(!$m||!$o)continue;
      $res[]=['u'=>$o,'text'=>$m['text']!==''?$m['text']:'📷 Foto','ts'=>(int)$m['ts'],'mine'=>(int)$m['a']===(int)$u['id'],'unread'=>(int)$r['unread']];
    }
    out(['rows'=>$res]);
  }
  if($a==='dmget'){
    $o=(int)($in['uid']??0);$since=(int)($in['since']??0);
    $p=one($db,"SELECT id,name,username,photo FROM users WHERE id=?",[$o]);if(!$p)out(['err'=>'Akun tidak ditemukan']);
    $ms=rows($db,"SELECT id,a,text,img,ts FROM dms WHERE id>? AND ((a=? AND b=?) OR (a=? AND b=?)) ORDER BY id DESC LIMIT 100",[$since,$u['id'],$o,$o,$u['id']]);
    run($db,"UPDATE dms SET rd=1 WHERE a=? AND b=? AND rd=0",[$o,$u['id']]);
    out(['p'=>$p,'msgs'=>array_reverse($ms)]);
  }
  if($a==='dmsend'){
    $o=(int)($in['uid']??0);$t=trim(mb_substr($in['text']??'',0,500));
    if($t==='')out(['ok'=>0]);
    if($o===(int)$u['id'])out(['err'=>'Tidak bisa mengirim pesan ke diri sendiri']);
    if(!val($db,"SELECT 1 FROM users WHERE id=?",[$o]))out(['err'=>'Akun tidak ditemukan']);
    run($db,"INSERT INTO dms(a,b,text,ts) VALUES(?,?,?,?)",[$u['id'],$o,$t,$now]);out(['ok'=>1]);
  }

  // ---------- LUCKY BAG global: dikirim dari luar room, muncul sebagai banner di semua room ----------
  if($a==='bag_list'){
    foreach(rows($db,"SELECT * FROM games WHERE kind='bag' AND state='live' AND ends<=?",[$now]) as $gx)gfinish($db,$gx,$now);
    $out=[];
    foreach(rows($db,"SELECT g.id,g.uid,g.room,rm.title AS rtitle,g.ends,g.data,u.name,u.photo,u.exp,(u.vip>$now) AS vip,".bsql()." AS badge FROM games g JOIN users u ON u.id=g.uid JOIN rooms rm ON rm.id=g.room WHERE g.kind='bag' AND g.state='live' ORDER BY g.id DESC LIMIT 10") as $g){
      $d=gdata($g);$gp=one($db,"SELECT val FROM gplay WHERE game=? AND uid=?",[$g['id'],$u['id']]);
      $cl=rows($db,"SELECT u.name,p.val AS amt FROM gplay p JOIN users u ON u.id=p.uid WHERE p.game=? ORDER BY p.ts LIMIT 20",[$g['id']]);
      $out[]=['id'=>(int)$g['id'],'room'=>(int)$g['room'],'rtitle'=>$g['rtitle'],'by'=>$g['name'],'photo'=>$g['photo'],'exp'=>(int)$g['exp'],'vip'=>(int)$g['vip'],'badge'=>$g['badge'],'ends'=>(int)$g['ends'],
        'total'=>(int)($d['total']??0),'slots'=>(int)($d['slots']??0),'ls'=>(int)($d['left_slots']??0),'mine'=>(int)$g['uid']===(int)$u['id']?1:0,'got'=>$gp?(int)$gp['val']:-1,'claims'=>$cl];
    }
    out(['bags'=>$out,'now'=>$now,'coins'=>(int)val($db,"SELECT coins FROM users WHERE id=?",[$u['id']])]);
  }
  if($a==='bag_send'){
    $br=$u['room_id']?one($db,"SELECT id,title FROM rooms WHERE id=? AND live=1",[$u['room_id']]):null;
    if(!$br)out(['err'=>'Lucky Bag hanya bisa dilempar dari dalam room yang sedang live']);
    $total=(int)($in['total']??0);$slots=(int)($in['slots']??0);
    if($total<100||$total>1000000)out(['err'=>'Total koin 100 – 1.000.000']);
    if($slots<1||$slots>100)out(['err'=>'Jumlah penerima 1 – 100']);
    if($slots>$total)out(['err'=>'Penerima tidak boleh melebihi total koin']);
    if((int)val($db,"SELECT COUNT(*) FROM games WHERE kind='bag' AND state='live' AND uid=?",[$u['id']])>=2)out(['err'=>'Maksimal 2 Lucky Bag aktif sekaligus']);
    $st=$db->prepare("UPDATE users SET coins=coins-? WHERE id=? AND coins>=?");$st->execute([$total,$u['id'],$total]);
    if($st->rowCount()<1)out(['err'=>'Koin tidak cukup']);
    run($db,"INSERT INTO games(room,kind,uid,title,data,state,created,ends) VALUES(?,'bag',?,'Lucky Bag',?,'live',?,?)",[$br['id'],$u['id'],json_encode(['total'=>$total,'slots'=>$slots,'left'=>$total,'left_slots'=>$slots]),$now,$now+180]);
    $nid=(int)$db->lastInsertId();
    addmsg($db,(int)$br['id'],$u['id'],'game','🧧 '.$u['name'].' melempar Lucky Bag '.$total.' koin untuk '.$slots.' orang! Buka menu Lucky Bag untuk mengambil.');
    out(['ok'=>1,'id'=>$nid]);
  }
  if($a==='bag_claim'){
    $gid=(int)($in['id']??0);$err=null;$amt=0;
    $db->exec('BEGIN IMMEDIATE');
    try{
      $g=one($db,"SELECT * FROM games WHERE id=? AND kind='bag'",[$gid]);
      if(!$g||$g['state']!=='live'||(int)$g['ends']<=$now)$err='Lucky Bag sudah berakhir';
      elseif((int)$g['uid']===(int)$u['id'])$err='Kamu tidak bisa mengambil Lucky Bag sendiri';
      elseif((int)$u['room_id']!==(int)$g['room'])$err='Masuk ke room tempat Lucky Bag dilempar dulu';
      elseif(val($db,"SELECT 1 FROM gplay WHERE game=? AND uid=?",[$gid,$u['id']]))$err='Kamu sudah mengambil';
      else{
        $d=gdata($g);$left=(int)$d['left'];$ls=(int)$d['left_slots'];
        if($ls<1||$left<1)$err='Lucky Bag sudah habis';
        else{
          $amt=$ls===1?$left:random_int(1,max(1,min(intdiv($left*2,$ls),$left-($ls-1))));
          $d['left']=$left-$amt;$d['left_slots']=$ls-1;
          run($db,"UPDATE games SET data=? WHERE id=?",[json_encode($d),$gid]);
          run($db,"INSERT INTO gplay(game,uid,val,ts) VALUES(?,?,?,?)",[$gid,$u['id'],$amt,$now]);
          run($db,"UPDATE users SET coins=coins+? WHERE id=?",[$amt,$u['id']]);
          if($d['left_slots']<1)run($db,"UPDATE games SET state='done' WHERE id=?",[$gid]);
        }
      }
      $db->exec('COMMIT');
    }catch(Exception $e){$db->exec('ROLLBACK');out(['err'=>'Coba lagi']);}
    if($err)out(['err'=>$err]);
    addexp($db,$u['id'],2);
    addmsg($db,(int)$g['room'],$u['id'],'game','🎉 '.$u['name'].' mendapat '.$amt.' koin dari Lucky Bag!');
    out(['ok'=>1,'amt'=>$amt]);
  }

  // ---------- di dalam room ----------
  $room=$u['room_id']?one($db,"SELECT * FROM rooms WHERE id=?",[$u['room_id']]):null;
  if(!$room||!$room['live'])out(['ended'=>1]);
  $R=(int)$room['id'];
  if(!isAdmin($u)&&val($db,"SELECT 1 FROM bans WHERE room=? AND uid=? AND until>?",[$R,$u['id'],$now]))out(['err'=>'kicked']);
  $role=roleOf($db,$room,$u['id']);$isHost=$role==='penyiar';$isAdmin=isAdmin($u);$isMod=$role!=='member'||$isAdmin;

  if($a==='lk'){
    if(!LK_URL||!LK_KEY)out(['off'=>1]);
    out(['url'=>LK_URL,'pub'=>$u['mic']==2,'token'=>jwt(['room'=>"r$R",'roomJoin'=>true,'canSubscribe'=>true,'canPublish'=>$u['mic']==2,'canPublishData'=>true],$u['id'],$u['name'])]);
  }
  if($a==='poll'){
    $since=(int)($_GET['since']??0);
    $reset=isset($_GET['clr'])&&(int)$_GET['clr']!==(int)$room['clr'];if($reset)$since=0;
    $ms=rows($db,"SELECT m.id,m.kind,m.text,u.id AS uid,u.name,u.photo,u.exp,(u.vip>$now) AS vip,".bsql()." AS badge FROM msgs m JOIN users u ON u.id=m.uid WHERE m.room=? AND m.id>? ORDER BY m.id LIMIT 100",
      [$R,$since?:max(0,(int)val($db,"SELECT MAX(id) FROM msgs WHERE room=?",[$R])-40)]);
    $mg=array_map('intval',$db->query("SELECT uid FROM mgr WHERE room=$R")->fetchAll(PDO::FETCH_COLUMN));
    foreach($ms as &$m)$m['role']=$m['uid']==$room['host']?'penyiar':(in_array((int)$m['uid'],$mg)?'manajer':'member');unset($m);
    $h=one($db,"SELECT name,photo FROM users WHERE id=?",[$room['host']]);
    $tr=1+(int)val($db,"SELECT COUNT(*) FROM rooms WHERE live=1 AND likes>?",[(int)$room['likes']]);
    gsweep($db,$R,$now);$gsum=[];
    foreach(rows($db,"SELECT id,kind,title,ends,uid,data FROM games WHERE room=? AND state='live' ORDER BY id DESC",[$R]) as $g){
      $gd=gdata($g);
      $gsum[]=['id'=>(int)$g['id'],'kind'=>$g['kind'],'title'=>$g['title'],'ends'=>(int)$g['ends'],'ls'=>(int)($gd['left_slots']??0),'slots'=>(int)($gd['slots']??0),'total'=>(int)($gd['total']??0),'played'=>(int)val($db,"SELECT COUNT(*) FROM gplay WHERE game=? AND uid=?",[$g['id'],$u['id']]),'mine'=>(int)$g['uid']===(int)$u['id']?1:0];
    }
    if($now-(int)$u['expat']>=60){addexp($db,$u['id'],$isHost?3:1);run($db,"UPDATE users SET expat=? WHERE id=?",[$now,$u['id']]);}
    $mu=mstate($db,$room,$nowms);
    $bagmax=(int)val($db,"SELECT COALESCE(MAX(id),0) FROM games WHERE kind='bag'");$bagnew=null;$bp=isset($_GET['bag'])?(int)$_GET['bag']:-1;
    if($bp>=0&&$bagmax>$bp){
      $bgr=one($db,"SELECT g.id,g.room,rm.title AS rtitle,g.data,u.name,u.photo,u.exp,(u.vip>$now) AS vip,".bsql()." AS badge FROM games g JOIN users u ON u.id=g.uid JOIN rooms rm ON rm.id=g.room WHERE g.kind='bag' AND g.id>? AND g.created>? ORDER BY g.id DESC LIMIT 1",[$bp,$now-30]);
      if($bgr){$bd=gdata($bgr);$bagnew=['id'=>(int)$bgr['id'],'room'=>(int)$bgr['room'],'rtitle'=>$bgr['rtitle'],'name'=>$bgr['name'],'photo'=>$bgr['photo'],'exp'=>(int)$bgr['exp'],'vip'=>(int)$bgr['vip'],'badge'=>$bgr['badge'],'total'=>(int)($bd['total']??0)];}
    }
    $gsumall=(int)val($db,"SELECT COALESCE(SUM(coins),0) FROM gifts WHERE room=?",[$R]);
    $folh=val($db,"SELECT 1 FROM follows WHERE a=? AND b=?",[$u['id'],$room['host']])?1:0;
    $tg=rows($db,"SELECT u.id,u.name,u.photo,SUM(g.coins) AS pts FROM gifts g JOIN users u ON u.id=g.uid WHERE g.room=? AND g.uid<>? GROUP BY g.uid ORDER BY pts DESC,u.id LIMIT 3",[$R,$room['host']]);
    out(['msgs'=>$ms,'clr'=>(int)$room['clr'],'reset'=>$reset,'room'=>['title'=>$room['title'],'cover'=>$room['cover'],'host'=>$h['name'],'hphoto'=>$h['photo'],'welcome'=>(string)($room['welcome']??''),'hostid'=>(int)$room['host'],'fol'=>$folh,'goal'=>['t'=>(string)($room['goal_t']??''),'n'=>(int)($room['goal_n']??0),'cur'=>max(0,$gsumall-(int)($room['goal_b']??0))]],
      'online'=>(int)val($db,"SELECT COUNT(*) FROM users WHERE (room_id=? AND seen>?) OR id=?",[$R,$live,$room['host']]),
      'toprank'=>$tr,'topg'=>$tg,'bagmax'=>$bagmax,'bagnew'=>$bagnew,'games'=>['now'=>$now,'list'=>$gsum],'music'=>['cur'=>$mu['cur'],'offset'=>$mu['offset'],'paused'=>$mu['paused'],'queue'=>$mu['queue']],
      'total'=>(int)val($db,"SELECT COUNT(*) FROM visits WHERE room=?",[$R]),
      'stage'=>rows($db,"SELECT id,name,photo,(id=?) AS host FROM users WHERE room_id=? AND mic=2 AND seen>? ORDER BY (id=?) DESC,id",[$room['host'],$R,$live,$room['host']]),
      'reqs'=>$isMod?rows($db,"SELECT id,name FROM users WHERE room_id=? AND mic=1 AND seen>?",[$R,$live]):[],
      'likes'=>(int)$room['likes'],'frozen'=>$room['frozen']==1,'elapsed'=>$now-(int)$room['started'],
      'me'=>['id'=>$u['id'],'name'=>$u['name'],'role'=>$role,'admin'=>$isAdmin?1:0,'mic'=>(int)$u['mic'],'coins'=>(int)$u['coins'],'exp'=>(int)$u['exp'],'vip'=>(int)$u['vip']>$now?1:0]]);
  }
  if($a==='send'){
    $t=trim($in['text']??'');if($t==='')out(['ok'=>0]);
    if($room['frozen']==1&&!$isMod)out(['err'=>'Chat sedang dibekukan']);
    addmsg($db,$R,$u['id'],(($in['announce']??'')==='1'&&$isMod)?'announce':'chat',$t);
    if($now-(int)$u['chatat']>=8){addexp($db,$u['id'],2);run($db,"UPDATE users SET chatat=? WHERE id=?",[$now,$u['id']]);}
    out(['ok'=>1]);
  }
  if($a==='sendimg'){
    if($room['frozen']==1&&!$isMod)out(['err'=>'Chat sedang dibekukan']);
    [$fn,$er]=saveImg($_FILES['f']??null,$UP,'chat_');if(!$fn)out(['err'=>$er]);
    addmsg($db,$R,$u['id'],'img',$fn);
    if($now-(int)$u['chatat']>=8){addexp($db,$u['id'],3);run($db,"UPDATE users SET chatat=? WHERE id=?",[$now,$u['id']]);}
    out(['ok'=>1]);
  }
  // ---------- musik room ----------
  if($a==='mtracks'){
    $mine=($in['tab']??'')==='mine';
    out(['rows'=>$mine
      ?rows($db,"SELECT t.id,t.title,t.artist,t.dur,t.plays,t.uid,u.name FROM tracks t JOIN users u ON u.id=t.uid WHERE t.uid=? ORDER BY t.id DESC LIMIT 100",[$u['id']])
      :rows($db,"SELECT t.id,t.title,t.artist,t.dur,t.plays,t.uid,u.name FROM tracks t JOIN users u ON u.id=t.uid ORDER BY t.plays DESC,t.id DESC LIMIT 50")]);
  }
  if($a==='mup'){
    $f=$_FILES['f']??null;
    if(!$f||$f['error'])out(['err'=>($f&&in_array($f['error'],[1,2]))?'File melebihi batas upload server (atur upload_max_filesize di php.ini)':'Upload gagal']);
    if($f['size']>20000000)out(['err'=>'Maksimal 20 MB']);
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $types=['audio/mpeg'=>'mp3','audio/mp3'=>'mp3','audio/mp4'=>'m4a','audio/x-m4a'=>'m4a','video/mp4'=>'m4a','audio/aac'=>'aac','audio/ogg'=>'ogg','application/ogg'=>'ogg','audio/wav'=>'wav','audio/x-wav'=>'wav','audio/webm'=>'webm','video/webm'=>'webm','audio/flac'=>'flac'];
    $ext=$types[$mime]??null;if(!$ext)out(['err'=>'Format musik tidak didukung (MP3, M4A, AAC, OGG, WAV)']);
    $fn='mus_'.bin2hex(random_bytes(10)).'.'.$ext;
    if(!move_uploaded_file($f['tmp_name'],"$UP/$fn"))out(['err'=>'Gagal menyimpan musik']);
    run($db,"INSERT INTO tracks(uid,title,artist,file,dur,ts) VALUES(?,?,?,?,?,?)",[$u['id'],trim(mb_substr($in['title']??'',0,60))?:'Tanpa judul',trim(mb_substr($in['artist']??'',0,40)),$fn,max(0,min(21600,(int)($in['dur']??0))),$now]);
    out(['ok'=>1,'id'=>(int)$db->lastInsertId()]);
  }
  if($a==='mdel'){
    $t=one($db,"SELECT * FROM tracks WHERE id=? AND uid=?",[(int)($in['id']??0),$u['id']]);if(!$t)out(['err'=>'Musik tidak ditemukan']);
    run($db,"UPDATE rooms SET mcur=0,mpaused=0,moff=0 WHERE mcur IN (SELECT id FROM rmusic WHERE track=?)",[$t['id']]);
    run($db,"DELETE FROM rmusic WHERE track=?",[$t['id']]);@unlink("$UP/".$t['file']);run($db,"DELETE FROM tracks WHERE id=?",[$t['id']]);out(['ok'=>1]);
  }
  if(in_array($a,['madd','mplay','mpause','mnext','mrm','mjump'],true)){
    if(!$isMod)out(['err'=>'Hanya penyiar dan manajer yang bisa mengatur musik']);
    $st=mstate($db,$room,$nowms);$q=$st['queue'];
    $mset=function($qid)use($db,$R,$nowms){run($db,"UPDATE rooms SET mcur=?,mstart=?,mpaused=0,moff=0 WHERE id=?",[$qid,$nowms,$R]);};
    if($a==='madd'){
      $tid=(int)($in['id']??0);if(!val($db,"SELECT 1 FROM tracks WHERE id=?",[$tid]))out(['err'=>'Musik tidak ditemukan']);
      run($db,"INSERT INTO rmusic(room,track,pos) VALUES(?,?,?)",[$R,$tid,1+(int)val($db,"SELECT MAX(pos) FROM rmusic WHERE room=?",[$R])]);
      $nid=(int)$db->lastInsertId();run($db,"UPDATE tracks SET plays=plays+1 WHERE id=?",[$tid]);
      if(!$st['curid'])$mset($nid);
    }
    if($a==='mplay'){
      if(!$st['curid']&&$q)$mset((int)$q[0]['id']);
      elseif($st['paused'])run($db,"UPDATE rooms SET mstart=?,mpaused=0 WHERE id=?",[$nowms-(int)$st['offset'],$R]);
    }
    if($a==='mpause'&&$st['curid']&&!$st['paused'])run($db,"UPDATE rooms SET mpaused=1,moff=? WHERE id=?",[$nowms-$st['start'],$R]);
    if($a==='mnext'&&$q&&$st['idx']>=0)$mset((int)$q[($st['idx']+1)%count($q)]['id']);
    if($a==='mjump'){$jid=(int)($in['id']??0);foreach($q as $x)if((int)$x['id']===$jid)$mset($jid);}
    if($a==='mrm'){
      $rid=(int)($in['id']??0);
      run($db,"DELETE FROM rmusic WHERE id=? AND room=?",[$rid,$R]);
      if($rid===$st['curid']){
        $rest=array_values(array_filter($q,function($x)use($rid){return (int)$x['id']!==$rid;}));
        if($rest)$mset((int)$rest[min($st['idx'],count($rest)-1)]['id']);
        else run($db,"UPDATE rooms SET mcur=0,mpaused=0,moff=0 WHERE id=?",[$R]);
      }
    }
    out(['ok'=>1]);
  }
  // ---------- game live ----------
  if($a==='gstart'){
    if(!$isMod)out(['err'=>'Hanya penyiar dan manajer yang bisa memulai game']);
    gsweep($db,$R,$now);
    if((int)val($db,"SELECT COUNT(*) FROM games WHERE room=? AND state='live'",[$R])>=3)out(['err'=>'Maksimal 3 game berjalan bersamaan']);
    $mk=function($kind,$title,$data,$ends)use($db,$R,$u,$now){
      run($db,"INSERT INTO games(room,kind,uid,title,data,state,created,ends) VALUES(?,?,?,?,?,'live',?,?)",[$R,$kind,$u['id'],$title,json_encode($data),$now,$ends]);
      return (int)$db->lastInsertId();
    };
    $kind=$in['kind']??'';$gid=0;
    if($kind==='lucky'){
      $total=(int)($in['total']??0);$slots=(int)($in['slots']??0);
      if($total<10||$total>100000)out(['err'=>'Total koin 10 – 100.000']);
      if($slots<1||$slots>50)out(['err'=>'Jumlah penerima 1 – 50']);
      if($slots>$total)out(['err'=>'Penerima tidak boleh melebihi total koin']);
      if((int)$u['coins']<$total)out(['err'=>'Koin tidak cukup']);
      run($db,"UPDATE users SET coins=coins-? WHERE id=?",[$total,$u['id']]);
      $gid=$mk('lucky','Lucky Box',['total'=>$total,'slots'=>$slots,'left'=>$total,'left_slots'=>$slots],$now+120);
      addmsg($db,$R,$u['id'],'game',"🎁 {$u['name']} membagikan Lucky Box $total koin untuk $slots orang! Buka menu Game untuk mengambil.");
    }elseif($kind==='quiz'){
      $q=trim(mb_substr($in['q']??'',0,150));
      $opts=array_values(array_filter(array_map(function($o){return trim(mb_substr((string)$o,0,60));},(array)json_decode($in['opts']??'[]',true)),function($o){return $o!=='';}));
      $correct=(int)($in['correct']??-1);$secs=max(10,min(60,(int)($in['secs']??20)));$prize=max(0,(int)($in['prize']??0));
      if($q==='')out(['err'=>'Tulis pertanyaan']);
      if(count($opts)<2||count($opts)>4)out(['err'=>'Isi 2 – 4 pilihan jawaban']);
      if($correct<0||$correct>=count($opts))out(['err'=>'Pilih jawaban yang benar']);
      if($prize>(int)$u['coins'])out(['err'=>'Koin tidak cukup untuk hadiah']);
      if($prize>0)run($db,"UPDATE users SET coins=coins-? WHERE id=?",[$prize,$u['id']]);
      $gid=$mk('quiz','Quiz',['q'=>$q,'opts'=>$opts,'correct'=>$correct,'prize'=>$prize,'secs'=>$secs],$now+$secs);
      addmsg($db,$R,$u['id'],'game',"❓ Quiz dimulai: $q ($secs detik)".($prize>0?" · Hadiah $prize koin":''));
    }elseif($kind==='link'){
      $title=trim(mb_substr($in['title']??'',0,40));$url=trim($in['url']??'');
      if($title==='')out(['err'=>'Isi nama game']);
      if(!preg_match('#^https://[^\s<>"\']{3,280}$#i',$url))out(['err'=>'Tautan harus diawali https://']);
      $gid=$mk('link',$title,['url'=>$url],$now+3600);
      addmsg($db,$R,$u['id'],'game',"🎮 {$u['name']} membuka game: $title");
    }else out(['err'=>'Jenis game tidak dikenal']);
    out(['ok'=>1,'id'=>$gid]);
  }
  if($a==='glist'){
    gsweep($db,$R,$now);$res=[];
    foreach(rows($db,"SELECT * FROM games WHERE room=? AND (state='live' OR created>?) ORDER BY id DESC LIMIT 12",[$R,$now-600]) as $g){
      $d=gdata($g);$my=one($db,"SELECT val FROM gplay WHERE game=? AND uid=?",[$g['id'],$u['id']]);
      $o=['id'=>(int)$g['id'],'kind'=>$g['kind'],'title'=>$g['title'],'state'=>$g['state'],'ends'=>(int)$g['ends'],'by'=>(string)val($db,"SELECT name FROM users WHERE id=?",[$g['uid']]),'mine'=>(int)$g['uid']===(int)$u['id'],'played'=>$my?1:0,'myval'=>$my?(int)$my['val']:null];
      if($g['kind']==='lucky'){
        $o['total']=(int)$d['total'];$o['slots']=(int)$d['slots'];$o['left_slots']=(int)$d['left_slots'];$o['left']=(int)$d['left'];
        $o['claims']=rows($db,"SELECT u.name,p.val AS amt FROM gplay p JOIN users u ON u.id=p.uid WHERE p.game=? ORDER BY p.ts",[$g['id']]);
      }elseif($g['kind']==='quiz'){
        $o['q']=$d['q'];$o['opts']=$d['opts'];$o['prize']=(int)$d['prize'];$o['secs']=(int)$d['secs'];
        $o['answers']=(int)val($db,"SELECT COUNT(*) FROM gplay WHERE game=?",[$g['id']]);
        if($g['state']==='done'){$o['correct']=(int)$d['correct'];$o['winners']=$d['winners']??[];$o['wcount']=(int)($d['wcount']??0);$o['each']=(int)($d['each']??0);}
      }else $o['url']=$d['url']??'';
      $res[]=$o;
    }
    out(['games'=>$res,'now'=>$now]);
  }
  if($a==='gclaim'){
    $gid=(int)($in['id']??0);$err=null;$amt=0;
    $db->exec('BEGIN IMMEDIATE');
    try{
      $g=one($db,"SELECT * FROM games WHERE id=? AND room=? AND kind='lucky'",[$gid,$R]);
      if(!$g||$g['state']!=='live'||(int)$g['ends']<=$now)$err='Lucky Box sudah berakhir';
      elseif((int)$g['uid']===(int)$u['id'])$err='Kamu tidak bisa mengambil Lucky Box sendiri';
      elseif(val($db,"SELECT 1 FROM gplay WHERE game=? AND uid=?",[$gid,$u['id']]))$err='Kamu sudah mengambil';
      else{
        $d=gdata($g);$left=(int)$d['left'];$ls=(int)$d['left_slots'];
        if($ls<1||$left<1)$err='Lucky Box sudah habis';
        else{
          $amt=$ls===1?$left:random_int(1,max(1,min(intdiv($left*2,$ls),$left-($ls-1))));
          $d['left']=$left-$amt;$d['left_slots']=$ls-1;
          run($db,"UPDATE games SET data=? WHERE id=?",[json_encode($d),$gid]);
          run($db,"INSERT INTO gplay(game,uid,val,ts) VALUES(?,?,?,?)",[$gid,$u['id'],$amt,$now]);
          run($db,"UPDATE users SET coins=coins+? WHERE id=?",[$amt,$u['id']]);
          if($d['left_slots']<1)run($db,"UPDATE games SET state='done' WHERE id=?",[$gid]);
        }
      }
      $db->exec('COMMIT');
    }catch(Exception $e){$db->exec('ROLLBACK');out(['err'=>'Coba lagi']);}
    if($err)out(['err'=>$err]);
    addmsg($db,$R,$u['id'],'game',"🎉 {$u['name']} mendapat $amt koin dari Lucky Box!");
    addexp($db,$u['id'],2);
    out(['ok'=>1,'amt'=>$amt]);
  }
  if($a==='ganswer'){
    $gid=(int)($in['id']??0);$c=(int)($in['choice']??-1);
    $g=one($db,"SELECT * FROM games WHERE id=? AND room=? AND kind='quiz'",[$gid,$R]);
    if(!$g||$g['state']!=='live'||(int)$g['ends']<=$now)out(['err'=>'Quiz sudah berakhir']);
    $d=gdata($g);
    if($c<0||$c>=count($d['opts']))out(['err'=>'Pilihan tidak valid']);
    if((int)$g['uid']===(int)$u['id'])out(['err'=>'Pembuat quiz tidak ikut menjawab']);
    if(val($db,"SELECT 1 FROM gplay WHERE game=? AND uid=?",[$gid,$u['id']]))out(['err'=>'Kamu sudah menjawab']);
    run($db,"INSERT INTO gplay(game,uid,val,ts) VALUES(?,?,?,?)",[$gid,$u['id'],$c,$now]);out(['ok'=>1]);
  }
  if($a==='gend'){
    if(!$isMod)out(['err'=>'Hanya penyiar dan manajer']);
    $g=one($db,"SELECT * FROM games WHERE id=? AND room=? AND state='live'",[(int)($in['id']??0),$R]);if(!$g)out(['err'=>'Game tidak ditemukan']);
    gfinish($db,$g,$now);out(['ok'=>1]);
  }
  if($a==='goal'&&$isMod){
    $gt=trim(mb_substr($in['t']??'',0,40));$gn=max(0,min(100000000,(int)($in['n']??0)));
    if($gn<1){$gt='';$gn=0;}elseif($gt==='')out(['err'=>'Isi nama target']);
    run($db,"UPDATE rooms SET goal_t=?,goal_n=?,goal_b=? WHERE id=?",[$gt,$gn,(int)val($db,"SELECT COALESCE(SUM(coins),0) FROM gifts WHERE room=?",[$R]),$R]);
    out(['ok'=>1]);
  }
  if($a==='like'){run($db,"UPDATE rooms SET likes=likes+1 WHERE id=?",[$R]);out(['ok'=>1]);}
  if($a==='gift'){
    $g=GIFTS[(int)($in['g']??0)%count(GIFTS)];if($u['coins']<$g[1])out(['err'=>'Koin tidak cukup']);
    run($db,"UPDATE users SET coins=coins-? WHERE id=?",[$g[1],$u['id']]);
    run($db,"UPDATE users SET coins=coins+? WHERE id=?",[(int)($g[1]*0.7),$room['host']]);
    run($db,"INSERT INTO gifts(room,uid,host,coins,ts) VALUES(?,?,?,?,?)",[$R,$u['id'],$room['host'],$g[1],$now]);
    if((int)$room['host']!==(int)$u['id'])run($db,"INSERT INTO notifs(uid,text,ts) VALUES(?,?,?)",[$room['host'],$u['name'].' mengirim '.$g[0].' ('.$g[1].' koin) di live kamu',$now]);
    addexp($db,$u['id'],max(1,intdiv($g[1],2)));if((int)$room['host']!==(int)$u['id'])addexp($db,$room['host'],max(1,intdiv($g[1],3)));
    addmsg($db,$R,$u['id'],'gift','mengirim '.$g[0]);out(['ok'=>1]);
  }
  if($a==='rank'){
    $rs=rows($db,"SELECT u.id,u.name,u.photo,u.exp,(u.vip>$now) AS vip,".bsql()." AS badge,COALESCE((SELECT SUM(g.coins) FROM gifts g WHERE g.room=? AND g.uid=u.id),0) AS coins,SUM(m.kind='chat') AS chats FROM msgs m JOIN users u ON u.id=m.uid WHERE m.room=? AND m.kind IN('chat','gift') AND u.id<>? GROUP BY u.id ORDER BY coins DESC,chats DESC LIMIT 25",[$R,$R,$room['host']]);
    foreach($rs as &$r){$r['role']=roleOf($db,$room,$r['id']);$r['banned']=val($db,"SELECT 1 FROM bans WHERE room=? AND uid=? AND until>?",[$R,$r['id'],$now])?1:0;}
    out(['rows'=>$rs]);
  }
  // daftar pendengar (termasuk penyiar)
  if($a==='listeners'){
    $rs=rows($db,"SELECT id,name,photo,mic,exp,(vip>$now) AS vip,".bsql('')." AS badge FROM users WHERE (room_id=? AND seen>?) OR id=? ORDER BY (id=?) DESC, mic DESC, name",[$R,$live,$room['host'],$room['host']]);
    foreach($rs as &$r)$r['role']=roleOf($db,$room,$r['id']);unset($r);
    out(['rows'=>$rs,'count'=>count($rs)]);
  }
  // panggung suara
  if($a==='ask'&&!$isHost){if($u['mic']==0)run($db,"UPDATE users SET mic=1 WHERE id=?",[$u['id']]);out(['ok'=>1]);}
  if($a==='down'&&!$isHost){run($db,"UPDATE users SET mic=0 WHERE id=?",[$u['id']]);lkperm($R,$u['id'],false);out(['ok'=>1]);}
  if($a==='stage'&&$isMod){
    $t=one($db,"SELECT * FROM users WHERE id=? AND room_id=?",[(int)($in['uid']??0),$R]);if(!$t||$t['id']==$room['host'])out(['err'=>'Tidak valid']);
    $up=($in['up']??'')==='1';
    if($up&&(int)val($db,"SELECT COUNT(*) FROM users WHERE room_id=? AND mic=2 AND seen>? AND id<>?",[$R,$live,$t['id']])>=8)out(['err'=>'Panggung penuh (maks. 8 kursi)']);
    run($db,"UPDATE users SET mic=? WHERE id=?",[$up?2:0,$t['id']]);lkperm($R,$t['id'],$up);out(['ok'=>1]);
  }
  // moderasi
  if(in_array($a,['kick','ban','unban'])&&$isMod){
    $t=one($db,"SELECT * FROM users WHERE id=?",[(int)($in['uid']??0)]);
    if(!$t)out(['err'=>'Tidak valid']);$tr=roleOf($db,$room,$t['id']);
    if(isAdmin($t)||(int)$t['id']===(int)$u['id']||(!$isAdmin&&($tr==='penyiar'||($tr==='manajer'&&!$isHost))))out(['err'=>'Tidak diizinkan']);
    if($a==='unban')run($db,"DELETE FROM bans WHERE room=? AND uid=?",[$R,$t['id']]);
    else{run($db,"REPLACE INTO bans VALUES(?,?,?)",[$R,$t['id'],$a==='kick'?$now+600:2147483647]);
      run($db,"UPDATE users SET mic=0 WHERE id=?",[$t['id']]);lkperm($R,$t['id'],false);
      addmsg($db,$R,$u['id'],'join',$t['name'].($a==='kick'?' dikeluarkan.':' diblokir.'));}
    out(['ok'=>1]);
  }
  if($a==='freeze'&&$isMod){$n=$room['frozen']==1?0:1;run($db,"UPDATE rooms SET frozen=? WHERE id=?",[$n,$R]);addmsg($db,$R,$u['id'],'join',$n?'Chat dibekukan.':'Chat dibuka kembali.');out(['ok'=>1]);}
  if($a==='clear'&&$isMod){
    clearChat($db,$UP,$R);addmsg($db,$R,$u['id'],'join','Chat dibersihkan oleh '.$u['name'].'.');
    if($isAdmin)alog($db,$u['id'],'clear chat room',$R,$room['title']);
    out(['ok'=>1]);
  }
  if($a==='title'&&$isHost){run($db,"UPDATE rooms SET title=? WHERE id=?",[trim(mb_substr($in['title']??'',0,40))?:$room['title'],$R]);
    if(array_key_exists('welcome',$in))run($db,"UPDATE rooms SET welcome=? WHERE id=?",[trim(mb_substr($in['welcome'],0,200)),$R]);out(['ok'=>1]);}
  if($a==='promote'&&$isHost){$t=(int)($in['uid']??0);
    if(($in['to']??'')==='manajer')run($db,"REPLACE INTO mgr VALUES(?,?)",[$R,$t]);else run($db,"DELETE FROM mgr WHERE room=? AND uid=?",[$R,$t]);out(['ok'=>1]);}
  if($a==='uncover'&&$isHost){$c=(string)$room['cover'];run($db,"UPDATE rooms SET cover='' WHERE id=?",[$R]);if($c!=='')@unlink("$UP/".basename($c));out(['ok'=>1]);}
  if($a==='end'&&$isHost){run($db,"UPDATE rooms SET live=0,ended=? WHERE id=?",[$now,$R]);out(['ok'=>1]);}
  out(['err'=>'Tidak diizinkan']);
}
?><!doctype html>
<html lang="id"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<link rel="preload" href="fonts/pretendard-400-latin.woff" as="font" type="font/woff" crossorigin>
<link rel="preload" href="fonts/pretendard-700-latin.woff" as="font" type="font/woff" crossorigin>
<link rel="preload" href="fonts/roboto-numbers.woff" as="font" type="font/woff" crossorigin>
<title>Live Room</title>
<style>
/* ---- font Pretendard (SIL OFL) + angka Roboto Medium; file ada di folder fonts/ ---- */
@font-face{font-family:'RobotoNum';src:url(fonts/roboto-numbers.woff) format('woff');font-weight:100 900;font-display:swap;unicode-range:U+0030-0039}
@font-face{font-family:'Pretendard';font-weight:400;font-style:normal;font-display:swap;src:url(fonts/pretendard-400-latin.woff) format('woff');unicode-range:U+0020-007E,U+00A0-00FF,U+0131,U+0152-0153,U+02C6,U+02DA,U+02DC,U+2013-2014,U+2018-201A,U+201C-201E,U+2020-2022,U+2026,U+2030,U+2039-203A,U+20AC,U+2122,U+2190-2193,U+2212}
@font-face{font-family:'Pretendard';font-weight:400;font-style:normal;font-display:swap;src:url(fonts/pretendard-400-ko.woff) format('woff');unicode-range:U+1100-11FF,U+3000-303F,U+3130-318F,U+A960-A97F,U+AC00-D7AF,U+D7B0-D7FF,U+FF00-FFEF}
@font-face{font-family:'Pretendard';font-weight:500;font-style:normal;font-display:swap;src:url(fonts/pretendard-500-latin.woff) format('woff');unicode-range:U+0020-007E,U+00A0-00FF,U+0131,U+0152-0153,U+02C6,U+02DA,U+02DC,U+2013-2014,U+2018-201A,U+201C-201E,U+2020-2022,U+2026,U+2030,U+2039-203A,U+20AC,U+2122,U+2190-2193,U+2212}
@font-face{font-family:'Pretendard';font-weight:500;font-style:normal;font-display:swap;src:url(fonts/pretendard-500-ko.woff) format('woff');unicode-range:U+1100-11FF,U+3000-303F,U+3130-318F,U+A960-A97F,U+AC00-D7AF,U+D7B0-D7FF,U+FF00-FFEF}
@font-face{font-family:'Pretendard';font-weight:600;font-style:normal;font-display:swap;src:url(fonts/pretendard-600-latin.woff) format('woff');unicode-range:U+0020-007E,U+00A0-00FF,U+0131,U+0152-0153,U+02C6,U+02DA,U+02DC,U+2013-2014,U+2018-201A,U+201C-201E,U+2020-2022,U+2026,U+2030,U+2039-203A,U+20AC,U+2122,U+2190-2193,U+2212}
@font-face{font-family:'Pretendard';font-weight:600;font-style:normal;font-display:swap;src:url(fonts/pretendard-600-ko.woff) format('woff');unicode-range:U+1100-11FF,U+3000-303F,U+3130-318F,U+A960-A97F,U+AC00-D7AF,U+D7B0-D7FF,U+FF00-FFEF}
@font-face{font-family:'Pretendard';font-weight:700;font-style:normal;font-display:swap;src:url(fonts/pretendard-700-latin.woff) format('woff');unicode-range:U+0020-007E,U+00A0-00FF,U+0131,U+0152-0153,U+02C6,U+02DA,U+02DC,U+2013-2014,U+2018-201A,U+201C-201E,U+2020-2022,U+2026,U+2030,U+2039-203A,U+20AC,U+2122,U+2190-2193,U+2212}
@font-face{font-family:'Pretendard';font-weight:700;font-style:normal;font-display:swap;src:url(fonts/pretendard-700-ko.woff) format('woff');unicode-range:U+1100-11FF,U+3000-303F,U+3130-318F,U+A960-A97F,U+AC00-D7AF,U+D7B0-D7FF,U+FF00-FFEF}
:root{--bg1:#8db6d2;--bg2:#4f6a80;--bar:#53697b;--bubble:#3b4c5c;--host:#ffb43a;--mgr:#2fd6b8;--live:#7be86f;--blue:#4a63f5;--ink:#2a2d34;--mut:#8a8f96}
*{box-sizing:border-box;margin:0;-webkit-tap-highlight-color:transparent}
html,body{height:100%}
body{font-family:'RobotoNum','Pretendard',-apple-system,BlinkMacSystemFont,Roboto,"Segoe UI",system-ui,sans-serif;-webkit-font-smoothing:antialiased;background:#222;color:#fff;display:flex;justify-content:center}
#app{width:100%;max-width:480px;height:100%;position:relative;overflow:hidden;background:#fff;color:var(--ink);display:flex;flex-direction:column}
.ic{display:inline-flex;width:1em;height:1em}.ic svg{width:100%;height:100%}
.ava{border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;flex:none;text-transform:uppercase;background-size:cover}
/* auth */
#auth{position:absolute;inset:0;z-index:20;background:linear-gradient(180deg,var(--bg1),var(--bg2));color:#fff;display:none;flex-direction:column;justify-content:center;gap:12px;padding:30px}
#auth h1{font-size:30px;font-weight:500;margin-bottom:6px}
#auth input,.fld{border:0;border-radius:8px;padding:14px;font-size:17px;outline:none;width:100%;font-family:inherit}
#auth .b{border:0;border-radius:8px;padding:14px;font-size:17px;background:var(--host);color:#fff;font-weight:700;cursor:pointer}
#auth .l{background:none;border:0;color:#fff;text-decoration:underline;font-size:15px;cursor:pointer}
#aerr{color:#ffd9d9;min-height:20px}
/* home */
.view{flex:1;display:none;flex-direction:column;min-height:0}.view.show{display:flex}
.tabs{display:flex;box-shadow:0 2px 4px rgba(0,0,0,.12);padding-top:0;background:#fff;z-index:2}
.tabs button{flex:1;border:0;background:none;padding:16px 0 12px;font-size:21px;font-weight:700;color:#bdbdbd;cursor:pointer;letter-spacing:.01em}
.tabs button.on{color:var(--host)}
.tabs button.on span{border-bottom:3px solid var(--host);padding-bottom:11px}
#list{flex:1;overflow-y:auto;padding:6px 0 10px}
.cast-card,.talk-card{margin:10px 14px;padding:13px;border:1px solid #e8e8e8;border-radius:12px;background:#fff}
.cast-head,.talk-head{display:flex;align-items:center;gap:10px;margin-bottom:9px}
.cast-head .ava,.talk-head .ava{width:42px;height:42px;font-size:17px}
.cast-meta{min-width:0;flex:1}.cast-meta b{display:block;font-size:16px}.cast-meta small,.talk-head small{color:var(--mut)}
.cast-card audio{width:100%;height:38px}.talk-body{font-size:16px;line-height:1.45;white-space:pre-wrap;overflow-wrap:anywhere}
.feed-action{border:0;background:none;color:#888;padding:5px 8px;cursor:pointer}

.it{display:flex;gap:20px;padding:14px 20px;cursor:pointer;align-items:flex-start}
.th{width:96px;height:96px;border:1px solid #ddd;position:relative;flex:none;background-size:cover!important;background-position:center!important;display:flex;align-items:center;justify-content:center;font-size:34px;font-weight:700;color:#fff}
.bd{position:absolute;left:5px;bottom:5px;background:var(--blue);width:36px;height:24px;border-radius:3px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:20px}
.inf{min-width:0;flex:1}.inf b{display:block;font-size:19px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px}
.inf .h{color:var(--mut);font-size:16px;margin-top:6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.inf .s{display:flex;gap:16px;color:#9a9ea4;font-size:15px;margin-top:12px}.inf .s span{display:flex;align-items:center;gap:6px}.inf .s .ic{font-size:20px}
.empty{color:var(--mut);text-align:center;padding:60px 30px;line-height:1.5}
#nav{display:none;border-top:1px solid #ddd;background:#fff;height:68px;padding-bottom:env(safe-area-inset-bottom);box-sizing:content-box}
#nav.show{display:flex}
#nav button{flex:1;border:0;background:none;font-size:36px;color:#8f9399;cursor:pointer;display:flex;align-items:center;justify-content:center}
#nav button.on{color:var(--ink)}#nav .plus{font-size:50px;color:#9a9ea4}
/* profil */
#prof{overflow-y:auto}
#prof h2{font-size:22px;font-weight:700}
#pav{width:112px;height:112px;font-size:44px;align-self:center;cursor:pointer;position:relative}
#pav em{position:absolute;right:0;bottom:0;background:var(--host);width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-style:normal;font-size:16px}
#prof .fld{border:1px solid #ddd}#prof label{font-size:14px;color:var(--mut);margin-bottom:-8px}
.pbtn{border:0;border-radius:10px;padding:13px;font-size:16px;font-weight:700;cursor:pointer;background:var(--host);color:#fff}.pbtn.g{background:#eee;color:var(--ink)}
.coin{background:#fff6e0;border-radius:10px;padding:12px 14px;display:flex;justify-content:space-between;align-items:center}
/* room */
#roomv{position:absolute;inset:0;z-index:6;display:none;flex-direction:column;color:#fff;background:linear-gradient(180deg,var(--bg1),var(--bg2))}
#roomv.show{display:flex}
#nav{position:relative;z-index:7;flex:none}
.room-heading{min-width:0;flex:1}

header{background:var(--bar);padding:calc(10px + env(safe-area-inset-top)) 14px 0}
.top{display:flex;align-items:center;gap:10px;height:64px}
.top .ava{width:52px;height:52px;font-size:22px}
.top div b{font-size:17px;font-weight:500;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.hb{flex:none;border:0;border-radius:20px;height:38px;min-width:38px;background:rgba(255,255,255,.16);color:#fff;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:5px;padding:0 10px}
.hb .ic{font-size:19px}.hb b{font-size:15px;font-weight:600;font-variant-numeric:tabular-nums}
#mini{display:none;flex:none;align-items:center;gap:10px;padding:8px 12px;background:var(--bar);color:#fff;cursor:pointer;border-top:1px solid rgba(255,255,255,.15);position:relative;z-index:7}
#mini.show{display:flex}
#mini .ava{width:42px;height:42px;font-size:18px}
#mini .mi{flex:1;min-width:0}
#mini .mi b{display:block;font-size:15px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#mini .mi small{font-size:12px;opacity:.9;display:flex;gap:6px;align-items:center;font-variant-numeric:tabular-nums}
#mini .dot{width:7px;height:7px;border-radius:50%;background:var(--live);animation:pulse 1.4s infinite}
@keyframes pulse{50%{opacity:.25}}
#mini .eq{display:flex;align-items:flex-end;gap:2px;height:20px;width:22px;flex:none}
#mini .eq i{flex:1;background:var(--live);border-radius:1px;height:3px}
#mini button{border:0;background:rgba(255,255,255,.16);color:#fff;width:36px;height:36px;border-radius:50%;font-size:16px;cursor:pointer;flex:none}
.cvp{width:112px;height:112px;margin:0 auto 6px;position:relative}
.cvv{position:absolute;inset:0;border-radius:12px;border:1px solid #ddd;display:flex;align-items:center;justify-content:center;font-size:42px;font-weight:700;color:#fff;background-size:cover}
.cvv em{position:absolute;right:-6px;bottom:-6px;background:var(--host);width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-style:normal;font-size:15px}
.cvf{position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer;font-size:0;z-index:1}
#pin{display:none;flex:none;background:rgba(255,180,58,.25);border-left:4px solid var(--host);padding:8px 14px;font-size:15px;line-height:1.35;cursor:pointer}
#pin b{display:block;font-size:12px;letter-spacing:.06em;opacity:.9;margin-bottom:2px}
#pin .pt{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;white-space:pre-wrap;overflow-wrap:anywhere}
#pin.open .pt{display:block}
.cdel{border:0;background:#fdecee;color:#e0405a;width:36px;height:36px;border-radius:50%;font-size:16px;cursor:pointer;flex:none}
.cvh{display:block;text-align:center;color:#888;margin-bottom:12px;font-size:13px}
.shead{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}.shead h3{margin:0!important}
.sx{border:0!important;background:#eee!important;width:34px;height:34px;border-radius:50%!important;padding:0!important;font-size:16px!important}
.top small{letter-spacing:.3em;font-weight:900;font-size:12px;text-transform:uppercase}
.stats{display:flex;align-items:stretch;background:#4a6071;margin:8px -14px 0;padding:0;font-size:15px;font-variant-numeric:tabular-nums}
.stats .sg{display:flex;align-items:center;gap:6px;padding:11px 10px;border:0;background:none;color:inherit;font:inherit;white-space:nowrap;min-width:0;cursor:default}
.stats button.sg{cursor:pointer}
.stats .sg:first-child{padding-left:14px}
.stats .sig{margin-left:auto;padding-right:14px;cursor:default}
.sig{margin-left:auto;display:flex;gap:2px}.sig i{width:9px;height:9px;background:#ccc;opacity:.3}
.sig i.on:nth-child(-n+2){background:#7be86f;opacity:1}.sig i.on:nth-child(3),.sig i.on:nth-child(4){background:#ffe75a;opacity:1}.sig i.on:nth-child(5),.sig i.on:nth-child(6){background:#ff9a1f;opacity:1}.sig i.on:nth-child(n+7){background:#ff4d4d;opacity:1}
#unlock{display:none;background:#ffb43a;color:#fff;font-weight:700;text-align:center;padding:10px;cursor:pointer}
#stage{display:flex;gap:14px;overflow-x:auto;padding:10px 14px;background:rgba(30,45,60,.28);min-height:78px}
.st{display:flex;flex-direction:column;align-items:center;gap:4px;font-size:12px;width:56px;flex:none}
.st .ava{width:46px;height:46px;font-size:18px;transition:box-shadow .15s}
.st.speak .ava{box-shadow:0 0 0 3px var(--live),0 0 14px var(--live)}
.st span{max-width:56px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.st.h .ava{border:2px solid var(--host)}
#chat{flex:1;overflow-y:auto;padding:14px 14px 8px;display:flex;flex-direction:column;gap:12px;position:relative}
.sys{font-weight:700;font-size:16px;padding:6px 0}
.msg{display:flex;gap:12px;align-items:flex-start}.msg .ava{width:48px;height:48px;font-size:19px}
.who{font-size:15px;font-weight:500;margin-bottom:4px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.tag{font-size:14px;font-weight:700;padding:1px 7px;border-radius:3px;color:#fff}.tag.penyiar{background:var(--host)}.tag.manajer{background:var(--mgr)}
.who .nm.host{letter-spacing:.2em;font-weight:900;font-size:13px;text-transform:uppercase}
.bub{background:var(--bubble);padding:9px 14px;border-radius:5px;font-size:19px;line-height:1.3;display:inline-block;word-break:break-word;max-width:100%}
.bub.announce{background:#c9892a}.bub.gift{background:#7b4b86}
#float{position:absolute;left:0;right:0;top:0;bottom:0;pointer-events:none;overflow:hidden}
.fl{position:absolute;bottom:0;font-size:34px;animation:up 2.4s ease-out forwards}
@keyframes up{to{transform:translateY(-70vh) scale(1.4);opacity:0}}
#dock{background:#bccbd6;padding:12px 12px;display:flex;gap:8px;align-items:center}#dock .sp{flex:1}
.rb{width:48px;height:48px;flex:none;border-radius:50%;border:0;background:#fff;font-size:24px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:#333}
.rb.on{background:#c8f5c2;box-shadow:0 0 0 3px var(--live)}
#inbar{display:none;background:#bccbd6;padding:0 18px 12px;gap:8px}#inbar.show{display:flex}
#inbar input{flex:1;border:0;border-radius:24px;padding:13px 18px;font-size:17px;outline:none;min-width:0}
#inbar button{border:0;border-radius:24px;background:#3aa0d8;color:#fff;font-size:16px;padding:0 20px}
#inbar #bAnn{background:#fff;font-size:20px;padding:0 14px}#inbar #bAnn.on{background:#ffd48a}
#acts{background:#f8f8f8;display:flex;justify-content:space-around;padding:14px 2px calc(12px + env(safe-area-inset-bottom))}#acts.hide{display:none}
.act{background:none;border:0;display:flex;flex-direction:column;align-items:center;gap:8px;font-size:14px;color:#7a838b;cursor:pointer;position:relative}
.act i{width:60px;height:60px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:26px;font-style:normal;color:#fff}
.act em{position:absolute;top:-2px;right:2px;background:#e0405a;color:#fff;font-style:normal;font-size:12px;border-radius:10px;padding:1px 6px;display:none}
button:focus-visible{outline:3px solid #fff;outline-offset:2px}
/* sheet */
#sheet{position:absolute;inset:0;background:rgba(0,0,0,.45);display:none;align-items:flex-end;z-index:10}#sheet.show{display:flex}
#sheet .box{background:#fff;color:#222;width:100%;border-radius:18px 18px 0 0;padding:18px;max-height:78%;overflow:auto}
#sheet h3{margin-bottom:12px;font-weight:500}
.row{display:flex;align-items:center;gap:8px;padding:9px 0;border-bottom:1px solid #eee;font-size:16px;flex-wrap:wrap;color:#222}
.row .n{width:26px;color:#888;text-align:center}.row .grow{flex:1;min-width:90px}.row .ava{width:34px;height:34px;font-size:14px}
.row button,.chip{border:1px solid #bbb;background:#fff;border-radius:16px;padding:6px 12px;font-size:14px;cursor:pointer;color:#222}.row button.d{border-color:#e0405a;color:#e0405a}
.chips{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px}
.box input.fld,.box textarea.fld{border:1px solid #ccc;margin-bottom:10px;padding:12px;font-size:16px}
.box .ok{background:#3aa0d8;color:#fff;border:0;border-radius:8px;padding:12px 18px;font-size:16px;cursor:pointer}

.hd{display:flex;align-items:center;justify-content:space-between;padding:calc(10px + env(safe-area-inset-top)) 12px 2px 18px;background:#fff}
.logo{font-size:28px;font-weight:800;letter-spacing:-.02em}
.hi{display:flex}
.hi button{position:relative;border:0;background:none;width:44px;height:44px;color:var(--ink);cursor:pointer;display:flex;align-items:center;justify-content:center}
.hi svg{width:27px;height:27px}
.bdg{position:absolute;top:4px;right:2px;min-width:17px;height:17px;border-radius:9px;background:#e0405a;color:#fff;font-size:11px;font-style:normal;font-weight:700;display:none;align-items:center;justify-content:center;padding:0 4px}
.sbar{display:flex;align-items:center;gap:4px;padding:calc(8px + env(safe-area-inset-top)) 8px 8px;border-bottom:1px solid #eee;background:#fff}
.sbar button{border:0;background:none;font-size:24px;width:42px;height:42px;cursor:pointer;color:var(--ink)}
.sbar h2{font-size:20px;flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#sBody{flex:1;overflow-y:auto;padding:0 14px 10px;display:flex;flex-direction:column;min-height:0}#sBody>*{flex:none}
#sFoot{display:flex;gap:8px;padding:10px 12px;border-top:1px solid #eee;background:#fff}#sFoot:empty{display:none}
#sFoot input{flex:1;min-width:0;border:1px solid #ddd;border-radius:22px;padding:11px 16px;font-size:16px;outline:none}
#sFoot button{border:0;border-radius:22px;background:var(--blue);color:#fff;font-size:16px;padding:0 20px;cursor:pointer}
.urow{display:flex;align-items:center;gap:12px;padding:11px 2px;border-bottom:1px solid #f0f0f0;cursor:pointer}
.urow .ava{width:46px;height:46px;font-size:18px}
.um{min-width:0;flex:1}.um b{display:block;font-size:16px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.um small{color:var(--mut);display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.livetag{background:var(--blue);color:#fff;font-size:11px;font-weight:800;padding:2px 7px;border-radius:4px}
.rk{width:30px;text-align:center;font-weight:700;color:#888;font-size:17px;flex:none}.pts{color:#c8871c;font-size:15px;flex:none}
.nrow{padding:13px 6px;border-bottom:1px solid #f0f0f0}.nrow.new{background:#fff8e8}.nrow .nt{font-size:16px;line-height:1.35}.nrow small{color:#999}
.ds{display:flex;flex-direction:column;align-items:flex-end;gap:4px;color:#999;flex:none}
.ub{background:#e0405a;color:#fff;font-style:normal;font-size:12px;border-radius:10px;padding:1px 7px;font-weight:700}
.seg{display:flex;background:#f1f1f1;border-radius:10px;padding:3px;margin:12px 0 10px}
.seg button{flex:1;border:0;background:none;padding:10px;border-radius:8px;font-size:16px;font-weight:700;color:#888;cursor:pointer}
.seg button.on{background:#fff;color:var(--ink);box-shadow:0 1px 3px rgba(0,0,0,.15)}
.chip.on{background:var(--host);border-color:var(--host);color:#fff}
#sBody .fld{border:1px solid #ddd;margin:12px 0 4px}
.dm{display:flex;flex-direction:column;margin:6px 0;max-width:80%}.dm.me{align-self:flex-end;align-items:flex-end}.dm.ot{align-self:flex-start}
.dm .db{padding:9px 13px;border-radius:14px;font-size:16px;line-height:1.35;overflow-wrap:anywhere;white-space:pre-wrap}
.dm.me .db{background:var(--blue);color:#fff}.dm.ot .db{background:#eee}.dm small{color:#aaa;font-size:11px;margin-top:2px}
#bTop3{flex:none;display:flex;align-items:center;border:0;background:none;padding:0 8px 0 0;cursor:pointer}
.t3{width:34px;height:34px;border-radius:50%;border:2px solid var(--c);margin-left:-8px;position:relative;background:rgba(255,255,255,.08);flex:none}
.t3:first-child{margin-left:0}.t3.r1{--c:#f5b83a}.t3.r2{--c:#c3cbd3}.t3.r3{--c:#d18b5b}
.t3.em{border-style:dashed;opacity:.5}
.t3 .ava{width:100%;height:100%;font-size:13px}
.t3 i{position:absolute;right:-4px;bottom:-6px;width:15px;height:15px;border-radius:50%;background:var(--c);color:#222;font-size:10px;font-style:normal;font-weight:800;display:flex;align-items:center;justify-content:center}

#sub.gray #sBody{background:#f1f1f1}
.btabs{display:flex;margin:0 -14px 14px;border-bottom:1px solid #e3e3e3;background:#fff}
.btabs button{flex:1;border:0;background:none;padding:14px 0;font-size:18px;color:#777;border-bottom:3px solid transparent;margin-bottom:-1px;cursor:pointer}
.btabs button.on{color:var(--ink);border-bottom-color:#444}
.hubcard{display:flex;align-items:center;gap:14px;background:#fff;border-radius:14px;padding:16px;margin-bottom:12px;cursor:pointer}
.hubcard .hic{width:30px;height:30px;flex:none;color:#333}.hubcard .hic svg{width:100%;height:100%}
.hubcard b{font-size:18px;font-weight:500;flex:1;min-width:0}
.hubcard .t3s,.topdj .t3s,.pfr .t3s{display:flex;flex:none;padding-right:6px}
.hubcard .t3,.topdj .t3,.pfr .t3{width:40px;height:40px;margin-left:-10px;border-width:2px;border-color:#fff;background:#eee;box-shadow:0 0 0 1px #ddd}
.hubcard .t3:first-child,.topdj .t3:first-child,.pfr .t3:first-child{margin-left:0}.hubcard .t3.em,.topdj .t3.em,.pfr .t3.em{opacity:1;border-style:solid;box-shadow:0 0 0 1px #e5e5e5}
.hubcard .t3 i,.topdj .t3 i,.pfr .t3 i{width:16px;height:16px;font-size:10px}
.bchips{display:flex;justify-content:space-around;margin:10px -6px 4px}
.bchips button{border:0;background:none;font-size:17px;font-weight:700;padding:10px 18px;border-radius:22px;color:var(--ink);cursor:pointer;white-space:nowrap}
.bchips button.on{background:#e7e7e7}
.podium{display:flex;justify-content:center;align-items:flex-start;gap:4px;padding:6px 0 20px;background:#fff;margin:0 -14px}
.pc{display:flex;flex-direction:column;align-items:center;width:31%;min-width:0;cursor:pointer}
.pc.p2,.pc.p3{margin-top:64px}
.pc .md{position:relative;height:50px;display:flex;justify-content:center}.pc .md svg{height:100%}
.pc .md small{position:absolute;left:calc(50% + 28px);top:12px;color:#999;font-size:13px;white-space:nowrap}
.pc .ava{box-shadow:0 4px 14px rgba(0,0,0,.2);margin-top:2px}
.pc.p1 .ava{width:112px;height:112px;font-size:44px}.pc.p2 .ava,.pc.p3 .ava{width:76px;height:76px;font-size:30px}
.pc .nm{font-weight:700;font-size:15px;margin-top:10px;max-width:100%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pc .sc{color:#888;font-size:13px}
.lrs{background:#f5f5f5;margin:0 -14px;padding:0 14px}
.lr{display:flex;align-items:center;gap:14px;padding:12px 0;cursor:pointer}
.lr .rn{width:40px;text-align:center;flex:none}.lr .rn b{display:block;font-size:20px}
.lr .ava{width:56px;height:56px;font-size:22px;flex:none}
.lr .nm{flex:1;min-width:0;font-weight:700;font-size:16px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.lr .sc{color:#888;font-size:14px;flex:none}
.chg{font-size:12px;font-weight:700;color:#999}.chg.up{color:#f04e1a}.chg.dn{color:#2f7be5}.chg.nw{color:#f04e1a}

.hero{position:relative}
.hsl{display:flex;overflow-x:auto;scroll-snap-type:x mandatory;scrollbar-width:none}.hsl::-webkit-scrollbar{display:none}
.hs{flex:0 0 100%;scroll-snap-align:start;height:190px;position:relative;color:#fff;display:flex;flex-direction:column;justify-content:flex-end;padding:14px 16px;cursor:pointer}
.hs .htag{align-self:flex-start;background:#1fd3c1;color:#fff;font-size:11px;font-weight:800;padding:3px 8px;border-radius:4px;margin-bottom:8px}
.hs .ht{font-size:22px;line-height:1.2;text-shadow:0 1px 6px rgba(0,0,0,.5);display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;max-width:80%}
.hs .hh{opacity:.95;margin-top:4px;font-size:14px}
.hcnt{position:absolute;right:12px;bottom:12px;background:rgba(0,0,0,.55);color:#fff;font-size:13px;font-weight:700;padding:4px 10px;border-radius:14px}
.topdj{display:flex;align-items:center;gap:10px;padding:14px 16px;border-bottom:8px solid #f2f2f2;cursor:pointer}
.topdj>b{flex:1;font-size:15px;letter-spacing:.02em}.topdj .t3s{display:flex}.topdj .chev{color:#aaa;font-size:24px}
.ptabs{display:flex;background:#fff;box-shadow:0 2px 4px rgba(0,0,0,.1);position:sticky;top:0;z-index:2}
#prof .ptabs{padding-top:env(safe-area-inset-top)}
.ptabs button{flex:1;border:0;background:none;padding:16px 2px;font-size:14px;font-weight:700;color:#bbb;border-bottom:3px solid transparent;cursor:pointer;text-transform:uppercase}
.ptabs button.on{color:var(--host);border-bottom-color:var(--host)}
.pbody{padding:24px 16px 30px;display:flex;flex-direction:column;align-items:stretch;gap:14px}
#sBody .pf{margin:0 -14px}
.pfa{width:128px;height:128px;font-size:50px;margin:6px auto 0}
.pfn{font-size:24px;font-weight:800;text-align:center;overflow-wrap:anywhere}.pfe{border:0;background:none;color:#aaa;font-size:20px;cursor:pointer;margin-left:6px}
.pfu{text-align:center;color:#bbb;margin-top:-8px}
.pfbar{display:flex;gap:10px;justify-content:center}
.fanbtn{border:2px solid var(--host);background:var(--host);color:#fff;font-weight:800;font-size:17px;padding:12px 34px;border-radius:4px;cursor:pointer}.fanbtn.on{background:#fff;color:var(--host)}
.pfdm{border:1px solid #ddd;background:#fff;border-radius:4px;width:52px;font-size:20px;cursor:pointer}
.pnb{display:flex;align-items:center;gap:10px;background:#f4f4f4;border-radius:8px;padding:14px;color:#999;cursor:pointer}.pnb .pnt{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pst{display:flex;border-top:1px solid #eee;border-bottom:1px solid #eee;padding:16px 0}
.psc{flex:1;text-align:center;cursor:pointer}.psc+.psc{border-left:1px solid #eee}.psc b{display:block;font-size:20px}.psc span{color:#666}
.pbio{text-align:center;line-height:1.4;color:#444;overflow-wrap:anywhere}
.pfl{border:0;background:var(--blue);color:#fff;font-weight:700;padding:13px;border-radius:24px;font-size:16px;cursor:pointer}
.pfr{display:flex;align-items:center;justify-content:space-between;border-top:8px solid #f2f2f2;border-bottom:8px solid #f2f2f2;margin:0 -16px;padding:16px}
.pfr b{font-size:17px}.pfr .mut{color:#aaa}.pfr .t3s{display:flex}
.ncard{background:#f8f8f8;border-radius:10px;padding:12px 14px;line-height:1.4;white-space:pre-wrap;overflow-wrap:anywhere}.ncard small{display:block;color:#aaa;margin-top:6px}
#nowp{display:none;flex:none;align-items:center;gap:10px;background:rgba(0,0,0,.28);padding:7px 14px;color:#fff}
#nowp .nmi{font-size:20px}#nowp .npt{flex:1;min-width:0;cursor:pointer}
#nowp b{display:block;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}#nowp small{opacity:.8;font-size:12px}
#nowp button{border:0;background:rgba(255,255,255,.2);color:#fff;width:34px;height:34px;border-radius:50%;font-size:15px;cursor:pointer;flex:none}
.mnow{background:#f4f6ff;border-radius:12px;padding:12px 14px;margin-bottom:6px;display:flex;align-items:center;gap:10px}.mnow>div:first-child{flex:1;min-width:0}.mnow b{display:block}.mnow small{color:#888}
.mctl{display:flex;gap:8px}.mctl button{border:0;background:var(--blue);color:#fff;width:38px;height:38px;border-radius:50%;font-size:16px;cursor:pointer}
.mrow{display:flex;align-items:center;gap:8px;padding:10px 0;border-bottom:1px solid #eee}.mrow .mi2{flex:1;min-width:0}.mrow b{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:15px}.mrow small{color:#999}.mrow.cur b{color:var(--blue)}
.fbtn{position:relative;display:inline-flex;align-items:center;justify-content:center;overflow:hidden;cursor:pointer}
.upl{background:var(--blue);color:#fff;border-radius:20px;padding:10px 16px;font-weight:700;font-size:15px;margin:4px 0 8px}
#inbar #bImg{background:#fff;font-size:20px;padding:0 14px;border-radius:24px}
.cimg{max-width:220px;max-height:260px;border-radius:10px;display:block;cursor:pointer;background:#0002}
.bub.img{padding:4px}.dm .db.imgb{padding:4px}
#lb{display:none;position:absolute;inset:0;z-index:40;background:rgba(0,0,0,.92);align-items:center;justify-content:center}#lb.show{display:flex}
#lb img{max-width:100%;max-height:100%;object-fit:contain}
#lb button{position:absolute;top:calc(12px + env(safe-area-inset-top));right:12px;border:0;background:rgba(255,255,255,.2);color:#fff;width:42px;height:42px;border-radius:50%;font-size:20px;cursor:pointer}
#sFoot .fbtn{background:#eee;border-radius:22px;width:46px;flex:none;font-size:20px}
.tag.admin{background:linear-gradient(90deg,#e0405a,#ff7a45)}
.tag.choice{background:linear-gradient(90deg,#8e5cf5,#e05cc0)}
.tag.sm{font-size:11px;padding:1px 6px;margin-left:6px;vertical-align:middle;letter-spacing:.04em;display:inline-block;line-height:1.5}
.btabs.sm button{font-size:15px;padding:12px 0}
.admg{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:6px}
.admc{background:#fff;border-radius:12px;padding:12px 6px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.1)}
.admc b{display:block;font-size:18px;overflow-wrap:anywhere}.admc span{color:#888;font-size:12px}
.admb{padding-bottom:30px}
#hAdmin{color:#e0405a!important}
#acts{flex-wrap:wrap;row-gap:10px}#acts .act{flex:0 0 25%}
/* ---- tampilan room: kursi, mode ikon call, pesan baru ---- */
.stats{background:none;margin:8px 0 0;padding:0 0 10px;gap:8px;align-items:center;font-size:15px}
.stats .sg{padding:6px 12px;border:1.5px solid rgba(255,255,255,.55);border-radius:20px;gap:5px;line-height:1.2}
.stats .sg:first-child{padding-left:12px}
.stats .tp{background:#ff4b12;border-color:#ff4b12;font-weight:700}
.stats .sig{margin-left:auto;padding:0 2px 0 0;border:0}
.stats svg{width:15px;height:15px;flex:none}
#stageWrap{flex:none;position:relative;margin:0 12px 18px}
#roomv.sc #stageWrap{display:none}
#stgCap{font-size:15px;opacity:.92;padding:8px 4px 8px}
#stage{display:grid;grid-template-columns:repeat(5,1fr);gap:12px 2px;padding:14px 6px 20px;background:rgba(40,52,64,.58);border-radius:16px;overflow:visible;min-height:0}
.st{width:auto;min-width:0;cursor:pointer;gap:5px;align-items:center}
.st .sv{position:relative;width:58px;height:58px}
.st .ava{width:58px;height:58px;font-size:22px}
.st .sn{font-size:14px;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.st .dj{font-size:12px;font-weight:800;color:#ff7a2b;background:rgba(0,0,0,.35);padding:2px 11px;border-radius:10px;line-height:1.3}
.st.vac .sv{border-radius:50%;background:rgba(255,255,255,.17);display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.5)}
.st.vac .sv svg{width:30px;height:30px}
.st.vac .sn{opacity:.55;font-weight:700}
.st .mi{position:absolute;inset:0;border-radius:50%;background:rgba(0,0,0,.58);display:none;align-items:center;justify-content:center}
.st.mu .mi{display:flex}.st .mi svg{width:26px;height:26px;color:#fff}
#stgCol{position:absolute;right:8px;bottom:-14px;width:54px;height:26px;border:0;border-radius:13px;background:#f1f1f1;color:#444;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 1px 5px rgba(0,0,0,.35)}
#stgCol svg{width:18px;height:18px}
#fabWrap{position:relative;height:0;z-index:5;display:none}
#roomv.sc #fabWrap{display:block}
#roomv.sc #pin{margin-right:84px}
#callFab{position:absolute;right:12px;top:10px;width:64px;height:64px;border:0;background:none;padding:0;cursor:pointer}
#callFab .cf{position:absolute;inset:0;border-radius:50%;background:#1b1b1b;color:#fff;display:flex;align-items:center;justify-content:center;box-shadow:0 0 0 3px rgba(150,200,255,.4),0 0 0 7px rgba(150,230,200,.18)}
#callFab .cf svg{width:28px;height:28px}
#callFab.speak .cf{animation:fabp 1.1s infinite}
@keyframes fabp{50%{box-shadow:0 0 0 5px rgba(80,220,140,.6),0 0 0 13px rgba(80,220,140,.2)}}
#callFab .ct{position:absolute;left:50%;bottom:-8px;transform:translateX(-50%);width:38px;height:17px;border-radius:9px;background:#f1f1f1;color:#555;display:flex;align-items:center;justify-content:center;box-shadow:0 1px 4px rgba(0,0,0,.35)}
#callFab .ct svg{width:14px;height:14px}
#callFab em{position:absolute;top:-4px;right:-4px;min-width:20px;height:20px;border-radius:10px;background:#e0405a;color:#fff;font-style:normal;font-size:12px;font-weight:700;display:none;align-items:center;justify-content:center;padding:0 5px}
#nmWrap{position:relative;height:0;z-index:5}
#newMsg{position:absolute;left:50%;bottom:12px;transform:translateX(-50%);display:none;align-items:center;gap:6px;border:0;border-radius:22px;background:#ececec;color:#333;font-size:16px;padding:9px 16px;cursor:pointer;white-space:nowrap;box-shadow:0 2px 8px rgba(0,0,0,.35)}
#newMsg.show{display:flex;animation:nmIn .18s ease-out}
@keyframes nmIn{from{opacity:0;transform:translate(-50%,8px)}}
#newMsg svg{width:17px;height:17px}
#dock{background:transparent;padding:10px 12px 10px;gap:9px}
.rb{width:48px;height:48px;box-shadow:none;color:#444}
.rb svg{width:25px;height:25px}
.rb.chev svg{transition:transform .2s}.rb.chev.open svg{transform:rotate(180deg)}
.chatp{height:48px;flex:none;border:0;border-radius:24px;background:#fff;color:#222;font-size:18px;font-weight:600;display:flex;align-items:center;gap:8px;padding:0 20px 0 16px;cursor:pointer}
.chatp svg{width:23px;height:23px}
.rb.heart{color:#aeb3b8}.rb.heart:active{color:#ff5a1f}
.rb.gift{background:none;width:54px}.rb.gift svg{width:46px;height:46px}
#bMic.wait{background:#ffe3c2;color:#d9741a}
#inbar{background:transparent;padding:0 12px 10px}
.bub{background:rgba(70,70,70,.58);border-radius:12px;font-size:18px}
.sys{font-weight:400;font-size:15px;background:rgba(70,70,70,.5);border-radius:12px;padding:12px 16px;line-height:1.45}
.bub.game{background:#fff3d6;color:#6b4200;border-left:4px solid #f0a020}
#gamebar{display:none;flex:none;background:linear-gradient(90deg,#ff9a3c,#ff5f6d);color:#fff;font-weight:700;font-size:14px;padding:8px 14px;cursor:pointer;text-align:center;animation:gpulse 1.6s infinite}
@keyframes gpulse{50%{filter:brightness(1.14)}}
.gh{position:relative;text-align:center;padding:4px 0 6px}.gh b{font-size:20px}.gh .sx{position:absolute;right:0;top:-2px}
.gsec{font-size:24px;font-weight:800;margin:16px 0 12px}
.gcards{display:flex;gap:14px;overflow-x:auto;padding-bottom:6px;scrollbar-width:none}.gcards::-webkit-scrollbar{display:none}
.gcard{flex:0 0 132px;cursor:pointer}.gcard b{display:block;font-size:18px;margin:10px 0 2px}.gcard small{color:#999;font-size:14px;line-height:1.3;display:block}
.gart{width:132px;height:132px;border-radius:26px;display:flex;align-items:center;justify-content:center}
.gart.lucky{background:linear-gradient(135deg,#3b6cf5,#6c9bff)}.gart.quiz{background:linear-gradient(135deg,#28c840,#58e36d)}.gart.link{background:linear-gradient(135deg,#7a5cf0,#a98bff)}.gart.preset{background:#f1f3f6;font-size:54px}
.gempty{display:flex;align-items:center;gap:16px;background:#f5f5f5;border-radius:20px;padding:22px;color:#999;font-size:17px;line-height:1.4}.gempty svg{width:52px;height:52px;flex:none;color:#bbb}
.gitem{background:#f7f8fa;border-radius:16px;padding:14px;margin-bottom:12px}
.gitem .gt{display:flex;align-items:center;justify-content:space-between;gap:8px;font-weight:800;font-size:17px}.gitem .gt small{color:#999;font-weight:600}
.gitem .gs{color:#666;margin:6px 0 10px;font-size:15px;line-height:1.35}
.gitem .gb{display:flex;flex-wrap:wrap;gap:8px}
.gopt{display:flex;align-items:center;gap:10px;width:100%;text-align:left;border:1px solid #e1e4e8;background:#fff;border-radius:12px;padding:12px 14px;margin:6px 0;font-size:16px;cursor:pointer;color:var(--ink)}
.gopt b{width:24px;height:24px;border-radius:50%;background:#eef1f5;display:inline-flex;align-items:center;justify-content:center;font-size:13px;flex:none}
.gopt.ok{border-color:#2a9d57;background:#e6f5ea}.gopt.me{border-color:var(--blue)}.gopt[disabled]{cursor:default}
.gcl{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}.gcl span{background:#fff;border:1px solid #eee;border-radius:12px;padding:3px 10px;font-size:13px}
#gframe{display:none;position:absolute;inset:0;z-index:35;background:#fff;flex-direction:column}#gframe.show{display:flex}
.gfh{display:flex;align-items:center;gap:10px;padding:calc(10px + env(safe-area-inset-top)) 12px 8px;border-bottom:1px solid #eee}
.gfh b{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.gfh a{color:var(--blue);font-weight:700;text-decoration:none}
.gfh button{border:0;background:#eee;width:36px;height:36px;border-radius:50%;font-size:16px;cursor:pointer}
#gframe small{padding:6px 12px;color:#999;background:#fafafa}#gframe iframe{flex:1;border:0;width:100%}
.qopt{display:flex;align-items:center;gap:8px;margin-bottom:8px}.qopt input.fld{margin:0;flex:1}
#dock{gap:8px}.rb{width:44px;height:44px}.chatp{height:44px;padding:0 14px 0 12px;gap:6px}.chatp svg{width:21px;height:21px}
.rb.game{background:none;width:44px}.rb.game svg{width:42px;height:42px}
.rb.gift{width:46px}.rb.gift svg{width:42px;height:42px}

.lv{display:inline-flex;align-items:center;gap:2px;color:#fff;font-size:11px;font-weight:800;padding:1px 7px 1px 4px;border-radius:9px;line-height:16px;vertical-align:middle;white-space:nowrap}
.lv svg{width:11px;height:11px}
.vipb{display:inline-flex;align-items:center;gap:3px;background:linear-gradient(135deg,#ffd75a,#f0a020);color:#6b4200;font-size:11px;font-weight:800;padding:1px 7px 1px 4px;border-radius:9px;line-height:16px;vertical-align:middle}
.vipb svg{width:12px;height:12px}
.who .lv,.who .vipb{margin-right:5px}.nm.vipn{color:#ffd24a}
.ava.vr{box-shadow:0 0 0 2px #fff,0 0 0 4px #f0a020}
.lvrow{display:flex;gap:6px;align-items:center;justify-content:center;flex-wrap:wrap;margin:2px 0 6px}
.expw{margin:10px 0 4px}.expbar{height:6px;background:#e9ecef;border-radius:3px;overflow:hidden}.expbar i{display:block;height:100%;background:linear-gradient(90deg,#7be86f,#3aa0d8);border-radius:3px}
.expw small{display:block;color:#999;font-size:12px;margin-top:4px;text-align:right}
.vhero{text-align:center;padding:4px 0 10px}.vcrown{width:66px;height:66px;border-radius:50%;margin:0 auto 8px;background:linear-gradient(135deg,#ffd75a,#f0a020);color:#6b4200;display:flex;align-items:center;justify-content:center}.vcrown svg{width:36px;height:36px}
.vperk{display:flex;gap:10px;align-items:center;padding:7px 0}.vperk svg{width:20px;height:20px;color:#2a9d57;flex:none}
.box .vplan{display:flex;justify-content:space-between;align-items:center;border:1px solid #eee;border-radius:12px;padding:14px 16px;margin:8px 0;cursor:pointer;font-weight:700;background:#fff;width:100%;font-size:16px;color:var(--ink)}
.pbtn.vipbtn{background:linear-gradient(135deg,#ffd75a,#f0a020);color:#5a3800}
.mon{display:flex;gap:9px;margin:10px 14px;padding:10px 12px;border-radius:10px;background:rgba(20,32,45,.62);color:#fff;font-size:14px;line-height:1.4;cursor:pointer}
.mon svg{width:20px;height:20px;flex:none;margin-top:1px}.mon.light{background:#f4f6f8;color:#555;margin:4px 0 12px;cursor:default}
/* ---- header room, pengumuman transparan, target, live berakhir, home Choice, online, Lucky Bag ---- */
.top{gap:6px}
.hpill{display:flex;align-items:center;gap:8px;border:1.5px solid rgba(255,255,255,.45);border-radius:32px;padding:4px 5px 4px 4px;min-width:0;flex:1 1 0;background:rgba(0,0,0,.28)}
.top .t3{width:30px;height:30px;margin-left:-10px}.top .t3:first-child{margin-left:0}.top #bTop3{padding-right:4px}
.top .hb{min-width:34px;padding:0 8px}
.hpill .ava{width:38px;height:38px;font-size:16px;flex:none}
.hpill .room-heading{min-width:0;flex:1}
.hpill .room-heading b{font-size:15px;font-weight:600;line-height:1.25}
.top .hpill small{display:block;letter-spacing:0;text-transform:none;font-weight:700;font-size:14px;opacity:.95;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.hfol{flex:none;width:30px;height:30px;border-radius:50%;border:0;background:#fff;color:#222;font-size:26px;line-height:1;display:flex;align-items:center;justify-content:center;cursor:pointer;padding:0 0 3px}
@media (max-width:440px){.stats .sig{display:none}.stats{gap:6px}.stats .sg{padding:5px 10px}}
#bAnnT{position:relative;flex:none;width:38px;height:38px;border-radius:50%;border:0;background:#fff;color:#222;display:flex;align-items:center;justify-content:center;cursor:pointer;padding:0}
#bAnnT svg{width:22px;height:22px}
#bAnnT.dot::after{content:'';position:absolute;top:1px;right:1px;width:10px;height:10px;border-radius:50%;background:#ff4b12;border:2px solid #fff}
#pin{display:none;position:absolute;left:12px;top:120px;width:min(380px,calc(100% - 84px));z-index:6;background:rgba(28,28,28,.68);border:1px solid rgba(255,255,255,.3);border-left-width:1px;border-radius:12px;padding:12px 14px;margin:0;color:#fff;backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px);cursor:pointer}
#pin.show{display:block;animation:nmIn .18s ease-out}
#roomv.sc #pin{margin-right:0}
#pin .ann b{display:block;font-size:15px;font-weight:500;letter-spacing:0;color:#ff6a3a;margin-bottom:4px;opacity:1}
#pin .pt{display:block;white-space:pre-wrap;overflow-wrap:anywhere;font-size:15px;line-height:1.4;max-height:30vh;overflow:auto}
#pin .gb{padding-bottom:10px;margin-bottom:10px;border-bottom:1px solid rgba(255,255,255,.3)}
#pin .gtt{display:block;font-size:16px;font-weight:600;margin-bottom:8px;color:#fff;opacity:1;letter-spacing:0}
.gbar{position:relative;height:26px;background:rgba(255,255,255,.28);border-radius:3px;overflow:hidden}
.gbar i{position:absolute;left:0;top:0;bottom:0;background:#ff7a50;transition:width .6s}
.gbar .gnum{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:15px;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.4)}
.gsc{display:flex;justify-content:space-between;font-size:14px;margin-top:4px}
#endv{display:none;position:absolute;inset:0;z-index:80;background:rgba(0,0,0,.93);color:#fff;flex-direction:column;align-items:center;justify-content:center;padding:24px;text-align:center}
#endv.show{display:flex}
#endv .ex{position:absolute;top:calc(14px + env(safe-area-inset-top,0px));right:14px;width:44px;height:44px;border:0;background:none;color:#fff;font-size:30px;cursor:pointer}
#endv h2{font-size:21px;font-weight:700;margin:0 0 26px}
#endv .eav{position:relative;width:82px;margin:0 auto 12px}
#endv .eav .ava{width:82px;height:82px;font-size:32px}
#endv .efol{position:absolute;left:50%;bottom:-12px;transform:translateX(-50%);width:76px;height:34px;border:0;border-radius:17px;background:#fff;color:#222;font-size:26px;line-height:1;cursor:pointer;padding:0 0 3px}
#endv .enm{font-size:19px;font-weight:700;margin:16px 0 26px}
#endv .erow{display:flex;width:100%;max-width:420px;background:#fff;border-radius:26px;overflow:hidden;border:2px solid #ddd}
#endv .erow input{flex:1;min-width:0;border:0;outline:none;padding:14px 18px;font-size:18px;font-family:inherit;color:#222;background:#fff}
#endv .erow button{border:0;background:#ffb98f;color:#fff;font-size:18px;font-weight:600;padding:0 20px;cursor:pointer}
#endv .erow button:not(:disabled){background:#ff7a2b}
.sech{display:flex;align-items:center;padding:14px 16px 8px}.sech b{flex:1;font-size:20px;font-weight:800}.sech .chev{color:#aaa;font-size:26px}
.chsec{padding-bottom:4px;border-bottom:8px solid #f2f2f2}
.chrow{display:flex;gap:12px;overflow-x:auto;padding:0 16px 14px;scroll-padding-left:16px;scroll-snap-type:x mandatory;scrollbar-width:none}.chrow::-webkit-scrollbar{display:none}
.chc{flex:0 0 74%;max-width:330px;scroll-snap-align:start;cursor:pointer}
.chi{height:176px;border-radius:16px;background-size:cover!important;background-position:center!important;position:relative;display:flex;align-items:center;justify-content:center;color:#fff;font-size:46px;overflow:hidden}
.ctag{position:absolute;left:10px;top:10px;background:#ff6a1a;color:#fff;font-size:12px;font-weight:800;padding:3px 9px;border-radius:12px;line-height:1.3}
.th .ctag{left:4px;top:4px;font-size:10px;padding:2px 6px}
.chc>b{display:block;margin:10px 2px 6px;font-size:17px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.chm{display:flex;align-items:center;gap:8px;padding:0 2px}.chm .ava{width:30px;height:30px;font-size:13px;flex:none}
.chm .chn{font-weight:600;font-size:14px;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.chm small{margin-left:auto;color:#999;font-size:13px;white-space:nowrap}
.ava.onl{position:relative}
.ava.onl::after{content:'';position:absolute;right:0;bottom:0;width:13px;height:13px;border-radius:50%;background:#2ecc71;border:2px solid #fff;box-sizing:border-box}
.ava.pfa.onl::after{width:22px;height:22px;right:6px;bottom:6px;border-width:3px}
.chip.on{background:var(--host);color:#fff;border-color:var(--host)}
.hi #hBag{color:#e0405a}.hi #hBag.has::after{content:'';position:absolute;top:7px;right:7px;width:9px;height:9px;border-radius:50%;background:#ff4b12;border:2px solid #fff}
#bagB{position:absolute;left:50%;top:calc(env(safe-area-inset-top,0px) + 74px);width:min(94%,440px);transform:translate(-50%,-160%);z-index:70;opacity:0;pointer-events:none;transition:transform .55s cubic-bezier(.2,1.3,.4,1),opacity .3s;cursor:pointer}
#bagB.show{transform:translate(-50%,0);opacity:1;pointer-events:auto}
.bgi{position:relative;display:flex;align-items:center;gap:12px;padding:16px 12px 14px 14px;border-radius:20px;color:#fff;overflow:hidden;background:radial-gradient(130% 150% at 50% 0%,#e8405a 0%,#b9163f 55%,#8a0f45 100%);border:3px solid #f6c95a;box-shadow:inset 0 0 0 2px #b88410,0 8px 26px rgba(0,0,0,.55),0 0 24px rgba(255,200,80,.35)}
.bgi::before{content:'';position:absolute;inset:0;background:linear-gradient(110deg,transparent 35%,rgba(255,255,255,.38) 50%,transparent 65%);transform:translateX(-120%);animation:bgs 2.2s ease-in-out infinite}
@keyframes bgs{to{transform:translateX(120%)}}
.bgi .bcr{position:absolute;top:-2px;left:50%;transform:translateX(-50%);width:92px;height:36px;pointer-events:none}
.bgi .bcn{position:absolute;top:-12px;width:9px;height:9px;border-radius:50%;background:radial-gradient(#ffe38a,#e0a020);animation:bgf 2.6s linear infinite;pointer-events:none}
@keyframes bgf{to{transform:translateY(110px) rotate(360deg);opacity:.2}}
.bgi .bga{width:56px;height:56px;font-size:22px;flex:none;box-shadow:0 0 0 3px #f6c95a,0 0 0 5px rgba(255,255,255,.7)}
.bgi .btx{min-width:0;flex:1;position:relative}
.bgi .bl1{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.bgi .bnm{font-size:18px;color:#ffd84a;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.bgi .bl2{margin-top:4px;font-size:16px;font-weight:600;display:flex;align-items:center;gap:5px;flex-wrap:wrap}
.bgi .bcoin{width:20px;height:20px}
.bgi .bgf{flex:none;width:58px;height:58px;position:relative;filter:drop-shadow(0 0 10px rgba(255,220,120,.8));animation:bgp 1.4s ease-in-out infinite}
@keyframes bgp{50%{transform:scale(1.08) rotate(-3deg)}}
.bgi .bgf svg{width:100%;height:100%}
.lbi{position:absolute;right:5px;bottom:5px;width:34px;height:34px;z-index:2;animation:lbw 1.1s ease-in-out infinite;filter:drop-shadow(0 2px 4px rgba(0,0,0,.5));pointer-events:none}
.lbi svg{width:100%;height:100%;display:block}
.hs .lbi,.chi .lbi{right:12px;top:12px;bottom:auto;width:46px;height:46px}
@keyframes lbw{0%,100%{transform:translateY(0) rotate(-7deg)}50%{transform:translateY(-6px) rotate(7deg) scale(1.06)}}
.bgi .bl3{font-size:13px;opacity:.92;margin-top:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
button,input,textarea,select{font-family:inherit}
@media (prefers-reduced-motion:reduce){.fl{animation-duration:.01s}}
</style></head>
<body><div id="app">

<div id="auth">
  <h1 id="at">Masuk</h1>
  <input id="au" placeholder="Username" autocomplete="username" autocapitalize="none">
  <input id="an" placeholder="Nama tampilan" maxlength="24" style="display:none">
  <input id="ap" type="password" placeholder="Password" autocomplete="current-password">
  <div id="aerr"></div>
  <button class="b" id="ab">Masuk</button>
  <button class="l" id="as">Belum punya akun? Daftar</button>
</div>

<section class="view" id="home">
  <div class="hd"><b class="logo">Live Room</b><div class="hi">
    <button id="hBag" type="button" aria-label="Lucky Bag" title="Lucky Bag"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8.500 3.500 10 6h4l1.500-2.500"/><path d="M9.500 6C6.500 9 4.500 12 4.500 15.500 4.500 19 7.500 21 12 21s7.500-2 7.500-5.500C19.500 12 17.500 9 14.500 6"/><path d="M12 10.500v7M10 12.500h3a1.300 1.300 0 0 1 0 2.600h-2a1.300 1.300 0 0 0 0 2.600h3"/></svg></button>
    <button id="hSearch" type="button" aria-label="Cari akun"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/></svg></button>
    <button id="hBell" type="button" aria-label="Notifikasi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 18h14l-1.700-2.600V11a5.300 5.300 0 0 0-10.600 0v4.400z"/><path d="M10 21a2 2 0 0 0 4 0"/></svg><em class="bdg"></em></button>
    <button id="hAdmin" type="button" aria-label="Panel admin" style="display:none"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 5 6v5c0 4.500 3 8 7 10 4-2 7-5.500 7-10V6z"/><path d="m9 12 2 2 4-4"/></svg></button>
    <button id="hTrophy" type="button" aria-label="Peringkat streamer dan spender"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 4h8v5a4 4 0 0 1-8 0z"/><path d="M8 6H4v1a4 4 0 0 0 4 4M16 6h4v1a4 4 0 0 1-4 4"/><path d="M12 13v4M9.500 20h5M10 17h4"/></svg></button>
    <button id="hDm" type="button" aria-label="Pesan"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 3 3 10.500l7 2.500 2.500 7z"/><path d="M21 3 10 13"/></svg><em class="bdg"></em></button>
  </div></div>
  <div class="tabs"><button class="on" data-t="live"><span>LIVE</span></button><button data-t="cast"><span>CAST</span></button><button data-t="talk"><span>TALK</span></button></div>
  <div id="list"></div>
  <div id="castTools" style="display:none;padding:12px 18px;border-top:1px solid #eee;background:#fff">
    <input id="castTitle" class="fld" maxlength="60" placeholder="Judul rekaman suara" style="border:1px solid #ddd;margin-bottom:8px">
    <div style="display:flex;gap:8px"><button class="pbtn" id="recStart" style="flex:1">● Rekam suara</button><button class="pbtn g" id="recStop" disabled style="flex:1">Selesai & simpan</button></div>
    <small id="recStatus" style="display:block;color:#888;margin-top:6px">Rekam hingga 60 detik, lalu simpan sebagai CAST.</small>
  </div>
  <div id="talkTools" style="display:none;padding:12px 14px;border-top:1px solid #eee;background:#fff">
    <textarea id="talkText" class="fld" maxlength="500" rows="2" placeholder="Apa yang sedang kamu pikirkan?" style="border:1px solid #ddd;resize:vertical"></textarea>
    <button class="pbtn" id="talkSend" style="width:100%;margin-top:8px">Kirim ke TALK</button>
  </div>
</section>

<section class="view" id="prof"><div id="myProf"></div></section>

<section class="view" id="sub">
  <div class="sbar"><button id="sBack" type="button" aria-label="Kembali">←</button><h2 id="sTitle"></h2><div id="sTools"></div></div>
  <div id="sBody"></div>
  <div id="sFoot"></div>
</section>

<div id="mini" role="button" aria-label="Kembali ke live">
  <div class="ava" id="mAva"></div>
  <div class="mi"><b id="mTitle"></b><small><span class="dot"></span>LIVE · <span id="mTm">00:00</span> · 👤 <span id="mOn">0</span></small></div>
  <div class="eq" id="mEq"><i></i><i></i><i></i><i></i></div>
  <button id="mMic" type="button" aria-label="Mute" style="display:none">🎤</button>
  <button id="mClose" type="button" aria-label="Keluar dari live">✕</button>
</div>

<nav id="nav">
  <button data-v="home" class="on" aria-label="Beranda"><span class="ic"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 11 12 3l9 8v10h-6v-6H9v6H3z"/></svg></span></button>
  <button data-v="new" aria-label="Mulai live" class="plus"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10.5"/><path d="M12 7v10M7 12h10"/></svg></span></button>
  <button data-v="prof" aria-label="Profil"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="8" r="4.2"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8"/></svg></span></button>
</nav>

<div id="bagB" role="button" tabindex="0" aria-label="Lucky Bag"></div>
<div id="endv"></div>
<section id="roomv">
<header>
  <div class="top"><div class="hpill" id="hpill"><div class="ava" id="hava"></div>
      <div class="room-heading"><b id="title"></b><small id="hname"></small></div>
      <button class="hfol" id="bFol" type="button" aria-label="Ikuti penyiar" title="Ikuti" style="display:none">+</button></div>
    <button id="bTop3" type="button" aria-label="Peringkat hadiah room" title="Top hadiah"></button>
    <button class="hb" id="bMin" type="button" aria-label="Perkecil room" title="Perkecil"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2.500"/><rect x="11" y="12" width="7" height="5" rx="1" fill="currentColor"/></svg></span></button>
    <button class="hb" id="bList" type="button" title="Pendengar" aria-label="Lihat pendengar"><span class="ic"><svg viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="8" r="3.6"/><path d="M2 20c0-3.900 3.100-7 7-7s7 3.100 7 7z"/><circle cx="17.500" cy="8.800" r="2.800"/><path d="M17.500 13c2.800 0 4.500 2.200 4.500 5v2h-4.500c0-2.600-.5-4.600-1.600-6 .4-.6 1-1 1.600-1z"/></svg></span><b id="lCnt">0</b></button>
  </div>
  <div class="stats">
    <button id="bAnnT" type="button" aria-label="Live Announcement" title="Pengumuman"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3 10v4a1 1 0 0 0 1 1h2l3.5 4h2.2l-1.2-4H12l7 3.500V6.500L12 10H4a1 1 0 0 0-1 1z"/><path d="M20.500 9.500v5" stroke="currentColor" stroke-width="1.800" stroke-linecap="round" fill="none"/></svg></button>
    <span class="sg tp" id="sTop" title="Peringkat room berdasarkan total taplov"><b id="sTopN">—</b></span>
    <button class="sg" id="sLkB" type="button" title="Tap love"><svg viewBox="0 0 24 24" aria-hidden="true" fill="currentColor"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg> <b id="sLk">0</b></button>
    <span class="sg" style="cursor:default"><svg viewBox="0 0 24 24" aria-hidden="true" fill="currentColor"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/></svg> <b id="sTm">00:00</b></span>
    <div class="sg sig" id="sig"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div>
  </div>
</header>
<div id="unlock">🔈 Ketuk untuk mengaktifkan suara</div>
<div id="stageWrap">
  <div id="stgCap">Ketuk kursi kosong untuk minta bicara</div>
  <div id="stage"></div>
  <button id="stgCol" type="button" aria-label="Ciutkan kursi, tampilkan ikon call" title="Ciutkan"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m6 15 6-6 6 6"/></svg></button>
</div>
<div id="nowp"><span class="nmi">🎵</span><div class="npt" id="npI"><b id="npT"></b><small id="npA"></small></div><button id="npP" type="button" aria-label="Putar atau jeda" style="display:none">⏸</button><button id="npN" type="button" aria-label="Lagu berikutnya" style="display:none">⏭</button><button id="npM" type="button" aria-label="Bisukan musik">🔊</button></div>
<div id="gamebar" role="button" tabindex="0"></div>
<div id="fabWrap"><button id="callFab" type="button" aria-label="Buka kursi panggung" title="Buka kursi"><span class="cf"><svg viewBox="0 0 24 24" aria-hidden="true" fill="currentColor"><path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></svg><em id="fabRq"></em></span><span class="ct"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></span></button></div>
<div id="pin" role="button" tabindex="0"></div>
<div id="chat"></div><div id="float"></div>
<div id="nmWrap"><button id="newMsg" type="button" aria-label="Lihat pesan baru">Lihat pesan baru <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></button></div>
<div id="dock">
  <button class="rb chev" id="bMore" type="button" aria-label="Menu room" title="Menu"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m6 15 6-6 6 6"/></svg></button>
  <button class="chatp" id="bChat" type="button" aria-label="Tulis pesan"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M7.9 20A9 9 0 1 0 4 16.1L2 22z"/><path d="M8.5 12h.01M12 12h.01M15.5 12h.01" stroke-width="3"/></svg><span>Chat</span></button>
  <span class="sp"></span>
  <button class="rb" id="bMic" type="button" aria-label="Mikrofon"><svg viewBox="0 0 24 24" aria-hidden="true" fill="currentColor"><path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></svg></button>
  <button class="rb heart" id="bLike" type="button" aria-label="Suka"><svg viewBox="0 0 24 24" aria-hidden="true" fill="currentColor"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg></button>
  <button class="rb game" id="bGame" type="button" aria-label="Live Game" title="Game"><svg viewBox="0 0 48 48" aria-hidden="true"><path fill="#fff" d="M14 15h20a9 9 0 0 1 8.7 11.3l-1.7 7a5 5 0 0 1-8.6 1.9L29 31H19l-3.4 4.2a5 5 0 0 1-8.6-1.9l-1.7-7A9 9 0 0 1 14 15z"/><path fill="#6b7280" d="M13 20h3v3h3v3h-3v3h-3v-3h-3v-3h3z"/><circle cx="31" cy="21" r="2.6" fill="#e8452c"/><circle cx="36" cy="26" r="2.6" fill="#e8452c"/><circle cx="24" cy="23" r="1.2" fill="#c5ccd6"/></svg></button>
  <button class="rb gift" id="bGift" type="button" aria-label="Hadiah"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#ff7a2b" d="M3 8.5h18V12H3z"/><path fill="#ff9650" d="M4.5 12h15v9h-15z"/><path fill="#fff" d="M11 8.5h2V21h-2z"/><path fill="none" stroke="#ff7a2b" stroke-width="2" stroke-linecap="round" d="M12 8.5C10 4.5 6 5 7 7.5c.5 1.3 3 1 5 1zm0 0c2-4 6-3.5 5-1-.5 1.3-3 1-5 1z"/></svg></button>
</div>
<div id="inbar"><label id="bImg" class="fbtn" title="Kirim foto" aria-label="Kirim foto">📷<input id="bImgF" class="cvf" type="file" accept="image/*"></label><button id="bAnn" style="display:none" title="Pengumuman">📢</button><input id="msg" placeholder="Tulis pesan…" maxlength="300" autocomplete="off"><button id="send">Kirim</button></div>
<div id="acts" class="hide">
  <button class="act" data-a="game"><i style="background:#5b7cfa">🎮</i>Game</button>
  <button class="act" data-a="bag"><i style="background:#e0405a">🧧</i>Lucky Bag</button>
  <button class="act" data-a="goal" style="display:none"><i style="background:#ff7a2b">🎯</i>Target</button>
  <button class="act" data-a="stage"><i style="background:#9b7fe0">🎙</i>Panggung<em id="rq">0</em></button>
  <button class="act" data-a="music"><i style="background:#f08cc0">🎵</i>Musik</button>
  <button class="act" data-a="skrip"><i style="background:#40c4bb">💬</i>Skrip</button>
  <button class="act" data-a="ubah"><i style="background:#f6aeae">✎</i>Ubah</button>
  <button class="act" data-a="hifi"><i style="background:#7a5cff">🎚</i>Audio</button>
  <button class="act" data-a="freeze"><i style="background:#52aee8">❄</i><span id="frzL">Bekukan</span></button>
  <button class="act" data-a="clear" style="display:none"><i style="background:#e8806a">🧹</i>Bersihkan</button>
  <button class="act" data-a="share"><i style="background:#82d4a8">➦</i>Bagikan</button>
</div>
</section>

<div id="sheet"><div class="box" id="box"></div></div>
<div id="gframe"><div class="gfh"><b id="gfT"></b><a id="gfO" target="_blank" rel="noopener noreferrer">Tab baru</a><button id="gfX" type="button" aria-label="Tutup">✕</button></div><small>Jika halaman tidak tampil, buka di tab baru.</small><iframe id="gfF" title="Game" sandbox="allow-scripts allow-same-origin allow-forms allow-popups" referrerpolicy="no-referrer"></iframe></div>
<div id="lb"><img alt=""><button type="button" aria-label="Tutup">✕</button></div>
</div>
<script src="https://cdn.jsdelivr.net/npm/livekit-client@2.5.0/dist/livekit-client.umd.min.js"></script>
<script>
const $=s=>document.querySelector(s),chat=$('#chat'),pad=n=>String(n).padStart(2,'0');
const GIFTS=<?= json_encode(GIFTS) ?>,GAME_PRESETS=<?= json_encode(GAME_PRESETS) ?>,MONITOR=<?= json_encode(MONITOR_TEXT) ?>,RULES=<?= json_encode(RULES) ?>;
let TOK=localStorage.getItem('tok')||'',P=null,curRoom=0,tokenPub=false,recon=false,peak=0,last=0,me=null,announce=false,frozen=false,first=true,lk=null,micOn=false,muted=false,lastData={reqs:[],stage:[]},regMode=false,lastClr=0,lastBag=-1;
const colors=['#d86a6a','#c08a3e','#4fa07a','#5a82c9','#9b6bc4','#c76ba0','#5ea5b5'];
const col=n=>colors[[...String(n)].reduce((a,c)=>a+c.charCodeAt(0),0)%colors.length];
const el=(t,c,x)=>{const e=document.createElement(t);if(c)e.className=c;if(x!=null)e.textContent=x;return e};
const bg=(n,ph)=>ph?`center/cover url(uploads/${ph})`:col(n);
const ava=(n,ph,cls,vip)=>{const d=el('div','ava'+(cls?' '+cls:'')+(vip?' vr':''),ph?'':(String(n)[0]||'?'));d.style.background=bg(n,ph);return d};
/* ---- level (dari EXP) dan VIP ---- */
const lvl=x=>Math.min(99,Math.floor(Math.sqrt(Math.max(0,+x||0)/100))+1);
const LVC=l=>l>=50?'#e8a317':l>=30?'#e0405a':l>=20?'#7a5cf0':l>=10?'#3aa0d8':'#8e9aa6';
const XI={shield:'<path d="M12 3l8 3v6c0 4.5-3.3 8-8 9-4.7-1-8-4.5-8-9V6z"/><path d="M9 12l2 2 4-4"/>',check:'<path d="M5 12.5l4.5 4.5L19 7.5"/>',ban:'<circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/>'};
const xsvg=(k,st)=>'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="'+(st||1.8)+'" stroke-linecap="round" stroke-linejoin="round">'+XI[k]+'</svg>';
function lvBadge(exp){const l=lvl(exp),e=el('span','lv');e.style.background=LVC(l);e.innerHTML='<svg viewBox="0 0 24 24" fill="currentColor"><path d="M13 2 4 14h6l-1 8 9-12h-6z"/></svg>'+l;e.title='Level '+l;return e}
const CROWN='<svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 8l4.5 4L12 5l4.5 7L21 8l-2 11H5z"/></svg>';
function vipBadge(){const e=el('span','vipb');e.innerHTML=CROWN+'VIP';e.title='Member VIP';return e}
function badgeRow(p){const r=el('div','lvrow');r.append(lvBadge(p.exp));if(+p.vip>0)r.append(vipBadge());return r}
function expBar(exp){exp=+exp||0;const l=lvl(exp),cur=100*(l-1)*(l-1),next=100*l*l,w=l>=99?100:Math.round((exp-cur)/(next-cur)*100);
  const c=el('div','expw'),bar=el('div','expbar'),i=el('i');i.style.width=w+'%';bar.append(i);c.append(bar,el('small',null,'EXP '+nf(exp)+' / '+nf(next)));return c}
const ICO={
 person:'<svg viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="8" r="4.2"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8z"/></svg>',
 heart:'<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 21s-8.5-5.4-8.5-11.3A4.7 4.7 0 0 1 12 7a4.7 4.7 0 0 1 8.5 2.7C20.5 15.600 12 21 12 21z"/></svg>',
 live:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M8 6.500a7 7 0 0 0 0 11M16 6.500a7 7 0 0 1 0 11"/><circle cx="12" cy="12" r="1.200" fill="currentColor"/></svg>'};
const BD={admin:['ADMIN','admin'],choice:['★ CHOICE','choice']};
const bdg=(b,sm)=>{if(!BD[b])return null;return el('span','tag '+BD[b][1]+(sm?' sm':''),BD[b][0])};
const nameB=(n,b)=>{const x=el('b',null,n),d=bdg(b,1);if(d)x.append(d);return x};
const ic=(k)=>{const s=el('span','ic');s.innerHTML=ICO[k];return s};

async function api(a,d){
  let r;
  try{r=await(await fetch('?a='+a+'&t='+TOK+(d?'':'&since='+last+'&clr='+lastClr+'&bag='+lastBag),{method:d?'POST':'GET',body:d?(d instanceof FormData?d:new URLSearchParams(d)):undefined})).json()}catch(e){return{err:'Koneksi bermasalah'}}
  if(r.err==='login'&&TOK)logout();
  if(r.err==='kicked'&&curRoom){alert('Kamu dikeluarkan dari live ini.');closeRoom(true)}
  if(r.ended&&curRoom){roomEnded()}
  return r;
}
const nudge=()=>{try{lk&&lk.localParticipant.publishData(new TextEncoder().encode('n'),{reliable:true})}catch(e){}};
const act=async(a,d)=>{const r=await api(a,d||{});if(r.err)toast(r.err);nudge();poll();return r};
function toast(t){chat.append(el('div','sys',t));chat.scrollTop=chat.scrollHeight}

/* ---------- auth ---------- */
function showAuth(){stopSub();$('#auth').style.display='flex';$('#roomv').classList.remove('show');$('#mini').classList.remove('show');['#home','#prof'].forEach(s=>$(s).classList.remove('show'));$('#nav').classList.remove('show')}
function logout(){if(curRoom){fetch('?a=exit&t='+TOK,{method:'POST'}).catch(()=>{});leaveLocal()}TOK='';localStorage.removeItem('tok');P=null;showAuth()}
$('#as').onclick=()=>{regMode=!regMode;$('#at').textContent=regMode?'Daftar':'Masuk';$('#ab').textContent=regMode?'Buat akun':'Masuk';$('#an').style.display=regMode?'':'none';$('#as').textContent=regMode?'Sudah punya akun? Masuk':'Belum punya akun? Daftar'};
$('#ab').onclick=async()=>{
  const d=await api(regMode?'reg':'login',{username:$('#au').value,password:$('#ap').value,name:$('#an').value});
  if(d.err){$('#aerr').textContent=d.err;return}
  TOK=d.t;localStorage.setItem('tok',TOK);$('#auth').style.display='none';start();
};
$('#ap').onkeydown=e=>{if(e.key==='Enter')$('#ab').click()};
async function start(){
  const d=await api('me');if(!d.me){showAuth();return}
  P=d.me;syncAdmin();$('#auth').style.display='none';go('home');refreshBadges();
  const rid=new URLSearchParams(location.search).get('room');if(rid){history.replaceState(null,'',location.pathname);openRoom(+rid)}
}

/* ---------- navigasi ---------- */
function go(v){
  if(v==='new')return newLive();
  if(subShown()){stopSub();subDrop()}
  if(v==='home'||v==='prof')curMain=v;
  $('#home').classList.toggle('show',v==='home');$('#prof').classList.toggle('show',v==='prof');$('#nav').classList.add('show');
  document.querySelectorAll('#nav button').forEach(b=>b.classList.toggle('on',b.dataset.v===v));
  if(v==='home')loadHome();if(v==='prof')fillProfile();
}
document.querySelectorAll('#nav button').forEach(b=>b.onclick=()=>go(b.dataset.v));
let tab='live';
document.querySelectorAll('.tabs button').forEach(b=>b.onclick=()=>{tab=b.dataset.t;document.querySelectorAll('.tabs button').forEach(x=>x.classList.toggle('on',x===b));loadHome()});

/* ---------- beranda ---------- */
let heroTimer=0;
function buildHero(info){
  clearInterval(heroTimer);
  const wrap=el('div','hero'),sl=el('div','hsl'),cnt=el('div','hcnt');
  const list=info.banners.length?info.banners:[null];
  list.forEach(r=>{
    const s=el('div','hs');
    if(r){
      s.style.background=`linear-gradient(transparent 40%,rgba(0,0,0,.7)),${bg(r.title,r.cover||r.hphoto)}`;
      s.append(el('span','htag','● LIVE'),el('b','ht',r.title),el('div','hh',r.host+' · 👤 '+r.listeners+'  ♥ '+r.likes));if(+r.bags)s.append(lbIcon());
      s.onclick=()=>openRoom(+r.id);
    }else{
      s.style.background='linear-gradient(135deg,#ffb43a,#ff6f61)';
      s.append(el('b','ht','Belum ada yang live'),el('div','hh','Jadilah yang pertama — ketuk untuk mulai siaran'));
      s.onclick=()=>newLive();
    }
    sl.append(s);
  });
  const N=list.length,upd=()=>{cnt.textContent=(Math.round(sl.scrollLeft/Math.max(1,sl.clientWidth))+1)+' / '+N};
  upd();sl.addEventListener('scroll',upd,{passive:true});
  wrap.append(sl);if(N>1)wrap.append(cnt);
  if(N>1)heroTimer=setInterval(()=>{if(!document.body.contains(sl)){clearInterval(heroTimer);return}
    const i=(Math.round(sl.scrollLeft/Math.max(1,sl.clientWidth))+1)%N;sl.scrollTo({left:i*sl.clientWidth,behavior:'smooth'})},4500);
  return wrap;
}
function buildDj(list){
  const row=el('div','topdj'),t3=el('span','t3s');row.append(el('b',null,'TOP STREAMER'));
  [0,1,2].forEach(n=>{const p=list[n],x=el('span','t3 r'+(n+1)+(p?'':' em'));if(p)x.append(ava(p.name,p.photo),el('i',null,n+1));t3.append(x)});
  row.append(t3,el('span','chev','›'));
  row.onclick=()=>openBoardDetail('likes_gifts','Taplov + Hadiah');
  return row;
}
async function loadHome(){
  const L=$('#list'),ct=$('#castTools'),tt=$('#talkTools');
  ct.style.display=tab==='cast'?'block':'none';tt.style.display=tab==='talk'?'block':'none';
  if(tab==='live'){
    const [d,info0]=await Promise.all([api('rooms'),api('homeinfo',{})]);
    if(!d.rooms)return;
    const info=info0&&info0.banners?info0:{banners:[],dj:[]};
    const ch=d.rooms.filter(r=>r.badge==='choice');$('#hBag').classList.toggle('has',(info.bags||0)>0);
    const key=JSON.stringify([ch.map(r=>[r.id,r.title,r.cover,r.hphoto,r.listeners,r.likes,r.bags]),info.banners.map(r=>[r.id,r.title,r.cover,r.hphoto,r.bags]),(info.dj||[]).map(r=>[r.id,r.photo])]);
    let top=L.firstElementChild&&L.firstElementChild.classList.contains('htop')?L.firstElementChild:null;
    if(!top||top.dataset.k!==key){
      const t=el('div','htop');t.dataset.k=key;t.append(buildChoice(ch),buildHero(info),buildDj(info.dj||[]));
      if(top){top.replaceWith(t)}else L.replaceChildren(t);top=t;
    }
    [...L.children].forEach(c=>{if(c!==top)c.remove()});
    if(!d.rooms.length){const e=el('div','empty','Belum ada yang live.\nTekan tombol + untuk mulai siaran.');e.style.whiteSpace='pre-line';L.append(e);return}
    {const hd=el('div','sech');hd.append(el('b',null,'Top Live'));L.append(hd)}
    L.append(...d.rooms.map(r=>{
      const it=el('div','it'),th=el('div','th',(r.cover||r.hphoto)?'':r.title[0].toUpperCase());
      th.style.background=bg(r.title,r.cover||r.hphoto);if(r.badge==='choice')th.append(el('span','ctag','Choice'));if(+r.bags)th.append(lbIcon());
      if(+r.speakers){const b=el('div','bd');b.append(ic('live'));th.append(b)}
      const inf=el('div','inf');inf.append(el('b',null,r.title),el('div','h',r.host));
      const st=el('div','s'),a=el('span');a.append(ic('person'),document.createTextNode(r.listeners));
      const h=el('span');h.append(ic('heart'),document.createTextNode(r.likes));st.append(a,h);inf.append(st);
      it.append(th,inf);it.onclick=()=>openRoom(r.id);return it}));
    return;
  }
  if(tab==='cast'){
    const d=await api('casts');if(!d.casts)return;
    if(!d.casts.length){L.replaceChildren(el('div','empty','Belum ada CAST. Buat rekaman suara pertamamu di bawah.'));return}
    L.replaceChildren(...d.casts.map(c=>{
      const card=el('article','cast-card'),head=el('div','cast-head');
      head.append(ava(c.name,c.photo),(()=>{const x=el('div','cast-meta');x.append(el('b',null,c.title),el('small',null,c.name+' · '+new Date(c.ts*1000).toLocaleString('id-ID')));return x})());
      const audio=el('audio');audio.controls=true;audio.preload='none';audio.src='uploads/'+encodeURIComponent(c.file);
      if(+c.uid===+P.id){const del=btn('🗑',async()=>{if(!confirm('Hapus CAST "'+c.title+'"?'))return;audio.pause();const r=await api('castdel',{id:c.id});if(r.err)alert(r.err);else loadHome()},'cdel');del.title='Hapus rekaman';del.setAttribute('aria-label','Hapus rekaman');head.append(del)}
      card.append(head,audio);
      return card;
    }));return;
  }
  const d=await api('talks');if(!d.talks)return;
  if(!d.talks.length){L.replaceChildren(el('div','empty','Belum ada obrolan. Mulai percakapan di TALK.'));return}
  L.replaceChildren(...d.talks.map(t=>{
    const card=el('article','talk-card'),head=el('div','talk-head');
    head.append(ava(t.name,t.photo),(()=>{const x=el('div','cast-meta');x.append(nameB(t.name,t.badge),el('small',null,new Date(t.ts*1000).toLocaleString('id-ID')));return x})());
    card.append(head,el('div','talk-body',t.text));
    if(+t.uid===+P.id)card.append(btn('Hapus',async()=>{await api('talkdel',{id:t.id});loadHome()},'feed-action'));
    return card;
  }));
}
setInterval(()=>{if($('#home').classList.contains('show')&&!(tab==='cast'&&[...document.querySelectorAll('#list audio')].some(a=>!a.paused)))loadHome()},5000);

/* ---------- profil ---------- */
async function fillProfile(){await refreshMe();renderProfile($('#myProf'),P.id,true)}
function shrink(file,max,cb){const img=new Image();img.onload=()=>{const k=Math.min(1,max/Math.max(img.width,img.height));const c=el('canvas');c.width=Math.round(img.width*k);c.height=Math.round(img.height*k);c.getContext('2d').drawImage(img,0,0,c.width,c.height);c.toBlob(cb,'image/jpeg',.85)};img.src=URL.createObjectURL(file)}
const upload=(kind,file,extra,max)=>new Promise(res=>shrink(file,max||640,async b=>{const f=new FormData();f.append('f',b,'p.jpg');for(const k in(extra||{}))f.append(k,extra[k]);res(await api(kind,f))}));

/* ---------- mulai live ---------- */
function coverPicker(cur){
  const st={file:null,url:null,remove:false};
  const prev=el('div','cvp'),vis=el('div','cvv'),f=el('input','cvf');f.type='file';f.accept='image/*';f.setAttribute('aria-label','Pilih cover live');
  prev.append(vis,f);const hint=el('small','cvh');
  const clear=btn('',()=>{if(st.file)st.file=null;else st.remove=true;paint()},'chip');clear.style.cssText='display:none;margin:0 auto 12px';
  const paint=()=>{
    vis.replaceChildren();const has=!st.file&&!!cur&&!st.remove;
    if(st.file)vis.style.background=`center/cover url(${st.url})`;
    else if(has)vis.style.background=`center/cover url(uploads/${cur})`;
    else{vis.style.background=bg(P.name,P.photo);if(!P.photo)vis.append(document.createTextNode((P.name||'?')[0].toUpperCase()))}
    hint.textContent=st.file?'Cover baru dipilih. Ketuk untuk ganti.':has?'Cover saat ini. Ketuk untuk ganti.':'Cover default: foto profilmu. Ketuk untuk memilih cover.';
    vis.append(el('em',null,'✎'));
    clear.textContent=st.file?'Batalkan pilihan':'Hapus cover';clear.style.display=(st.file||has)?'block':'none';
  };
  f.onchange=()=>{const x=f.files&&f.files[0];if(!x)return;st.file=x;st.remove=false;if(st.url)URL.revokeObjectURL(st.url);st.url=URL.createObjectURL(x);paint();f.value=''};
  paint();return{nodes:[prev,hint,clear],st};
}
async function newLive(){
  if(curRoom){await closeRoom();if(curRoom)return}
  const cp=coverPicker('');
  const t=el('input','fld');t.placeholder='Judul live';t.maxLength=40;
  const w=el('textarea','fld');w.placeholder='Kata sambutan / pengumuman room (opsional)';w.maxLength=200;w.rows=3;
  const start=btn('Mulai siaran',async()=>{
    if(!t.value.trim())return alert('Isi judul live');
    start.disabled=true;
    const r=await api('create',{title:t.value,welcome:w.value});if(r.err){start.disabled=false;return alert(r.err)}
    if(cp.st.file){const u=await upload('cover',cp.st.file,{room:r.id},1080);if(u&&u.err)alert('Cover gagal diunggah ('+u.err+'), memakai cover default.')}
    closeSheet();openRoom(r.id)},'ok');
  sheet([el('h3',null,'Mulai live'),...cp.nodes,t,w,monitorNote(true),start]);
}

/* ---------- room: buka / minimize / kembali / keluar ---------- */
let roomHist=false,ignorePop=false,listOpen=false,lcTick=0;
const roomShown=()=>$('#roomv').classList.contains('show');
function histPush(){if(!roomHist){history.pushState({liveRoom:curRoom},'',location.href);roomHist=true}}
function histDrop(){if(roomHist){roomHist=false;ignorePop=true;history.back()}}
async function openRoom(id){
  if(curRoom===id){restoreRoom();return}
  if(curRoom){await closeRoom(false,true);if(curRoom)return}
  const r=await api('enter',{room:id});if(r.err)return alert(r.err);
  curRoom=id;last=0;lastClr=0;lastBag=-1;first=true;chat.replaceChildren(monitorNote());$('#stage').replaceChildren();hideNM();setStageMode(false);speakSet=new Set();me=null;muted=false;micOn=false;announce=false;peak=0;
  $('#inbar').classList.remove('show');$('#mini').classList.remove('show');$('#nav').classList.remove('show');
  {const pin=$('#pin');pin.dataset.s='';pin.classList.remove('show');pin.replaceChildren();$('#bAnnT').classList.remove('dot');$('#bTop3').dataset.k='';const rv=$('#roomv');rv.dataset.c='';rv.style.background=''}
  $('#roomv').classList.add('show');histPush();await poll();voice();
}
function leaveLocal(){
  try{if(window.BalanceAndroid&&typeof window.BalanceAndroid.stopLiveAudio==='function')window.BalanceAndroid.stopLiveAudio()}catch(e){}
  curRoom=0;lastBag=-1;bagQ=[];bagBusy=false;clearTimeout(bagT);$('#bagB').classList.remove('show');$('#pin').classList.remove('show');stopMusic();gList=[];$('#gamebar').style.display='none';$('#gframe').classList.remove('show');$('#gfF').src='about:blank';
  if(lk){const x=lk;lk=null;try{x.disconnect()}catch(e){}}
  document.querySelectorAll('audio[data-lk-track-id]').forEach(e=>e.remove());
  $('#roomv').classList.remove('show');$('#mini').classList.remove('show');closeSheet();histDrop();
}
async function closeRoom(skipApi,quiet){
  if(!skipApi){
    if(me&&me.role==='penyiar'){if(!confirm('Akhiri live untuk semua pendengar?'))return;await api('end',{})}
    await api('exit',{});
  }
  if(quiet){const h=roomHist;roomHist=false;leaveLocal();roomHist=h;return}
  leaveLocal();$('#nav').classList.add('show');if(!subShown())go(curMain);
}
function minimizeRoom(fromPop){
  if(!curRoom||!roomShown())return;
  closeSheet();$('#inbar').classList.remove('show');
  $('#roomv').classList.remove('show');
  if(!fromPop)histDrop();
  $('#mini').classList.add('show');$('#nav').classList.add('show');
  // LiveKit, mikrofon, chat, dan polling tetap berjalan di latar.
}
function restoreRoom(){
  if(!curRoom)return;
  $('#mini').classList.remove('show');$('#nav').classList.remove('show');
  $('#roomv').classList.add('show');histPush();chat.scrollTop=chat.scrollHeight;
}
// Tombol Back (Android/browser): tutup sheet dulu, lalu perkecil room, atau tutup halaman sub.
window.addEventListener('popstate',()=>{
  if(ignorePop){ignorePop=false;return}
  if(roomHist){
    roomHist=false;
    if(curRoom&&roomShown()){
      if($('#sheet').classList.contains('show')){closeSheet();histPush();return}
      minimizeRoom(true);
    }
    return;
  }
  if(subHist){
    subHist=false;
    if($('#sheet').classList.contains('show')){closeSheet();subPush();return}
    if(subBackFn){subPush();subBackFn()}else closeSub(true);
  }
});
$('#bTop3').onclick=()=>showRank();
$('#bMin').onclick=e=>{e.stopPropagation();minimizeRoom()};
$('#mini').onclick=restoreRoom;
$('#pin').onclick=()=>{clearTimeout(annTimer);$('#pin').classList.remove('show')};
$('#mClose').onclick=e=>{e.stopPropagation();closeRoom()};
$('#mMic').onclick=e=>{e.stopPropagation();if(me&&me.mic===2){muted=!muted;micUI();syncMic()}};
$('#sLkB').onclick=()=>{fly('❤️');act('like')};
$('#bList').onclick=()=>showListeners();

async function showListeners(refresh){
  const d=await api('listeners',{});if(!d.rows)return;
  if(refresh&&!listOpen)return;
  const sc=$('#box').scrollTop,head=el('div','shead');
  head.append(el('h3',null,`Pendengar (${d.count})`),btn('✕',closeSheet,'sx'));
  head.lastChild.setAttribute('aria-label','Tutup');
  const nodes=[head];
  d.rows.forEach(r=>{
    const row=el('div','row');row.append(ava(r.name,r.photo,'',+r.vip));row.style.cursor='pointer';row.onclick=()=>showProfile(r.id);
    const nm=el('span','grow',r.name+(me&&+r.id===+me.id?' (kamu)':''));nm.append(' ',lvBadge(r.exp));if(+r.vip)nm.append(' ',vipBadge());row.append(nm);
    if(r.role!=='member')row.append(el('span','tag '+r.role,r.role==='penyiar'?'PENYIAR':'Manajer'));
    {const bd=bdg(r.badge);if(bd)row.append(bd)}
    if(+r.mic===2)row.append(el('span',null,'🎙'));
    nodes.push(row);
  });
  sheet(nodes);listOpen=true;$('#box').scrollTop=refresh?sc:0;
}

/* ---------- halaman sub: cari, notifikasi, peringkat, DM ---------- */
let curMain='home',subHist=false,subBackFn=null,subTimer=0,subTok=0,subRe=null;
const nf=n=>Number(n).toLocaleString('id-ID');
const ago=ts=>{const d=Math.floor(Date.now()/1000)-ts;if(d<60)return 'baru saja';if(d<3600)return Math.floor(d/60)+' mnt';if(d<86400)return Math.floor(d/3600)+' jam';return new Date(ts*1000).toLocaleDateString('id-ID',{day:'numeric',month:'short'})};
const subShown=()=>$('#sub').classList.contains('show');
function subPush(){if(!subHist){history.pushState({sub:1},'',location.href);subHist=true}}
function subDrop(){if(subHist){subHist=false;ignorePop=true;history.back()}}
function stopSub(){clearInterval(subTimer);subTimer=0;subBackFn=null;$('#sub').classList.remove('show')}
function openSub(title,opts){
  // jika room sedang tampil: perkecil dulu, entri history-nya dipakai ulang untuk halaman ini
  if(roomShown()){minimizeRoom(true);if(roomHist){roomHist=false;subHist=true}}
  stopSub();closeSheet();subTok++;$('#sub').classList.remove('gray');
  ['#home','#prof'].forEach(x=>$(x).classList.remove('show'));
  $('#nav').classList.add('show');$('#sub').classList.add('show');
  $('#sTitle').textContent=title;$('#sBody').replaceChildren();$('#sBody').scrollTop=0;$('#sFoot').replaceChildren();$('#sTools').replaceChildren();
  subBackFn=(opts&&opts.back)||null;subRe=(opts&&opts.re)||null;subPush();
  return $('#sBody');
}
function closeSub(fromPop){stopSub();if(!fromPop)subDrop();go(curMain)}
$('#sBack').onclick=()=>subBackFn?subBackFn():closeSub();
function setBadge(sel,n){const e=$(sel+' .bdg');e.textContent=n>9?'9+':n;e.style.display=n?'flex':'none'}
async function refreshBadges(){if(!TOK||!P)return;const d=await api('badges',{});if(d.n===undefined)return;setBadge('#hBell',d.n);setBadge('#hDm',d.d)}
setInterval(refreshBadges,8000);

function userRow(u){
  const r=el('div','urow');r.append(onv(ava(u.name,u.photo,'',+u.vip),+u.onl));
  const m=el('div','um'),nb=nameB(u.name,u.badge);if(u.exp!==undefined)nb.append(' ',lvBadge(u.exp));if(+u.vip)nb.append(' ',vipBadge());m.append(nb,el('small',null,'@'+u.username+' · ID '+u.id));r.append(m);
  if(+u.live)r.append(el('span','livetag','LIVE'));
  r.onclick=()=>openProfile(u.id);return r;
}
async function showProfile(uid){
  const d=await api('profile',{uid});if(!d.p)return alert(d.err||'Gagal memuat profil');const p=d.p;
  const big=onv(ava(p.name,p.photo,'',+p.vip),+p.onl);big.style.cssText='width:96px;height:96px;font-size:38px;margin:0 auto 10px';
  const h=el('h3',null,p.name);h.style.textAlign='center';
  const sub=el('div',null,'@'+p.username+' · ID '+p.id+(+p.hid?' · online (disembunyikan)':''));sub.style.cssText='text-align:center;color:#888;margin-bottom:8px';
  const st=el('div','pst');st.style.marginBottom='10px';
  [['FANS',p.fans],['Mengikuti',p.following]].forEach(([l,n])=>{const c=el('div','psc');c.append(el('b',null,nf(n)),el('span',null,l));st.append(c)});
  const nodes=[big,h,badgeRow(p),sub,expBar(p.exp),st];
  if(p.bio){const bi=el('div',null,p.bio);bi.style.cssText='text-align:center;margin:0 0 12px;line-height:1.4';nodes.push(bi)}
  const bar=el('div','chips');bar.style.justifyContent='center';
  if(+p.id!==+P.id){
    const fb=btn('',async()=>{const r=await api('follow',{uid:p.id,on:+p.isfan?'0':'1'});if(r.err)return alert(r.err);p.isfan=+p.isfan?0:1;paint()},'ok');
    const paint=()=>{fb.textContent=+p.isfan?'FAN ✓':'+ FAN'};paint();bar.append(fb);
    bar.append(btn('✉ Pesan',()=>{closeSheet();openDM(p.id)},'ok'));
  }
  bar.append(btn('Profil',()=>{closeSheet();openProfile(p.id)},'ok'));
  if(+p.live&&+p.live!==curRoom)bar.append(btn('Tonton live',()=>{closeSheet();openRoom(+p.live)},'ok'));
  nodes.push(bar);sheet(nodes);
}
/* ---------- halaman profil (tap profil / tab My) ---------- */
async function refreshMe(){const d=await api('me');if(d.me){P=d.me;syncAdmin()}}
function openProfile(uid){
  if(+uid===+P.id){if(subShown())closeSub();go('prof');return}
  const prev=subShown()?subRe:null;
  const b=openSub('',{back:prev,re:()=>openProfile(uid)});
  const m=btn('⋮',()=>sheet([el('h3',null,'Opsi'),btn('✉ Kirim pesan',()=>{closeSheet();openDM(uid)},'ok'),btn('Salin ID akun',()=>{navigator.clipboard&&navigator.clipboard.writeText(String(uid)).then(()=>toast('ID disalin'));closeSheet()},'ok')]));
  m.setAttribute('aria-label','Opsi');$('#sTools').append(m);
  renderProfile(b,uid,false);
}
function editProfile(after){
  const prev=el('div','cvp'),vis=el('div','cvv'),f=el('input','cvf');f.type='file';f.accept='image/*';f.setAttribute('aria-label','Ganti foto profil');prev.append(vis,f);
  const paint=()=>{vis.replaceChildren();vis.style.borderRadius='50%';vis.style.background=bg(P.name,P.photo);if(!P.photo)vis.append(document.createTextNode((P.name[0]||'?').toUpperCase()));vis.append(el('em',null,'✎'))};
  paint();
  f.onchange=async()=>{const x=f.files&&f.files[0];if(!x)return;const r=await upload('photo',x);f.value='';if(r.err)alert(r.err);else{await refreshMe();paint();after&&after()}};
  const n=el('input','fld');n.value=P.name;n.maxLength=24;n.placeholder='Nama';
  const bi=el('input','fld');bi.value=P.bio||'';bi.maxLength=120;bi.placeholder='Bio';
  const vipOn=+P.vip>0,ho=el('label'),cb=el('input');cb.type='checkbox';cb.checked=!!+P.hide;ho.style.cssText='display:flex;gap:10px;align-items:center;padding:6px 2px 10px;cursor:pointer';ho.append(cb,el('span',null,'Sembunyikan status online (fitur VIP)'));
  sheet([el('h3',null,'Edit profil'),prev,n,bi,...(vipOn?[ho]:[]),btn('Simpan',async()=>{await api('upd',{name:n.value,bio:bi.value});if(vipOn)await api('hideon',{on:cb.checked?1:0});await refreshMe();closeSheet();after&&after()},'ok')]);
}
function showFanList(uid,kind,label){
  api('fanlist',{uid,kind}).then(d=>{
    if(!d.rows)return;
    const head=el('div','shead');head.append(el('h3',null,label+' ('+d.rows.length+')'),btn('✕',closeSheet,'sx'));
    const nodes=[head];
    if(!d.rows.length)nodes.push(el('div','empty','Belum ada.'));
    d.rows.forEach(u=>{const r=el('div','row');r.append(onv(ava(u.name,u.photo),+u.onl),el('span','grow',u.name));r.style.cursor='pointer';r.onclick=()=>{closeSheet();openProfile(u.id)};nodes.push(r)});
    sheet(nodes);
  });
}
async function renderProfile(root,uid,mine,startTab){
  root.replaceChildren(el('div','empty','Memuat…'));
  const d=await api('profile',{uid});if(!d.p){root.replaceChildren(el('div','empty',d.err||'Gagal memuat profil'));return}
  const p=d.p,wrap=el('div','pf'),tabsEl=el('div','ptabs'),body=el('div','pbody');wrap.append(tabsEl,body);root.replaceChildren(wrap);
  let tab=startTab||'profil',fansN=null;
  const again=t=>renderProfile(root,uid,mine,t);
  const drawProfil=()=>{
    const av=onv(ava(p.name,p.photo,'pfa',+p.vip),+p.onl),nm=el('div','pfn',p.name);
    if(mine){av.style.cursor='pointer';av.onclick=()=>editProfile(()=>again());const e=btn('✎',()=>editProfile(()=>again()),'pfe');e.setAttribute('aria-label','Edit profil');nm.append(e)}
    {const bd=bdg(p.badge,1);if(bd)nm.append(' ',bd)}
    body.append(av,nm,badgeRow(p),el('div','pfu','@'+p.username),expBar(p.exp));
    if(!mine){
      const bar=el('div','pfbar'),fb=el('button','fanbtn');fb.type='button';
      const paint=()=>{fb.textContent=+p.isfan?'FAN ✓':'+ FAN';fb.classList.toggle('on',!!+p.isfan)};paint();
      fb.onclick=async()=>{fb.disabled=true;const r=await api('follow',{uid:p.id,on:+p.isfan?'0':'1'});fb.disabled=false;if(r.err)return alert(r.err);p.isfan=+p.isfan?0:1;p.fans=r.fans;paint();if(fansN)fansN.textContent=nf(p.fans)};
      const dm=btn('✉',()=>openDM(p.id),'pfdm');dm.setAttribute('aria-label','Kirim pesan');bar.append(fb,dm);body.append(bar);
    }
    if(+p.live&&+p.live!==curRoom)body.append(btn('● Sedang LIVE — Tonton',()=>openRoom(+p.live),'pfl'));
    const nb=el('div','pnb');nb.append(el('span',null,'📢'),el('span','pnt',p.notice||(mine?'Tulis pengumuman di profilmu!':'Belum ada pengumuman.')),el('span',null,'›'));nb.onclick=()=>show('notice');body.append(nb);
    const st=el('div','pst');
    [['FANS',p.fans,'fans'],['Mengikuti',p.following,'following']].forEach(([l,n,k])=>{const c=el('div','psc'),nn=el('b',null,nf(n));c.append(nn,el('span',null,l));c.onclick=()=>showFanList(p.id,k,l);if(k==='fans')fansN=nn;st.append(c)});
    body.append(st);
    if(p.bio)body.append(el('div','pbio',p.bio));
    const fr=el('div','pfr');fr.append(el('b',null,'Peringkat FAN'));
    const tops=p.topfans||[];
    if(!tops.length)fr.append(el('span','mut','Belum ada fan teratas'));
    else{const t3=el('span','t3s');tops.forEach((f,n)=>{const x=el('span','t3 r'+(n+1));x.append(ava(f.name,f.photo),el('i',null,n+1));x.onclick=()=>openProfile(f.id);t3.append(x)});fr.append(t3)}
    body.append(fr);
    if(mine){
      const cr=el('div','coin');cr.append(el('span',null,'Koin: '+nf(p.coins||0)),btn('Klaim '+(+p.vip>0?100:50),async()=>{const r=await api('claim',{});if(r.err)alert(r.err);again()},'pbtn g'));
      body.append(cr,btn(+p.vip>0?'👑 VIP aktif · perpanjang':'👑 Jadi VIP',()=>showVip(()=>again()),'pbtn vipbtn'));if(P.admin)body.append(btn('🛡 Panel Admin',()=>openAdmin(),'pbtn'));body.append(btn('Edit profil',()=>editProfile(()=>again()),'pbtn'),btn('Keluar',()=>logout(),'pbtn g'));
    }
  };
  const drawNotice=async()=>{
    if(mine){
      const i=el('input','fld');i.placeholder='Tulis pengumuman…';i.maxLength=300;i.style.border='1px solid #ddd';
      body.append(i,btn('Kirim pengumuman',async()=>{if(!i.value.trim())return;const r=await api('noticeadd',{text:i.value});if(r.err)return alert(r.err);again('notice')},'pbtn'));
    }
    const r=await api('notices',{uid:p.id});if(!r.rows||tab!=='notice')return;
    if(!r.rows.length)body.append(el('div','empty','Belum ada pengumuman.'));
    r.rows.forEach(n=>{const c=el('div','ncard',n.text);c.append(el('small',null,ago(+n.ts)));
      if(mine){const x=btn('Hapus',async()=>{await api('noticedel',{id:n.id});again('notice')},'chip');c.append(x)}body.append(c)});
  };
  const drawCasts=async()=>{
    const r=await api('ucasts',{uid:p.id});if(!r.casts||tab!=='cast')return;
    if(!r.casts.length)body.append(el('div','empty','Belum ada CAST.'));
    r.casts.forEach(c=>{const card=el('article','cast-card'),meta=el('div','cast-meta');meta.append(el('b',null,c.title),el('small',null,new Date(c.ts*1000).toLocaleString('id-ID')));
      const au=el('audio');au.controls=true;au.preload='none';au.src='uploads/'+encodeURIComponent(c.file);card.append(meta,au);body.append(card)});
  };
  const drawTalks=async()=>{
    const r=await api('utalks',{uid:p.id});if(!r.talks||tab!=='talk')return;
    if(!r.talks.length)body.append(el('div','empty','Belum ada TALK.'));
    r.talks.forEach(t=>{const c=el('div','ncard',t.text);c.append(el('small',null,new Date(t.ts*1000).toLocaleString('id-ID')));body.append(c)});
  };
  const show=k=>{
    tab=k;
    tabsEl.replaceChildren(...[['profil','Profil'],['notice','Pengumuman'],['cast','Cast'],['talk','Talk']].map(([x,l])=>btn(l,()=>show(x),tab===x?'on':'')));
    body.replaceChildren();
    if(k==='profil')drawProfil();else if(k==='notice')drawNotice();else if(k==='cast')drawCasts();else drawTalks();
  };
  show(tab);
}

function openSearch(){
  const b=openSub('Cari akun',{re:openSearch});
  const i=el('input','fld');i.placeholder='Cari nama, username, atau ID…';i.maxLength=30;i.setAttribute('autocomplete','off');
  const res=el('div');b.append(i,res);
  let tm=0,seq=0;
  const doSearch=async()=>{
    const q=i.value.trim(),my=++seq;
    if(!q){res.replaceChildren(el('div','empty','Ketik nama, username, atau ID akun.'));return}
    const d=await api('search',{q});if(my!==seq||!d.rows)return;
    res.replaceChildren(...(d.rows.length?d.rows.map(userRow):[el('div','empty','Akun tidak ditemukan.')]));
  };
  i.oninput=()=>{clearTimeout(tm);tm=setTimeout(doSearch,300)};
  doSearch();i.focus();
}
async function openNotifs(){
  const b=openSub('Notifikasi',{re:openNotifs}),tk=subTok;
  const d=await api('notifs',{});if(tk!==subTok||!d.rows)return;
  setBadge('#hBell',0);
  if(!d.rows.length){b.append(el('div','empty','Belum ada notifikasi.'));return}
  d.rows.forEach(n=>{const r=el('div','nrow'+(+n.rd?'':' new'));r.append(el('div','nt',n.text),el('small',null,ago(+n.ts)));b.append(r)});
}
const SV=p=>`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">${p}</svg>`;
const BIC={
  live:SV('<circle cx="12" cy="12" r="2.2"/><path d="M7.800 7.800a5.900 5.900 0 0 0 0 8.400M16.200 7.800a5.900 5.900 0 0 1 0 8.400M4.900 4.900a10 10 0 0 0 0 14.200M19.100 4.900a10 10 0 0 1 0 14.200"/>'),
  heart:SV('<path d="M12 20s-7.500-4.600-7.500-10.200A4.300 4.300 0 0 1 12 7.300a4.300 4.300 0 0 1 7.500 2.500C19.500 15.400 12 20 12 20z"/>'),
  clock:SV('<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.500 2"/>'),
  play:SV('<circle cx="12" cy="12" r="9"/><path d="M10 8.500v7l6-3.500z" fill="currentColor"/>'),
  gift:SV('<rect x="3.500" y="9" width="17" height="11" rx="1.500"/><path d="M3 9h18v3H3zM12 9v11M12 9c-3 0-4.500-1-4.500-2.500S9 4 10.500 5.500 12 9 12 9zM12 9c3 0 4.500-1 4.500-2.500S15 4 13.500 5.500 12 9 12 9z"/>'),
  chat:SV('<path d="M4 5h16v11H9l-4 3.500V16H4z"/>')
};
const BCATS={
  streamer:[['likes_gifts','Taplov + Hadiah','live'],['likes','Taplov Gratis','heart'],['time','Durasi Live','clock'],['cast','Cast','play']],
  spender:[['spend','Hadiah Terkirim','gift'],['chat','Chat Terbanyak','chat']]
};
const medalSVG=n=>{const c=[['#ffc928','#b8620a','#f5a300'],['#cfd4da','#5f6770','#aeb5bd'],['#e2a58a','#7c3f27','#c98366']][n-1];
  return `<svg viewBox="0 0 40 50"><path d="M9 28 4 48l8-4 5 6 5-22z" fill="${c[2]}"/><path d="M31 28l5 20-8-4-5 6-5-22z" fill="${c[2]}"/><circle cx="20" cy="18" r="15" fill="${c[0]}" stroke="${c[1]}" stroke-width="3"/><circle cx="20" cy="18" r="10.500" fill="none" stroke="${c[1]}" stroke-width="1.500"/><text x="20" y="24" text-anchor="middle" font-size="16" font-weight="800" fill="${c[1]}">${n}</text></svg>`};
const fmtDur=sec=>{sec=+sec;const h=Math.floor(sec/3600),m=Math.floor(sec%3600/60);return h?`${h}j ${m}m`:`${m} mnt`};
const fmtPts=(cat,v)=>cat==='time'?fmtDur(v):cat==='likes'?nf(v)+' ♥':cat==='cast'?nf(v)+' cast':cat==='chat'?nf(v)+' chat':cat==='spend'?nf(v)+' 🪙':nf(v)+' poin';
const chgEl=c=>{const e=el('small','chg');if(c===null||c===undefined){e.className+=' nw';e.textContent='NEW'}else if(c>0){e.className+=' up';e.textContent='▲'+c}else if(c<0){e.className+=' dn';e.textContent='▼'+(-c)}else e.textContent='-';return e};

let boardTab='streamer',boardPer='day';
async function openBoard(){
  const b=openSub('Peringkat',{re:openBoard}),tk=subTok;$('#sub').classList.add('gray');
  const tabs=el('div','btabs'),cards=el('div');b.append(tabs,cards);
  let top=null;
  const draw=()=>{
    tabs.replaceChildren(...[['streamer','Streamer'],['spender','Spender']].map(([k,l])=>btn(l,()=>{boardTab=k;draw()},boardTab===k?'on':'')));
    cards.replaceChildren(...BCATS[boardTab].map(([cat,label,ic])=>{
      const c=el('div','hubcard'),i=el('span','hic');i.innerHTML=BIC[ic];
      const t3=el('span','t3s'),list=(top&&top[cat])||[];
      [0,1,2].forEach(n=>{const p=list[n],x=el('span','t3 r'+(n+1)+(p?'':' em'));if(p)x.append(ava(p.name,p.photo),el('i',null,n+1));t3.append(x)});
      c.append(i,el('b',null,label),t3);c.onclick=()=>openBoardDetail(cat,label);return c}));
  };
  draw();
  const d=await api('boardhub',{});if(tk!==subTok||!d.top)return;top=d.top;draw();
}
function openBoardDetail(cat,label){
  const b=openSub(label,{back:openBoard,re:()=>openBoardDetail(cat,label)}),tk=subTok;$('#sub').classList.add('gray');
  const chips=el('div','bchips'),pod=el('div'),lst=el('div','lrs');b.append(chips,pod,lst);
  let seq=0;
  const load=async()=>{
    const my=++seq;
    chips.replaceChildren(...[['day','Hari ini'],['week','Minggu ini'],['month','Bulan ini']].map(([k,l])=>btn(l,()=>{boardPer=k;load()},boardPer===k?'on':'')));
    const d=await api('board',{cat,per:boardPer});if(tk!==subTok||my!==seq||!d.rows)return;
    const rows=d.rows;
    if(!rows.length){pod.replaceChildren();lst.replaceChildren(el('div','empty','Belum ada data untuk periode ini.'));return}
    const pc=(n)=>{const u=rows[n-1],c=el('div','pc p'+n),md=el('div','md');md.innerHTML=medalSVG(n);
      if(u){md.append(chgEl(u.chg))}else md.append(el('small',null,'-'));
      c.append(md);
      if(u){c.append(ava(u.name,u.photo),el('div','nm',u.name),el('div','sc',fmtPts(cat,u.pts)));c.onclick=()=>openProfile(u.id)}
      else{c.append(ava('?',''),el('div','nm','—'));c.style.cursor='default'}
      return c};
    pod.className='podium';pod.replaceChildren(pc(2),pc(1),pc(3));
    lst.replaceChildren(...rows.slice(3).map((u,i)=>{
      const r=el('div','lr'),rn=el('div','rn');rn.append(el('b',null,i+4),chgEl(u.chg));
      r.append(rn,ava(u.name,u.photo),el('div','nm',u.name),el('div','sc',fmtPts(cat,u.pts)));r.onclick=()=>openProfile(u.id);return r}));
  };
  load();
}
function openDmList(){
  const b=openSub('Pesan',{re:openDmList}),tk=subTok;
  $('#sTools').append(btn('✎',openSearch));$('#sTools').firstChild.setAttribute('aria-label','Cari akun untuk pesan baru');
  const load=async()=>{
    const d=await api('dmlist',{});if(tk!==subTok||!d.rows)return;
    if(!d.rows.length){const e=el('div','empty','Belum ada pesan.\nCari akun lalu pilih "Kirim pesan".');e.style.whiteSpace='pre-line';b.replaceChildren(e);return}
    b.replaceChildren(...d.rows.map(r=>{
      const row=el('div','urow');row.append(ava(r.u.name,r.u.photo));
      const m=el('div','um');m.append(el('b',null,r.u.name),el('small',null,(r.mine?'Kamu: ':'')+r.text));row.append(m);
      const side=el('div','ds');side.append(el('small',null,ago(r.ts)));if(+r.unread)side.append(el('em','ub',r.unread));row.append(side);
      row.onclick=()=>openDM(r.u.id);return row}));
  };
  load();subTimer=setInterval(load,5000);
}
function openDM(uid){
  const b=openSub('Pesan',{back:openDmList,re:()=>openDM(uid)}),tk=subTok;
  let lastId=0,peer=null;
  const inp=el('input');inp.placeholder='Tulis pesan…';inp.maxLength=500;const sb=el('button',null,'Kirim');
  const pf=el('label','fbtn','📷'),pi=el('input','cvf');pi.type='file';pi.accept='image/*';pf.title='Kirim foto';pf.append(pi);
  pi.onchange=()=>{const f=pi.files&&pi.files[0];pi.value='';if(!f)return;shrink(f,1280,async bl=>{const fd=new FormData();fd.append('f',bl,'p.jpg');fd.append('uid',uid);const r=await api('dmimg',fd);if(r.err)alert(r.err);load()})};
  $('#sFoot').append(pf,inp,sb);
  const load=async()=>{
    const d=await api('dmget',{uid,since:lastId});if(tk!==subTok)return;
    if(d.err){b.replaceChildren(el('div','empty',d.err));return}
    if(!d.msgs)return;
    if(!peer){peer=d.p;$('#sTitle').textContent=peer.name}
    if(!d.msgs.length){if(!lastId&&!b.firstChild)b.append(el('div','empty','Belum ada pesan. Sapa duluan!'));return}
    const nb=!lastId||b.scrollHeight-b.scrollTop-b.clientHeight<80;
    b.querySelectorAll('.empty').forEach(x=>x.remove());
    d.msgs.forEach(m=>{lastId=Math.max(lastId,+m.id);const w=el('div','dm '+(+m.a===+P.id?'me':'ot')),bub=el('div','db');
      if(m.img){const im=el('img','cimg');im.src='uploads/'+m.img;im.onclick=()=>openImg(m.img);im.onload=()=>{if(nb)b.scrollTop=b.scrollHeight};bub.classList.add('imgb');bub.append(im)}
      if(m.text)bub.append(document.createTextNode(m.text));
      w.append(bub,el('small',null,ago(+m.ts)));b.append(w)});
    if(nb)b.scrollTop=b.scrollHeight;
  };
  const send=async()=>{const t=inp.value.trim();if(!t)return;inp.value='';const r=await api('dmsend',{uid,text:t});if(r.err)alert(r.err);load()};
  sb.onclick=send;inp.onkeydown=e=>{if(e.key==='Enter')send()};
  load();subTimer=setInterval(load,3000);
}
$('#hSearch').onclick=openSearch;$('#hBell').onclick=openNotifs;$('#hTrophy').onclick=openBoard;$('#hDm').onclick=openDmList;

function renderTop3(list){
  const c=$('#bTop3'),k=JSON.stringify(list.map(x=>[x.id,x.photo,x.name]));
  if(c.dataset.k===k)return;c.dataset.k=k;
  c.replaceChildren(...[0,1,2].map(i=>{const p=list[i],x=el('span','t3 r'+(i+1)+(p?'':' em'));if(p){x.append(ava(p.name,p.photo),el('i',null,i+1));x.title=p.name}return x}));
}

/* ---------- musik room (sinkron lewat server) ---------- */
const mAud=new Audio();mAud.preload='auto';mAud.volume=.85;let mCur=0;
const tryPlay=()=>mAud.play().catch(()=>{$('#unlock').style.display='block'});
function stopMusic(){mAud.pause();mAud.removeAttribute('src');mCur=0;$('#nowp').style.display='none'}
function syncMusic(m){
  const cur=m&&m.cur,strip=$('#nowp');
  if(!cur){if(mCur||!mAud.paused)stopMusic();return}
  const mod=me&&(me.role!=='member'||me.admin);
  strip.style.display='flex';$('#npT').textContent=cur.title;$('#npA').textContent=(cur.artist||'')+(m.paused?' · dijeda':'');
  $('#npP').style.display=mod?'':'none';$('#npN').style.display=mod?'':'none';$('#npP').textContent=m.paused?'▶':'⏸';
  const t=m.offset/1000;
  if(mCur!==+cur.id){
    mCur=+cur.id;mAud.src='uploads/'+encodeURIComponent(cur.file);
    mAud.onloadedmetadata=()=>{try{mAud.currentTime=Math.min(t+.3,(mAud.duration||t+1)-.1)}catch(e){}if(!m.paused)tryPlay()};
    mAud.onended=()=>{if(mod&&!(+cur.dur>0))act('mnext')};
    mAud.load();
  }else if(m.paused){if(!mAud.paused)mAud.pause()}
  else{if(mAud.readyState>0&&Math.abs(mAud.currentTime-t)>3)mAud.currentTime=t;if(mAud.paused)tryPlay()}
}
$('#npM').onclick=()=>{mAud.muted=!mAud.muted;$('#npM').textContent=mAud.muted?'🔇':'🔊'};
$('#npP').onclick=()=>act(lastData.music&&lastData.music.paused?'mplay':'mpause');
$('#npN').onclick=()=>act('mnext');
$('#npI').onclick=()=>showMusic();
const fmtT=s=>{s=+s||0;return s?`${Math.floor(s/60)}:${pad(s%60)}`:''};
let musicTab='queue';
async function musicAct(a,d){const r=await api(a,d||{});if(r.err)toast(r.err);nudge();await poll();showMusic()}
function musicUpload(){
  const l=el('label','fbtn upl','⬆ Unggah musik'),f=el('input','cvf');f.type='file';f.accept='audio/*';l.append(f);
  f.onchange=async()=>{const x=f.files&&f.files[0];f.value='';if(!x)return;
    const dur=await new Promise(res=>{const a=new Audio(),u=URL.createObjectURL(x);a.preload='metadata';a.onloadedmetadata=()=>{res(isFinite(a.duration)?Math.round(a.duration):0);URL.revokeObjectURL(u)};a.onerror=()=>res(0);a.src=u});
    const fd=new FormData();fd.append('f',x);fd.append('title',x.name.replace(/\.[^.]+$/,'').replace(/_+/g,' '));fd.append('dur',dur);
    toast('Mengunggah musik…');const r=await api('mup',fd);if(r.err)alert(r.err);else{musicTab='mine';showMusic()}};
  return l;
}
async function showMusic(tab){
  if(tab)musicTab=tab;
  const mod=me&&(me.role!=='member'||me.admin),m=(lastData&&lastData.music)||{queue:[]};
  const head=el('div','shead');head.append(el('h3',null,'Musik'),btn('✕',closeSheet,'sx'));
  const nodes=[head];
  if(m.cur){const np=el('div','mnow'),inf=el('div');inf.append(el('b',null,m.cur.title),el('small',null,(m.cur.artist||'—')+(m.paused?' · dijeda':' · diputar')));np.append(inf);
    if(mod){const c=el('div','mctl');c.append(btn(m.paused?'▶':'⏸',()=>musicAct(m.paused?'mplay':'mpause')),btn('⏭',()=>musicAct('mnext')));np.append(c)}nodes.push(np)}
  const seg=el('div','seg');seg.style.margin='8px 0';
  [['queue','Antrian'],['rec','Rekomendasi'],['mine','Milikku']].forEach(([k,l])=>seg.append(btn(l,()=>showMusic(k),musicTab===k?'on':'')));nodes.push(seg);
  if(musicTab==='queue'){
    if(!m.queue.length)nodes.push(el('div','empty',mod?'Antrian kosong. Tambahkan dari Rekomendasi atau Milikku.':'Belum ada musik di room ini.'));
    m.queue.forEach(q=>{const r=el('div','mrow'+(m.cur&&+m.cur.id===+q.id?' cur':'')),i2=el('div','mi2');i2.append(el('b',null,q.title),el('small',null,(q.artist||'—')+(fmtT(q.dur)?' · '+fmtT(q.dur):'')));r.append(i2);
      if(mod){r.append(btn('▶',()=>musicAct('mjump',{id:q.id}),'chip'),btn('✕',()=>musicAct('mrm',{id:q.id}),'chip'))}nodes.push(r)});
    sheet(nodes);return;
  }
  nodes.push(musicUpload());
  const d=await api('mtracks',{tab:musicTab});if(!d.rows)return;
  if(!d.rows.length)nodes.push(el('div','empty',musicTab==='mine'?'Belum ada musik yang kamu unggah.':'Belum ada rekomendasi. Unggah musik pertama!'));
  d.rows.forEach(t=>{const r=el('div','mrow'),i2=el('div','mi2');i2.append(el('b',null,t.title),el('small',null,(t.artist||t.name)+(fmtT(t.dur)?' · '+fmtT(t.dur):'')+(+t.plays?' · '+t.plays+'×':'')));r.append(i2);
    if(mod)r.append(btn('＋ Antrian',()=>{musicAct('madd',{id:t.id});toast('Ditambahkan ke antrian')},'chip'));
    if(+t.uid===+P.id)r.append(btn('🗑',async()=>{if(!confirm('Hapus "'+t.title+'"?'))return;const x=await api('mdel',{id:t.id});if(x.err)alert(x.err);showMusic()},'chip'));
    nodes.push(r)});
  sheet(nodes);
}
function openImg(f){const lb=$('#lb');lb.querySelector('img').src='uploads/'+f;lb.classList.add('show')}
$('#lb').onclick=()=>$('#lb').classList.remove('show');
$('#bImgF').onchange=e=>{const f=e.target.files&&e.target.files[0];e.target.value='';if(!f||!curRoom)return;
  shrink(f,1280,async bl=>{const fd=new FormData();fd.append('f',bl,'p.jpg');const r=await api('sendimg',fd);if(r.err)toast(r.err);else{nudge();poll()}})};
function addMsg(m){
  const bottom=chat.scrollHeight-chat.scrollTop-chat.clientHeight<80,mine=!!(me&&+m.uid===+me.id);
  if(m.kind==='join')chat.append(el('div','sys',m.text));
  else{
    const w=el('div','msg');w.append(ava(m.name,m.photo,'',+m.vip));const c=el('div'),who=el('div','who');
    if(m.role!=='member')who.append(el('span','tag '+m.role,m.role==='penyiar'?'PENYIAR':'Manajer'));
    {const bd=bdg(m.badge);if(bd)who.append(bd)}
    who.append(lvBadge(m.exp));if(+m.vip)who.append(vipBadge());who.append(el('span','nm'+(m.role==='penyiar'?' host':'')+(+m.vip?' vipn':''),m.name));
    if(m.kind==='img'){const bb=el('div','bub img'),im=el('img','cimg');im.src='uploads/'+m.text;im.loading='lazy';im.onclick=()=>openImg(m.text);im.onload=()=>{if(bottom||first||mine)chat.scrollTop=chat.scrollHeight};bb.append(im);c.append(who,bb)}
    else c.append(who,el('div','bub '+(m.kind==='chat'?'':m.kind),m.text));
    w.append(c);chat.append(w);
    if(m.kind==='gift'&&!first)fly([...m.text].slice(-2).join('').trim());
  }
  while(chat.children.length>150)chat.firstChild.remove();
  if(bottom||first||mine)chat.scrollTop=chat.scrollHeight;
  else if(m.kind!=='join')showNM();
}
function fly(e){const f=el('div','fl',e);f.style.left=(10+Math.random()*75)+'%';$('#float').append(f);setTimeout(()=>f.remove(),2500)}
function renderStage(list){
  const s=$('#stage');s.replaceChildren();
  const host=list.find(p=>+p.host),seats=[host||null,...list.filter(p=>!+p.host)],n=Math.max(8,seats.length);
  for(let i=0;i<n;i++){
    const p=seats[i],d=el('div','st'+(p?(+p.host?' h':''):' vac')),sv=el('div','sv');
    if(p){
      d.dataset.id=p.id;sv.append(ava(p.name,p.photo));const mi=el('i','mi');mi.innerHTML=IC.micoff;sv.append(mi);
      d.append(sv,el('span','sn',p.name));if(+p.host)d.append(el('span','dj','DJ'));
    }else{sv.innerHTML=IC.person;d.append(sv,el('span','sn',String(i+1)))}
    d.onclick=()=>seatTap(p);s.append(d);
  }
  const m=me?me.mic:0;$('#stgCap').textContent=m===2?'Kamu ada di panggung':m===1?'Menunggu persetujuan penyiar…':'Ketuk kursi kosong untuk minta bicara';
  const rq=lastData&&lastData.reqs?lastData.reqs.length:0,fr=$('#fabRq');fr.textContent=rq;fr.style.display=rq&&me&&(me.role!=='member'||me.admin)?'flex':'none';
  applySpeak();seatMute();
}
function micUI(){const b=$('#bMic'),m=me?me.mic:0;b.innerHTML=m===2?(muted?IC.micoff:IC.mic):IC.phone;b.classList.toggle('on',m===2&&!muted);b.classList.toggle('wait',m===1);b.title=m===2?'Mute / buka mute':m===1?'Batalkan permintaan':'Minta bicara';const mm=$('#mMic');mm.style.display=m===2?'':'none';mm.textContent=muted?'🔇':'🎤'}
// ===== HI-FI MIC (soundcard / live akustik) =====
const HF={
  on:(localStorage.hfOn??'')===''?null:localStorage.hfOn==='1', // null = otomatis (penyiar Hi-Fi, tamu Suara)
  dev:localStorage.hfDev||'',
  ch:+(localStorage.hfCh||1) // 1 = mono (1 mic), 2 = stereo (mixer)
};
const hfActive=()=>HF.on===null?!!(me&&me.role==='penyiar'):HF.on;
const capOpts=()=>hfActive()
  ?{echoCancellation:false,noiseSuppression:false,autoGainControl:false,channelCount:HF.ch,sampleRate:48000,sampleSize:16,latency:0.01,...(HF.dev?{deviceId:{exact:HF.dev}}:{})}
  :{echoCancellation:true,noiseSuppression:true,autoGainControl:true,channelCount:1,...(HF.dev?{deviceId:HF.dev}:{})};
const pubOpts=()=>hfActive()
  ?{audioPreset:{maxBitrate:HF.ch===2?160000:96000},dtx:false,red:false,forceStereo:HF.ch===2}
  :{audioPreset:{maxBitrate:32000},dtx:true};
async function syncMic(force){
  if(!lk||!me)return;const want=me.mic===2&&!muted;if(want===micOn&&force!==true)return;
  try{
    const p=lk.localParticipant;
    if(force===true){const pub=p.getTrackPublication(LivekitClient.Track.Source.Microphone);if(pub&&pub.track)await p.unpublishTrack(pub.track,true)}
    await p.setMicrophoneEnabled(want,want?capOpts():undefined,want?pubOpts():undefined);micOn=want;
    if(want&&window.BalanceAndroid&&typeof window.BalanceAndroid.startLiveAudio==='function'){
      const keep=window.BalanceAndroid.startLiveAudio(location.origin+location.pathname,TOK);
      if(!keep)toast('Mode latar belakang tidak aktif. Periksa izin mikrofon dan konfigurasi APK.');
    }
    if(want)toast('Mikrofon aktif'+(hfActive()?' (Hi-Fi)':'')+' — silakan bicara.')
  }catch(e){micOn=false;muted=true;micUI();toast('Mikrofon gagal: '+(e.name==='NotAllowedError'?'izin ditolak browser atau halaman bukan HTTPS.':e.name==='NotFoundError'?'mikrofon tidak ditemukan.':e.name==='OverconstrainedError'?'input soundcard tidak ditemukan, pilih ulang di menu Audio.':(e.message||e.name)))}
}
async function hifiSheet(){
  let devs=[];try{const st=await navigator.mediaDevices.getUserMedia({audio:true});st.getTracks().forEach(t=>t.stop());devs=await LivekitClient.Room.getLocalDevices('audioinput')}catch(e){}
  const head=el('div','shead');head.append(el('h3',null,'Audio / Soundcard'),btn('✕',closeSheet,'sx'));
  const mk=(opts,cur)=>{const s=el('select','fld');opts.forEach(([v,t])=>{const o=el('option',null,t);o.value=v;s.append(o)});s.value=cur;s.style.border='1px solid #ddd';return s};
  const dev=mk([['','Default (mic bawaan)'],...devs.map(d=>[d.deviceId,d.label||'Input audio'])],HF.dev);
  const ch=mk([['1','Mono (1 mic / 1 input)'],['2','Stereo (mixer / 2 input)']],String(HF.ch));
  const md=mk([['','Otomatis (penyiar Hi-Fi, tamu Suara)'],['1','Hi-Fi (wajib headphone)'],['0','Suara (anti-gema)']],HF.on===null?'':HF.on?'1':'0');
  const inf=el('div',null,'Hi-Fi mematikan echo cancel, noise suppression, dan auto gain. Pakai headphone supaya tidak bergema. Satu mic di soundcard: pilih Mono.');inf.style.cssText='color:#888;font-size:13px;margin:8px 0;line-height:1.4';
  const h=t=>{const x=el('h4',null,t);x.style.cssText='margin:12px 0 6px;font-size:15px';return x};
  sheet([head,h('Input'),dev,h('Kanal'),ch,h('Mode'),md,inf,btn('Terapkan',async()=>{
    HF.dev=dev.value;HF.ch=+ch.value;HF.on=md.value===''?null:md.value==='1';
    localStorage.hfDev=HF.dev;localStorage.hfCh=HF.ch;localStorage.hfOn=md.value;
    closeSheet();await syncMic(true)},'ok')]);
}
async function poll(){
  if(!curRoom)return;
  try{
    const d=await api('poll');if(!d.msgs||!curRoom)return;
    if(d.reset){chat.replaceChildren();last=0;first=true;hideNM()}lastClr=d.clr;
    d.msgs.forEach(m=>{addMsg(m);last=Math.max(last,m.id)});
    const wasFirst=first;first=false;me=d.me;frozen=d.frozen;lastData=d;
    $('#lCnt').textContent=d.online;$('#mOn').textContent=d.online;$('#sTopN').textContent='Top '+d.toprank;
    renderTop3(d.topg||[]);
    {const rv=$('#roomv'),c=d.room.cover||'';if(rv.dataset.c!==c){rv.dataset.c=c;rv.style.background=c?`linear-gradient(rgba(20,32,45,.55),rgba(20,32,45,.82)),url(uploads/${c}) center/cover`:''}}
    renderAnn(d);
    if(+$('#sLk').textContent<d.likes&&!wasFirst)fly('❤️');
    $('#sLk').textContent=d.likes;$('#title').textContent=d.room.title;$('#hname').textContent=d.room.host;renderPill(d);
    ['#hava','#mAva'].forEach(k=>{const h=$(k);h.style.background=bg(d.room.title,d.room.cover||d.room.hphoto);h.textContent=(d.room.cover||d.room.hphoto)?'':d.room.title[0]});
    $('#mTitle').textContent=d.room.title;
    const e=d.elapsed,tm=e>=3600?`${Math.floor(e/3600)}:${pad(Math.floor(e%3600/60))}:${pad(e%60)}`:`${pad(Math.floor(e/60))}:${pad(e%60)}`;$('#sTm').textContent=tm;$('#mTm').textContent=tm;
    if(listOpen&&++lcTick%2===0)showListeners(true);
    syncGames(d.games);if(gameOpen&&++gcTick%2===0)showGame(true);
    handleBag(d);
    $('#frzL').textContent=frozen?'Buka':'Bekukan';
    const mod=me.role!=='member'||!!me.admin;$('#bAnn').style.display=mod?'':'none';
    document.querySelector('[data-a=freeze]').style.display=mod?'':'none';
    document.querySelector('[data-a=clear]').style.display=mod?'':'none';
    document.querySelector('[data-a=goal]').style.display=mod?'':'none';
    document.querySelector('[data-a=ubah]').style.display=me.role==='penyiar'?'':'none';
    const rq=$('#rq');rq.textContent=d.reqs.length;rq.style.display=d.reqs.length?'block':'none';
    $('#msg').placeholder=frozen&&!mod?'Chat dibekukan':'Tulis pesan…';
    renderStage(d.stage);micUI();syncMusic(d.music);
    if(lk&&!recon&&(me.mic===2)!==tokenPub){recon=true;try{await lk.disconnect()}catch(e){}lk=null;await voice();recon=false}
    syncMic();
  }catch(e){}
}
setInterval(poll,2000);

async function voice(){
  if(!window.LivekitClient)return toast('Library suara gagal dimuat (cek internet / blokir CDN).');
  const t=await api('lk',{});if(!t.token)return t.off?toast('Suara belum aktif: isi LK_URL, LK_KEY, LK_SECRET di config.php.'):0;
  const L=LivekitClient,r=new L.Room({adaptiveStream:true,dynacast:true});
  r.on(L.RoomEvent.TrackSubscribed,tr=>{if(tr.kind==='audio')document.body.append(tr.attach())});
  r.on(L.RoomEvent.TrackUnsubscribed,tr=>tr.detach().forEach(e=>e.remove()));
  r.on(L.RoomEvent.ActiveSpeakersChanged,sp=>{speakSet=new Set(sp.map(x=>String(x.identity)));applySpeak()});
  r.on(L.RoomEvent.AudioPlaybackStatusChanged,()=>{$('#unlock').style.display=r.canPlaybackAudio?'none':'block'});
  r.on(L.RoomEvent.DataReceived,()=>poll());
  r.on(L.RoomEvent.Disconnected,()=>{if(lk===r&&!recon&&curRoom)toast('Suara terputus.')});
  try{await r.connect(t.url,t.token);if(!curRoom){r.disconnect();return}lk=r;tokenPub=!!t.pub;micOn=false;await r.startAudio().catch(()=>{});nudge();
    if(!window.__tick)window.__tick=setInterval(meter,90)}
  catch(e){toast('Gagal terhubung ke LiveKit: '+(e.message||e)+' (LK_URL harus berawalan wss://)')}
}
$('#unlock').onclick=async()=>{try{await lk.startAudio()}catch(e){}if(mCur&&lastData&&lastData.music&&!lastData.music.paused)tryPlay();$('#unlock').style.display='none'};
document.addEventListener('click',()=>{if(lk&&!lk.canPlaybackAudio)lk.startAudio().catch(()=>{})});
function meter(){
  let lv=0;
  if(lk){
    // Browser dan APK memakai sumber level suara LiveKit JavaScript yang sama.
    lv=lk.localParticipant.audioLevel||0;
    lk.remoteParticipants.forEach(p=>{lv=Math.max(lv,p.audioLevel||0)});
  }
  peak=Math.max(lv,peak*0.82);const n=peak<0.004?0:Math.min(8,Math.ceil(Math.sqrt(peak)*9));
  document.querySelectorAll('#sig i').forEach((i,k)=>i.classList.toggle('on',k<n));
  if($('#mini').classList.contains('show'))document.querySelectorAll('#mEq i').forEach((i,k)=>i.style.height=Math.max(3,Math.min(20,n*2.4*(.6+.4*Math.sin(Date.now()/120+k*1.7))))+'px');
}

$('#send').onclick=()=>{const t=$('#msg').value;$('#msg').value='';if(t.trim())act('send',{text:t,announce:announce?'1':'0'})};
$('#msg').onkeydown=e=>{if(e.key==='Enter')$('#send').click()};
$('#bChat').onclick=()=>{$('#inbar').classList.toggle('show');$('#msg').focus()};
$('#bAnn').onclick=()=>{announce=!announce;$('#bAnn').classList.toggle('on',announce)};
$('#bLike').onclick=()=>{fly('❤️');act('like')};
$('#bMore').onclick=()=>{const a=$('#acts'),at=atBottom();a.classList.toggle('hide');$('#bMore').classList.toggle('open',!a.classList.contains('hide'));if(at)chat.scrollTop=chat.scrollHeight};
$('#bMic').onclick=()=>{if(!me)return;if(me.mic===0)act('ask');else if(me.mic===1)act('down');else{muted=!muted;micUI();syncMic()}};

function sheet(nodes,handler){listOpen=false;gameOpen=false;const b=$('#box');b.replaceChildren(...nodes);b.onclick=handler||null;$('#sheet').classList.add('show')}
const closeSheet=()=>{listOpen=false;gameOpen=false;$('#sheet').classList.remove('show')};
$('#sheet').onclick=e=>{if(e.target.id==='sheet')closeSheet()};
const btn=(t,fn,c)=>{const b=el('button',c,t);b.onclick=fn;return b};

$('#bGift').onclick=()=>{
  const chips=el('div','chips');
  GIFTS.forEach(([g,p],i)=>chips.append(btn(`${g} ${p}`,async()=>{const r=await act('gift',{g:i});if(!r.err)closeSheet()},'chip')));
  sheet([el('h3',null,`Kirim hadiah · Koin kamu: ${me?me.coins:0}`),chips,btn('Klaim '+(me&&+me.vip?100:50)+' koin harian',async()=>{const r=await api('claim',{});toast(r.err||'+'+(r.bonus||50)+' koin diklaim.');closeSheet()},'chip')]);
};
const scripts=['Selamat datang! 👋','Jangan lupa follow ya','Terima kasih sudah mampir','Yuk ramaikan room-nya','Sampai jumpa di live berikutnya!'];
async function showRank(){
  const d=await api('rank',{});if(!d.rows)return;const nodes=[el('h3',null,'Peringkat hadiah')];
  d.rows.forEach((r,i)=>{
    const row=el('div','row');row.append(el('span','n',i+1),ava(r.name,r.photo,'',+r.vip),el('span','grow',r.name+(r.role!=='member'?` (${r.role})`:'')+(+r.banned?' · diblokir':'')),el('b',null,+r.coins?nf(r.coins)+' 🪙':r.chats+' chat'));
    {const bd=bdg(r.badge,1);if(bd)row.querySelector('.grow').append(bd)}
    {const g=row.querySelector('.grow');g.append(' ',lvBadge(r.exp));if(+r.vip)g.append(' ',vipBadge())}
    if(me&&r.id!=me.id&&r.badge!=='admin'&&(me.admin||(me.role!=='member'&&r.role!=='penyiar'&&(me.role==='penyiar'||r.role==='member')))){
      const goA=async(a,x)=>{await act(a,{uid:r.id,...x});showRank()};
      if(+r.banned)row.append(btn('Buka blokir',()=>goA('unban')));
      else row.append(btn('Kick',()=>goA('kick'),'d'),btn('Blokir',()=>goA('ban'),'d'));
      if(me.role==='penyiar'&&!+r.banned)row.append(btn(r.role==='manajer'?'Cabut manajer':'Jadikan manajer',()=>goA('promote',{to:r.role==='manajer'?'member':'manajer'})));
    }
    nodes.push(row);
  });
  sheet(nodes);
}
function showStage(){
  const d=lastData,mod=me.role!=='member'||!!me.admin,nodes=[el('h3',null,'Panggung suara')];
  const goS=async(id,up)=>{await act('stage',{uid:id,up});showStage()};
  if(mod){nodes.push(el('b',null,'Permintaan bicara'));
    if(!d.reqs.length)nodes.push(el('div','row','Tidak ada permintaan'));
    d.reqs.forEach(p=>{const r=el('div','row');r.append(el('span','grow',p.name),btn('Naikkan',()=>goS(p.id,'1')));nodes.push(r)})}
  nodes.push(el('b',null,'Di panggung'));
  d.stage.forEach(p=>{const r=el('div','row');r.append(ava(p.name,p.photo),el('span','grow',p.name+(+p.host?' (penyiar)':'')));
    if(mod&&!+p.host)r.append(btn('Turunkan',()=>goS(p.id,'0'),'d'));
    if(p.id==me.id&&!+p.host)r.append(btn('Turun',async()=>{await act('down');closeSheet()}));nodes.push(r)});
  sheet(nodes);
}
document.querySelectorAll('.act').forEach(b=>b.onclick=()=>{
  const a=b.dataset.a;
  if(a==='hifi')return hifiSheet();
  if(a==='rank')showRank();
  if(a==='stage')showStage();
  if(a==='music')showMusic('queue');
  if(a==='skrip'){const c=el('div','chips');scripts.forEach(s=>c.append(btn(s,()=>{act('send',{text:s});closeSheet()},'chip')));sheet([el('h3',null,'Skrip cepat'),c])}
  if(a==='ubah'){const cp=coverPicker((lastData.room&&lastData.room.cover)||'');const i=el('input','fld');i.maxLength=40;i.value=$('#title').textContent;const w=el('textarea','fld');w.maxLength=200;w.rows=3;w.placeholder='Kata sambutan / pengumuman room';w.value=$('#pin').dataset.v||'';
    sheet([el('h3',null,'Ubah live'),...cp.nodes,i,w,btn('Simpan',async()=>{
      await act('title',{title:i.value,welcome:w.value});
      if(cp.st.file){const u=await upload('cover',cp.st.file,{room:curRoom},1080);if(u&&u.err)toast(u.err)}else if(cp.st.remove)await act('uncover');
      closeSheet()},'ok')])}
  if(a==='game')showGame();
  if(a==='bag')showBag();
  if(a==='goal')showGoal();
  if(a==='freeze')act('freeze');
  if(a==='clear'&&confirm('Bersihkan semua chat di room ini untuk semua orang?'))act('clear');
  if(a==='share'){const u=location.origin+location.pathname+'?room='+curRoom;
    if(navigator.share)navigator.share({title:$('#title').textContent,url:u}).catch(()=>{});
    else navigator.clipboard.writeText(u).then(()=>toast('Link live disalin.'))}
});


// ---------- CAST recorder ----------
let recorder=null,recordChunks=[],recordStarted=0,recordStream=null,recordLimit=null;
$('#recStart').onclick=async()=>{
  if(!navigator.mediaDevices?.getUserMedia||!window.MediaRecorder){$('#recStatus').textContent='Browser tidak mendukung perekaman suara.';return}
  try{
    recordStream=await navigator.mediaDevices.getUserMedia({audio:true});
    const opts=MediaRecorder.isTypeSupported('audio/webm;codecs=opus')?{mimeType:'audio/webm;codecs=opus'}:{};
    recorder=new MediaRecorder(recordStream,opts);recordChunks=[];recordStarted=Date.now();
    recorder.ondataavailable=e=>{if(e.data.size)recordChunks.push(e.data)};
    recorder.onstop=async()=>{
      const blob=new Blob(recordChunks,{type:recorder.mimeType||'audio/webm'});
      const f=new FormData();f.append('f',blob,'recording.webm');f.append('title',$('#castTitle').value||'Rekaman suara');f.append('duration',Math.round((Date.now()-recordStarted)/1000));
      $('#recStatus').textContent='Mengunggah CAST…';
      const r=await api('castadd',f);if(r.err)$('#recStatus').textContent=r.err;else{$('#recStatus').textContent='CAST berhasil disimpan.';$('#castTitle').value='';loadHome()}
      recordStream?.getTracks().forEach(t=>t.stop());recordStream=null;
    };
    recorder.start();$('#recStart').disabled=true;$('#recStop').disabled=false;$('#recStatus').textContent='Sedang merekam… maksimal 60 detik.';
    recordLimit=setTimeout(()=>$('#recStop').click(),60000);
  }catch(e){$('#recStatus').textContent='Tidak dapat mengakses mikrofon: '+(e.message||e)}
};
$('#recStop').onclick=()=>{
  if(recorder&&recorder.state!=='inactive')recorder.stop();
  clearTimeout(recordLimit);$('#recStart').disabled=false;$('#recStop').disabled=true;
};
$('#talkSend').onclick=async()=>{
  const text=$('#talkText').value.trim();if(!text)return;
  const b=$('#talkSend');b.disabled=true;
  const r=await api('talkadd',{text});b.disabled=false;
  if(r.err)alert(r.err);else{$('#talkText').value='';loadHome()}
};


/* ---------- PANEL ADMIN ---------- */
function syncAdmin(){const b=$('#hAdmin');if(b)b.style.display=P&&P.admin?'':'none'}
$('#hAdmin').onclick=()=>openAdmin();
let admTab='dash',admSub='talk';
const admCall=async(a,d)=>{const r=await api(a,d||{});if(r.err){alert(r.err);return null}return r};
const admLbl=t=>{const x=el('small',null,t);x.style.cssText='display:block;color:#888;margin:12px 0 4px';return x};
const admIn=(v,ph,type)=>{const i=el('input','fld');i.value=v==null?'':v;i.placeholder=ph||'';if(type)i.type=type;i.style.border='1px solid #ddd';return i};
const admBadge=u=>u.role==='admin'?'admin':(+u.choice?'choice':'');
function openAdmin(){
  const b=openSub('Panel Admin',{re:openAdmin}),tk=subTok;$('#sub').classList.add('gray');
  const tabs=el('div','btabs sm'),body=el('div','admb');b.append(tabs,body);
  const T=[['dash','Ringkasan'],['users','Pengguna'],['rooms','Live'],['content','Konten'],['log','Log']],V={dash:admDash,users:admUsers,rooms:admRooms,content:admContent,log:admLog};
  const draw=()=>{tabs.replaceChildren(...T.map(([k,l])=>btn(l,()=>{admTab=k;draw()},admTab===k?'on':'')));body.replaceChildren();V[admTab](body,tk)};
  draw();
}
async function admDash(body,tk){
  const g=el('div','admg');body.append(g);
  const d=await admCall('adm_stats');if(tk!==subTok||!d)return;const s=d.s;
  [['Pengguna',s.users],['Online',s.online],['Live aktif',s.live],['Admin',s.admins],['CHOICE',s.choice],['Diblokir',s.banned],['Total koin',s.coins],['Koin hadiah',s.gifts],['Pesan room',s.msgs],['TALK',s.talks],['CAST',s.casts]]
    .forEach(([l,n])=>{const c=el('div','admc');c.append(el('b',null,nf(n)),el('span',null,l));g.append(c)});
  const t=el('textarea','fld');t.rows=3;t.maxLength=200;t.placeholder='Tulis pengumuman untuk semua pengguna…';t.style.border='1px solid #ddd';
  body.append(admLbl('Siaran pengumuman (masuk ke notifikasi semua akun)'),t,
    btn('Kirim ke semua',async()=>{if(!t.value.trim()||!confirm('Kirim ke semua pengguna?'))return;const r=await admCall('adm_broadcast',{text:t.value});if(r){t.value='';alert('Terkirim ke '+r.n+' akun')}},'pbtn'));
}
function admUsers(body,tk){
  const q=admIn('','Cari username / nama / ID…'),list=el('div'),more=btn('Muat lagi',()=>load(true),'chip');
  more.style.display='none';let off=0,seq=0,tmr=0,onlyOn=0;const fb=el('div','chips');fb.style.margin='8px 0';
  const mk=()=>{fb.replaceChildren(btn('Semua',()=>{onlyOn=0;mk();load()},onlyOn?'chip':'chip on'),btn('🟢 Online',()=>{onlyOn=1;mk();load()},onlyOn?'chip on':'chip'))};mk();
  body.append(q,fb,list,more);
  const load=async add=>{
    const my=++seq;if(!add){off=0}
    const d=await admCall('adm_users',{q:q.value,off,online:onlyOn});if(tk!==subTok||my!==seq||!d)return;
    if(!add)list.replaceChildren();
    d.rows.forEach(u=>list.append(admUserRow(u,()=>load())));off+=d.rows.length;more.style.display=d.more?'':'none';
    if(!list.children.length)list.append(el('div','empty','Tidak ada akun.'));
  };
  q.oninput=()=>{clearTimeout(tmr);tmr=setTimeout(()=>load(),300)};
  load();
}
function admUserRow(u,reload){
  const r=el('div','urow');r.append(onv(ava(u.name,u.photo),+u.onl));
  const m=el('div','um');m.append(nameB(u.name,admBadge(u)),el('small',null,'@'+u.username+' · ID '+u.id+' · 🪙 '+nf(u.coins)+(+u.onl?' · online'+(+u.hid?' (disembunyikan)':''):'')+(+u.banned?' · DIBLOKIR':'')));
  r.append(m);r.onclick=()=>admUser(u.id,reload);return r;
}
async function admUser(id,reload){
  const d=await admCall('adm_user',{uid:id});if(!d)return;const u=d.u,self=+u.id===+P.id,isA=u.role==='admin';
  const again=()=>{reload&&reload();admUser(id,reload)};
  const head=el('div','shead');head.append(el('h3',null,'Kelola akun'),btn('✕',closeSheet,'sx'));head.lastChild.setAttribute('aria-label','Tutup');
  const top=el('div','urow');top.style.cursor='default';top.append(onv(ava(u.name,u.photo),+u.onl));
  const tm=el('div','um');tm.append(nameB(u.name,admBadge(u)),el('small',null,'@'+u.username+' · ID '+u.id+(+u.banned?' · DIBLOKIR':'')));top.append(tm);
  const nodes=[head,top];
  // koin
  const coin=admIn('','Jumlah koin','number');coin.min='0';const cb=el('div','chips');
  [['＋ Tambah','add'],['− Kurangi','sub'],['= Set','set']].forEach(([l,m])=>cb.append(btn(l,async()=>{
    const r=await admCall('adm_coin',{uid:id,mode:m,amount:coin.value});if(r){alert('Koin sekarang: '+nf(r.coins));if(self)refreshMe();again()}},'chip')));
  nodes.push(admLbl('Koin saat ini: '+nf(u.coins)),coin,cb);
  // profil
  const un=admIn(u.username,'Username'),nm=admIn(u.name,'Nama tampilan'),bi=admIn(u.bio,'Bio');
  nodes.push(admLbl('Username'),un,admLbl('Nama tampilan'),nm,admLbl('Bio'),bi,
    btn('Simpan profil',async()=>{if(await admCall('adm_edit',{uid:id,username:un.value,name:nm.value,bio:bi.value})){if(self)refreshMe();again()}},'ok'));
  // ID
  const ni=admIn(u.id,'ID baru','number');
  nodes.push(admLbl('Ganti ID akun (semua data akun ikut dipindahkan)'),ni,
    btn('Ganti ID',async()=>{if(!confirm('Ganti ID '+id+' menjadi '+ni.value+'?'))return;const r=await admCall('adm_setid',{uid:id,newid:ni.value});
      if(r){if(self)await refreshMe();reload&&reload();admUser(r.id,reload)}},'ok'));
  // password
  const pw=admIn('','Password baru (min. 6 karakter)','password');pw.autocomplete='new-password';
  nodes.push(admLbl('Reset password'),pw,
    btn('Reset password',async()=>{if(await admCall('adm_pass',{uid:id,password:pw.value})){pw.value='';alert('Password diganti'+(self?'.':'. Akun tersebut keluar dari semua perangkat.'))}},'ok'));
  // badge, peran, status
  const ac=el('div','chips');ac.style.marginTop='16px';
  ac.append(btn(+u.choice?'Cabut CHOICE':'★ Beri CHOICE',async()=>{if(await admCall('adm_badge',{uid:id,choice:+u.choice?0:1})){if(self)refreshMe();again()}},'chip'));
  if(!self)ac.append(btn(isA?'Cabut ADMIN':'🛡 Jadikan ADMIN',async()=>{
    if(!confirm(isA?'Cabut status admin akun ini?':'Jadikan admin? Akun ini akan punya akses penuh ke panel admin.'))return;
    if(await admCall('adm_role',{uid:id,role:isA?'user':'admin'}))again()},'chip'));
  if(!self&&!isA){
    ac.append(btn(+u.banned?'Buka blokir akun':'⛔ Blokir akun',async()=>{if(!+u.banned&&!confirm('Blokir akun ini? Ia langsung keluar dan tidak bisa login.'))return;if(await admCall('adm_ban',{uid:id,on:+u.banned?0:1}))again()},'chip'));
    const del=btn('🗑 Hapus akun',async()=>{if(!confirm('HAPUS PERMANEN @'+u.username+' beserta semua datanya (live, chat, CAST, TALK, pesan, musik)?'))return;if(await admCall('adm_deluser',{uid:id})){closeSheet();reload&&reload()}},'chip');
    del.style.cssText='border-color:#e0405a;color:#e0405a';ac.append(del);
  }
  ac.append(btn('Lihat profil',()=>{closeSheet();openProfile(id)},'chip'));
  nodes.push(ac);sheet(nodes);
}
async function admRooms(body,tk){
  const d=await admCall('adm_rooms');if(tk!==subTok||!d)return;
  if(!d.rows.length){body.append(el('div','empty','Tidak ada live yang sedang berlangsung.'));return}
  d.rows.forEach(r=>{
    const c=el('div','ncard');c.style.margin='10px 0';
    c.append(el('b',null,r.title),el('div',null,'Host: '+r.host+' · 👤 '+r.listeners+' · ♥ '+r.likes+(+r.frozen?' · ❄ dibekukan':'')));
    const bar=el('div','chips');bar.style.marginTop='8px';
    bar.append(btn('Masuk',()=>openRoom(+r.id),'chip'),
      btn('🧹 Clear chat',async()=>{if(!confirm('Bersihkan semua chat di "'+r.title+'"?'))return;if(await admCall('adm_clearroom',{id:r.id}))alert('Chat dibersihkan')},'chip'),
      btn('Akhiri live',async()=>{if(!confirm('Akhiri live "'+r.title+'" untuk semua pendengar?'))return;if(await admCall('adm_endroom',{id:r.id})){body.replaceChildren();admRooms(body,tk)}},'chip'));
    c.append(bar);body.append(c);
  });
}
function admContent(body,tk){
  const bar=el('div','bchips'),box=el('div');body.append(bar,box);
  const draw=async()=>{
    bar.replaceChildren(...[['talk','TALK'],['cast','CAST']].map(([k,l])=>btn(l,()=>{admSub=k;draw()},admSub===k?'on':'')));
    box.replaceChildren();const kind=admSub;
    const d=await admCall(kind==='talk'?'adm_talks':'adm_casts');if(tk!==subTok||!d||kind!==admSub)return;
    if(!d.rows.length){box.append(el('div','empty','Belum ada konten.'));return}
    d.rows.forEach(x=>{
      const c=el('div','ncard');c.style.margin='10px 0';
      c.append(el('b',null,x.name+' · ID '+x.uid),el('div',null,kind==='talk'?x.text:x.title));
      if(kind==='cast'){const au=el('audio');au.controls=true;au.preload='none';au.src='uploads/'+encodeURIComponent(x.file);au.style.cssText='width:100%;margin-top:6px';c.append(au)}
      c.append(el('small',null,new Date(x.ts*1000).toLocaleString('id-ID')));
      const del=btn('🗑 Hapus',async()=>{if(!confirm('Hapus konten ini?'))return;if(await admCall(kind==='talk'?'adm_talkdel':'adm_castdel',{id:x.id}))draw()},'chip');del.style.marginTop='8px';c.append(del);
      box.append(c);
    });
  };
  draw();
}
async function admLog(body,tk){
  const d=await admCall('adm_log');if(tk!==subTok||!d)return;
  if(!d.rows.length){body.append(el('div','empty','Belum ada aktivitas admin.'));return}
  d.rows.forEach(x=>{const c=el('div','ncard');c.style.margin='8px 0';
    c.append(el('b',null,x.act+(+x.target?' · ID '+x.target:'')),el('div',null,x.detail||'—'),el('small',null,(x.admin||'?')+' · '+new Date(x.ts*1000).toLocaleString('id-ID')));body.append(c)});
}


/* ---------- TAMPILAN ROOM: kursi, mode ikon call, pesan baru ---------- */
const IC={phone:'<svg viewBox="0 0 24 24" aria-hidden="true" fill="currentColor"><path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></svg>',mic:'<svg viewBox="0 0 24 24" aria-hidden="true" fill="currentColor"><path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm5.3-3c0 3-2.54 5.1-5.3 5.1S6.7 14 6.7 11H5c0 3.41 2.72 6.23 6 6.72V21h2v-3.28c3.28-.48 6-3.3 6-6.72h-1.7z"/></svg>',micoff:'<svg viewBox="0 0 24 24" aria-hidden="true" fill="currentColor"><path d="M19 11h-1.7c0 .74-.16 1.43-.43 2.05l1.23 1.23c.56-.98.9-2.09.9-3.28zm-4.02.17c0-.06.02-.11.02-.17V5c0-1.66-1.34-3-3-3S9 3.34 9 5v.18l5.98 5.99zM4.27 3 3 4.27l6.01 6.01V11c0 1.66 1.33 3 2.99 3 .22 0 .44-.03.65-.08l1.66 1.66c-.71.33-1.5.52-2.31.52-2.76 0-5.3-2.1-5.3-5.1H5c0 3.41 2.72 6.23 6 6.72V21h2v-3.28c.91-.13 1.77-.45 2.54-.9L19.73 21 21 19.73 4.27 3z"/></svg>',person:'<svg viewBox="0 0 24 24" aria-hidden="true" fill="currentColor"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>'};
let speakSet=new Set(),nmN=0;
const atBottom=()=>chat.scrollHeight-chat.scrollTop-chat.clientHeight<80;
function showNM(){nmN++;$('#newMsg').classList.add('show')}
function hideNM(){nmN=0;$('#newMsg').classList.remove('show')}
$('#newMsg').onclick=()=>{chat.scrollTo({top:chat.scrollHeight,behavior:'smooth'});hideNM()};
chat.addEventListener('scroll',()=>{if(atBottom())hideNM()});
function setStageMode(c){const at=atBottom();$('#roomv').classList.toggle('sc',!!c);$('#stgCol').setAttribute('aria-expanded',c?'false':'true');if(at)chat.scrollTop=chat.scrollHeight}
$('#stgCol').onclick=()=>setStageMode(true);
$('#callFab').onclick=()=>setStageMode(false);
function applySpeak(){document.querySelectorAll('.st[data-id]').forEach(e=>e.classList.toggle('speak',speakSet.has(e.dataset.id)));$('#callFab').classList.toggle('speak',speakSet.size>0)}
function seatMute(){
  if(!curRoom)return;
  document.querySelectorAll('.st[data-id]').forEach(e=>{
    const id=e.dataset.id;let mu=false;
    if(me&&String(me.id)===id)mu=!(me.mic===2&&!muted);
    else if(lk){let f=null;lk.remoteParticipants.forEach(p=>{if(String(p.identity)===id)f=p});mu=f?!f.isMicrophoneEnabled:true}
    e.classList.toggle('mu',mu);
  });
}
setInterval(seatMute,800);
function seatTap(p){
  if(!me)return;
  if(!p){if(me.mic===0)act('ask');else if(me.mic===1)act('down');else toast('Kamu sudah di panggung.');return}
  if(+p.id===+me.id){muted=!muted;micUI();syncMic();seatMute();return}
  if(me.role!=='member'||me.admin)showStage();
}
const GAMEPATH='<path d="M7 8h10a4 4 0 0 1 4 4v3a3 3 0 0 1-5.2 2l-1.3-1.5h-5L8.2 17A3 3 0 0 1 3 15v-3a4 4 0 0 1 4-4z"/><path d="M8 11v3M6.5 12.5h3"/><circle cx="15.5" cy="11.5" r=".8" fill="currentColor"/><circle cx="17.5" cy="13.5" r=".8" fill="currentColor"/>';
/* ---------- pantauan siaran ---------- */
function monitorNote(light){
  if(!MONITOR)return document.createDocumentFragment();
  const n=el('div','mon'+(light?' light':''));
  n.innerHTML=xsvg('shield');n.append(el('span',null,MONITOR));n.onclick=showRules;return n;
}
function showRules(){
  const head=el('div','shead');head.append(el('h3',null,'Aturan siaran'),btn('✕',closeSheet,'sx'));
  const info=el('div',null,MONITOR);info.style.cssText='color:#666;margin-bottom:8px;line-height:1.4';
  const nodes=[head,info];
  RULES.forEach(t=>{const r=el('div','vperk'),i=el('span');i.innerHTML=xsvg('ban',2);i.firstChild.style.color='#e0405a';r.append(i,el('span',null,t));nodes.push(r)});
  sheet(nodes);
}
/* ---------- VIP ---------- */
function showVip(after){
  const until=+(P.vip||0),active=until>Date.now()/1000;
  const head=el('div','shead');head.append(el('h3',null,'VIP'),btn('✕',closeSheet,'sx'));
  const hero=el('div','vhero'),cr=el('div','vcrown');cr.innerHTML=CROWN;hero.append(cr,el('b',null,active?'VIP aktif':'Jadi member VIP'));
  const sub=el('div',null,active?'Berlaku sampai '+new Date(until*1000).toLocaleDateString('id-ID',{day:'numeric',month:'long',year:'numeric'}):'Tampil beda dan naik level lebih cepat');sub.style.cssText='color:#888;margin-top:4px';hero.append(sub);
  const nodes=[head,hero];
  ['Lencana VIP dan ring avatar emas','EXP 1,5× lebih cepat','Klaim koin harian 100 (biasa 50)','Nama emas di chat dan pengumuman masuk'].forEach(t=>{
    const r=el('div','vperk'),i=el('span');i.innerHTML=xsvg('check',2.4);r.append(i,el('span',null,t));nodes.push(r)});
  const co=el('div',null,'Koin kamu: '+nf(P.coins||0)+' 🪙');co.style.cssText='margin:10px 0 0;color:#666';nodes.push(co);
  [[7,150],[30,500],[90,1200]].forEach(([d,pr],i)=>{
    const b=el('button','vplan');b.type='button';b.append(el('span',null,d+' hari'),el('span',null,nf(pr)+' 🪙'));
    b.onclick=async()=>{if(!confirm('Beli VIP '+d+' hari seharga '+nf(pr)+' koin?'))return;const r=await api('vipbuy',{plan:i});if(r.err)return alert(r.err);await refreshMe();after&&after();showVip(after)};
    nodes.push(b)});
  sheet(nodes);
}
/* ---------- Live Game: Lucky Box, Quiz, Tautan Game ---------- */
let gList=[],gOff=0,gameOpen=false,gcTick=0;
const gNow=()=>Date.now()/1000+gOff;
const fmtRem=s=>{s=Math.ceil(Math.max(0,s));return Math.floor(s/60)+':'+pad(s%60)};
function syncGames(g){if(!g)return;gOff=g.now-Date.now()/1000;gList=g.list||[];updGameBar()}
function updGameBar(){
  const bar=$('#gamebar');
  if(!curRoom||!gList.length){bar.style.display='none';return}
  const g=gList[0],rem=g.ends-gNow(),todo=!(g.mine||g.played);
  let t;
  if(g.kind==='lucky')t='🎁 Lucky Box · sisa '+g.ls+'/'+g.slots+' · '+fmtRem(rem)+(todo?' — Ambil!':'');
  else if(g.kind==='quiz')t='❓ Quiz · '+fmtRem(rem)+(todo?' — Jawab!':'');
  else t='🎮 '+g.title+' — Main!';
  if(gList.length>1)t+='  (+'+(gList.length-1)+')';
  bar.textContent=t;bar.style.display='block';
}
setInterval(updGameBar,1000);
$('#gamebar').onclick=()=>showGame();
const GART={
  lucky:'<svg viewBox="0 0 64 64" width="74" height="74"><rect x="10" y="26" width="44" height="30" rx="5" fill="#fff"/><rect x="6" y="18" width="52" height="12" rx="4" fill="#ffd9d6"/><rect x="28" y="18" width="8" height="38" fill="#ff5a4f"/><path d="M32 18c-8-12-20-6-14 0zM32 18c8-12 20-6 14 0z" fill="#ff5a4f"/></svg>',
  quiz:'<svg viewBox="0 0 64 64" width="74" height="74"><text x="32" y="50" text-anchor="middle" font-size="58" font-weight="900" fill="#ff7ac8" stroke="#fff" stroke-width="2.500">?</text></svg>',
  link:'<svg viewBox="0 0 64 64" width="74" height="74" fill="none" stroke="#fff" stroke-width="5" stroke-linecap="round"><path d="M27 37a10 10 0 0 0 14 0l9-9a10 10 0 0 0-14-14l-3 3"/><path d="M37 27a10 10 0 0 0-14 0l-9 9a10 10 0 0 0 14 14l3-3"/></svg>'
};
function gcard(kind,title,desc,fn,icon){
  const c=el('div','gcard'),a=el('div','gart '+kind);if(icon)a.textContent=icon;else a.innerHTML=GART[kind];
  c.append(a,el('b',null,title),el('small',null,desc));c.onclick=fn;return c;
}
const goBack=()=>btn('← Game',()=>showGame(),'chip');
async function showGame(refresh){
  const mod=me&&(me.role!=='member'||me.admin);
  const d=await api('glist',{});if(!d.games)return;
  if(refresh&&!gameOpen)return;
  const sc=$('#box').scrollTop,head=el('div','gh');head.append(el('b',null,'Live Game'),btn('✕',closeSheet,'sx'));
  const nodes=[head];
  if(mod){
    nodes.push(el('h2','gsec','Mulai game'));
    const row=el('div','gcards');
    row.append(gcard('lucky','Lucky Box','Bagikan koin ke pendengar!',startLucky),gcard('quiz','Quiz','Kirim kuis pilihan ganda seru!',startQuiz),gcard('link','Tautan Game','Mainkan game lewat link langsung.',startLink));
    GAME_PRESETS.forEach(p=>row.append(gcard('preset',p.title,'Game bawaan',()=>presetStart(p),p.icon||'🎮')));
    nodes.push(row);
  }else{const n=el('div',null,'Hanya penyiar dan manajer yang bisa memulai game. Kamu bisa ikut bermain di bawah.');n.style.cssText='color:#888;margin:6px 0';nodes.push(n)}
  nodes.push(el('h2','gsec','Sedang berlangsung'));
  if(!d.games.length){const e=el('div','gempty');e.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'+GAMEPATH+'</svg>';e.append(el('span',null,mod?'Belum ada game. Mulai game dari atas!':'Belum ada game saat ini.'));nodes.push(e)}
  d.games.forEach(g=>nodes.push(gameItem(g,mod)));
  sheet(nodes);gameOpen=true;$('#box').scrollTop=refresh?sc:0;
}
function gameItem(g,mod){
  const c=el('div','gitem'),live=g.state==='live',rem=g.ends-gNow(),gt=el('div','gt'),gb=el('div','gb');
  const again=async(a,x)=>{const r=await api(a,{id:g.id,...(x||{})});if(r.err)toast(r.err);nudge();poll();return r};
  if(g.kind==='lucky'){
    gt.append(el('span',null,'🎁 Lucky Box'),el('small',null,live?fmtRem(rem):'Selesai'));
    c.append(gt,el('div','gs',g.by+' membagikan '+nf(g.total)+' koin untuk '+g.slots+' orang · sisa '+g.left_slots));
    if(live&&!g.mine&&!g.played&&g.left_slots>0)gb.append(btn('Ambil sekarang!',async()=>{const r=await again('gclaim');if(r.ok)toast('Kamu mendapat '+r.amt+' koin! 🎉');showGame(true)},'ok'));
    else if(g.played)gb.append(el('b',null,'Kamu mendapat '+nf(g.myval)+' koin'));
    else if(g.mine)gb.append(el('small',null,'Kamu pembagi Lucky Box ini'));
    if(live&&(mod||g.mine))gb.append(btn('Akhiri',async()=>{await again('gend');showGame(true)},'chip'));
    c.append(gb);
    if(g.claims&&g.claims.length){const cl=el('div','gcl');g.claims.forEach(x=>cl.append(el('span',null,x.name+' +'+nf(x.amt))));c.append(cl)}
  }else if(g.kind==='quiz'){
    gt.append(el('span',null,'❓ Quiz'),el('small',null,live?fmtRem(rem):'Selesai'));
    c.append(gt,el('div','gs',g.q+(g.prize>0?'  ·  Hadiah '+nf(g.prize)+' koin':'')));
    const L=['A','B','C','D'];
    g.opts.forEach((o,i)=>{
      const b=el('button','gopt'),k=el('b',null,L[i]);b.type='button';b.append(k,el('span',null,o));
      if(!live){b.disabled=true;if(i===g.correct)b.classList.add('ok');if(g.myval===i)b.classList.add('me')}
      else if(g.played||g.mine){b.disabled=true;if(g.myval===i)b.classList.add('me')}
      else b.onclick=async()=>{const r=await again('ganswer',{choice:i});if(!r.err)toast('Jawaban terkirim');showGame(true)};
      c.append(b);
    });
    if(live)c.append(el('div','gs',g.mine?g.answers+' orang sudah menjawab':(g.played?'Jawabanmu terkirim. Menunggu hasil…':'Pilih jawabanmu!')));
    else c.append(el('div','gs',g.wcount?g.wcount+' orang benar'+(g.each>0?' (+'+nf(g.each)+' koin)':'')+': '+g.winners.map(w=>w.name).join(', '):'Tidak ada yang menjawab benar.'));
    if(live&&(mod||g.mine))gb.append(btn('Akhiri sekarang',async()=>{await again('gend');showGame(true)},'chip'));
    c.append(gb);
  }else{
    gt.append(el('span',null,'🎮 '+g.title),el('small',null,live?'Berlangsung':'Selesai'));
    c.append(gt,el('div','gs','Dibagikan oleh '+g.by));
    if(live)gb.append(btn('Main',()=>openGameFrame(g.url,g.title),'ok'));
    if(live&&(mod||g.mine))gb.append(btn('Tutup game',async()=>{await again('gend');showGame(true)},'chip'));
    c.append(gb);
  }
  return c;
}
function openGameFrame(url,title){
  let host='';try{host=new URL(url).hostname}catch(e){return toast('Tautan tidak valid')}
  if(!confirm('Membuka game eksternal dari '+host+'.\nPastikan kamu mempercayai situs ini. Lanjutkan?'))return;
  closeSheet();$('#gfT').textContent=title;$('#gfO').href=url;$('#gfF').src=url;$('#gframe').classList.add('show');
}
$('#gfX').onclick=()=>{$('#gframe').classList.remove('show');$('#gfF').src='about:blank'};
async function sendGame(d){const r=await api('gstart',d);if(r.err){alert(r.err);return false}nudge();poll();showGame();return true}
function startLucky(){
  const t=el('input','fld');t.type='number';t.value=100;t.min=10;t.placeholder='Total koin';
  const n=el('input','fld');n.type='number';n.value=5;n.min=1;n.max=50;n.placeholder='Jumlah penerima';
  const info=el('div',null,'Koinmu: '+nf((me&&me.coins)||0)+' 🪙. Koin dibagi acak ke yang pertama mengambil. Sisa dikembalikan setelah 2 menit.');info.style.cssText='color:#888;margin:2px 0 10px;font-size:14px;line-height:1.4';
  const go=btn('Bagikan Lucky Box',async()=>{go.disabled=true;if(!await sendGame({kind:'lucky',total:t.value,slots:n.value}))go.disabled=false},'ok');
  sheet([goBack(),el('h3',null,'Lucky Box'),el('small',null,'Total koin'),t,el('small',null,'Jumlah penerima'),n,info,go]);
}
function startQuiz(){
  const q=el('input','fld');q.placeholder='Pertanyaan';q.maxLength=150;
  const rows=[0,1,2,3].map(i=>{const w=el('label','qopt'),r=el('input');r.type='radio';r.name='qc';if(i===0)r.checked=true;const f=el('input','fld');f.placeholder='Pilihan '+'ABCD'[i]+(i>1?' (opsional)':'');f.maxLength=60;w.append(r,f);return{w,r,f}});
  const secs=el('select','fld');[15,20,30,45,60].forEach(v=>{const o=el('option',null,v+' detik');o.value=v;if(v===20)o.selected=true;secs.append(o)});
  const prize=el('input','fld');prize.type='number';prize.value=0;prize.min=0;prize.placeholder='Hadiah koin (opsional)';
  const info=el('div',null,'Pilih bulatan di samping jawaban yang benar. Hadiah dibagi rata ke yang menjawab benar; sisa dikembalikan. Koinmu: '+nf((me&&me.coins)||0)+' 🪙');info.style.cssText='color:#888;margin:2px 0 10px;font-size:14px;line-height:1.4';
  const go=btn('Mulai quiz',async()=>{
    const opts=[];let correct=-1;
    rows.forEach((x,i)=>{const v=x.f.value.trim();if(v){opts.push(v);if(x.r.checked)correct=opts.length-1}});
    if(!q.value.trim())return alert('Tulis pertanyaan');
    if(opts.length<2)return alert('Isi minimal 2 pilihan');
    if(correct<0)return alert('Pilih jawaban yang benar (harus terisi)');
    go.disabled=true;if(!await sendGame({kind:'quiz',q:q.value,opts:JSON.stringify(opts),correct,secs:secs.value,prize:prize.value||0}))go.disabled=false;
  },'ok');
  sheet([goBack(),el('h3',null,'Quiz'),q,...rows.map(x=>x.w),el('small',null,'Waktu menjawab'),secs,el('small',null,'Hadiah'),prize,info,go]);
}
function startLink(){
  const t=el('input','fld');t.placeholder='Nama game';t.maxLength=40;
  const u=el('input','fld');u.type='url';u.placeholder='https://alamat-game-kamu.com';
  const info=el('div',null,'Hanya tautan https. Beberapa situs tidak bisa tampil di dalam aplikasi, pemain tetap bisa membukanya di tab baru.');info.style.cssText='color:#888;margin:2px 0 10px;font-size:14px;line-height:1.4';
  const go=btn('Bagikan game',async()=>{go.disabled=true;if(!await sendGame({kind:'link',title:t.value,url:u.value}))go.disabled=false},'ok');
  sheet([goBack(),el('h3',null,'Tautan Game'),t,u,info,go]);
}
async function presetStart(p){if(confirm('Mulai game "'+p.title+'"?'))sendGame({kind:'link',title:p.title,url:p.url})}
$('#bGame').onclick=()=>showGame();

/* ---------- header room, pengumuman, target, live berakhir, Lucky Bag, home Choice ---------- */
const onv=(a,on)=>{if(+on)a.classList.add('onl');return a};
let annTimer=0,endedShown=false,bagQ=[],bagBusy=false,bagT=0;
function renderPill(d){
  const b=$('#bFol'),r=d.room||{},mineHost=me&&+r.hostid===+me.id;
  b.style.display=(+r.fol||mineHost)?'none':'flex';
  b.onclick=async e=>{e.stopPropagation();const x=await api('follow',{uid:r.hostid,on:1});if(x.err)return alert(x.err);b.style.display='none';r.fol=1};
}
function openAnn(auto){
  const pin=$('#pin'),h=document.querySelector('#roomv header');pin.style.top=(h.offsetHeight+8)+'px';
  pin.classList.add('show');$('#bAnnT').classList.remove('dot');clearTimeout(annTimer);
  if(auto)annTimer=setTimeout(()=>pin.classList.remove('show'),8000);
}
$('#bAnnT').onclick=()=>{const pin=$('#pin');clearTimeout(annTimer);if(pin.classList.contains('show'))pin.classList.remove('show');else openAnn(false)};
function renderAnn(d){
  const pin=$('#pin'),r=d.room||{},w=(r.welcome||'').trim(),g=r.goal||{t:'',n:0,cur:0};
  const sig=JSON.stringify([w,g.t,g.n]),changed=pin.dataset.s!==sig;
  pin.replaceChildren();
  if(+g.n>0){
    const gb=el('div','gb'),bar=el('div','gbar'),fill=el('i'),cur=Math.min(+g.cur||0,+g.n);
    fill.style.width=Math.min(100,Math.round(cur/g.n*100))+'%';bar.append(fill,el('span','gnum',nf(cur)+'/'+nf(g.n)));
    const sc=el('div','gsc');sc.append(el('span',null,'0'),el('span',null,nf(g.n)));
    gb.append(el('b','gtt',g.t),bar,sc);pin.append(gb);
  }
  if(w){const a=el('div','ann');a.append(el('b',null,'Live Announcement'),el('div','pt',w));pin.append(a)}
  if(!pin.children.length){const a=el('div','ann');a.append(el('div','pt','Belum ada pengumuman.'));pin.append(a)}
  if(changed){pin.dataset.s=sig;if(w||+g.n>0){$('#bAnnT').classList.add('dot');openAnn(true)}}
}
function showGoal(){
  const g=(lastData&&lastData.room&&lastData.room.goal)||{t:'',n:0};
  const head=el('div','shead');head.append(el('h3',null,'Target live'),btn('✕',closeSheet,'sx'));
  const t=el('input','fld');t.maxLength=40;t.placeholder='Nama target (mis. Beli mic baru)';t.value=g.t||'';
  const n=el('input','fld');n.type='number';n.min='1';n.placeholder='Jumlah koin target';n.value=+g.n||'';
  const inf=el('div',null,'Progres bertambah dari hadiah yang masuk ke room ini sejak target dibuat.');inf.style.cssText='color:#888;font-size:14px;margin:6px 0 12px;line-height:1.4';
  const del=btn('Hapus target',async()=>{await act('goal',{t:'',n:0});closeSheet()},'chip');
  sheet([head,t,n,inf,btn('Simpan target',async()=>{const r=await api('goal',{t:t.value,n:n.value});if(r.err)return alert(r.err);closeSheet();poll()},'ok'),del]);
}
/* ---- live berakhir ---- */
function roomEnded(){
  if(endedShown||!curRoom)return;endedShown=true;setTimeout(()=>{endedShown=false},2000);
  const r=lastData&&lastData.room,self=r&&me&&+r.hostid===+me.id;
  closeRoom(true);
  if(!r||self)alert('Live telah berakhir.');else showEnded(r);
}
function showEnded(r){
  const v=$('#endv');v.replaceChildren();
  const x=el('button','ex','✕');x.type='button';x.setAttribute('aria-label','Tutup');x.onclick=()=>v.classList.remove('show');
  const av=el('div','ava');av.style.background=bg(r.host,r.hphoto);if(!r.hphoto)av.textContent=(String(r.host||'?')[0]||'?').toUpperCase();
  const wrap=el('div','eav');wrap.append(av);
  const fol=el('button','efol','+');fol.type='button';fol.setAttribute('aria-label','Ikuti');if(+r.fol)fol.style.display='none';
  fol.onclick=async()=>{const x=await api('follow',{uid:r.hostid,on:1});if(x.err)return alert(x.err);fol.style.display='none'};wrap.append(fol);
  const row=el('div','erow'),inp=el('input'),sb=el('button',null,'Kirim');inp.placeholder='Kirim pesan';inp.maxLength=300;sb.type='button';sb.disabled=true;
  inp.oninput=()=>{sb.disabled=!inp.value.trim()};
  const send=async()=>{const tx=inp.value.trim();if(!tx)return;sb.disabled=true;const x=await api('dmsend',{uid:r.hostid,text:tx});if(x.err){sb.disabled=false;return alert(x.err)}inp.value='';sb.textContent='Terkirim ✓';setTimeout(()=>{sb.textContent='Kirim';sb.disabled=!inp.value.trim()},1600)};
  sb.onclick=send;inp.onkeydown=e=>{if(e.key==='Enter')send()};row.append(inp,sb);
  v.append(x,el('h2',null,'Live telah berakhir.'),wrap,el('div','enm',r.host),row);v.classList.add('show');
}
/* ---- Lucky Bag global ---- */
const COIN_SVG='<svg class="bcoin" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="#f6b21a" stroke="#d98e0a" stroke-width="1.5"/><path d="m12 6.500 1.600 3.400 3.700.5-2.700 2.600.7 3.700L12 14.900 8.700 16.700l.7-3.700-2.700-2.600 3.700-.5z" fill="#fff3b8"/></svg>';
const BAG_GIFT='<svg viewBox="0 0 64 64"><defs><linearGradient id="bgp" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#ff6fae"/><stop offset="1" stop-color="#d81f78"/></linearGradient></defs><rect x="4" y="4" width="56" height="56" rx="14" fill="url(#bgp)" stroke="#f6c95a" stroke-width="3"/><path d="M32 14l5 10 11 1.600-8 7.700 1.900 11L32 39l-9.900 5.300 1.900-11-8-7.700L27 24z" fill="#ffd86b" stroke="#c98a12" stroke-width="1.500"/><path d="M12 46l-4 6 8-1zM52 46l4 6-8-1z" fill="#ffd86b"/></svg>';
const BAG_CROWN='<svg class="bcr" viewBox="0 0 92 36"><path d="M46 4l5 7 7-3-2 9H36l-2-9 7 3z" fill="#ffd24a" stroke="#c98a12" stroke-width="1.200"/><path d="M30 18C20 18 12 14 4 6c4 12 14 19 28 19zM62 18c10 0 18-4 26-12-4 12-14 19-28 19z" fill="#f6c95a" opacity=".9"/></svg>';
const LB_ICON='<svg viewBox="0 0 40 40" aria-hidden="true"><rect x="3" y="3" width="34" height="34" rx="9" fill="#e8405a" stroke="#f6c95a" stroke-width="2.4"/><path d="M20 9l3 6.200 6.800 1-4.900 4.800 1.200 6.800L20 24.600l-6.100 3.200 1.200-6.800-4.900-4.800 6.800-1z" fill="#ffd86b"/></svg>';
function lbIcon(){const e=el('span','lbi');e.title='Ada Lucky Bag!';e.innerHTML=LB_ICON;return e}
function buildBag(b){
  const w=el('div','bgi');w.innerHTML=BAG_CROWN;
  for(let i=0;i<7;i++){const c=el('i','bcn');c.style.left=(10+i*13)+'%';c.style.animationDelay=(i*.35)+'s';w.append(c)}
  const tx=el('div','btx'),l1=el('div','bl1');l1.append(el('b','bnm',b.name),lvBadge(b.exp));if(+b.vip)l1.append(vipBadge());
  const l2=el('div','bl2');l2.append(document.createTextNode('Kirim Lucky Bag : '+nf(b.total)+' '));const ci=el('span');ci.innerHTML=COIN_SVG;l2.append(ci.firstChild);
  tx.append(l1,l2);
  if(+b.room&&+b.room!==+curRoom)tx.append(el('div','bl3','📍 '+b.rtitle+' · ketuk untuk masuk'));
  const g=el('div','bgf');g.innerHTML=BAG_GIFT;
  w.append(ava(b.name,b.photo,'bga',+b.vip),tx,g);return w;
}
function handleBag(d){
  if(d.bagmax===undefined)return;
  if(lastBag>=0&&d.bagnew&&+d.bagnew.id>lastBag)bagQ.push(d.bagnew);
  lastBag=Math.max(lastBag,d.bagmax);playBag();
}
function playBag(){
  if(bagBusy||!bagQ.length)return;bagBusy=true;
  const b=bagQ.shift(),box=$('#bagB');box.replaceChildren(buildBag(b));
  requestAnimationFrame(()=>box.classList.add('show'));
  const done=()=>{box.classList.remove('show');setTimeout(()=>{bagBusy=false;playBag()},700)};
  clearTimeout(bagT);bagT=setTimeout(done,6000);
  box.onclick=()=>{clearTimeout(bagT);done();goBag(+b.room)};
}
// pindah otomatis ke room tempat Lucky Bag dilempar, lalu buka daftar Lucky Bag
async function goBag(roomId){
  if(roomId&&+roomId!==+curRoom){closeSheet();await openRoom(roomId);if(+curRoom!==+roomId)return}
  showBag();
}
const remTxt=s=>{s=Math.max(0,s|0);return Math.floor(s/60)+':'+pad(s%60)};
async function showBag(){
  const d=await api('bag_list',{});if(!d.bags)return alert(d.err||'Gagal memuat Lucky Bag');
  const head=el('div','shead');head.append(el('h3',null,'Lucky Bag'),btn('✕',closeSheet,'sx'));
  const nodes=[head];const sec=t=>{const h=el('h4',null,t);h.style.cssText='margin:14px 0 6px;font-size:17px';return h};
  nodes.push(sec('Sedang dibagikan'));
  if(!d.bags.length){const e=el('div','empty',curRoom?'Belum ada Lucky Bag aktif.\nLempar satu di bawah!':'Belum ada Lucky Bag aktif.\nMasuk room lalu lempar dari menu ^ → Lucky Bag.');e.style.cssText='padding:18px 0;white-space:pre-line';nodes.push(e)}
  d.bags.forEach(b=>{
    const c=el('div','ncard');c.style.margin='8px 0';
    const top=el('div');top.style.cssText='display:flex;gap:10px;align-items:center';
    const tx=el('div');tx.style.cssText='flex:1;min-width:0';const nb=nameB(b.by,b.badge);if(+b.vip)nb.append(' ',vipBadge());
    tx.append(nb,el('small',null,nf(b.total)+' koin · sisa '+b.ls+'/'+b.slots+' · '+remTxt(b.ends-d.now)),el('small',null,'📍 '+b.rtitle));
    const av=ava(b.by,b.photo,'',+b.vip);av.style.cssText='width:44px;height:44px;flex:none;font-size:18px';top.append(av,tx);c.append(top);
    const here=+b.room===+curRoom;
    if(+b.got>=0){const g=el('div',null,'Kamu mendapat +'+nf(b.got)+' koin 🎉');g.style.cssText='margin-top:8px;color:#2a9d57;font-weight:700';c.append(g)}
    else if(+b.mine)c.append(el('small',null,'Lucky Bag milikmu'));
    else if(+b.ls>0){
      const bt=btn(here?'Ambil sekarang!':'Masuk room & ambil',async()=>{
        bt.disabled=true;
        if(!here){closeSheet();await openRoom(+b.room);if(+curRoom!==+b.room)return;showBag();return}
        const r=await api('bag_claim',{id:b.id});if(r.err)alert(r.err);await refreshMe();showBag()},'ok');
      bt.style.marginTop='8px';c.append(bt);
    }
    if(b.claims&&b.claims.length){const cl=el('div','chips');cl.style.marginTop='8px';b.claims.forEach(x=>cl.append(el('span','chip',x.name+' +'+nf(x.amt))));c.append(cl)}
    nodes.push(c);
  });
  if(curRoom){
    nodes.push(sec('Lempar Lucky Bag di room ini'));
    const mk=(ph,v)=>{const i=el('input','fld');i.type='number';i.placeholder=ph;i.value=v;i.style.border='1px solid #ddd';return i};
    const tot=mk('Total koin (min. 100)',500),slots=mk('Jumlah penerima (1 – 100)',5);
    const inf=el('div',null,'Koinmu: '+nf(d.coins)+' 🪙. Banner tampil ke semua orang di semua room; yang ketuk akan langsung pindah ke room ini. Bagian diambil acak, sisa dikembalikan setelah 3 menit.');inf.style.cssText='color:#888;font-size:13px;margin:6px 0 10px;line-height:1.4';
    nodes.push(tot,slots,inf,btn('Lempar Lucky Bag',async()=>{const r=await api('bag_send',{total:tot.value,slots:slots.value});if(r.err)return alert(r.err);await refreshMe();showBag()},'ok'));
  }
  sheet(nodes);
}
$('#hBag').onclick=()=>showBag();
/* ---- home: bagian Choice ---- */
function buildChoice(list){
  const sec=el('div','chsec');if(!list.length)return sec;
  const h=el('div','sech');h.append(el('b',null,'Pilihan Choice'),el('span','chev','›'));
  const row=el('div','chrow');
  list.forEach(r=>{
    const c=el('div','chc'),im=el('div','chi'),pic=r.cover||r.hphoto;im.style.background=bg(r.title,pic);if(!pic)im.textContent=(r.title[0]||'?').toUpperCase();
    im.append(el('span','ctag','Choice'));if(+r.bags)im.append(lbIcon());
    const m=el('div','chm'),st=el('small',null,'👤 '+r.listeners+'   ♥ '+r.likes);m.append(ava(r.host,r.hphoto),el('span','chn',r.host),st);
    c.append(im,el('b',null,r.title),m);c.onclick=()=>openRoom(+r.id);row.append(c);
  });
  sec.append(h,row);return sec;
}
if(TOK)start();else showAuth();
</script>
</body></html>