<?php
$page_title = 'ประวัติและผลคะแนนสอบ';
require_once 'auth_guard.php';
require_once 'config/db.php';

// 🛡️ เฉพาะนักศึกษาเท่านั้นที่เข้าหน้านี้ได้
require_role(['student']);

$student_id = $_SESSION['user_id'];

// ---------------------------------------------------------
// 1. ดึงข้อมูลสถิติภาพรวมของนักศึกษาคนนี้
// ---------------------------------------------------------
// ดึงจำนวนวิชาทั้งหมดที่สอบ
$stat_total = $conn->prepare("SELECT COUNT(id) as total_exams FROM student_results WHERE student_id = :student_id");
$stat_total->execute([':student_id' => $student_id]);
$total_exams = $stat_total->fetchColumn();

// ดึงสถิติ วิชาที่ทำได้ดีสุด และ ต่ำสุด โดยคิดจาก % (คะแนนที่ได้ / คะแนนเต็ม * 100)
// ใช้ NULLIF เพื่อป้องกัน Error หารด้วย 0 กรณีชุดข้อสอบยังไม่มีข้อสอบ
$stat_perf_sql = "
    SELECT 
        e.course_code, 
        e.title, 
        sr.score,
        (SELECT SUM(assigned_points) FROM exam_questions WHERE exam_id = e.id) as max_score,
        (sr.score / NULLIF((SELECT SUM(assigned_points) FROM exam_questions WHERE exam_id = e.id), 0)) * 100 as percentage
    FROM student_results sr
    JOIN exams e ON sr.exam_id = e.id
    WHERE sr.student_id = :student_id 
    HAVING percentage IS NOT NULL
    ORDER BY percentage
";
$stmt_perf = $conn->prepare($stat_perf_sql);
$stmt_perf->execute([':student_id' => $student_id]);
$all_perfs = $stmt_perf->fetchAll(PDO::FETCH_ASSOC);

$max_exam = null;
$min_exam = null;
if (count($all_perfs) > 0) {
    $min_exam = $all_perfs[0]; // แถวแรกคือ % ต่ำสุด (ASC)
    $max_exam = end($all_perfs); // แถวสุดท้ายคือ % สูงสุด
}

// ---------------------------------------------------------
// 2. ดึงประวัติการสอบทั้งหมด (พร้อมคำนวณคะแนนเต็ม และเช็กสถานะรอตรวจ)
// ---------------------------------------------------------
$sql = "SELECT 
            sr.id as result_id,
            sr.score,
            sr.submitted_at,
            e.course_code,
            e.title,
            (SELECT SUM(assigned_points) FROM exam_questions WHERE exam_id = e.id) as max_score,
            (SELECT COUNT(sa.id) FROM student_answers sa 
             JOIN questions q ON sa.question_id = q.id 
             WHERE sa.result_id = sr.id AND q.question_type = 'subjective' AND sa.is_correct IS NULL) as pending_grading
        FROM student_results sr
        JOIN exams e ON sr.exam_id = e.id
        WHERE sr.student_id = :student_id
        ORDER BY sr.submitted_at DESC";

$stmt = $conn->prepare($sql);
$stmt->execute([':student_id' => $student_id]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div id="content-wrapper" class="d-flex flex-column">
    <div id="content">
        <?php include 'includes/topbar.php'; ?>

        <div class="container-fluid">
            <div class="d-sm-flex align-items-center justify-content-between mb-4">
                <h1 class="h3 mb-0 text-gray-800"><i class="fas fa-file-alt text-primary"></i> ประวัติและผลคะแนนสอบของคุณ</h1>
            </div>

            <div class="row">
                <div class="col-xl-4 col-md-6 mb-4">
                    <div class="card border-left-primary shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">จำนวนวิชาที่สอบแล้ว</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $total_exams ?: 0 ?> รายวิชา</div>
                                </div>
                                <div class="col-auto"><i class="fas fa-tasks fa-2x text-gray-300"></i></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-4 col-md-6 mb-4">
                    <div class="card border-left-success shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-success text-uppercase mb-1">วิชาที่ทำได้ดีที่สุด (จุดแข็ง)</div>
                                    <div class="h6 mb-0 font-weight-bold text-gray-800">
                                        <?php if($max_exam): ?>
                                            <span title="<?= htmlspecialchars($max_exam['title']) ?>"><?= htmlspecialchars($max_exam['course_code']) ?></span><br>
                                            <small class="text-success font-weight-bold"><?= number_format($max_exam['percentage'], 1) ?>%</small>
                                            <small class="text-muted">(<?= $max_exam['score'] ?>/<?= $max_exam['max_score'] ?>)</small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-auto"><i class="fas fa-arrow-up text-success fa-2x"></i></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-4 col-md-6 mb-4">
                    <div class="card border-left-danger shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">วิชาที่ควรพัฒนา</div>
                                    <div class="h6 mb-0 font-weight-bold text-gray-800">
                                        <?php if($min_exam): ?>
                                            <span title="<?= htmlspecialchars($min_exam['title']) ?>"><?= htmlspecialchars($min_exam['course_code']) ?></span><br>
                                            <small class="text-danger font-weight-bold"><?= number_format($min_exam['percentage'], 1) ?>%</small>
                                            <small class="text-muted">(<?= $min_exam['score'] ?>/<?= $min_exam['max_score'] ?>)</small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-auto"><i class="fas fa-arrow-down text-danger fa-2x"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow mb-4">
                <div class="card-header py-3 bg-gradient-light">
                    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-list-ul"></i> รายการสอบทั้งหมดของคุณ</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover text-dark" id="dataTable" width="100%" cellspacing="0">
                            <thead class="bg-light">
                                <tr class="text-center">
                                    <th>วันที่สอบ (ส่งคำตอบ)</th>
                                    <th>รหัสวิชา</th>
                                    <th>หัวข้อการสอบ</th>
                                    <th>คะแนนเต็ม</th>
                                    <th>คะแนนที่ได้</th>
                                    <th>สถานะการตรวจ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(count($results) > 0): ?>
                                    <?php foreach($results as $r): ?>
                                        <?php 
                                            // 1. คำนวณหาเปอร์เซ็นต์ผลการสอบรายวิชา
                                            $pct = ($r['max_score'] > 0) ? ($r['score'] / $r['max_score']) * 100 : 0;
                                            
                                            // 2. แยกการแสดงสีของ Progress Bar ตามสัดส่วนคะแนนที่ได้
                                            if ($pct >= 80) {
                                                $bar_color = 'bg-success'; // สีเขียวเมื่อผ่านเกณฑ์ดีเยี่ยม
                                            } elseif ($pct >= 50) {
                                                $bar_color = 'bg-warning'; // สีเหลืองเมื่อผ่านเกณฑ์ปานกลาง
                                            } else {
                                                $bar_color = 'bg-danger';  // สีแดงเมื่อคะแนนต่ำกว่าครึ่งหนึ่ง
                                            }
                                        ?>
                                        <tr>
                                            <td class="text-center align-middle" data-sort="<?= date('Y-m-d H:i:s', strtotime($r['submitted_at'])) ?>">
                                                <?= date('d/m/Y H:i', strtotime($r['submitted_at'])) ?> น.
                                            </td>
                                            <td class="text-center align-middle font-weight-bold text-primary"><?= htmlspecialchars($r['course_code']) ?></td>
                                            <td class="align-middle"><?= htmlspecialchars($r['title']) ?></td>
                                            <td class="text-center align-middle"><?= number_format($r['max_score'], 2) ?></td>
                                            <td class="text-center align-middle" style="min-width: 120px;">
                                                <div class="font-weight-bold mb-1 <?= $r['pending_grading'] > 0 ? 'text-muted' : 'text-success' ?>">
                                                    <?= number_format($r['score'], 2) ?>
                                                </div>
                                                <div class="progress progress-sm" style="height: 6px; background-color: #eaecf4;">
                                                    <div class="progress-bar <?= $bar_color ?>" role="progressbar" style="width: <?= $pct ?>%" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                                </div>
                                                <small class="text-muted font-weight-bold"><?= number_format($pct, 1) ?>%</small>
                                            </td>
                                            <td class="text-center align-middle">
                                                <?php if($r['pending_grading'] > 0): ?>
                                                    <span class="badge badge-warning p-2 text-dark shadow-sm" title="มีข้อเขียนที่รออาจารย์ให้คะแนน">
                                                        <i class="fas fa-clock"></i> รอตรวจอัตนัย (<?= $r['pending_grading'] ?> ข้อ)
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge badge-success p-2 shadow-sm">
                                                        <i class="fas fa-check-circle"></i> ตรวจเสร็จสิ้น
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-5">
                                            <i class="fas fa-folder-open fa-3x mb-3 text-gray-300"></i><br>
                                            คุณยังไม่มีประวัติการสอบ
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>
    
<?php include 'includes/footer.php'; ?>