<?php
const DB_SERVER   = "sql102.infinityfree.com";
const DB_USERNAME = "if0_38646809";
const DB_PASSWORD = "12345bbcMG4Ncxan6OuP";
const DB_NAME     = "product_db";

//建立連線
function create_connection()
{
    $conn = mysqli_connect(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
    if (!$conn) {
        echo json_encode(["state" => false, "message" => "連線失敗!"]);
        exit;
    }
    return $conn;
}

//取得JSON的資料
function get_json_input()
{
    $data = file_get_contents("php://input");
    return json_decode($data, true);
}


//回復JOSN訊息
//state: 狀態(成功或失敗) message: 訊息內容 data: 回傳資料(可有可無)
function respond($state, $message, $data = null)
{
    echo json_encode(["state" => $state, "message" => $message, "data" => $data]);
}

// Uid01驗證
// {"uid01" : "owner01"}
// {"state" : true, "message" : "驗證成功", "data" : "使用者資訊"}
// {"state" : false, "message" : "驗證失敗與相關錯誤訊息"}
// {"state" : false, "message" : "欄位錯誤"}
// {"state" : false, "message" : "欄位不得為空白"}
function check_uid()
{
    $input = get_json_input();
    if (isset($input["uid01"])) {
        $p_uid = trim($input["uid01"]); // trim會把空白拿掉
        if ($p_uid) {
            $conn = create_connection();

            $stmt = $conn->prepare("SELECT Username, Email, Uid01, Created_at FROM users WHERE Uid01 = ?"); // 從member資料表找Username相對應的Password
            $stmt->bind_param("s", $p_uid); // 一定要傳遞變數
            $stmt->execute(); // 更新成功
            $result = $stmt->get_result();

            if ($result->num_rows === 1) {
                // 驗證成功
                $userdata = $result->fetch_assoc();
                respond(true, "驗證成功", $userdata);
            } else {
                respond(false, "驗證失敗");
            }
            $stmt->close();
            $conn->close();
        } else {
            respond(false, "欄位不得為空");
        }
    } else {
        respond(false, "欄位錯誤");
    }
}

// 購物車
function checkout()
{
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    $input = get_json_input();

    if (!isset($input["cart"]) || !is_array($input["cart"]) || count($input["cart"]) === 0) {
        respond(false, "購物車資料錯誤");
        exit;
    }

    // 檢查是否有 `uid01`
    if (!isset($input["Uid01"])) {
        respond(false, "會員 ID 缺失");
        exit;
    }

    $p_uid = trim($input["Uid01"]);  // 移除前後空白
    $conn = create_connection(); // 連線資料庫

    // **查詢 user_id**
    $stmt = $conn->prepare("SELECT ID FROM users WHERE Uid01 = ?");
    $stmt->bind_param("s", $p_uid);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows !== 1) {
        respond(false, "查無此會員");
        exit;
    }

    $user = $result->fetch_assoc();
    $userId = $user["ID"];  // 取得 `user_id`

    // 計算總價
    $cart = $input["cart"];
    $totalPrice = 0;

    $conn->begin_transaction();

    try {
        // **檢查庫存**
        foreach ($cart as $item) {
            $stmt = $conn->prepare("SELECT Count FROM menus WHERE Menu_id = ?");
            $stmt->bind_param("i", $item['id']);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows === 0) {
                throw new Exception("商品 " . $item['id'] . " 不存在");
            }

            $row = $result->fetch_assoc();
            if ($row['Count'] < $item['quantity']) {
                throw new Exception("商品 " . $item['id'] . " 庫存不足（剩餘 " . $row['Count'] . " 件）");
            }
        }

        // **扣除庫存**
        foreach ($cart as $item) {
            $stmt = $conn->prepare("UPDATE menus SET Count = Count - ? WHERE Menu_id = ?");
            $stmt->bind_param("ii", $item['quantity'], $item['id']);
            $stmt->execute();
        }

        // **新增訂單**
        foreach ($cart as $item) {
            $totalPrice += $item['price'] * $item['quantity'];
        }

        $stmt = $conn->prepare("INSERT INTO orders (user_id, total_price) VALUES (?, ?)");
        $stmt->bind_param("id", $userId, $totalPrice);
        if (!$stmt->execute()) {
            throw new Exception("訂單建立失敗");
        }

        $orderId = $stmt->insert_id;

        // **新增每個商品到 `order_items`**
        foreach ($cart as $item) {
            $stmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("iiid", $orderId, $item['id'], $item['quantity'], $item['price']);
            $stmt->execute();
        }

        // **提交交易**
        $conn->commit();
        respond(true, "訂單成功！訂單編號：" . $orderId);
    } catch (Exception $e) {
        // **發生錯誤時回滾**
        $conn->rollback();
        respond(false, "結帳失敗：" . $e->getMessage() . " (於 " . $e->getFile() . " 第 " . $e->getLine() . " 行)");
    }

    $stmt->close();
    $conn->close();
}

function checkout01()
{
    $input = get_json_input();

    if (!isset($input["cart"]) || !is_array($input["cart"]) || count($input["cart"]) === 0) {
        respond(false, "購物車資料錯誤");
        exit;
    }

    // 檢查是否有 `uid01`
    if (!isset($input["Uid01"])) {
        respond(false, "會員 ID 缺失");
        exit;
    }

    $p_uid = trim($input["Uid01"]);  // 移除前後空白
    $conn = create_connection(); // 連線資料庫

    // **查詢 user_id**
    $stmt = $conn->prepare("SELECT ID FROM users WHERE Uid01 = ?");
    $stmt->bind_param("s", $p_uid);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows !== 1) {
        respond(false, "查無此會員");
        exit;
    }

    $user = $result->fetch_assoc();
    $userId = $user["ID"];  // 取得 `user_id`

    // 計算總價
    $cart = $input["cart"];
    $totalPrice = 0;

    // $conn->begin_transaction();

    foreach ($cart as $item) {
        $totalPrice += $item['price'] * $item['quantity'];
    }

    // **新增訂單**
    $stmt = $conn->prepare("INSERT INTO orders (user_id, total_price) VALUES (?, ?)");
    $stmt->bind_param("id", $userId, $totalPrice);

    if ($stmt->execute()) {
        $orderId = $stmt->insert_id;

        // **新增每個商品到 `order_items`**
        foreach ($cart as $item) {
            $stmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("iiid", $orderId, $item['id'], $item['quantity'], $item['price']);
            $stmt->execute();
        }

        respond(true, "訂單成功！訂單編號：" . $orderId);
    } else {
        respond(false, "訂單建立失敗");
    }

    $stmt->close();
    $conn->close();
}

// 重新加入購物車
function reorder()
{
    if (!isset($_SESSION['user_id'])) {
        respond(false, "請先登入");
        exit;
    }

    if (!isset($_POST['order_id'])) {
        respond(false, "缺少訂單 ID");
        exit;
    }

    $user_id = $_SESSION['user_id'];
    $order_id = $_POST['order_id'];
    $conn = create_connection();

    // 取得該訂單的所有商品
    $stmt = $conn->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $stmt2 = $conn->prepare("INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = quantity + ?");
        $stmt2->bind_param("iiii", $user_id, $row['product_id'], $row['quantity'], $row['quantity']);
        $stmt2->execute();
    }

    respond(true, "商品已重新加入購物車");
}

function cancelOrder()
{
     // 連接到資料庫
     $conn = create_connection();

     // **檢查資料庫連線**
     if (!$conn) {
         echo json_encode(['success' => false, 'message' => '資料庫連線失敗']);
         exit;
     }
 
     // **更新訂單狀態為已取消 (假設 2 表示已取消)**
     $stmt = $conn->prepare("UPDATE orders SET status = 2 WHERE id = ?");
     if (!$stmt) {
         echo json_encode(['success' => false, 'message' => 'SQL 錯誤: ' . $conn->error]);
         exit;
     }
 
     $stmt->bind_param("i", $orderId);
     if ($stmt->execute()) {
         echo json_encode(['success' => true, 'message' => '訂單已取消']);
     } else {
         echo json_encode(['success' => false, 'message' => '取消訂單失敗', 'error' => $conn->error]);
     }
 
     $stmt->close();
     $conn->close();
}

// 取得訂單詳情
function getOrderDetails()
{
    if (isset($_GET['orderId']) && !empty($_GET['orderId'])) {
        $orderId = $_GET['orderId'];

        $conn = create_connection();
        if (!$conn) {
            echo json_encode(['success' => false, 'message' => '資料庫連接失敗']);
            exit;
        }
        // 查詢訂單詳細資料
        $stmt = $conn->prepare("SELECT oi.product_id, p.Name, oi.price, oi.quantity FROM order_items oi JOIN menus p ON oi.product_id = p.Menu_id WHERE oi.order_id = ?");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $items = [];
            while ($row = $result->fetch_assoc()) {
                $items[] = $row;
            }
            echo json_encode(['success' => true, 'items' => $items]);
        } else {
            echo json_encode(['success' => false, 'message' => '無訂單資料']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => '訂單 ID 缺失']);
    }
}

// 取得所有訂單資訊
function getAllOrders()
{
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    $conn = create_connection();
    if (!$conn) {
        echo json_encode(['success' => false, 'message' => '資料庫連接失敗']);
        exit;
    }

    // 查詢所有訂單
    $stmt = $conn->prepare("SELECT id, user_id, total_price, created_at, status FROM orders ORDER BY created_at DESC");
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $orders = [];
        while ($row = $result->fetch_assoc()) {
            $orders[] = $row;
        }
        echo json_encode(['success' => true, 'orders' => $orders]);
    } else {
        echo json_encode(['success' => false, 'message' => '無訂單資料']);
    }
}

// 刪除訂單
function deleteOrder()
{
    if (!isset($_GET['orderId'])) {
        echo json_encode(['success' => false, 'message' => '缺少訂單 ID']);
        exit;
    }

    $orderId = intval($_GET['orderId']); // 轉換成整數，確保安全

    $conn = create_connection();
    if (!$conn) {
        echo json_encode(['success' => false, 'message' => '資料庫連接失敗']);
        exit;
    }

    // 先刪除與該訂單相關的 order_items 資料
    $stmt = $conn->prepare("DELETE FROM order_items WHERE order_id = ?");
    $stmt->bind_param("i", $orderId);
    if (!$stmt->execute()) {
        echo json_encode(['success' => false, 'message' => '刪除訂單項目失敗', 'error' => $stmt->error]);
        exit;
    }

    // 刪除訂單
    $stmt = $conn->prepare("DELETE FROM orders WHERE id = ?");
    $stmt->bind_param("i", $orderId);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => '訂單刪除成功']);
    } else {
        echo json_encode([
            'success' => false,
            'message' => '刪除失敗',
            'error' => $stmt->error  // 顯示 SQL 錯誤訊息
        ]);
    }
}

// 查詢歷史訂單
function getOrders()
{
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    header('Content-Type: application/json; charset=utf-8');

    if (!isset($_GET['Uid01'])) {
        echo json_encode(['success' => false, 'message' => '會員 ID 缺失']);
        exit;
    }

    $Uid01 = $_GET['Uid01'];
    $conn = create_connection();

    // **檢查資料庫連線**
    if (!$conn) {
        echo json_encode(['success' => false, 'message' => '資料庫連線失敗']);
        exit;
    }

    // **查詢 user_id**
    $stmt = $conn->prepare("SELECT ID FROM users WHERE Uid01 = ?");
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'SQL 錯誤: ' . $conn->error]);
        exit;
    }

    $stmt->bind_param("s", $Uid01);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows !== 1) {
        echo json_encode(['success' => false, 'message' => '查無此會員']);
        exit;
    }

    $user = $result->fetch_assoc();
    $userId = $user['ID'];

    // **查詢該會員的訂單資訊**
    $stmt = $conn->prepare("SELECT id, total_price, status FROM orders WHERE user_id = ?");
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'SQL 錯誤: ' . $conn->error]);
        exit;
    }

    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    $orders = [];
    while ($row = $result->fetch_assoc()) {
        $orderId = $row['id'];
        $order = [
            'orderId' => $orderId,
            'totalPrice' => $row['total_price'],
            'status' => $row['status'],
            'items' => []
        ];

        // **查詢該訂單的商品詳細資訊**
        $stmtItems = $conn->prepare("
            SELECT m.Name, oi.quantity, oi.price 
            FROM order_items oi
            JOIN menus m ON oi.product_id = m.Menu_id
            WHERE oi.order_id = ?
        ");
        if (!$stmtItems) {
            echo json_encode(['success' => false, 'message' => 'SQL 錯誤: ' . $conn->error]);
            exit;
        }

        $stmtItems->bind_param("i", $orderId);
        $stmtItems->execute();
        $resultItems = $stmtItems->get_result();

        while ($item = $resultItems->fetch_assoc()) {
            $order['items'][] = $item;
        }

        $orders[] = $order;
        $stmtItems->close();
    }

    echo json_encode(['success' => true, 'orders' => $orders]);

    $stmt->close();
    $conn->close();
}

// 取得銷售資料(給chart)
function getSalesData()
{
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    $conn = create_connection();

    // **檢查資料庫連線**
    if (!$conn) {
        echo json_encode(['success' => false, 'message' => '資料庫連線失敗']);
        exit;
    }

    // **從請求中獲取 product_id 參數（如果有）**
    $product_id = isset($_GET['product_id']) ? $_GET['product_id'] : null;

    // **如果有提供 product_id，則根據 product_id 查詢；如果沒有，則查詢所有商品**
    $query = "SELECT m.Name, SUM(oi.quantity) AS total_sales
              FROM order_items oi
              JOIN menus m ON oi.product_id = m.Menu_id";

    if ($product_id) {
        $query .= " WHERE oi.product_id = ?";
    }

    $query .= " GROUP BY oi.product_id HAVING total_sales > 0";

    $stmt = $conn->prepare($query);

    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'SQL 錯誤: ' . $conn->error]);
        exit;
    }

    if ($product_id) {
        $stmt->bind_param('i', $product_id);  // 綁定 product_id 參數
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $salesData = [];
    while ($row = $result->fetch_assoc()) {
        $salesData[] = [
            'productName' => $row['Name'],
            'totalSales' => $row['total_sales']
        ];
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'salesData' => $salesData]);

    $stmt->close();
    $conn->close();
}

// 取得資料庫資料
function getProductData()
{
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    $conn = create_connection();

    // 檢查資料庫連線
    if (!$conn) {
        echo json_encode(['success' => false, 'message' => '資料庫連線失敗']);
        exit;
    }

    // 查詢所有商品及庫存數量
    $query = "SELECT Name, Count FROM menus";  // 假設 'menus' 表中有 'Name' 和 'Count' 欄位

    // 執行查詢
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'SQL 錯誤: ' . $conn->error]);
        exit;
    }

    $stmt->execute();
    $result = $stmt->get_result();

    // 儲存庫存數據
    $inventoryData = [];
    while ($row = $result->fetch_assoc()) {
        $inventoryData[] = [
            'productName' => $row['Name'],
            'stock' => (int) $row['Count']  // 強制轉換為整數
        ];
    }

    // 回傳庫存數據
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'inventoryData' => $inventoryData]);

    // 關閉資料庫連線
    $stmt->close();
    $conn->close();
}

// getMenuList
function getMenuList()
{
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    $conn = create_connection();

    // **檢查資料庫連線**
    if (!$conn) {
        echo json_encode(['success' => false, 'message' => '資料庫連線失敗']);
        exit;
    }

    // **查詢菜單列表**
    $stmt = $conn->prepare("SELECT Menu_id, Name FROM menus");
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'SQL 錯誤: ' . $conn->error]);
        exit;
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $menuList = [];
    while ($row = $result->fetch_assoc()) {
        $menuList[] = [
            'productId' => $row['Menu_id'],
            'productName' => $row['Name']
        ];
    }

    echo json_encode(['success' => true, 'menuList' => $menuList]);

    $stmt->close();
    $conn->close();
}

function add()
{
    if (!isset($input['cart']) || !is_array($input['cart']) || count($input['cart']) === 0) {
        echo json_encode(['success' => false, 'message' => '購物車資料錯誤']);
        exit;
    }
    $conn = create_connection();

    // 假設購物車中的每個項目都有 id, quantity 和 price
    foreach ($input['cart'] as $item) {
        $productId = $item['id'];
        $quantity = $item['quantity'];
        $price = $item['price'];

        // 假設你已經有處理購物車存儲的邏輯
        // 比如將商品加入資料庫中的購物車表（cart_items）
        $userId = $_SESSION['user_id'];  // 假設從 session 取得當前用戶ID

        $stmt = $conn->prepare("INSERT INTO cart_items (user_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iiid", $userId, $productId, $quantity, $price);
        $stmt->execute();
    }

    echo json_encode(['success' => true, 'message' => '商品已加入購物車']);
}

function getSalesData_YMD($period)
{
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    global $conn;

    $conn = create_connection();

    if ($conn === null) {
        echo json_encode(['success' => false, 'message' => '資料庫連接未初始化']);
        exit;
    }

    switch ($period) {
        case 'daily':
            $groupBy = "DATE(o.created_at)";
            break;
        case 'weekly':
            $groupBy = "YEARWEEK(o.created_at)";
            break;
        case 'monthly':
            $groupBy = "DATE_FORMAT(o.created_at, '%Y-%m')";
            break;
        default:
            echo json_encode(['success' => false, 'message' => '無效的時間範圍']);
            exit;
    }

    $query = "SELECT $groupBy AS period, SUM(o.total_price) AS total_sales, COUNT(o.id) AS order_count 
              FROM orders o 
              GROUP BY $groupBy 
              ORDER BY period ASC";

    $result = $conn->query($query);

    if (!$result) {
        echo json_encode([
            'success' => false,
            'message' => '查詢失敗: ' . $conn->error . ' 查詢語句: ' . $query
        ]);
        exit;
    }

    $salesData = [];
    while ($row = $result->fetch_assoc()) {
        $salesData[] = [
            'period' => $row['period'],
            'totalSales' => $row['total_sales'],
            'orderCount' => $row['order_count']
        ];
    }

    echo json_encode(['success' => true, 'salesData' => $salesData]);

    $conn->close();
}

// 更新商品狀態
function updateProductStatus()
{
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    // 創建資料庫連線
    $conn = create_connection();

    // 檢查資料庫連線
    if (!$conn) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => '資料庫連線失敗: ' . mysqli_connect_error()]);
        exit;
    }

    // 確保請求是 POST
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => '請求方式錯誤']);
        exit;
    }

    // 取得前端傳來的數據
    $productId = $_POST["id"] ?? null;
    $newStatus = $_POST["status"] ?? null;

    if (!$productId || !$newStatus) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => '缺少必要參數']);
        exit;
    }

    // 更新商品狀態
    $query = "UPDATE menus SET status = ? WHERE id = ?";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'SQL 錯誤: ' . $conn->error]);
        exit;
    }

    $stmt->bind_param("si", $newStatus, $productId);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => '狀態已更新']);
    } else {
        echo json_encode(['success' => false, 'message' => '更新失敗']);
    }

    // 關閉資料庫連線
    $stmt->close();
    $conn->close();
}

// 更新訂單狀態
function updateOrderStatus()
{
    // 創建資料庫連線
    $conn = create_connection();

    // 檢查資料庫連線
    if (!$conn) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => '資料庫連線失敗: ' . mysqli_connect_error()]);
        exit;
    }

    $orderId = $_POST['orderId'];
    $status = $_POST['status'];

    $sql = "UPDATE orders SET status = '$status' WHERE id = '$orderId'";
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false]);
    }
}

//在postman網址後面要加"?action="+當前case, ex:http://192.168.10.72/member_control_api_v1.php?action=register
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_GET['action'] ?? '';
    switch ($action) {
        case 'checkuid':
            check_uid();
            break;
        case 'checkout':
            checkout();
            break;
        case 'reorder':
            reorder();
            break;
        case 'updateStatus':
            updateProductStatus();
            break;
        case 'OrderStatus':
            updateOrderStatus();
            break;
        case 'cancelOrder':
            cancelOrder();
            break;
        default:
            respond(false, "無效的操作");;
    }
} else if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    switch ($action) {
        case 'getOrders':
            getOrders();
            break;
        case 'getOrderDetails':
            getOrderDetails();
            break;
        case 'add':
            add();
            break;
        case 'getSalesData':
            getSalesData();
            break;
        case 'getMenuList':
            getMenuList();
            break;
        case 'getDailySales':
            getSalesData_YMD('daily');
            break;
        case 'getWeeklySales':
            getSalesData_YMD('weekly');
            break;
        case 'getMonthlySales':
            getSalesData_YMD('monthly');
            break;
        case 'getAllOrders':
            getAllOrders();
            break;
        case 'getProduct':
            getProductData();
            break;
        default:
            respond(false, "無效的操作");;
    }
} else if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $action = $_GET['action'] ?? '';
    switch ($action) {
        case 'deleteOrder':
            deleteOrder();
            break;
        default:
            respond(false, "無效的操作");;
    }
} else {
    respond(false, "無效的請求方法");
}
