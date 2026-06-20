<?php
$page_title = 'ผลการสอบของนักศึกษา';
require_once 'auth_guard.php';
require_role(['teacher', 'admin']);
require_once 'config/db.php';

if (!isset($_GET['exam_id'])) {
    header("Location: question_bank.php");
    exit();
}

$exam_id = $_GET['exam_id'];
$teacher_id = $_SESSION['user_id'];

// 1. ตรวจสอบสิทธิ์ว่าอาจารย์คนนี้เป็นเจ้าของข้อสอบหรือไม่
$stmt = $conn->prepare("SELECT * FROM exams WHERE id = :exam_id AND teacher_id = :teacher_id");
$stmt->execute([':exam_id' => $exam_id, ':teacher_id' => $teacher_id]);
$exam = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$exam) {
    echo "<script>alert('ไม่พบข้อมูลข้อสอบ หรือคุณไม่มีสิทธิ์เข้าถึง'); window.location.href='question_bank.php';</script>";
    exit();
}

// 1.5 คำนวณคะแนนเต็มทั้งหมดของชุดข้อสอบนี้
$pts_stmt = $conn->prepare("SELECT SUM(assigned_points) FROM exam_questions WHERE exam_id = :exam_id");
$pts_stmt->execute([':exam_id' => $exam_id]);
$exam_max_points = (float)$pts_stmt->fetchColumn();

// 2. ดึงรายชื่อนักศึกษาที่ส่งข้อสอบชุดนี้
$sql = "SELECT sr.*, u.fullname, u.username,
        (SELECT COUNT(sa.id) FROM student_answers sa 
         JOIN questions q ON sa.question_id = q.id 
         WHERE sa.result_id = sr.id AND q.question_type = 'subjective' AND sa.is_correct IS NULL) as pending_grading
        FROM student_results sr 
        JOIN users u ON sr.student_id = u.id 
        WHERE sr.exam_id = :exam_id 
        ORDER BY sr.submitted_at DESC";
$res_stmt = $conn->prepare($sql);
$res_stmt->execute([':exam_id' => $exam_id]);
$results = $res_stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. คำนวณสถิติเบื้องต้น
$total_students = count($results);
$sum_score = 0;
$max_score_achieved = 0;
foreach($results as $r) { 
    $sum_score += (float)$r['score']; 
    if((float)$r['score'] > $max_score_achieved) $max_score_achieved = (float)$r['score'];
}
$avg_score = $total_students > 0 ? ($sum_score / $total_students) : 0;

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div id="content-wrapper" class="d-flex flex-column">
    <div id="content">
        <?php include 'includes/topbar.php'; ?>

        <div class="container-fluid">
            <div class="d-sm-flex align-items-center justify-content-between mb-4">
                <h1 class="h3 mb-0 text-gray-800">ผลการสอบ: <span class="text-primary"><?= htmlspecialchars($exam['course_code']) ?> <?= htmlspecialchars($exam['title']) ?></span></h1>
                <div>
                    <a href="item_analysis.php?exam_id=<?= $exam_id ?>" class="btn btn-sm btn-purple shadow-sm text-white mr-2" style="background-color: #6f42c1; border-color: #6f42c1;">
                        <i class="fas fa-microscope"></i> วิเคราะห์ข้อสอบ (Item Analysis)
                    </a>
                    <a href="question_bank.php" class="btn btn-sm btn-secondary shadow-sm">
                        <i class="fas fa-arrow-left"></i> กลับไปคลังข้อสอบ
                    </a>
                </div>
            </div>  

            <div class="row mb-4">
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-primary shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">จำนวนผู้ส่งข้อสอบ</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $total_students ?> คน</div>
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
                                    <div class="text-xs font-weight-bold text-info text-uppercase mb-1">คะแนนเต็ม</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?= number_format($exam_max_points, 2) ?> คะแนน</div>
                                </div>
                                <div class="col-auto"><i class="fas fa-star fa-2x text-gray-300"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-success shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-success text-uppercase mb-1">คะแนนเฉลี่ย (Mean)</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?= number_format($avg_score, 2) ?></div>
                                </div>
                                <div class="col-auto"><i class="fas fa-chart-line fa-2x text-gray-300"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-warning shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">คะแนนสูงสุด (Max)</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?= number_format($max_score_achieved, 2) ?></div>
                                </div>
                                <div class="col-auto"><i class="fas fa-trophy fa-2x text-gray-300"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow mb-4 border-bottom-info">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-list"></i> รายชื่อผู้เข้าสอบและผลคะแนน</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover" width="100%" cellspacing="0">
                            <thead class="bg-light">
                                <tr>
                                    <th>รหัสนักศึกษา (Username)</th>
                                    <th>ชื่อ - นามสกุล</th>
                                    <th class="text-center">คะแนนรวมสุทธิ</th>
                                    <th class="text-center">เวลาที่ส่ง</th>
                                    <th class="text-center">สถานะการตรวจ</th>
                                    <th class="text-center">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(count($results) > 0): ?>
                                    <?php foreach($results as $r): ?>
                                        <tr>
                                            <td class="font-weight-bold text-dark"><?= htmlspecialchars($r['username']) ?></td>
                                            <td><?= htmlspecialchars($r['fullname']) ?></td>
                                            <td class="text-center font-weight-bold text-primary h6 align-middle mb-0">
                                                <?= number_format($r['score'], 2) ?> <span class="text-muted small">/ <?= number_format($exam_max_points, 2) ?></span>
                                            </td>
                                            <td class="text-center small align-middle"><?= date('d/m/Y H:i', strtotime($r['submitted_at'])) ?> น.</td>
                                            <td class="text-center align-middle">
                                                <?php if($r['pending_grading'] > 0): ?>
                                                    <span class="badge badge-warning p-2 text-dark shadow-sm"><i class="fas fa-clock"></i> รอตรวจอัตนัย (<?= $r['pending_grading'] ?> ข้อ)</span>
                                                <?php else: ?>
                                                    <span class="badge badge-success p-2 shadow-sm"><i class="fas fa-check-circle"></i> ตรวจเสร็จสิ้น</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center align-middle">
                                                <a href="grade_student.php?result_id=<?= $r['id'] ?>" class="btn btn-sm btn-info shadow-sm">
                                                    <i class="fas fa-search"></i> ดูคำตอบ / ให้คะแนน
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="6" class="text-center text-muted py-5"><i class="fas fa-inbox fa-3x mb-3 text-gray-300"></i><br>ยังไม่มีนักศึกษาส่งข้อสอบชุดนี้</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php include 'includes/footer.php'; ?>