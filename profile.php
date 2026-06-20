<?php
session_start();
require_once 'config/db.php';
// require_once 'auth_guard.php'; // เปิดใช้งานไฟล์นี้เพื่อเช็ค Session ตามโครงสร้างของคุณ

// ตรวจสอบการเข้าสู่ระบบ
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$msg = '';
$msg_type = ''; // 'success' หรือ 'danger'

// จัดการเมื่อมีการ Submit Form
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // ส่วนที่ 1: อัปเดตข้อมูลทั่วไป
    if (isset($_POST['update_profile'])) {
        $fullname = trim($_POST['fullname']);
        $faculty = trim($_POST['faculty']);
        $major = trim($_POST['major']);
        $year_level = !empty($_POST['year_level']) ? $_POST['year_level'] : null;

        try {
            $sql = "UPDATE users SET fullname = :fullname, faculty = :faculty, major = :major, year_level = :year_level WHERE id = :id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':fullname' => $fullname,
                ':faculty' => $faculty,
                ':major' => $major,
                ':year_level' => $year_level,
                ':id' => $user_id
            ]);
            
            // อัปเดต Session ให้แสดงชื่อใหม่ทันทีที่ Topbar
            $_SESSION['fullname'] = $fullname;
            
            $msg = "อัปเดตข้อมูลส่วนตัวเรียบร้อยแล้ว";
            $msg_type = "success";
        } catch(PDOException $e) {
            $msg = "เกิดข้อผิดพลาด: " . $e->getMessage();
            $msg_type = "danger";
        }
    } 
    
    // ส่วนที่ 2: เปลี่ยนรหัสผ่าน
    elseif (isset($_POST['change_password'])) {
        $old_password = $_POST['old_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        if ($new_password !== $confirm_password) {
            $msg = "รหัสผ่านใหม่และการยืนยันรหัสผ่านไม่ตรงกัน!";
            $msg_type = "danger";
        } else {
            // ดึงรหัสผ่านเดิมมาตรวจก่อน
            $stmt = $conn->prepare("SELECT password FROM users WHERE id = :id");
            $stmt->execute([':id' => $user_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (password_verify($old_password, $row['password'])) {
                // เข้ารหัสรหัสผ่านใหม่
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $updateStmt = $conn->prepare("UPDATE users SET password = :password WHERE id = :id");
                $updateStmt->execute([
                    ':password' => $hashed_password, 
                    ':id' => $user_id
                ]);
                $msg = "เปลี่ยนรหัสผ่านเรียบร้อยแล้ว กรุณาใช้รหัสผ่านใหม่ในการเข้าระบบครั้งต่อไป";
                $msg_type = "success";
            } else {
                $msg = "รหัสผ่านเดิมไม่ถูกต้อง!";
                $msg_type = "danger";
            }
        }
    }
}

// ดึงข้อมูลผู้ใช้ปัจจุบันมาแสดงผลในฟอร์ม
$stmt = $conn->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute([':id' => $user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>โปรไฟล์ส่วนตัว | Smart Exam</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;700;900&display=swap" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <style>body { font-family: 'Sarabun', sans-serif; }</style>
</head>

<body id="page-top">
    <div id="wrapper">
        
        <?php include 'includes/sidebar.php'; ?>

        <div id="content-wrapper" class="d-flex flex-column">
            <div id="content">

                <?php include 'includes/topbar.php'; ?>

                <div class="container-fluid">
                    <h1 class="h3 mb-4 text-gray-800">จัดการข้อมูลส่วนตัว</h1>

                    <?php if (!empty($msg)): ?>
                        <div class="alert alert-<?php echo $msg_type; ?> alert-dismissible fade show" role="alert">
                            <?php echo $msg; ?>
                            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-lg-6">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">ข้อมูลทั่วไป</h6>
                                </div>
                                <div class="card-body">
                                    <form method="POST" action="profile.php">
                                        <div class="form-group">
                                            <label>ชื่อผู้ใช้งาน (Username) <small class="text-danger">*ไม่สามารถแก้ไขได้</small></label>
                                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['username']); ?>" readonly>
                                        </div>
                                        <?php if(!empty($user['student_code'])): ?>
                                        <div class="form-group">
                                            <label>รหัสนักศึกษา <small class="text-danger">*ไม่สามารถแก้ไขได้</small></label>
                                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['student_code']); ?>" readonly>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <div class="form-group">
                                            <label>ชื่อ-นามสกุล</label>
                                            <input type="text" class="form-control" name="fullname" value="<?php echo htmlspecialchars($user['fullname']); ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label>คณะ</label>
                                            <input type="text" class="form-control" name="faculty" value="<?php echo htmlspecialchars($user['faculty'] ?? ''); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label>สาขาวิชา</label>
                                            <input type="text" class="form-control" name="major" value="<?php echo htmlspecialchars($user['major'] ?? ''); ?>">
                                        </div>
                                        
                                        <?php if($user['role'] === 'student'): ?>
                                        <div class="form-group">
                                            <label>ชั้นปี</label>
                                            <input type="number" class="form-control" name="year_level" min="1" max="8" value="<?php echo htmlspecialchars($user['year_level'] ?? ''); ?>">
                                        </div>
                                        <?php endif; ?>

                                        <button type="submit" name="update_profile" class="btn btn-primary mt-3">
                                            <i class="fas fa-save"></i> บันทึกข้อมูล
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-6">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-danger">เปลี่ยนรหัสผ่าน</h6>
                                </div>
                                <div class="card-body">
                                    <form method="POST" action="profile.php">
                                        <div class="form-group">
                                            <label>รหัสผ่านเดิม</label>
                                            <input type="password" class="form-control" name="old_password" required>
                                        </div>
                                        <div class="form-group">
                                            <label>รหัสผ่านใหม่</label>
                                            <input type="password" class="form-control" name="new_password" required minlength="6">
                                        </div>
                                        <div class="form-group">
                                            <label>ยืนยันรหัสผ่านใหม่</label>
                                            <input type="password" class="form-control" name="confirm_password" required minlength="6">
                                        </div>
                                        <button type="submit" name="change_password" class="btn btn-danger mt-3">
                                            <i class="fas fa-key"></i> อัปเดตรหัสผ่าน
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div> </div> </div> <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <a class="scroll-to-top rounded" href="#page-top">
        <i class="fas fa-angle-up"></i>
    </a>

    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="vendor/jquery-easing/jquery.easing.min.js"></script>
    <script src="js/sb-admin-2.min.js"></script>
</body>
</html>