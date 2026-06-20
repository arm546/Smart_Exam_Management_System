<?php
// ดึงชื่อไฟล์ปัจจุบันเพื่อนำไปเช็ก Active Menu อัตโนมัติแบบไร้รอยต่อ
$current_page = basename($_SERVER['PHP_SELF']);
$user_role = $_SESSION['role'] ?? 'teacher'; 

/* =====================================
   Helper – Render menu item
===================================== */
function menu_item($href, $current_page, $icon, $label) {
    // ระบบเปรียบเทียบชื่อไฟล์ปัจจุบันกับ href โดยตรงเพื่อติดสถานะ active
    $isActive = ($current_page === $href) ? "active" : "";
    return <<<HTML
<li class="nav-item $isActive">
    <a class="nav-link" href="$href">
        <i class="fas fa-fw $icon"></i>
        <span>$label</span>
    </a>
</li>
HTML;
}
?>

<style>
/* ==========================================
   UI / UX Custom Modern Styling สำหรับ Sidebar
============================================= */
#accordionSidebar { 
    padding-top: 10px; 
}

#accordionSidebar .nav-link {
    color: rgba(255, 255, 255, 0.85) !important;
    padding: 12px 18px !important;
    border-radius: 8px;
    margin: 4px 12px;
    display: flex;
    align-items: center;
    gap: 12px;
    transition: all 0.25s ease;
    font-size: 0.95rem;
}

#accordionSidebar .nav-link i {
    width: 20px;
    font-size: 1.06rem;
    transition: all 0.25s ease;
    text-align: center;
}

/* เอฟเฟกต์ Hover เมื่อเมาส์ชี้เมนู */
#accordionSidebar .nav-link:hover {
    background-color: rgba(255, 255, 255, 0.15) !important;
    color: #fff !important;
    transform: translateX(4px);
}

#accordionSidebar .nav-link:hover i {
    transform: scale(1.1);
}

/* เอฟเฟกต์ Active เมื่อกำลังใช้งานหน้านั้นๆ (ไล่เฉดเงา + แถบขาวซ้ายมือ) */
#accordionSidebar .nav-item.active .nav-link {
    background: linear-gradient(90deg, rgba(255, 255, 255, 0.25), rgba(255, 255, 255, 0.05)) !important;
    border-left: 4px solid #ffffff !important;
    color: #ffffff !important;
    font-weight: 600;
}

/* ตกแต่งส่วนหัวข้อประเภทเมนู (Sidebar Headings) */
.sidebar-heading {
    font-size: 0.72rem !important;
    text-transform: uppercase;
    color: rgba(255, 255, 255, 0.55) !important;
    margin: 18px 0 6px !important;
    padding-left: 1.5rem !important;
    letter-spacing: .8px;
    font-weight: 700;
}

/* จัดการขนาดและสัดส่วนของบล็อกโลโก้ */
.sidebar-brand {
    height: auto !important;
    padding: 1.5rem 1rem !important;
}

.sidebar-brand img {
    width: 40px;
    height: auto;
    object-fit: contain;
    filter: drop-shadow(0 2px 5px rgba(0, 0, 0, 0.2));
    transition: transform 0.3s ease;
}

.sidebar-brand:hover img {
    transform: scale(1.05);
}
</style>

<ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar">

<a class="sidebar-brand d-flex align-items-center justify-content-center" href="index.php">
    <div class="sidebar-brand-icon">
        <img src="img/logo.png">
    </div>
    <div class="sidebar-brand-text mx-3">Smart Exam</div>
</a>

<hr class="sidebar-divider my-0">

<?php if ($user_role === 'teacher'): ?>
    <div class="sidebar-heading">ระบบจัดการสอบ (ผู้สอน)</div>
    <?= menu_item("index.php",         $current_page, "fa-tachometer-alt", "แดชบอร์ดสรุปผล") ?>
    <?= menu_item("create_exam.php",   $current_page, "fa-plus-square", "สร้างชุดข้อสอบ") ?>
    <?= menu_item("ai_generate.php",   $current_page, "fa-robot", "AI ช่วยสร้างข้อสอบ") ?>
    <?= menu_item("question_bank.php", $current_page, "fa-database", "คลังข้อสอบ") ?>
    <?= menu_item("reports.php",       $current_page, "fa-chart-bar", "รายงานผลการสอบ") ?>
<?php endif; ?>

<?php if ($user_role === 'admin'): ?>
    <div class="sidebar-heading">ระบบดูแลบ้าน (Admin Only)</div>
    <?= menu_item("manage_users.php", $current_page, "fa-users-cog", "จัดการผู้ใช้งาน") ?>
    <?= menu_item("manage_courses.php", $current_page, "fa-book", "จัดการรายวิชา") ?>
<?php endif; ?>

<?php if ($user_role === 'student'): ?>
    <div class="sidebar-heading">เมนูนักศึกษา</div>
    <?= menu_item("index.php",      $current_page, "fa-home", "หน้าหลัก") ?>
    <?= menu_item("join_exam.php",  $current_page, "fa-key", "เข้าสอบ (กรอก PIN)") ?>
    <?= menu_item("my_results.php", $current_page, "fa-file-alt", "ประวัติและผลคะแนน") ?>
<?php endif; ?>

<hr class="sidebar-divider d-none d-md-block" style="margin-top: 15px;">

<div class="text-center d-none d-md-inline">
    <button class="rounded-circle border-0" id="sidebarToggle"></button>
</div>

</ul>