<?php
session_start();
require_once 'config/db.php'; // ดึงไฟล์เชื่อมต่อฐานข้อมูลมาใช้

try {
    // 1. เช็คว่ามี User ที่เป็น 'admin' อยู่ในระบบหรือยัง
    $check_admin = $conn->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin'");
    $check_admin->execute();
    $admin_exists = $check_admin->fetchColumn();

    // 2. ถ้ายังไม่มี admin เลย (เช่น พึ่งสร้างฐานข้อมูลใหม่)
    if ($admin_exists == 0) {
        $default_username = 'admin';
        // เข้ารหัสผ่านด้วย password_hash() เสมอเพื่อความปลอดภัย (รหัสเริ่มต้นคือ: admin123)
        $default_password = password_hash('admin123', PASSWORD_DEFAULT); 
        $default_fullname = 'System Administrator';
        $default_role = 'admin';
        $is_active = 1;

        // 3. ทำการเพิ่มข้อมูล Admin ค่าเริ่มต้นลงไป
        $insert_admin = $conn->prepare("INSERT INTO users (username, password, fullname, role, is_active) VALUES (:username, :password, :fullname, :role, :is_active)");
        
        $insert_admin->execute([
            ':username' => $default_username,
            ':password' => $default_password,
            ':fullname' => $default_fullname,
            ':role' => $default_role,
            ':is_active' => $is_active
        ]);
    }
} catch(PDOException $e) {
    // ปล่อยผ่าน หรือแจ้งเตือน error กรณีที่ตาราง users ยังไม่ถูกสร้าง
    // echo "Database Error: " . $e->getMessage();
}

// ถ้าล็อกอินอยู่แล้ว ให้เด้งไปหน้า Dashboard เลย
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$error_msg = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'];
    $password = $_POST['password'];

    // 1. ค้นหาผู้ใช้จาก username อย่างเดียวก่อน
    $stmt = $conn->prepare("SELECT * FROM users WHERE username = :username AND is_active = 1 LIMIT 1");
    $stmt->bindParam(':username', $username);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // 2. ใช้ password_verify ตรวจสอบรหัสผ่านที่รับมา กับรหัสที่เข้ารหัสไว้ใน DB
        if (password_verify($password, $user['password'])) {
            // ✅ รหัสถูกต้อง
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['fullname'] = $user['fullname'];
            $_SESSION['role'] = $user['role'];

            header("Location: index.php");
            exit();
        } else {
            // ❌ รหัสผ่านผิด
            $error_msg = "ชื่อผู้ใช้งาน หรือ รหัสผ่าน ไม่ถูกต้อง!";
        }
    } else {
        $error_msg = "ชื่อผู้ใช้งาน หรือ รหัสผ่าน ไม่ถูกต้อง!";
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>เข้าสู่ระบบ | Smart Exam</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700;900&display=swap" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <style>
        body { 
            font-family: 'Sarabun', sans-serif; 
            background-color: #f8f9fc; /* เปลี่ยนจากสีพื้นหลังทึบ เป็นสีเทาอ่อนให้ดูสะอาดตา */
        }
        /* กำหนดรูปภาพฝั่งซ้าย */
        .bg-login-image {
            background: url('https://images.unsplash.com/photo-1516321497487-e288fb19713f?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1000&q=80');
            background-position: center;
            background-size: cover;
        }
        /* ตกแต่งปุ่มและฟอร์มให้ดูมนขึ้น */
        .form-control-user {
            border-radius: 10rem;
            padding: 1.5rem 1rem;
        }
        .btn-user {
            border-radius: 10rem;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        .login-card-body {
            padding: 4rem !important;
        }
        .system-subtitle {
            color: #858796;
            font-size: 0.95rem;
            margin-bottom: 2rem;
        }
    </style>
</head>
<body class="bg-gradient-primary d-flex align-items-center min-vh-100">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-xl-10 col-lg-12 col-md-9">
                <div class="card o-hidden border-0 shadow-lg">
                    <div class="card-body p-0">
                        <div class="row">
                            <div class="col-lg-6 d-none d-lg-block bg-login-image"></div>
                            
                            <div class="col-lg-6">
                                <div class="login-card-body">
                                    <div class="text-center">
                                        <div class="mb-3">
                                            <img src="img/logo.png" alt="Logo" style="max-height: 80px;">
                                        </div>
                                        <h1 class="h3 text-gray-900 font-weight-bold mb-1">Smart Exam</h1>
                                        <p class="system-subtitle">AI-Generated Quiz System</p>
                                    </div>
                                    
                                    <?php if(!empty($error_msg)): ?>
                                        <div class="alert alert-danger text-center rounded-pill text-sm" role="alert">
                                            <i class="fas fa-exclamation-circle mr-1"></i> <?php echo $error_msg; ?>
                                        </div>
                                    <?php endif; ?>

                                    <form class="user mt-4" method="POST" action="login.php">
                                        <div class="form-group">
                                            <input type="text" class="form-control form-control-user bg-light" name="username" placeholder="ชื่อผู้ใช้งาน (Username)" required autofocus>
                                        </div>
                                        <div class="form-group">
                                            <input type="password" class="form-control form-control-user bg-light" name="password" placeholder="รหัสผ่าน (Password)" required>
                                        </div>
                                        <button type="submit" class="btn btn-primary btn-user btn-block mt-4 shadow-sm">
                                            <i class="fas fa-sign-in-alt mr-2"></i> เข้าสู่ระบบ
                                        </button>
                                    </form>
                                    
                                    <hr class="mt-5 mb-4">
                                    <div class="text-center text-muted small">
                                        &copy; <?php echo date('Y'); ?> Smart Exam System. All rights reserved.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="js/sb-admin-2.min.js"></script>
</body>
</html>