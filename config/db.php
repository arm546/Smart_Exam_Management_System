<?php
date_default_timezone_set('Asia/Bangkok');

$host = 'ระบุที่อยู่ MySQL Host Name ของเซิร์ฟเวอร์';
$dbname = 'ระบุชื่อฐานข้อมูลของคุณ';
$username = 'ระบุชื่อผู้ใช้งานฐานข้อมูล'; 
$password = 'ระบุรหัสผ่านเข้าใช้ฐานข้อมูล';     

// 🔒 ประกาศ API Key ไว้ที่ส่วนกลาง (นำรหัสของคุณมาใส่ได้เลย)
define('GEMINI_API_KEY', 'ระบุรหัส API Key ของคุณ');

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("เชื่อมต่อฐานข้อมูลล้มเหลว: " . $e->getMessage());
}
?>