<?php

$botinfo = select("botsaz", "*", "bot_token", $ApiToken, "select");
$userbot = select("user", "*", "id", $botinfo['id_user'], "select");
$hide_panel = json_decode($botinfo['hide_panel'], true);
$text_bot_var =  json_decode(file_get_contents('text.json'), true);
// keyboard bot 
$keyboarddate = array(
    'text_sell' => $text_bot_var['btn_keyboard']['buy'],
    'text_usertest' => $text_bot_var['btn_keyboard']['test'],
    'text_Purchased_services' => $text_bot_var['btn_keyboard']['my_service'],
    'accountwallet' => $text_bot_var['btn_keyboard']['wallet'],
    'text_support' => $text_bot_var['btn_keyboard']['support'],
    'text_Admin' => "👨‍💼 پنل مدیریت",
);
$list_admin = select("botsaz", "*", "bot_token", $ApiToken, "select");
$admin_idsmain = select("admin", "id_admin", null, null, "FETCH_COLUMN");
$admin_ids_decoded = json_decode($list_admin['admin_ids'] ?? '[]', true);
if (!is_array($admin_ids_decoded)) {
    $admin_ids_decoded = [];
}

if (!is_array($admin_idsmain)) {
    $admin_idsmain = [];
}

if (!in_array($from_id, $admin_ids_decoded) && !in_array($from_id, $admin_idsmain)) {
    unset($keyboarddate['text_Admin']);
}
$keyboard = ['keyboard' => [], 'resize_keyboard' => true];
$tempArray = [];

foreach ($keyboarddate as $keyboardtext) {
    $tempArray[] = ['text' => $keyboardtext];
    if (count($tempArray) == 2) {
        $keyboard['keyboard'][] = $tempArray;
        $tempArray = [];
    }
}
if (count($tempArray) > 0) {
    $keyboard['keyboard'][] = $tempArray;
}
$keyboard  = json_encode($keyboard);

$backuser = json_encode([
    'keyboard' => [
        [['text' => "🏠 بازگشت به منوی اصلی"]]
    ],
    'resize_keyboard' => true,
    'input_field_placeholder' => "برای بازگشت روی دکمه زیر کلیک کنید"
]);

// keyboard list panel for test 

$stmt = $pdo->prepare("SELECT * FROM marzban_panel WHERE TestAccount = 'ONTestAccount' AND (agent = '{$userbot['agent']}' OR agent = 'all')");
$stmt->execute();
$list_marzban_panel_usertest = ['inline_keyboard' => []];
while ($result = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if ($result['hide_user'] != null and in_array($from_id, json_decode($result['hide_user'], true))) continue;
    if (in_array($result['name_panel'], $hide_panel)) continue;
    $list_marzban_panel_usertest['inline_keyboard'][] = [
        ['text' => $result['name_panel'], 'callback_data' => "locationtest_{$result['code_panel']}"]
    ];
}
$list_marzban_panel_usertest['inline_keyboard'][] = [
    ['text' => "🏠 بازگشت به منوی اصلی", 'callback_data' => "backuser"],
];
$list_marzban_usertest = json_encode($list_marzban_panel_usertest);


$keyboardadmin = json_encode([
    'keyboard' => [
        [
            ['text' => "📊 آمار ربات"]
        ],
        [
            ['text' => "💰 تنظیمات فروشگاه"],
            ['text' => "⚙️ وضعیت قابلیت ها"],
        ],
        [
            ['text' => "🔍 جستجو کاربر"],
            ['text' => "👨‍🔧  مدیریت ادمین ها"]
        ],
        [
            ['text' => "📝 تنظیم متون"]
        ],
        [
            ['text' => "📞 تنظیم نام کاربری پشتیبانی"],
            ['text' => "📬 گزارش ربات"],
        ],
        [
            ['text' => "📣 جوین اجباری"]
        ],
        [
            ['text' => "🏠 بازگشت به منوی اصلی"]
        ],
    ],
    'resize_keyboard' =>  true
]);

$keyboardprice = json_encode([
    'keyboard' => [
        [
            ['text' => "💰 تنظیم قیمت محصول"],
            ['text' => "✏️ تنظیم نام محصول"],
        ],
        [
            ['text' => "بازگشت به منوی ادمین"]
        ],
    ],
    'resize_keyboard' =>  true
]);

$keyboard_change_price = json_encode([
    'keyboard' => [
        [
            ['text' => "💎 متن کارت"],
            ['text' => "🛍 دکمه خرید"]
        ],
        [
            ['text' => "🔑 دکمه تست"],
            ['text' => "🛒 دکمه سرویس های من"]
        ],
        [
            ['text' => "👤 دکمه حساب کاربری"],
            ['text' => "☎️ متن دکمه پشتیبانی"]
        ],
        [
            ['text' => "💸 متن مرحله افزایش موجودی"]
        ],
        [
            ['text' => "بازگشت به منوی ادمین"]
        ]
    ],
    'resize_keyboard' =>  true
]);

$backadmin = json_encode([
    'keyboard' => [
        [
            ['text' => "بازگشت به منوی ادمین"]
        ],
    ],
    'resize_keyboard' =>  true
]);

//------------------  [ listpanelusers ]----------------//
$stmt = $pdo->prepare("SELECT * FROM marzban_panel WHERE status = 'active' AND (agent = '{$userbot['agent']}' OR agent = 'all')");
$stmt->execute();
$list_marzban_panel_users = ['inline_keyboard' => []];
while ($result = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if ($result['hide_user'] != null and in_array($from_id, json_decode($result['hide_user'], true))) continue;
    if (in_array($result['name_panel'], $hide_panel)) continue;
    $list_marzban_panel_users['inline_keyboard'][] = [
        ['text' => $result['name_panel'], 'callback_data' => "location_{$result['code_panel']}"]
    ];
}
$list_marzban_panel_users['inline_keyboard'][] = [
    ['text' => "🏠 بازگشت به منوی اصلی", 'callback_data' => "backuser"],
];
$list_marzban_panel_user = json_encode($list_marzban_panel_users);

$payment = json_encode([
    'inline_keyboard' => [
        [['text' => "💰 پرداخت و دریافت سرویس", 'callback_data' => "confirmandgetservice"]],
        [['text' => "🏠 بازگشت به منوی اصلی",  'callback_data' => "backuser"]]
    ]
]);
$KeyboardBalance = json_encode([
    'inline_keyboard' => [
        [['text' => "💸 افزایش موجودی", 'callback_data' => "AddBalance"]],
        [['text' => "🏠 بازگشت به منوی اصلی",  'callback_data' => "backuser"]]
    ]
]);

function KeyboardProduct($location, $query, $pricediscount, $datakeyboard, $statuscustom = false, $backuser = "backuser", $valuetow = null, $customvolume = "customsellvolume")
{
    global $pdo, $textbotlang;
    $product = ['inline_keyboard' => []];
    $statusshowprice = select("shopSetting", "*", "Namevalue", "statusshowprice", "select")['value'];
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $valuetow = $valuetow != null ? "-$valuetow" : "";
    while ($result = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $productlist = json_decode(file_get_contents('product.json'), true);
        $productlist_name = json_decode(file_get_contents('product_name.json'), true);
        if (isset($productlist[$result['code_product']])) $result['price_product'] = $productlist[$result['code_product']];
        $result['name_product'] = empty($productlist_name[$result['code_product']]) ? $result['name_product'] : $productlist_name[$result['code_product']];
        $hide_panel = json_decode($result['hide_panel'], true);
        if (in_array($location, $hide_panel)) continue;
        if (intval($pricediscount) != 0) {
            $resultper = ($result['price_product'] * $pricediscount) / 100;
            $result['price_product'] = $result['price_product'] - $resultper;
        }
        $namekeyboard = $result['name_product'] . " - " . number_format($result['price_product']) . "تومان";
        if ($statusshowprice == "onshowprice")$result['name_product'] = $namekeyboard;
        $product['inline_keyboard'][] = [
            ['text' =>  $result['name_product'], 'callback_data' => "{$datakeyboard}{$result['code_product']}{$valuetow}"]
        ];
    }
    if ($statuscustom) $product['inline_keyboard'][] = [['text' => $textbotlang['users']['customsellvolume']['title'], 'callback_data' => $customvolume]];
    $product['inline_keyboard'][] = [
        ['text' => $textbotlang['users']['stateus']['backinfo'], 'callback_data' => $backuser],
    ];
    return json_encode($product);
}
function KeyboardCategory($location, $agent, $backuser = "backuser")
{
    global $pdo, $textbotlang;
    $stmt = $pdo->prepare("SELECT * FROM category");
    $stmt->execute();
    $list_category = ['inline_keyboard' => [],];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $stmts = $pdo->prepare("SELECT * FROM product WHERE (Location = :location OR Location = '/all') AND category = :category AND agent = :agent");
        $stmts->bindParam(':location', $location, PDO::PARAM_STR);
        $stmts->bindParam(':category', $row['remark'], PDO::PARAM_STR);
        $stmts->bindParam(':agent', $agent);
        $stmts->execute();
        if ($stmts->rowCount() == 0) continue;
        $list_category['inline_keyboard'][] = [['text' => $row['remark'], 'callback_data' => "categorynames_" . $row['id']]];
    }
    $list_category['inline_keyboard'][] = [
        ['text' => "▶️ بازگشت به منوی قبل", "callback_data" => $backuser],
    ];
    return json_encode($list_category);
}

// Show all categories for agent (without location filter) - for new flow: category first
function KeyboardCategoryAll($agent, $backuser = "backuser")
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM category");
    $stmt->execute();
    $list_category = ['inline_keyboard' => [],];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $stmts = $pdo->prepare("SELECT * FROM product WHERE category = :category AND agent = :agent");
        $stmts->bindParam(':category', $row['remark'], PDO::PARAM_STR);
        $stmts->bindParam(':agent', $agent);
        $stmts->execute();
        if ($stmts->rowCount() == 0) continue;
        $list_category['inline_keyboard'][] = [['text' => $row['remark'], 'callback_data' => "buycategory_" . $row['id']]];
    }
    $list_category['inline_keyboard'][] = [
        ['text' => "▶️ بازگشت به منوی قبل", "callback_data" => $backuser],
    ];
    return json_encode($list_category);
}

// Show servers that have products in a specific category - for new flow
function KeyboardServersForCategory($category, $agent, $backuser = "backuser")
{
    global $pdo;
    $hide_panel = json_decode(select("botsaz", "hide_panel", "id_user", select("user", "id", "agent", $agent, "select")['id'], "select")['hide_panel'] ?? '[]', true);
    $stmt = $pdo->prepare("SELECT DISTINCT mp.* FROM marzban_panel mp
        INNER JOIN product p ON (p.Location = mp.name_panel OR p.Location = '/all')
        WHERE mp.status = 'active' AND (mp.agent = :agent OR mp.agent = 'all')
        AND p.category = :category AND p.agent = :agent2");
    $stmt->bindParam(':agent', $agent);
    $stmt->bindParam(':agent2', $agent);
    $stmt->bindParam(':category', $category);
    $stmt->execute();
    $list_servers = ['inline_keyboard' => [],];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (in_array($row['name_panel'], $hide_panel ?? [])) continue;
        $list_servers['inline_keyboard'][] = [['text' => $row['name_panel'], 'callback_data' => "buyserver_" . $row['code_panel']]];
    }
    $list_servers['inline_keyboard'][] = [
        ['text' => "▶️ بازگشت به منوی قبل", "callback_data" => $backuser],
    ];
    return json_encode($list_servers);
}
