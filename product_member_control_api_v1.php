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

//會員註冊
// {"username" : "owner01", "password" : "123456", "email" : "owner01@text.com"}
// {"state" : true, "message" : "註冊成功"}
// {"state" : false, "message" : "新增失敗與相關錯誤訊息"}
// {"state" : false, "message" : "欄位錯誤"}
// {"state" : false, "message" : "欄位不得為空"}

function register_user()
{
    $input = get_json_input();
    if (isset($input["username"], $input["password"], $input["email"])) {
        $p_username = trim($input["username"]); // trim會把空白拿掉
        $p_password = password_hash(trim($input["password"]), PASSWORD_DEFAULT);
        $p_email = trim($input["email"]);
        if ($p_username && $p_password && $p_email) {
            $conn = create_connection();

            $stmt = $conn->prepare("INSERT INTO users(Username, Password, Email) VALUES(?, ?, ?)");
            $stmt->bind_param("sss", $p_username, $p_password, $p_email);

            if ($stmt->execute()) {
                respond(true, "註冊成功");
            } else {
                respond(false, "註冊失敗");
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

// 會員登入
// {"username" : "owner01", "password" : "123456"}
// {"state" : true, "message" : "登入成功", "data" : "使用者資訊"}
// {"state" : false, "message" : "登入失敗與相關錯誤訊息"}
// {"state" : false, "message" : "欄位錯誤"}
// {"state" : false, "message" : "欄位不得為空白"}
function login_user()
{
    $input = get_json_input();
    if (isset($input["username"], $input["password"])) {
        $p_username = trim($input["username"]); // trim會把空白拿掉
        $p_password = trim($input["password"]);
        if ($p_username && $p_password) {
            $conn = create_connection();

            $stmt = $conn->prepare("SELECT * FROM users WHERE Username = ?");
            $stmt->bind_param("s", $p_username); //一定要傳遞變數
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 1) {
                //抓取密碼執行password_verify比對
                $row = $result->fetch_assoc();
                if (password_verify($p_password, $row["Password"])) {
                    //比對成功
                    //產生UID並更新至資料庫
                    $uid01 =  substr(hash('md5', time()), 3, 4) . substr(bin2hex(random_bytes(16)), 9, 6);
                    $update_stmt = $conn->prepare("UPDATE users SET Uid01 = ? WHERE Username = ?");
                    $update_stmt->bind_param('ss', $uid01, $p_username);
                    if ($update_stmt->execute()) {
                        // unset($row["Password"]);
                        // 取得登入的使用者資訊
                        $user_stmt = $conn->prepare("SELECT ID, Username, Email, Uid01, Created_at, Level FROM users WHERE Username = ?");
                        $user_stmt->bind_param('s', $p_username);
                        $user_stmt->execute();
                        $user_data = $user_stmt->get_result()->fetch_assoc();
                        respond(true, "登入成功", $user_data);
                    } else {
                        respond(false, "登入失敗,UID更新失敗");
                    }
                } else {
                    // 比對失敗
                    respond(false, "登入失敗,密碼錯誤");
                }
            } else {
                respond(false, "登入失敗, 該帳號不存在!");
            }
            $stmt->close();
            $conn->close();
        } else {
            respond(false, "欄位不得為空!");
        }
    } else {
        respond(false, "欄位錯誤!");
    }
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

// 驗證帳號是否存在(給註冊使用)
// {"username" : "xxxxxxxx"}
// {"state" : true, "message" : "帳號不存在,可使用"}
// {"state" : false, "message" : "帳號已存在,不可使用"}
// {"state" : false, "message" : "欄位錯誤"}
// {"state" : false, "message" : "欄位不得為空白"}
function check_uni_username()
{
    $input = get_json_input();
    if (isset($input["username"])) {
        $p_username = trim($input["username"]); // trim會把空白拿掉
        if ($p_username) {
            $conn = create_connection();

            $stmt = $conn->prepare("SELECT Username FROM users WHERE Username = ?"); // 從member資料表找Username相對應的Password
            $stmt->bind_param("s", $p_username); // 一定要傳遞變數
            $stmt->execute(); // 更新成功
            $result = $stmt->get_result();

            if ($result->num_rows === 1) {
                // 帳號已存在
                respond(false, "帳號已存在,不可使用");
            } else {
                respond(true, "帳號不存在,可以使用");
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

// 取得所有會員資料
// input:none
// {"state" : true, "message" : "取得所有會員資料成功", "data" : "所有使用者資訊"}
// {"state" : false, "message" : "取得所有會員資料失敗"}
function get_all_user_data()
{
    $conn = create_connection();

    $stmt = $conn->prepare("SELECT * FROM users ORDER BY ID");
    $stmt->execute(); // 更新成功
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $mydata = array();
        while ($row = $result->fetch_assoc()) {
            unset($row["Password"]);
            unset($row["Uid01"]);
            $mydata[] = $row;
        }
        respond(true, "取得所有會員資料成功!", $mydata);
    } else {
        // 查無資料
        respond(false, "查無資料");
    }
    $stmt->close();
    $conn->close();
}
// 取得菜單資料
// input:none
// {"state" : true, "message" : "取得所有菜單資料成功", "data" : "所有菜單資訊"}
// {"state" : false, "message" : "取得所有菜單資料失敗"}
function get_all_menu_data()
{
    $conn = create_connection();

    $stmt = $conn->prepare("SELECT * FROM menus ORDER BY Menu_id DESC");
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

// 會員更新
// {"id" : "xxxxxxxx", "email" : "xxxxxxxx"}
// {"state" : true, "message" : "會員更新成功"}
// {"state" : false, "message" : "會員更新與相關錯誤訊息"}
// {"state" : false, "message" : "欄位錯誤"}
// {"state" : false, "message" : "欄位不得為空白"}
function update_user()
{
    $input = get_json_input();
    if (isset($input["id"], $input["email"])) {
        $p_id = trim($input["id"]);
        $p_email = trim($input["email"]);
        if ($p_id && $p_email) {
            $conn = create_connection();

            $stmt = $conn->prepare("UPDATE users SET Email = ? WHERE ID = ?");
            $stmt->bind_param("si", $p_email, $p_id); //一定要傳遞變數

            if ($stmt->execute()) {
                if ($stmt->affected_rows === 1) {
                    respond(true, "會員更新成功");
                } else {
                    respond(false, "會員更新失敗, 並無更新行為!");
                }
            } else {
                respond(false, "會員更新失敗");
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

// 菜單更新
// {"name" : "xx", "price" : "xx", "count" : "xx", "status" : "xx"}
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
                    respond(true, "會員更新成功");
                } else {
                    respond(false, "會員更新失敗, 並無更新行為!");
                }
            } else {
                respond(false, "會員更新失敗");
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

// 會員刪除
//{"id" : "xxxxxxxx"}
// {"state" : true, "message" : "會員刪除成功"}
// {"state" : false, "message" : "會員刪除與相關錯誤訊息"}
// {"state" : false, "message" : "欄位錯誤"}
// {"state" : false, "message" : "欄位不得為空白"}

function delete_user()
{
    $input = get_json_input();
    if (isset($input["id"])) {
        $p_id = trim($input["id"]);
        if ($p_id) {
            $conn = create_connection();

            $stmt = $conn->prepare("DELETE FROM users WHERE ID = ?");
            $stmt->bind_param("i", $p_id); //一定要傳遞變數

            if ($stmt->execute()) {
                if ($stmt->affected_rows === 1) {
                    respond(true, "會員刪除成功");
                } else {
                    respond(false, "會員刪除失敗, 並無刪除行為!");
                }
            } else {
                respond(false, "會員刪除失敗");
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
        case 'register':
            register_user();
            break;
        case 'login':
            login_user();
            break;
        case 'checkuid':
            check_uid();
            break;
        case 'checkuni':
            check_uni_username();
            break;
        case 'update':
            update_user();
            break;
        case 'updatemenu':
            update_menu();
            break; 
        default:
            respond(false, "無效的操作");;
    }
} else if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    switch ($action) {
        case 'getalldata':
            get_all_user_data();
            break;
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
            delete_user();
            break;
        default:
            respond(false, "無效的操作");;
    }
} else {
    respond(false, "無效的請求方法");
}
