<?php
const DB_SERVER   = "localhost";
const DB_USERNAME = "owner01";
const DB_PASSWORD = "123456";
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

            $stmt = $conn->prepare("SELECT ID, Username, Email, Uid01, Created_at, Level FROM users WHERE Uid01 = ?"); // 從member資料表找Username相對應的Password
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

// 菜單新增
// {"name" : "xx咖啡", "price" : "xx", "count" : "xx", "photo" : "xx", "status" : "avaliable"}
// {"state" : true, "message" : "新增成功"}
// {"state" : false, "message" : "新增失敗與相關錯誤訊息"}
// {"state" : false, "message" : "欄位錯誤"}
// {"state" : false, "message" : "欄位不得為空"}
function add_menu()
{
    $input = get_json_input();
    if (isset($input["name"], $input["price"], $input["count"], $input["status"])) {
        $p_name = trim($input["name"]); // trim會把空白拿掉
        $p_price = trim($input["price"]);
        $p_count = trim($input["count"]);
        $p_status = trim($input["status"]);
        if ($p_name && $p_price && $p_count && $p_status) {
            $conn = create_connection();

            $stmt = $conn->prepare("INSERT INTO menus(Name, Price, Count, Status) VALUES( ?, ?, ?, ?)");
            $stmt->bind_param("ssss", $p_name, $p_price, $p_count, $p_status);

            if ($stmt->execute()) {
                respond(true, "新增成功");
            } else {
                respond(false, "新增失敗");
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
    $input = get_json_input();
    if (!isset($input["cart"])) {
        respond(false, "購物車資料錯誤");
        exit;
    }

    $cart = $input["cart"];
    if (empty($cart)) {
        respond(false, "購物車是空的");
        exit;
    }    

    $conn = create_connection(); // 連線資料庫
    $totalPrice = 0;

    foreach ($cart as $item) {
        $totalPrice += $item['price'] * $item['quantity'];
    }

    // **新增訂單**
    $stmt = $conn->prepare("INSERT INTO orders (total_price) VALUES (?)");
    $stmt->bind_param("d", $totalPrice);

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

// 驗證產品是否存在(給註冊使用)
// {"name" : "xxxxxxxx"}
// {"state" : true, "message" : "產品不存在,可使用"}
// {"state" : false, "message" : "產品已存在,不可使用"}
// {"state" : false, "message" : "欄位錯誤"}
// {"state" : false, "message" : "欄位不得為空白"}
function check_uni_menuname()
{
    $input = get_json_input();
    if (isset($input["name"])) {
        $p_name = trim($input["name"]); // trim會把空白拿掉
        if ($p_name) {
            $conn = create_connection();

            $stmt = $conn->prepare("SELECT Name FROM menus WHERE Name = ?"); // 從member資料表找Username相對應的Password
            $stmt->bind_param("s", $p_name); // 一定要傳遞變數
            $stmt->execute(); // 更新成功
            $result = $stmt->get_result();

            if ($result->num_rows === 1) {
                // 帳號已存在
                respond(false, "產品已存在,不可新增");
            } else {
                respond(true, "產品不存在,可以新增");
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

// 取得菜單資料
// input:none
// {"state" : true, "message" : "取得所有菜單資料成功", "data" : "所有菜單資訊"}
// {"state" : false, "message" : "取得所有菜單資料失敗"}
function get_all_menu_data()
{
    $conn = create_connection();

    $stmt = $conn->prepare("SELECT * FROM menus ORDER BY Menu_id");
    $stmt->execute(); // 更新成功
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $mydata = array();
        while ($row = $result->fetch_assoc()) {
            $mydata[] = $row;
        }
        respond(true, "取得所有商品資料成功!", $mydata);
    } else {
        // 查無資料
        respond(false, "查無資料");
    }
    $stmt->close();
    $conn->close();
}

// 菜單更新
// {"id" : "xx", "price" : "xx", "count" : "xx", "status" : "xx"}
// {"state" : true, "message" : "菜單更新成功"}
// {"state" : false, "message" : "菜單更新與相關錯誤訊息"}
// {"state" : false, "message" : "欄位錯誤"}
// {"state" : false, "message" : "欄位不得為空白"}
function update_menu()
{
    $input = get_json_input();
    if (isset($input["menu_id"], $input["price"], $input["count"], $input["status"])) {
        $p_menu_id = trim($input["menu_id"]);
        $p_price = trim($input["price"]);
        $p_count = trim($input["count"]);
        $p_status = trim($input["status"]);
        if ($p_menu_id && $p_price && $p_count && $p_status) {
            $conn = create_connection();

            $stmt = $conn->prepare("UPDATE menus SET Price = ?, Count = ?, Status = ? WHERE Menu_id = ?");
            $stmt->bind_param("iisi", $p_price, $p_count, $p_status, $p_menu_id); //一定要傳遞變數

            if ($stmt->execute()) {
                if ($stmt->affected_rows === 1) {
                    respond(true, "商品更新成功");
                } else {
                    respond(false, "商品更新失敗, 並無更新行為!");
                }
            } else {
                respond(false, "商品更新失敗");
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

// 菜單刪除
//{"id" : "xxxxxxxx"}
// {"state" : true, "message" : "會員刪除成功"}
// {"state" : false, "message" : "會員刪除與相關錯誤訊息"}
// {"state" : false, "message" : "欄位錯誤"}
// {"state" : false, "message" : "欄位不得為空白"}

function delete_menu()
{
    $input = get_json_input();
    if (isset($input["id"])) {
        $p_id = trim($input["id"]);
        if ($p_id) {
            $conn = create_connection();

            $stmt = $conn->prepare("DELETE FROM menus WHERE Menu_id = ?");
            $stmt->bind_param("i", $p_id); //一定要傳遞變數

            if ($stmt->execute()) {
                if ($stmt->affected_rows === 1) {
                    respond(true, "商品刪除成功");
                } else {
                    respond(false, "商品刪除失敗, 並無刪除行為!");
                }
            } else {
                respond(false, "商品刪除失敗");
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

//在postman網址後面要加"?action="+當前case, ex:http://192.168.10.72/member_control_api_v1.php?action=register
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_GET['action'] ?? '';
    switch ($action) {
        case 'checkuid':
            check_uid();
            break;
        case 'checkuni':
            check_uni_menuname();
            break;
        case 'updatemenu':
            update_menu();
            break;
        case 'addmenu':
            add_menu();
            break;
        case 'checkout':
            checkout();
            break;
        default:
            respond(false, "無效的操作");;
    }
} else if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    switch ($action) {
        case 'menudata':
            get_all_menu_data();
            break;
        default:
            respond(false, "無效的操作");;
    }
} else if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $action = $_GET['action'] ?? '';
    switch ($action) {
        case 'delete':
            delete_menu();
            break;
        default:
            respond(false, "無效的操作");;
    }
} else {
    respond(false, "無效的請求方法");
}
