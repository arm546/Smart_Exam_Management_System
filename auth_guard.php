<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// 🛡️ ฟังก์ชันสำหรับตรวจสอบ Role (สิทธิ์การเข้าถึง)
function require_role($allowed_roles) {
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
        // ใช้ JavaScript เด้งกลับพร้อมแจ้งเตือนหากสิทธิ์ไม่ถูกต้อง
        echo "<script>alert('ไม่มีสิทธิ์เข้าถึงหน้านี้'); window.location.href='index.php';</script>";
        exit();
    }
}
?>