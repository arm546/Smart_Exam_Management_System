<?php
$page_title = 'เข้าห้องสอบ';
require_once 'auth_guard.php';
require_role(['student']);
require_once 'config/db.php';

$error_msg = "";

// เมื่อนักศึกษากดปุ่ม "เข้าห้องสอบ"
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $exam_pin = trim($_POST['exam_pin']);

    try {
        // ค้นหาชุดข้อสอบจากรหัส PIN และเช็กว่าสถานะการสอบยังเปิดอยู่ (is_active = 1)
        $sql = "SELECT * FROM exams WHERE exam_pin = :exam_pin AND is_active = 1";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':exam_pin', $exam_pin);
        $stmt->execute();
        $exam = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($exam) {
            // ถ้ารหัสถูกต้อง พาไปหน้าทำข้อสอบ พร้อมส่ง exam_id ไปด้วย
            header("Location: take_exam.php?exam_id=" . $exam['id']);
            exit();
        } else {
            // ถ้ารหัสผิด หรือห้องสอบปิดแล้ว
            $error_msg = "<div class='alert alert-danger text-center'><i class='fas fa-times-circle'></i> รหัสเข้าสอบไม่ถูกต้อง หรือห้องสอบนี้ถูกปิดไปแล้ว!</div>";
        }
    } catch (PDOException $e) {
        $error_msg = "<div class='alert alert-danger'>เกิดข้อผิดพลาด: " . $e->getMessage() . "</div>";
    }
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div id="content-wrapper" class="d-flex flex-column">
    <div id="content">
        <?php include 'includes/topbar.php'; ?>

        <div class="container-fluid">

            <div class="d-sm-flex align-items-center justify-content-between mb-4">
                <h1 class="h3 mb-0 text-gray-800"><i class="fas fa-sign-in-alt text-primary"></i> เข้าสู่ห้องสอบ</h1>
            </div>

            <div class="row justify-content-center mt-5">
                <div class="col-xl-5 col-lg-6 col-md-8">
                    <div class="card shadow-lg mb-4 border-bottom-primary">
                        <div class="card-header py-3 bg-gradient-primary">
                            <h6 class="m-0 font-weight-bold text-white text-center">กรอกรหัสเข้าสอบ (Exam PIN)</h6>
                        </div>
                        <div class="card-body p-5">
                            
                            <?= $error_msg ?>

                            <div class="text-center mb-4">
                                <i class="fas fa-lock fa-3x text-gray-300 mb-3"></i>
                                <p class="text-muted">โปรดกรอกรหัสเข้าสอบ 6 หลักที่คุณได้รับจากอาจารย์ผู้สอน เพื่อเริ่มต้นการทำข้อสอบ</p>
                            </div>

                            <form action="join_exam.php" method="POST" class="user">
                                <div class="form-group">
                                    <input type="text" 
                                           class="form-control form-control-user text-center font-weight-bold text-primary" 
                                           id="exam_pin" 
                                           name="exam_pin" 
                                           placeholder="--- รหัส 6 หลัก ---" 
                                           maxlength="6" 
                                           style="font-size: 1.5rem; letter-spacing: 5px;" 
                                           required autofocus>
                                </div>
                                <button type="submit" class="btn btn-primary btn-user btn-block font-weight-bold">
                                    <i class="fas fa-door-open"></i> เข้าห้องสอบ
                                </button>
                            </form>
                            
                        </div>
                    </div>
                </div>
            </div>

        </div>
        </div>
    <?php include 'includes/footer.php'; ?>