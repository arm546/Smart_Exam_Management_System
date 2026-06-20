<?php
$page_title = 'รายงานผลการสอบ';
require_once 'auth_guard.php';
require_once 'config/db.php';

// 🛡️ เข้าถึงได้เฉพาะอาจารย์และแอดมิน
require_role(['teacher', 'admin']);

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// ฟังก์ชันช่วยแปลงวินาที เป็น นาที:วินาที สำหรับแสดงผล
function formatTime($seconds) {
    if ($seconds <= 0 || $seconds == null) return "-";
    $m = floor($seconds / 60);
    $s = $seconds % 60;
    return $m . " นาที " . $s . " วินาที";
}

// ---------------------------------------------------------
// 1. ระบบ Export ไฟล์เป็น CSV (Excel) - เพิ่มคอลัมน์เวลา
// ---------------------------------------------------------
if (isset($_GET['export_csv']) && !empty($_GET['exam_id'])) {
    $export_exam_id = $_GET['export_csv'] == 1 ? $_GET['exam_id'] : ''; // ใช้ exam_id จาก GET
    
    $ex_stmt = $conn->prepare("SELECT course_code, title FROM exams WHERE id = :id");
    $ex_stmt->execute([':id' => $_GET['exam_id']]);
    $ex_info = $ex_stmt->fetch(PDO::FETCH_ASSOC);
    $filename = "Report_" . ($ex_info ? $ex_info['course_code'] : "Exam") . "_" . date('Ymd_Hi') . ".csv";

    // ดึงข้อมูลคะแนน + เพิ่ม time_taken_seconds
    $sql_export = "SELECT u.student_code, u.fullname, u.year_level, u.faculty, u.major, sr.score, sr.time_taken_seconds, sr.submitted_at,
                   (SELECT SUM(assigned_points) FROM exam_questions WHERE exam_id = sr.exam_id) as max_score
                   FROM student_results sr
                   JOIN users u ON sr.student_id = u.id
                   WHERE sr.exam_id = :exam_id
                   ORDER BY sr.score DESC, u.student_code ASC";
    $stmt_exp = $conn->prepare($sql_export);
    $stmt_exp->execute([':exam_id' => $_GET['exam_id']]);
    $export_data = $stmt_exp->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF"); // BOM กันภาษาไทยเพี้ยน
    
    // เพิ่มหัวคอลัมน์ "ระยะเวลาที่ใช้"
    fputcsv($output, ['รหัสนักศึกษา', 'ชื่อ-นามสกุล', 'ชั้นปี', 'คณะ', 'สาขาวิชา', 'คะแนนที่ได้', 'คะแนนเต็ม', 'ระยะเวลาที่ใช้', 'เวลาที่ส่ง']);
    
    foreach ($export_data as $row) {
        fputcsv($output, [
            $row['student_code'], 
            $row['fullname'], 
            !empty($row['year_level']) ? $row['year_level'] : '-',
            $row['faculty'], 
            $row['major'], 
            $row['score'], 
            $row['max_score'], 
            formatTime($row['time_taken_seconds']), // แปลงเวลาใน CSV
            date('d/m/Y H:i', strtotime($row['submitted_at']))
        ]);
    }
    fclose($output);
    exit();
}

// ---------------------------------------------------------
// 2. ดึงรายชื่อวิชา (เหมือนเดิม)
// ---------------------------------------------------------
if ($role === 'admin') {
    $exam_stmt = $conn->query("SELECT id, course_code, title, exam_set FROM exams ORDER BY created_at DESC");
} else {
    $exam_stmt = $conn->prepare("SELECT id, course_code, title, exam_set FROM exams WHERE teacher_id = :tid ORDER BY created_at DESC");
    $exam_stmt->execute([':tid' => $user_id]);
}
$exams_list = $exam_stmt->fetchAll(PDO::FETCH_ASSOC);

// ---------------------------------------------------------
// 3. ดึงข้อมูลตาราง - เพิ่ม sr.time_taken_seconds ใน SQL
// ---------------------------------------------------------
$selected_exam_id = isset($_GET['exam_id']) ? $_GET['exam_id'] : '';
$report_data = [];

if (!empty($selected_exam_id)) {
    $sql_report = "SELECT u.student_code, u.fullname, u.faculty, u.major, sr.score, sr.time_taken_seconds, sr.submitted_at,
                (SELECT SUM(assigned_points) FROM exam_questions WHERE exam_id = sr.exam_id) as max_score
                FROM student_results sr
                JOIN users u ON sr.student_id = u.id
                WHERE sr.exam_id = :exam_id
                ORDER BY sr.score DESC, u.student_code ASC";
    $report_stmt = $conn->prepare($sql_report);
    $report_stmt->execute([':exam_id' => $selected_exam_id]);
    $report_data = $report_stmt->fetchAll(PDO::FETCH_ASSOC);
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div id="content-wrapper" class="d-flex flex-column">
    <div id="content">
        <?php include 'includes/topbar.php'; ?>

        <div class="container-fluid">
            <h1 class="h3 mb-4 text-gray-800"><i class="fas fa-chart-bar text-primary"></i> รายงานผลการสอบ</h1>

            <div class="card shadow mb-4 border-left-info">
                <div class="card-body">
                    <form action="reports.php" method="GET" class="form-inline">
                        <label class="font-weight-bold mr-3" for="exam_id">เลือกชุดข้อสอบที่ต้องการดูรายงาน:</label>
                        <select name="exam_id" class="form-control searchable-select" style="width: 100%;" data-placeholder="-- คลิกที่นี่เพื่อเลือก หรือ พิมพ์ค้นหา --" required>
                            <option value="" selected></option>
                            <?php foreach($exams_list as $ex): ?>
                                <option value="<?= $ex['id'] ?>" <?= ($selected_exam_id == $ex['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($ex['course_code']) ?>: <?= htmlspecialchars($ex['title']) ?> (Set <?= $ex['exam_set'] ?? '-' ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-info"><i class="fas fa-search"></i> ดูรายงาน</button>
                    </form>
                </div>
            </div>

            <?php if (!empty($selected_exam_id)): ?>
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between bg-gradient-light">
                    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-clipboard-list"></i> รายชื่อผู้ส่งข้อสอบและคะแนนที่ได้</h6>
                    <?php if(count($report_data) > 0): ?>
                        <a href="reports.php?export_csv=1&exam_id=<?= $selected_exam_id ?>" class="btn btn-success btn-sm shadow-sm">
                            <i class="fas fa-download fa-sm text-white-50"></i> Export เป็น Excel (CSV)
                        </a>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover text-dark" id="dataTable" width="100%" cellspacing="0">
                            <thead class="bg-light text-center">
                                <tr>
                                    <th width="60">อันดับ</th>
                                    <th>รหัสนักศึกษา</th>
                                    <th>ชื่อ-นามสกุล</th>
                                    <th>คณะ</th> 
                                    <th>สาขาวิชา</th>
                                    <th>ระยะเวลาที่ใช้</th>
                                    <th>คะแนนที่ได้</th>
                                    <th>เวลาที่ส่ง</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(count($report_data) > 0): ?>
                                    <?php $rank = 1; foreach($report_data as $row): ?>
                                    <tr>
                                        <td class="text-center align-middle"><?= $rank++ ?></td>
                                        <td class="text-center align-middle font-weight-bold text-primary">
                                            <?= htmlspecialchars($row['student_code'] ?? '-') ?>
                                        </td>
                                        <td class="align-middle"><?= htmlspecialchars($row['fullname']) ?></td>
                                        
                                        <td class="align-middle"><?= htmlspecialchars($row['faculty'] ?? '-') ?></td>
                                        <td class="align-middle"><?= htmlspecialchars($row['major'] ?? '-') ?></td>
                                        
                                        <td class="text-center align-middle">
                                            <span class="badge badge-light p-2 border">
                                                <i class="far fa-clock text-primary"></i> <?= formatTime($row['time_taken_seconds']) ?>
                                            </span>
                                        </td>
                                        <td class="text-center align-middle font-weight-bold text-success">
                                            <?= number_format($row['score'], 2) ?> <small class="text-muted">/ <?= number_format($row['max_score'], 2) ?></small>
                                        </td>
                                        <td class="text-center text-xs align-middle text-muted">
                                            <?= date('d/m/y H:i', strtotime($row['submitted_at'])) ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
<?php include 'includes/footer.php'; ?>