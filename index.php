<?php
$page_title = 'Dashboard';
require_once 'auth_guard.php';
require_once 'config/db.php';

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// ==========================================
// 1. ดึงข้อมูลสำหรับมุมมอง "Admin"
// ==========================================
if ($role === 'admin') {
    // สถิติรวม
    $total_students = $conn->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn();
    $total_teachers = $conn->query("SELECT COUNT(*) FROM users WHERE role = 'teacher'")->fetchColumn();
    $total_exams_sys = $conn->query("SELECT COUNT(*) FROM exams")->fetchColumn();
    $total_courses = $conn->query("SELECT COUNT(*) FROM courses")->fetchColumn();
    
    // แจ้งเตือนทุจริตทั้งหมดในระบบ
    $cheating_alerts = $conn->query("SELECT COUNT(*) FROM student_results WHERE is_cheating_suspected = 1")->fetchColumn();

    // กราฟ: จำนวนการสอบย้อนหลัง 7 วัน
    $chart_stmt = $conn->query("
        SELECT DATE(submitted_at) as submit_date, COUNT(id) as total_subs 
        FROM student_results 
        WHERE submitted_at >= DATE(NOW()) - INTERVAL 7 DAY 
        GROUP BY DATE(submitted_at) 
        ORDER BY submit_date ASC
    ");
    $admin_chart_data = $chart_stmt->fetchAll(PDO::FETCH_ASSOC);
    $admin_labels = []; $admin_data = [];
    foreach($admin_chart_data as $row) {
        $admin_labels[] = $row['submit_date'];
        $admin_data[] = $row['total_subs'];
    }
}
// ==========================================
// 2. ดึงข้อมูลสำหรับมุมมอง "Teacher"
// ==========================================
elseif ($role === 'teacher') {
    // จำนวนรายวิชาที่รับผิดชอบสอน (ดึงจากตาราง teacher_courses)
    $stat_my_courses = $conn->prepare("SELECT COUNT(course_id) FROM teacher_courses WHERE teacher_id = :id");
    $stat_my_courses->execute([':id' => $user_id]);
    $my_total_courses = $stat_my_courses->fetchColumn();

    // จำนวนข้อสอบที่เปิดใช้งานอยู่
    $stat_active = $conn->prepare("SELECT COUNT(id) FROM exams WHERE teacher_id = :id AND is_active = 1");
    $stat_active->execute([':id' => $user_id]);
    $active_exams = $stat_active->fetchColumn();

    // รอตรวจอัตนัย (Action Required)
    $stat_pending = $conn->prepare("
        SELECT COUNT(sa.id) 
        FROM student_answers sa 
        JOIN student_results sr ON sa.result_id = sr.id 
        JOIN exams e ON sr.exam_id = e.id 
        JOIN questions q ON sa.question_id = q.id 
        WHERE e.teacher_id = :id AND q.question_type = 'subjective' AND sa.is_correct IS NULL
    ");
    $stat_pending->execute([':id' => $user_id]);
    $pending_grading = $stat_pending->fetchColumn();

    // พบผู้ต้องสงสัยทุจริตในวิชาของตนเอง (Action Required)
    $stat_cheat = $conn->prepare("
        SELECT COUNT(sr.id) 
        FROM student_results sr 
        JOIN exams e ON sr.exam_id = e.id 
        WHERE e.teacher_id = :id AND sr.is_cheating_suspected = 1
    ");
    $stat_cheat->execute([':id' => $user_id]);
    $cheating_suspects = $stat_cheat->fetchColumn();

    // คะแนนสูงสุด เฉลี่ย และต่ำสุด 5 วิชาล่าสุด สำหรับกราฟ (ปรับปรุงคิดเป็น %)
    $chart_stmt = $conn->prepare("
        SELECT 
            e.course_code, 
            IFNULL(MAX((sr.score / NULLIF((SELECT SUM(assigned_points) FROM exam_questions WHERE exam_id = e.id), 0)) * 100), 0) as max_score_pct,
            IFNULL(AVG((sr.score / NULLIF((SELECT SUM(assigned_points) FROM exam_questions WHERE exam_id = e.id), 0)) * 100), 0) as avg_score_pct,
            IFNULL(MIN((sr.score / NULLIF((SELECT SUM(assigned_points) FROM exam_questions WHERE exam_id = e.id), 0)) * 100), 0) as min_score_pct
        FROM exams e 
        LEFT JOIN student_results sr ON e.id = sr.exam_id 
        WHERE e.teacher_id = :id 
        GROUP BY e.id 
        ORDER BY e.created_at DESC LIMIT 5
    ");
    $chart_stmt->execute([':id' => $user_id]);
    $chart_data = $chart_stmt->fetchAll(PDO::FETCH_ASSOC);
    $labels = []; $max_scores = []; $avg_scores = []; $min_scores = [];
    foreach($chart_data as $data) {
        $labels[] = $data['course_code'] ?: 'ไม่มีรหัส';
        $max_scores[] = round($data['max_score_pct'], 2);
        $avg_scores[] = round($data['avg_score_pct'], 2);
        $min_scores[] = round($data['min_score_pct'], 2);
    }

    // รายการส่งข้อสอบล่าสุด 5 รายการ
    $recent_subs_stmt = $conn->prepare("
        SELECT u.fullname, u.student_code, e.title, sr.score, sr.submitted_at 
        FROM student_results sr 
        JOIN users u ON sr.student_id = u.id 
        JOIN exams e ON sr.exam_id = e.id 
        WHERE e.teacher_id = :id 
        ORDER BY sr.submitted_at DESC LIMIT 5
    ");
    $recent_subs_stmt->execute([':id' => $user_id]);
    $recent_submissions = $recent_subs_stmt->fetchAll(PDO::FETCH_ASSOC);
} 
// ==========================================
// 3. ดึงข้อมูลสำหรับมุมมอง "Student"
// ==========================================
else {
    $stat_my_exams = $conn->prepare("SELECT COUNT(id) FROM student_results WHERE student_id = :id");
    $stat_my_exams->execute([':id' => $user_id]);
    $total_taken = $stat_my_exams->fetchColumn();

    // คะแนนเฉลี่ยรวมของคุณ (ปรับปรุงคำนวณเป็น % จากทุกวิชาที่สอบ)
    $stat_avg = $conn->prepare("
        SELECT IFNULL(AVG((sr.score / NULLIF((SELECT SUM(assigned_points) FROM exam_questions WHERE exam_id = e.id), 0)) * 100), 0) as avg_pct
        FROM student_results sr
        JOIN exams e ON sr.exam_id = e.id
        WHERE sr.student_id = :id
    ");
    $stat_avg->execute([':id' => $user_id]);
    $my_avg_score = round($stat_avg->fetchColumn(), 2);

    // ประวัติการสอบล่าสุด 5 รายการเพื่อใช้พล็อตพัฒนาการกราฟ
    $recent_exams_stmt = $conn->prepare("
        SELECT 
            e.course_code, 
            e.title, 
            sr.score, 
            (SELECT SUM(assigned_points) FROM exam_questions WHERE exam_id = e.id) as max_score,
            sr.submitted_at, 
            sr.is_cheating_suspected 
        FROM student_results sr 
        JOIN exams e ON sr.exam_id = e.id 
        WHERE sr.student_id = :id 
        ORDER BY sr.submitted_at DESC LIMIT 5
    ");
    $recent_exams_stmt->execute([':id' => $user_id]);
    $my_recent_exams = $recent_exams_stmt->fetchAll(PDO::FETCH_ASSOC);

    // ข้อมูลกราฟพัฒนาการ (คำนวณสเกล % เพื่อให้กราฟพล็อตตั้งแต่ 0-100 ได้สมเหตุสมผล)
    $my_labels = []; $my_scores = [];
    foreach(array_reverse($my_recent_exams) as $data) { 
        $my_labels[] = $data['course_code'] ?: 'Exam';
        $pct = ($data['max_score'] > 0) ? ($data['score'] / $data['max_score']) * 100 : 0;
        $my_scores[] = round($pct, 1);
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
                <h1 class="h3 mb-0 text-gray-800">แดชบอร์ดภาพรวม</h1>
                <span class="badge badge-primary p-2" style="font-size: 0.9rem;">
                    <i class="fas fa-user-circle"></i> 
                    เข้าสู่ระบบในฐานะ: <?= ucfirst($role) ?>
                </span>
            </div>

            <?php if($role === 'admin'): ?>
            <div class="row">
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-primary shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">นักศึกษาทั้งหมด</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?= number_format($total_students) ?> คน</div>
                                </div>
                                <div class="col-auto"><i class="fas fa-users fa-2x text-gray-300"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-info shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-info text-uppercase mb-1">อาจารย์ทั้งหมด</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?= number_format($total_teachers) ?> คน</div>
                                </div>
                                <div class="col-auto"><i class="fas fa-chalkboard-teacher fa-2x text-gray-300"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-success shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-success text-uppercase mb-1">รายวิชาในระบบทั้งหมด</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?= number_format($total_courses) ?> วิชา</div>
                                </div>
                                <div class="col-auto"><i class="fas fa-book-open fa-2x text-gray-300"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-danger shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">แจ้งเตือนทุจริตการสอบ</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?= number_format($cheating_alerts) ?> ครั้ง</div>
                                </div>
                                <div class="col-auto"><i class="fas fa-exclamation-triangle fa-2x text-gray-300"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-12">
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">สถิติการส่งข้อสอบ (7 วันล่าสุด)</h6>
                        </div>
                        <div class="card-body">
                            <div class="chart-area"><canvas id="adminChart"></canvas></div>
                        </div>
                    </div>
                </div>
            </div>

            <?php elseif($role === 'teacher'): ?>
            <div class="row">
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-primary shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">วิชาที่รับผิดชอบสอน</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $my_total_courses ?> วิชา</div>
                                </div>
                                <div class="col-auto"><i class="fas fa-book fa-2x text-gray-300"></i></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-success shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-success text-uppercase mb-1">ข้อสอบ (Active)</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $active_exams ?> ชุด</div>
                                </div>
                                <div class="col-auto"><i class="fas fa-broadcast-tower fa-2x text-gray-300"></i></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-warning shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">รอตรวจข้อสอบอัตนัย</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $pending_grading ?> ข้อ</div>
                                </div>
                                <div class="col-auto"><i class="fas fa-marker fa-2x text-gray-300"></i></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-danger shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">ผู้ต้องสงสัยทุจริต</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $cheating_suspects ?> กรณี</div>
                                </div>
                                <div class="col-auto"><i class="fas fa-user-shield fa-2x text-gray-300"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-xl-7 col-lg-6">
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-chart-bar"></i> สถิติคะแนน 5 การสอบล่าสุด (สูงสุด/เฉลี่ย/ต่ำสุด)</h6>
                        </div>
                        <div class="card-body">
                            <div class="chart-bar"><canvas id="avgScoreChart"></canvas></div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-5 col-lg-6">
                    <div class="card shadow mb-4">
                        <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                            <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-history"></i> ผู้เรียนที่ส่งข้อสอบล่าสุด</h6>
                            <a href="reports.php" class="btn btn-sm btn-primary">ดูทั้งหมด</a>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover mb-0">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>รหัสนักศึกษา</th>
                                            <th>วิชา</th>
                                            <th>คะแนน</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if(empty($recent_submissions)): ?>
                                            <tr><td colspan="3" class="text-center py-3">ยังไม่มีข้อมูลการสอบ</td></tr>
                                        <?php else: ?>
                                            <?php foreach($recent_submissions as $sub): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($sub['student_code']) ?></td>
                                                <td><?= htmlspecialchars(mb_strimwidth($sub['title'], 0, 20, '...')) ?></td>
                                                <td class="font-weight-bold text-success"><?= $sub['score'] ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php else: ?>
            <div class="row mb-4">
                <div class="col-xl-4 col-md-6 mb-4">
                    <div class="card shadow border-bottom-warning h-100">
                        <div class="card-header py-3 bg-gradient-warning">
                            <h6 class="m-0 font-weight-bold text-dark text-center"><i class="fas fa-sign-in-alt"></i> เข้าห้องสอบด่วน</h6>
                        </div>
                        <div class="card-body d-flex flex-column justify-content-center text-center">
                            <form action="join_exam.php" method="POST">
                                <div class="form-group mb-2">
                                    <input type="text" class="form-control form-control-lg text-center font-weight-bold shadow-sm" name="exam_pin" placeholder="รหัส PIN 6 หลัก" maxlength="6" required style="letter-spacing: 3px;">
                                </div>
                                <button type="submit" class="btn btn-warning btn-block text-dark font-weight-bold">เริ่มทำข้อสอบ</button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-xl-4 col-md-6 mb-4">
                    <div class="card border-left-info shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-info text-uppercase mb-1">จำนวนวิชาที่สอบแล้ว</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $total_taken ?> วิชา</div>
                                </div>
                                <div class="col-auto"><i class="fas fa-clipboard-check fa-2x text-gray-300"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-4 col-md-6 mb-4">
                    <div class="card border-left-primary shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">คะแนนเฉลี่ยรวมของคุณ</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $my_avg_score ?>%</div>
                                </div>
                                <div class="col-auto"><i class="fas fa-star fa-2x text-gray-300"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-xl-7 col-lg-6 mb-4">
                    <div class="card shadow mb-4 h-100">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-chart-line"></i> กราฟพัฒนาการคะแนนสอบ</h6>
                        </div>
                        <div class="card-body">
                            <div class="chart-area"><canvas id="myProgressChart"></canvas></div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-5 col-lg-6 mb-4">
                    <div class="card shadow h-100">
                        <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                            <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-history"></i> ประวัติการสอบล่าสุด</h6>
                            <a href="my_results.php" class="btn btn-sm btn-outline-primary">ดูผลทั้งหมด</a>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover table-vcenter mb-0">
                                    <tbody>
                                        <?php if(empty($my_recent_exams)): ?>
                                            <tr><td class="text-center py-4 text-muted">ยังไม่มีประวัติการสอบ</td></tr>
                                        <?php else: ?>
                                            <?php foreach($my_recent_exams as $exam): ?>
                                            <tr>
                                                <td class="p-3">
                                                    <div class="font-weight-bold text-dark"><?= htmlspecialchars($exam['course_code']) ?></div>
                                                    <div class="text-xs text-muted"><?= date('d/m/Y H:i', strtotime($exam['submitted_at'])) ?></div>
                                                    <?php if($exam['is_cheating_suspected']): ?>
                                                        <span class="badge badge-danger mt-1">ถูกเพ่งเล็ง</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-right p-3 align-middle">
                                                    <span class="badge badge-success p-2" style="font-size: 0.9rem;"><?= $exam['score'] ?> คะแนน</span>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
<?php include 'includes/footer.php'; ?>

<script src="vendor/chart.js/Chart.min.js"></script>
<script src="vendor/chart.js/Chart.min.js"></script>
<script>
Chart.defaults.global.defaultFontFamily = 'Nunito', '-apple-system,system-ui,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif';
Chart.defaults.global.defaultFontColor = '#858796';

<?php if($role === 'admin'): ?>
// --- กราฟ Admin ---
var ctxAdmin = document.getElementById("adminChart");
if(ctxAdmin) {
    new Chart(ctxAdmin, {
        type: 'line',
        data: {
            labels: <?= json_encode($admin_labels) ?>,
            datasets: [{
                label: "จำนวนผู้ส่งข้อสอบ",
                lineTension: 0.3,
                backgroundColor: "rgba(78, 115, 223, 0.05)",
                borderColor: "rgba(78, 115, 223, 1)",
                pointRadius: 3,
                pointBackgroundColor: "rgba(78, 115, 223, 1)",
                data: <?= json_encode($admin_data) ?>,
            }],
        },
        options: { 
        maintainAspectRatio: false, 
        legend: { display: false },
        scales: {
            yAxes: [{
                ticks: {
                    beginAtZero: true, // บังคับให้แกน Y เริ่มที่ 0 เสมอ
                    precision: 0       // บังคับไม่ให้แสดงทศนิยม (แสดงแค่จำนวนเต็ม)
                }
            }]
        }
    }
    });
}

<?php elseif($role === 'teacher'): ?>
// --- กราฟ Teacher ---
var ctxTeacher = document.getElementById("avgScoreChart");
if(ctxTeacher) {
    new Chart(ctxTeacher, {
        type: 'bar',
        data: {
            labels: <?= json_encode($labels) ?>,
            datasets: [
                {
                    label: "สูงสุด",
                    backgroundColor: "#1cc88a",
                    hoverBackgroundColor: "#17a673",
                    borderColor: "#1cc88a",
                    data: <?= json_encode($max_scores) ?>,
                },
                {
                    label: "เฉลี่ย",
                    backgroundColor: "#4e73df",
                    hoverBackgroundColor: "#2e59d9",
                    borderColor: "#4e73df",
                    data: <?= json_encode($avg_scores) ?>,
                },
                {
                    label: "ต่ำสุด",
                    backgroundColor: "#e74a3b",
                    hoverBackgroundColor: "#e02d1b",
                    borderColor: "#e74a3b",
                    data: <?= json_encode($min_scores) ?>,
                }
            ],
        },
        options: { 
            maintainAspectRatio: false, 
            scales: { 
                yAxes: [{ 
                    ticks: { 
                        beginAtZero: true, 
                        max: 100,
                        callback: function(value) { return value + "%"; }
                    } 
                }] 
            }, 
            legend: { display: true, position: 'bottom' } 
        }
    });
}

<?php else: ?>
// --- กราฟ Student ---
var ctxStudent = document.getElementById("myProgressChart");
if(ctxStudent) {
    new Chart(ctxStudent, {
        type: 'line',
        data: {
            labels: <?= json_encode($my_labels) ?>,
            datasets: [{
                label: "คะแนนที่ได้ (%)",
                lineTension: 0.3,
                backgroundColor: "rgba(28, 200, 138, 0.05)",
                borderColor: "#1cc88a",
                pointRadius: 4,
                pointBackgroundColor: "#1cc88a",
                data: <?= json_encode($my_scores) ?>,
            }],
        },
        options: { 
            maintainAspectRatio: false, 
            scales: { 
                yAxes: [{ 
                    ticks: { 
                        beginAtZero: true, 
                        max: 100,
                        callback: function(value) { return value + "%"; }
                    } 
                }] 
            }, 
            legend: { display: false } 
        }
    });
}
<?php endif; ?>
</script>