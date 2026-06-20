<?php
date_default_timezone_set('Asia/Bangkok');

$host = 'localhost';
$dbname = 'smart_exam_db';
$username = 'root'; 
$password = '';     

// 🔒 ประกาศ API Key ไว้ที่ส่วนกลาง (นำรหัสของคุณมาใส่ได้เลย)
define('GEMINI_API_KEY', 'AIzaSyBWOE7lrYc_xB8cS0uezKO3YAvH2HGCjf4');

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("เชื่อมต่อฐานข้อมูลล้มเหลว: " . $e->getMessage());
}
?>