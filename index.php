<?php
/**
 * FULL WORKING index.php (Webhook) — FAST OPTIMIZED
 *
 * ✅ Main Menu + Admin Panel = Reply Keyboard (big buttons like your screenshot)
 * ✅ FAST: stock + prices loaded in 2 queries total (not 8+ queries)
 * ✅ 2K & 4K: when user clicks, ONLY a message appears (NO payment / NO next step)
 * ✅ Shop: 500/1000 work normally -> QR -> "I have done payment" deletes QR -> UTR -> Screenshot -> Admin approve/decline
 * ✅ Admin: Send QR, Prices, Add/Remove Coupons, Stock, Purchases, Free code, Stats
 * ✅ No cron: approx timeout via lazy-expiry (~12 min) checked on any user action
 *
 * ENV (Render):
 * BOT_TOKEN
 * DATABASE_URL
 * ADMIN_IDS (comma separated)
 * SUPPORT_BOT_USERNAME (optional) e.g. Viippphelp_bot
 * APP_TZ (optional) default Asia/Kolkata
 */

date_default_timezone_set(getenv('APP_TZ') ?: 'Asia/Kolkata');

$BOT_TOKEN = getenv('BOT_TOKEN');
$DATABASE_URL = getenv('DATABASE_URL');
$ADMIN_IDS_RAW = getenv('ADMIN_IDS') ?: '';
$SUPPORT_BOT_USERNAME = getenv('SUPPORT_BOT_USERNAME') ?: 'Viippphelp_bot';

if (!$BOT_TOKEN) { http_response_code(500); echo "BOT_TOKEN missing"; exit; }
if (!$DATABASE_URL) { http_response_code(500); echo "DATABASE_URL missing"; exit; }

$ADMIN_IDS = array_values(array_filter(array_map('trim', explode(',', $ADMIN_IDS_RAW))));
$ADMIN_IDS = array_map('strval', $ADMIN_IDS);

const ORDER_TTL_SECONDS = 720; // ~12 minutes

const PRODUCT_TYPES = [
  '500_500'   => '500 off 500',
  '1000_1000' => '1000 off 1000',
  '2000_2000' => '2000 off 2000',
  '4000_4000' => '4000 off 4000',
];

if (!function_exists('str_starts_with')) {
  function str_starts_with($haystack, $needle) {
    return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
  }
}

function botlog($msg) {
  @file_put_contents(__DIR__."/bot_debug.log", "[".date('Y-m-d H:i:s')."] ".$msg."\n", FILE_APPEND);
}

function isAdmin($tg_id) {
  global $ADMIN_IDS;
  return in_array((string)$tg_id, $ADMIN_IDS, true);
}
function nowText() { return date('d M Y, h:i:s A'); }

/* -------------------- DB -------------------- */
function db() {
  static $pdo = null;
  global $DATABASE_URL;
  if ($pdo) return $pdo;

  $url = parse_url($DATABASE_URL);
  if (!$url || empty($url['host'])) throw new Exception("Invalid DATABASE_URL");

  $host = $url['host']; $port = $url['port'] ?? 5432;
  $user = $url['user'] ?? ''; $pass = $url['pass'] ?? '';
  $dbn  = ltrim($url['path'] ?? '', '/');

  $dsn = "pgsql:host={$host};port={$port};dbname={$dbn};sslmode=require";
  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  return $pdo;
}
function dbExec($sql, $params=[]) {
  $st = db()->prepare($sql);
  $st->execute($params);
  return $st;
}
function dbOne($sql, $params=[]) {
  $st = dbExec($sql, $params);
  $r = $st->fetch();
  return $r ?: null;
}
function dbAll($sql, $params=[]) {
  $st = dbExec($sql, $params);
  return $st->fetchAll();
}

/* -------------------- Telegram API -------------------- */
function tg($method, $params=[]) {
  global $BOT_TOKEN;
  $url = "https://api.telegram.org/bot{$BOT_TOKEN}/{$method}";
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
  curl_setopt($ch, CURLOPT_TIMEOUT, 15);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
  $res = curl_exec($ch);
  $err = curl_error($ch);
  curl_close($ch);

  if ($err) { botlog("TG curl error {$method}: ".$err); return ['ok'=>false,'description'=>$err]; }
  $json = json_decode($res, true);
  if (!$json) { botlog("TG bad json {$method}: ".$res); return ['ok'=>false,'description'=>$res]; }
  return $json;
}
function sendMessage($chat_id, $text, $reply_markup=null) {
  $p = ['chat_id'=>$chat_id,'text'=>$text,'parse_mode'=>'HTML','disable_web_page_preview'=>true];
  if ($reply_markup) $p['reply_markup'] = json_encode($reply_markup);
  return tg('sendMessage', $p);
}
function sendPhoto($chat_id, $file_id, $caption='', $reply_markup=null) {
  $p = ['chat_id'=>$chat_id,'photo'=>$file_id,'caption'=>$caption,'parse_mode'=>'HTML'];
  if ($reply_markup) $p['reply_markup'] = json_encode($reply_markup);
  return tg('sendPhoto', $p);
}
function deleteMessage($chat_id, $message_id) {
  return tg('deleteMessage', ['chat_id'=>$chat_id,'message_id'=>$message_id]);
}
function answerCallback($cb_id, $text='', $alert=false) {
  return tg('answerCallbackQuery', [
    'callback_query_id'=>$cb_id,
    'text'=>$text,
    'show_alert'=>$alert?'true':'false'
  ]);
}

/* -------------------- FAST CACHE (per webhook request) -------------------- */
function stockMap() {
  static $map = null;
  if ($map !== null) return $map;
  $map = ['500_500'=>0,'1000_1000'=>0,'2000_2000'=>0,'4000_4000'=>0];

  try {
    $rows = dbAll("select type, count(*)::int as c from coupons where is_used=false group by type");
    foreach ($rows as $r) {
      $t = $r['type']; $c = (int)$r['c'];
      if (isset($map[$t])) $map[$t] = $c;
    }
  } catch (Exception $e) {
    botlog("stockMap error: ".$e->getMessage());
  }
  return $map;
}
function priceMap() {
  static $map = null;
  if ($map !== null) return $map;
  $map = ['500_500'=>0,'1000_1000'=>0,'2000_2000'=>0,'4000_4000'=>0];

  try {
    // prices table might not exist — ignore if missing
    $rows = dbAll("select type, price_inr from prices");
    foreach ($rows as $r) {
      $t = $r['type']; $p = (int)$r['price_inr'];
      if (isset($map[$t])) $map[$t] = $p;
    }
  } catch (Exception $e) {
    // do nothing (keep 0)
  }
  return $map;
}
function priceLabel($type) {
  $pm = priceMap();
  return "₹".(int)($pm[$type] ?? 0);
}
function stockCount($type) {
  $sm = stockMap();
  return (int)($sm[$type] ?? 0);
}

/* -------------------- Users -------------------- */
function upsertUser($tg_id, $username, $first_name) {
  try {
    dbExec("
      insert into users(tg_id, username, first_name, last_seen, updated_at)
      values(:tg, :u, :f, now(), now())
      on conflict (tg_id) do update set
        username=excluded.username,
        first_name=excluded.first_name,
        last_seen=now(),
        updated_at=now()
    ", [':tg'=>$tg_id, ':u'=>$username, ':f'=>$first_name]);
  } catch (Exception $e) { botlog("upsertUser error: ".$e->getMessage()); }
}
function getUser($tg_id) {
  try { return dbOne("select * from users where tg_id=:tg", [':tg'=>$tg_id]); }
  catch (Exception $e) { botlog("getUser error: ".$e->getMessage()); return null; }
}
function setUserStep($tg_id, $step, $order_id=null) {
  try {
    dbExec("update users set step=:s, current_order_id=:oid, updated_at=now() where tg_id=:tg",
      [':s'=>$step, ':oid'=>$order_id, ':tg'=>$tg_id]
    );
  } catch (Exception $e) { botlog("setUserStep error: ".$e->getMessage()); }
}

/* -------------------- Prices admin (SAFE) -------------------- */
function setPrice($type, $price) {
  try {
    dbExec("insert into prices(type, price_inr, updated_at)
            values(:t,:p, now())
            on conflict (type) do update set price_inr=excluded.price_inr, updated_at=now()",
      [':t'=>$type, ':p'=>$price]
    );
    return true;
  } catch (Exception $e) {
    botlog("setPrice error: ".$e->getMessage());
    return false;
  }
}

/* -------------------- UI -------------------- */
function stockLines() {
  $lines = [];
  foreach (PRODUCT_TYPES as $k=>$label) {
    $lines[] = "• <b>{$label}</b> - <b>".priceLabel($k)."</b> (Stock: <code>".stockCount($k)."</code>)";
  }
  return implode("\n", $lines);
}
function mainReplyKb($tg_id) {
  $kb = [
    ['🛍️ Buy Vouchers', '📦 My Orders'],
    ['🔁 Recover Vouchers', '🆘 Support'],
    ['📜 Disclaimer'],
  ];
  if (isAdmin($tg_id)) $kb[] = ['🛠 Admin Panel'];

  return ['keyboard'=>$kb,'resize_keyboard'=>true,'is_persistent'=>true];
}
function adminReplyKb() {
  $kb = [
    ['📷 Send QR', '📊 Stats'],
    ['💰 Prices', '📦 Stock'],
    ['➕ Add Coupons', '➖ Remove Coupons'],
    ['🧾 Purchases', '🎁 Get a code'],
    ['⬅️ Back to Menu'],
  ];
  return ['keyboard'=>$kb,'resize_keyboard'=>true,'is_persistent'=>true];
}
function shopKb() {
  $rows = [];
  foreach (PRODUCT_TYPES as $k=>$label) {
    $rows[] = [ ['text'=> $label." • ".priceLabel($k), 'callback_data'=>"buy:{$k}"] ];
  }
  $rows[] = [ ['text'=>'⬅️ Back','callback_data'=>'inline_back_home'] ];
  return ['inline_keyboard'=>$rows];
}
function adminPickTypeKb($prefix) {
  $rows = [];
  foreach (PRODUCT_TYPES as $k=>$label) {
    $rows[] = [ ['text'=>$label, 'callback_data'=>"{$prefix}:{$k}"] ];
  }
  return ['inline_keyboard'=>$rows];
}
function adminPricesPickKb() {
  $rows = [];
  foreach (PRODUCT_TYPES as $k=>$label) {
    $rows[] = [ ['text'=>$label." (".priceLabel($k).")", 'callback_data'=>"admin_price_pick:{$k}"] ];
  }
  return ['inline_keyboard'=>$rows];
}

function homeText() {
  return "🎉 <b>Welcome to Shop Bot!</b>\n\n📦 <b>Available Stock</b>\n".stockLines()."\n\nChoose an option below 👇";
}
function disclaimerText() {
  return "📜 <b>Disclaimer</b>\n\n".
         "1. Once coupon is delivered, no returns or refunds will be accepted.\n".
         "2. If Your Reason Of Refund And Replacement Is Valid Then Do A Screen Recording Or Give Any Proof Based On Your Problem\n".
         "3. If Admin Saw That Your Reason Is Valid Then Only You Get Refund And Replacement";
}

/* -------------------- QR -------------------- */
function getActiveQrFileId() {
  try {
    $r = dbOne("select file_id from qrs where active=true order by updated_at desc limit 1");
    return $r['file_id'] ?? null;
  } catch (Exception $e) {
    botlog("getActiveQrFileId error: ".$e->getMessage());
    return null;
  }
}

/* -------------------- Orders -------------------- */
function createOrder($tg_id, $username, $type) {
  try {
    $r = dbOne("insert into orders(tg_id, username, type, status) values(:tg,:u,:t,'pending_qr') returning id",
      [':tg'=>$tg_id, ':u'=>$username, ':t'=>$type]
    );
    return (int)($r['id'] ?? 0);
  } catch (Exception $e) { botlog("createOrder error: ".$e->getMessage()); return 0; }
}
function getOrder($id) {
  try { return dbOne("select * from orders where id=:id", [':id'=>$id]); }
  catch (Exception $e) { botlog("getOrder error: ".$e->getMessage()); return null; }
}
function expireIfNeeded($order) {
  if (!$order) return [false, null];
  $status = $order['status'];
  if (!in_array($status, ['pending_qr','awaiting_utr','awaiting_screenshot'], true)) return [false, $order];

  $created = strtotime($order['created_at']);
  if ((time() - $created) > ORDER_TTL_SECONDS) {
    try { dbExec("update orders set status='expired', updated_at=now() where id=:id", [':id'=>$order['id']]); } catch (Exception $e) {}
    if (!empty($order['qr_chat_id']) && !empty($order['qr_message_id'])) deleteMessage($order['qr_chat_id'], $order['qr_message_id']);
    return [true, array_merge($order, ['status'=>'expired'])];
  }
  return [false, $order];
}

function adminNotify($text, $photo_file_id=null, $reply_markup=null) {
  global $ADMIN_IDS;
  foreach ($ADMIN_IDS as $aid) {
    if ($photo_file_id) sendPhoto($aid, $photo_file_id, $text, $reply_markup);
    else sendMessage($aid, $text, $reply_markup);
  }
}

/* -------------------- User: History -------------------- */
function showHistory($chat_id, $tg_id) {
  try {
    $rows = dbAll("select * from orders where tg_id=:tg and status='approved' order by created_at desc limit 50", [':tg'=>$tg_id]);
  } catch (Exception $e) { $rows = []; }

  if (!$rows) { sendMessage($chat_id, "📦 <b>My Orders</b>\n\nNo vouchers purchased yet.", mainReplyKb($tg_id)); return; }

  $out = ["📦 <b>My Orders</b>\n"];
  foreach ($rows as $o) {
    $out[] = "• <b>".(PRODUCT_TYPES[$o['type']] ?? $o['type'])."</b>\n".
             "  Code: <code>{$o['delivered_code']}</code>\n".
             "  Date: ".date('d M Y, h:i A', strtotime($o['created_at']));
  }
  sendMessage($chat_id, implode("\n\n", $out), mainReplyKb($tg_id));
}

/* -------------------- Admin texts -------------------- */
function adminStatsText() {
  try {
    $users  = (int)(dbOne("select count(*) c from users")['c'] ?? 0);
    $online = (int)(dbOne("select count(*) c from users where last_seen > now() - interval '5 minutes'")['c'] ?? 0);
    $coupons = (int)(dbOne("select count(*) c from coupons where is_used=false")['c'] ?? 0);
    $used   = (int)(dbOne("select count(*) c from coupons where is_used=true")['c'] ?? 0);
  } catch (Exception $e) { $users=$online=$coupons=$used=0; }

  return "📊 <b>Stats</b>\n\n".
         "👥 Users: <b>{$users}</b>\n".
         "🟢 Online: <b>{$online}</b>\n".
         "🎟️ Coupons: <b>{$coupons}</b>\n".
         "🎟️ Used: <b>{$used}</b>";
}
function adminStockText() {
  $lines = [];
  foreach (PRODUCT_TYPES as $k=>$label) {
    $lines[] = "• <b>{$label}</b> : <code>".stockCount($k)."</code> | Price: <b>".priceLabel($k)."</b>";
  }
  return "📦 <b>Stock</b>\n\n".implode("\n", $lines);
}
function adminPurchasesText() {
  try { $rows = dbAll("select * from orders where status='approved' order by created_at desc limit 20"); }
  catch (Exception $e) { $rows = []; }
  if (!$rows) return "🧾 <b>Purchases</b>\n\nNo purchases yet.";

  $lines = [];
  foreach ($rows as $o) {
    $p = PRODUCT_TYPES[$o['type']] ?? $o['type'];
    $lines[] = "• <b>{$p}</b> | <code>{$o['tg_id']}</code> | ".date('d M, h:i A', strtotime($o['created_at']));
  }
  return "🧾 <b>Last Purchases</b>\n\n".implode("\n", $lines);
}

/* -------------------- Shop Flow (500/1000 only) -------------------- */
function startBuy($chat_id, $tg_id, $username, $type, $cb_id=null) {
  if (!isset(PRODUCT_TYPES[$type])) { if ($cb_id) answerCallback($cb_id, "Invalid type", true); return; }

  // block 2k/4k purchase completely (your new requirement)
  if ($type === '2000_2000' || $type === '4000_4000') {
    global $SUPPORT_BOT_USERNAME;
    $label = PRODUCT_TYPES[$type];
    if ($cb_id) answerCallback($cb_id);
    sendMessage(
      $chat_id,
      "✅ <b>{$label}</b>\n\nTo buy this coupon message: @{$SUPPORT_BOT_USERNAME}",
      mainReplyKb($tg_id)
    );
    return;
  }

  if (stockCount($type) <= 0) {
    if ($cb_id) answerCallback($cb_id, "Out of stock ❌", true);
    sendMessage($chat_id, "❌ <b>Out of stock</b>\n\nPlease try later.", mainReplyKb($tg_id));
    return;
  }

  $qr = getActiveQrFileId();
  if (!$qr) {
    if ($cb_id) answerCallback($cb_id, "QR not set", true);
    sendMessage($chat_id, "❌ <b>Payment QR is not set by admin.</b>", mainReplyKb($tg_id));
    return;
  }

  $order_id = createOrder($tg_id, $username, $type);
  if ($order_id <= 0) {
    if ($cb_id) answerCallback($cb_id, "Order failed", true);
    sendMessage($chat_id, "❌ Order create failed. Try again.", mainReplyKb($tg_id));
    return;
  }

  setUserStep($tg_id, 'paying', $order_id);

  $caption =
"🛒 <b>Order Created</b>\n".
"Product: <b>".PRODUCT_TYPES[$type]."</b>\n".
"Price: <b>".priceLabel($type)."</b>\n\n".
"✅ Scan QR and pay.\n\n".
"After payment, click: <b>I have done the payment</b>";

  $kb = ['inline_keyboard'=>[
    [ ['text'=>'✅ I have done the payment','callback_data'=>"paid:{$order_id}"] ],
    [ ['text'=>'❌ Cancel','callback_data'=>"cancel:{$order_id}"] ],
  ]];

  $res = sendPhoto($chat_id, $qr, $caption, $kb);
  if (!empty($res['ok']) && !empty($res['result']['message_id'])) {
    try {
      dbExec("update orders set qr_chat_id=:c, qr_message_id=:m, updated_at=now() where id=:id",
        [':c'=>$chat_id, ':m'=>$res['result']['message_id'], ':id'=>$order_id]
      );
    } catch (Exception $e) {}
  }
}

function cancelOrder($chat_id, $tg_id, $order_id) {
  $o = getOrder($order_id);
  if (!$o || (int)$o['tg_id'] !== (int)$tg_id) { sendMessage($chat_id, "❌ Order not found.", mainReplyKb($tg_id)); return; }

  if (!empty($o['qr_chat_id']) && !empty($o['qr_message_id'])) deleteMessage($o['qr_chat_id'], $o['qr_message_id']);
  try { dbExec("update orders set status='cancelled', updated_at=now() where id=:id", [':id'=>$order_id]); } catch (Exception $e) {}
  setUserStep($tg_id, '', null);
  sendMessage($chat_id, "✅ Order cancelled.", mainReplyKb($tg_id));
}

function handlePaidClicked($chat_id, $tg_id, $order_id, $cb_id=null) {
  $o = getOrder($order_id);
  if (!$o || (int)$o['tg_id'] !== (int)$tg_id) { if ($cb_id) answerCallback($cb_id, "Order not found", true); return; }

  [$expired] = expireIfNeeded($o);
  if ($expired) {
    if ($cb_id) answerCallback($cb_id, "Order expired", true);
    setUserStep($tg_id, '', null);
    sendMessage($chat_id, "⏰ Order expired. Please buy again.", mainReplyKb($tg_id));
    return;
  }

  if ($o['status'] !== 'pending_qr') { if ($cb_id) answerCallback($cb_id, "Not in stage", true); return; }

  // delete QR immediately
  if (!empty($o['qr_chat_id']) && !empty($o['qr_message_id'])) deleteMessage($o['qr_chat_id'], $o['qr_message_id']);

  try { dbExec("update orders set status='awaiting_utr', updated_at=now() where id=:id", [':id'=>$order_id]); } catch (Exception $e) {}
  setUserStep($tg_id, 'awaiting_utr', $order_id);

  if ($cb_id) answerCallback($cb_id, "Send UTR ✅", false);
  sendMessage($chat_id, "✅ <b>Payment marked</b>\n\nNow send your <b>UTR / Transaction ID</b> (text only).", mainReplyKb($tg_id));
}

function handleUtrMessage($chat_id, $tg_id, $text) {
  $u = getUser($tg_id);
  if (!$u || $u['step'] !== 'awaiting_utr' || empty($u['current_order_id'])) return;

  $order_id = (int)$u['current_order_id'];
  $o = getOrder($order_id);
  [$expired] = expireIfNeeded($o);
  if ($expired) {
    setUserStep($tg_id, '', null);
    sendMessage($chat_id, "⏰ Order expired. Please buy again.", mainReplyKb($tg_id));
    return;
  }

  $utr = trim($text);
  if (strlen($utr) < 6) { sendMessage($chat_id, "❌ UTR too short. Send correct UTR.", mainReplyKb($tg_id)); return; }

  try {
    dbExec("update orders set utr=:utr, status='awaiting_screenshot', paid_at=now(), updated_at=now() where id=:id",
      [':utr'=>$utr, ':id'=>$order_id]
    );
  } catch (Exception $e) {}

  setUserStep($tg_id, 'awaiting_screenshot', $order_id);
  sendMessage($chat_id, "✅ UTR saved.\n\nNow upload <b>screenshot of payment</b> (as photo).", mainReplyKb($tg_id));
}

function handleScreenshot($chat_id, $tg_id, $photo_file_id) {
  $u = getUser($tg_id);
  if (!$u || $u['step'] !== 'awaiting_screenshot' || empty($u['current_order_id'])) return;

  $order_id = (int)$u['current_order_id'];
  $o = getOrder($order_id);
  [$expired] = expireIfNeeded($o);
  if ($expired) {
    setUserStep($tg_id, '', null);
    sendMessage($chat_id, "⏰ Order expired. Please buy again.", mainReplyKb($tg_id));
    return;
  }

  try { dbExec("update orders set screenshot_file_id=:f, status='submitted', updated_at=now() where id=:id", [':f'=>$photo_file_id, ':id'=>$order_id]); }
  catch (Exception $e) {}

  setUserStep($tg_id, '', null);

  $uname = $o['username'] ?: 'N/A';
  $product = PRODUCT_TYPES[$o['type']] ?? $o['type'];
  $price = priceLabel($o['type']);

  $adminText =
"🧾 <b>Payment Submitted</b>\n\n".
"User: @{$uname} (ID: <code>{$o['tg_id']}</code>)\n".
"Product: <b>{$product}</b>\n".
"Price: <b>{$price}</b>\n".
"UTR: <code>{$o['utr']}</code>\n".
"Time: <b>".nowText()."</b>\n\n".
"Approve to deliver coupon.";

  $kb = ['inline_keyboard'=>[
    [ ['text'=>'✅ Approve','callback_data'=>"admin_approve:{$order_id}"],
      ['text'=>'❌ Decline','callback_data'=>"admin_decline:{$order_id}"] ],
  ]];

  adminNotify($adminText, $photo_file_id, $kb);
  sendMessage($chat_id, "✅ Submitted.\n\nWait for admin approval.", mainReplyKb($tg_id));
}

/* -------------------- Admin Approve/Decline -------------------- */
function handleAdminApprove($admin_id, $order_id, $cb_id=null) {
  if (!isAdmin($admin_id)) { if ($cb_id) answerCallback($cb_id, "Not admin", true); return; }
  $o = getOrder($order_id);
  if (!$o || $o['status'] !== 'submitted') { if ($cb_id) answerCallback($cb_id, "Invalid order", true); return; }

  try {
    db()->beginTransaction();

    $coupon = dbOne("select * from coupons where type=:t and is_used=false order by id asc limit 1 for update", [':t'=>$o['type']]);
    if (!$coupon) {
      db()->rollBack();
      if ($cb_id) answerCallback($cb_id, "No stock", true);
      sendMessage($admin_id, "❌ No stock for this type.", adminReplyKb());
      return;
    }

    dbExec("update coupons set is_used=true, used_at=now(), used_by=:tg, order_id=:oid where id=:cid",
      [':tg'=>$o['tg_id'], ':oid'=>$order_id, ':cid'=>$coupon['id']]
    );
    dbExec("update orders set status='approved', admin_decision_at=now(), delivered_code=:c, updated_at=now() where id=:id",
      [':c'=>$coupon['code'], ':id'=>$order_id]
    );

    db()->commit();

    $product = PRODUCT_TYPES[$o['type']] ?? $o['type'];
    sendMessage($o['tg_id'], "🎉 <b>Congratulations!</b>\n\nYour Coupon:\n<code>{$coupon['code']}</code>\n\n<b>{$product}</b>", mainReplyKb($o['tg_id']));
    if ($cb_id) answerCallback($cb_id, "Approved ✅", false);
    sendMessage($admin_id, "✅ Delivered to user: <code>{$o['tg_id']}</code>", adminReplyKb());
  } catch (Exception $e) {
    try { db()->rollBack(); } catch (Exception $x) {}
    if ($cb_id) answerCallback($cb_id, "Error", true);
    sendMessage($admin_id, "❌ Error: ".$e->getMessage(), adminReplyKb());
  }
}
function handleAdminDecline($admin_id, $order_id, $cb_id=null) {
  if (!isAdmin($admin_id)) { if ($cb_id) answerCallback($cb_id, "Not admin", true); return; }
  $o = getOrder($order_id);
  if (!$o) { if ($cb_id) answerCallback($cb_id, "Order not found", true); return; }

  try { dbExec("update orders set status='declined', admin_decision_at=now(), updated_at=now() where id=:id", [':id'=>$order_id]); }
  catch (Exception $e) {}

  if (!empty($o['qr_chat_id']) && !empty($o['qr_message_id'])) deleteMessage($o['qr_chat_id'], $o['qr_message_id']);

  sendMessage($o['tg_id'], "❌ <b>Payment declined.</b>\n\nContact support if needed.", mainReplyKb($o['tg_id']));
  if ($cb_id) answerCallback($cb_id, "Declined ❌", false);
  sendMessage($admin_id, "✅ Declined order #{$order_id}", adminReplyKb());
}

/* -------------------- Webhook Handler -------------------- */
try {
  $raw = file_get_contents('php://input');
  $update = json_decode($raw, true);
  if (!$update) { echo "OK"; exit; }

  /* ==================== MESSAGE ==================== */
  if (isset($update['message'])) {
    $m = $update['message'];
    $chat_id = $m['chat']['id'];
    $from = $m['from'] ?? [];
    $tg_id = $from['id'] ?? 0;
    $username = $from['username'] ?? '';
    $first_name = $from['first_name'] ?? '';
    $text = $m['text'] ?? '';

    upsertUser($tg_id, $username, $first_name);
    $u = getUser($tg_id);

    // lazy expire active order
    if ($u && !empty($u['current_order_id'])) {
      $o = getOrder((int)$u['current_order_id']);
      [$expired] = expireIfNeeded($o);
      if ($expired) {
        setUserStep($tg_id, '', null);
        sendMessage($chat_id, "⏰ Order expired. Place a new order.", mainReplyKb($tg_id));
        echo "OK"; exit;
      }
    }

    if ($text === '/start') {
      sendMessage($chat_id, homeText(), mainReplyKb($tg_id));
      echo "OK"; exit;
    }

    // Admin: waiting QR photo
    if ($u && $u['step'] === 'admin_wait_qr' && isAdmin($tg_id)) {
      if (!empty($m['photo'])) {
        $best = end($m['photo']);
        $file_id = $best['file_id'] ?? null;
        if ($file_id) {
          try {
            dbExec("update qrs set active=false where active=true");
            dbExec("insert into qrs(file_id, active) values(:f,true)", [':f'=>$file_id]);
          } catch (Exception $e) {}
          setUserStep($tg_id, '', null);
          sendMessage($chat_id, "✅ QR updated.", adminReplyKb());
        }
      } else {
        sendMessage($chat_id, "❌ Send QR as photo.", adminReplyKb());
      }
      echo "OK"; exit;
    }

    // Admin: set price input
    if ($u && isAdmin($tg_id) && str_starts_with($u['step'], 'admin_wait_price_')) {
      $type = substr($u['step'], strlen('admin_wait_price_'));
      if (!isset(PRODUCT_TYPES[$type])) {
        setUserStep($tg_id, '', null);
        sendMessage($chat_id, "❌ Invalid price mode.", adminReplyKb());
        echo "OK"; exit;
      }
      $p = (int)preg_replace('/\D+/', '', $text);
      if ($p <= 0) { sendMessage($chat_id, "❌ Send valid price. Example: <code>199</code>", adminReplyKb()); echo "OK"; exit; }
      $ok = setPrice($type, $p);
      setUserStep($tg_id, '', null);
      sendMessage($chat_id, $ok ? "✅ Price updated: <b>".PRODUCT_TYPES[$type]."</b> = <b>₹{$p}</b>" : "❌ Price update failed. Create prices table.", adminReplyKb());
      echo "OK"; exit;
    }

    // Admin: add coupons input
    if ($u && isAdmin($tg_id) && str_starts_with($u['step'], 'admin_wait_add_')) {
      $type = substr($u['step'], strlen('admin_wait_add_'));
      if (!isset(PRODUCT_TYPES[$type])) { setUserStep($tg_id, '', null); sendMessage($chat_id, "❌ Invalid add mode.", adminReplyKb()); echo "OK"; exit; }

      $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $text))));
      $ok = 0; $bad = 0;
      foreach ($lines as $code) {
        if (strlen($code) < 3) { $bad++; continue; }
        try { dbExec("insert into coupons(type, code, is_used) values(:t,:c,false)", [':t'=>$type, ':c'=>$code]); $ok++; }
        catch (Exception $e) { $bad++; }
      }
      setUserStep($tg_id, '', null);
      sendMessage($chat_id, "✅ Added to <b>".PRODUCT_TYPES[$type]."</b>\n\n✅ Added: <b>{$ok}</b>\n❌ Failed: <b>{$bad}</b>", adminReplyKb());
      echo "OK"; exit;
    }

    // Admin: remove coupons input
    if ($u && isAdmin($tg_id) && str_starts_with($u['step'], 'admin_wait_remove_')) {
      $type = substr($u['step'], strlen('admin_wait_remove_'));
      if (!isset(PRODUCT_TYPES[$type])) { setUserStep($tg_id, '', null); sendMessage($chat_id, "❌ Invalid remove mode.", adminReplyKb()); echo "OK"; exit; }

      $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $text))));
      $ok = 0; $bad = 0;
      foreach ($lines as $code) {
        if (strlen($code) < 3) { $bad++; continue; }
        try {
          $r = dbExec("delete from coupons where code=:c and type=:t and is_used=false", [':c'=>$code, ':t'=>$type]);
          if ($r->rowCount() > 0) $ok++; else $bad++;
        } catch (Exception $e) { $bad++; }
      }
      setUserStep($tg_id, '', null);
      sendMessage($chat_id, "✅ Removed from <b>".PRODUCT_TYPES[$type]."</b>\n\n✅ Removed: <b>{$ok}</b>\n❌ Not found/used: <b>{$bad}</b>", adminReplyKb());
      echo "OK"; exit;
    }

    // Payment steps
    if ($u && $u['step'] === 'awaiting_utr') { handleUtrMessage($chat_id, $tg_id, $text); echo "OK"; exit; }
    if ($u && $u['step'] === 'awaiting_screenshot') {
      if (!empty($m['photo'])) {
        $best = end($m['photo']);
        $file_id = $best['file_id'] ?? null;
        if ($file_id) handleScreenshot($chat_id, $tg_id, $file_id);
      } else {
        sendMessage($chat_id, "❌ Upload screenshot as <b>photo</b>.", mainReplyKb($tg_id));
      }
      echo "OK"; exit;
    }

    /* Main menu */
    if ($text === '🛍️ Buy Vouchers') { sendMessage($chat_id, "🛒 <b>Shop</b>\n\nSelect a coupon:", shopKb()); echo "OK"; exit; }
    if ($text === '📦 My Orders') { showHistory($chat_id, $tg_id); echo "OK"; exit; }
    if ($text === '📜 Disclaimer') { sendMessage($chat_id, disclaimerText(), mainReplyKb($tg_id)); echo "OK"; exit; }
    if ($text === '🆘 Support') { global $SUPPORT_BOT_USERNAME; sendMessage($chat_id, "🆘 Support: https://t.me/{$SUPPORT_BOT_USERNAME}", mainReplyKb($tg_id)); echo "OK"; exit; }
    if ($text === '🔁 Recover Vouchers') { sendMessage($chat_id, "🔁 If you already received a coupon, open <b>My Orders</b>.\nIf issue, contact <b>Support</b>.", mainReplyKb($tg_id)); echo "OK"; exit; }

    if ($text === '🛠 Admin Panel') {
      if (!isAdmin($tg_id)) { sendMessage($chat_id, "❌ Not authorized.", mainReplyKb($tg_id)); echo "OK"; exit; }
      sendMessage($chat_id, "🛠 <b>Admin Panel</b>\nChoose an option:", adminReplyKb());
      echo "OK"; exit;
    }

    /* Admin panel buttons */
    if (isAdmin($tg_id) && $text === '⬅️ Back to Menu') { sendMessage($chat_id, homeText(), mainReplyKb($tg_id)); echo "OK"; exit; }
    if (isAdmin($tg_id) && $text === '📷 Send QR') { setUserStep($tg_id, 'admin_wait_qr', null); sendMessage($chat_id, "📷 Send new <b>QR image</b> now (photo).", adminReplyKb()); echo "OK"; exit; }
    if (isAdmin($tg_id) && $text === '📊 Stats') { sendMessage($chat_id, adminStatsText(), adminReplyKb()); echo "OK"; exit; }
    if (isAdmin($tg_id) && $text === '💰 Prices') { sendMessage($chat_id, "💰 <b>Change Prices</b>\nSelect coupon type:", adminPricesPickKb()); echo "OK"; exit; }
    if (isAdmin($tg_id) && $text === '📦 Stock') { sendMessage($chat_id, adminStockText(), adminReplyKb()); echo "OK"; exit; }
    if (isAdmin($tg_id) && $text === '🧾 Purchases') { sendMessage($chat_id, adminPurchasesText(), adminReplyKb()); echo "OK"; exit; }
    if (isAdmin($tg_id) && $text === '➕ Add Coupons') { sendMessage($chat_id, "➕ <b>Add Coupons</b>\nSelect type:", adminPickTypeKb('admin_add_pick')); echo "OK"; exit; }
    if (isAdmin($tg_id) && $text === '➖ Remove Coupons') { sendMessage($chat_id, "➖ <b>Remove Coupons</b>\nSelect type:", adminPickTypeKb('admin_remove_pick')); echo "OK"; exit; }
    if (isAdmin($tg_id) && $text === '🎁 Get a code') { sendMessage($chat_id, "🎁 <b>Get a free code</b>\nSelect type:", adminPickTypeKb('admin_free_pick')); echo "OK"; exit; }

    sendMessage($chat_id, "Choose an option from the menu 👇", mainReplyKb($tg_id));
    echo "OK"; exit;
  }

  /* ==================== CALLBACKS ==================== */
  if (isset($update['callback_query'])) {
    $cq = $update['callback_query'];
    $cb_id = $cq['id'];
    $data = $cq['data'] ?? '';
    $from = $cq['from'] ?? [];
    $tg_id = $from['id'] ?? 0;
    $username = $from['username'] ?? '';
    $chat_id = $cq['message']['chat']['id'] ?? 0;

    upsertUser($tg_id, $username, $from['first_name'] ?? '');

    if ($data === 'inline_back_home') { answerCallback($cb_id); sendMessage($chat_id, homeText(), mainReplyKb($tg_id)); echo "OK"; exit; }

    if (str_starts_with($data, 'buy:')) {
      $type = substr($data, 4);

      // 2K & 4K: ONLY show message (no payment)
      if ($type === '2000_2000' || $type === '4000_4000') {
        global $SUPPORT_BOT_USERNAME;
        answerCallback($cb_id);
        $label = PRODUCT_TYPES[$type] ?? $type;
        sendMessage($chat_id, "✅ <b>{$label}</b>\n\nTo buy this coupon message: @{$SUPPORT_BOT_USERNAME}", mainReplyKb($tg_id));
        echo "OK"; exit;
      }

      answerCallback($cb_id);
      startBuy($chat_id, $tg_id, $username, $type, $cb_id);
      echo "OK"; exit;
    }

    if (str_starts_with($data, 'paid:')) { $oid=(int)substr($data,5); handlePaidClicked($chat_id,$tg_id,$oid,$cb_id); echo "OK"; exit; }
    if (str_starts_with($data, 'cancel:')) { $oid=(int)substr($data,7); answerCallback($cb_id); cancelOrder($chat_id,$tg_id,$oid); echo "OK"; exit; }
    if (str_starts_with($data, 'admin_approve:')) { $oid=(int)substr($data,strlen('admin_approve:')); handleAdminApprove($tg_id,$oid,$cb_id); echo "OK"; exit; }
    if (str_starts_with($data, 'admin_decline:')) { $oid=(int)substr($data,strlen('admin_decline:')); handleAdminDecline($tg_id,$oid,$cb_id); echo "OK"; exit; }

    if (str_starts_with($data, 'admin_price_pick:')) {
      if (!isAdmin($tg_id)) { answerCallback($cb_id, "Not admin", true); echo "OK"; exit; }
      $type = substr($data, strlen('admin_price_pick:'));
      if (!isset(PRODUCT_TYPES[$type])) { answerCallback($cb_id, "Invalid type", true); echo "OK"; exit; }
      answerCallback($cb_id);
      setUserStep($tg_id, "admin_wait_price_{$type}", null);
      sendMessage($chat_id, "💰 Set price for <b>".PRODUCT_TYPES[$type]."</b>\nCurrent: <b>".priceLabel($type)."</b>\n\nSend new price (number). Example: <code>199</code>", adminReplyKb());
      echo "OK"; exit;
    }

    if (str_starts_with($data, 'admin_add_pick:')) {
      if (!isAdmin($tg_id)) { answerCallback($cb_id, "Not admin", true); echo "OK"; exit; }
      $type = substr($data, strlen('admin_add_pick:'));
      if (!isset(PRODUCT_TYPES[$type])) { answerCallback($cb_id, "Invalid type", true); echo "OK"; exit; }
      answerCallback($cb_id);
      setUserStep($tg_id, "admin_wait_add_{$type}", null);
      sendMessage($chat_id, "➕ Adding: <b>".PRODUCT_TYPES[$type]."</b>\n\nSend coupon codes (one per line).", adminReplyKb());
      echo "OK"; exit;
    }

    if (str_starts_with($data, 'admin_remove_pick:')) {
      if (!isAdmin($tg_id)) { answerCallback($cb_id, "Not admin", true); echo "OK"; exit; }
      $type = substr($data, strlen('admin_remove_pick:'));
      if (!isset(PRODUCT_TYPES[$type])) { answerCallback($cb_id, "Invalid type", true); echo "OK"; exit; }
      answerCallback($cb_id);
      setUserStep($tg_id, "admin_wait_remove_{$type}", null);
      sendMessage($chat_id, "➖ Removing from: <b>".PRODUCT_TYPES[$type]."</b>\n\nSend codes to remove (unused only), one per line.", adminReplyKb());
      echo "OK"; exit;
    }

    if (str_starts_with($data, 'admin_free_pick:')) {
      if (!isAdmin($tg_id)) { answerCallback($cb_id, "Not admin", true); echo "OK"; exit; }
      $type = substr($data, strlen('admin_free_pick:'));
      if (!isset(PRODUCT_TYPES[$type])) { answerCallback($cb_id, "Invalid type", true); echo "OK"; exit; }
      answerCallback($cb_id);

      try {
        db()->beginTransaction();
        $coupon = dbOne("select * from coupons where type=:t and is_used=false order by id asc limit 1 for update", [':t'=>$type]);
        if (!$coupon) { db()->rollBack(); answerCallback($cb_id, "No stock", true); echo "OK"; exit; }
        dbExec("update coupons set is_used=true, used_at=now(), used_by=:tg where id=:id", [':tg'=>$tg_id, ':id'=>$coupon['id']]);
        db()->commit();

        sendMessage($chat_id, "🎁 Free Code:\n<code>{$coupon['code']}</code>\nType: <b>".PRODUCT_TYPES[$type]."</b>", adminReplyKb());
      } catch (Exception $e) {
        try { db()->rollBack(); } catch (Exception $x) {}
        sendMessage($chat_id, "❌ Error: ".$e->getMessage(), adminReplyKb());
      }
      echo "OK"; exit;
    }

    answerCallback($cb_id);
    echo "OK"; exit;
  }

  echo "OK";
} catch (Exception $e) {
  botlog("FATAL: ".$e->getMessage());
  echo "OK";
}
