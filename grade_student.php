<?php
$page_title = 'ตรวจและให้คะแนนข้อสอบ';
require_once 'auth_guard.php';
require_role(['teacher', 'admin']);
require_once 'config/db.php';

if (!isset($_GET['result_id'])) {
    header("Location: question_bank.php");
    exit();
}

$result_id = $_GET['result_id'];
$alert_msg = "";

// 1. ระบบบันทึกการให้คะแนน
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_grades'])) {
    try {
        $conn->beginTransaction();

        if (isset($_POST['points_given']) && is_array($_POST['points_given'])) {
            $upd_ans = $conn->prepare("UPDATE student_answers SET points_received = :pts, is_correct = :is_c WHERE id = :ans_id");
            
            foreach ($_POST['points_given'] as $ans_id => $pts) {
                $pts = (float)$pts;
                // พิจารณาว่า "ถูก" (is_correct=1) ถ้าได้คะแนนมากกว่า 0
                $is_c = ($pts > 0) ? 1 : 0;
                
                $upd_ans->execute([
                    ':pts' => $pts, 
                    ':is_c' => $is_c,
                    ':ans_id' => $ans_id
                ]);
            }
        }

        // คำนวณคะแนนรวมใหม่จากตาราง student_answers
        $calc = $conn->prepare("SELECT SUM(points_received) FROM student_answers WHERE result_id = :result_id");
        $calc->execute([':result_id' => $result_id]);
        $new_total_score = (float)$calc->fetchColumn();

        // อัปเดตคะแนนรวมกลับไปที่ตาราง student_results
        $upd_score = $conn->prepare("UPDATE student_results SET score = :score WHERE id = :result_id");
        $upd_score->execute([':score' => $new_total_score, ':result_id' => $result_id]);

        $conn->commit();
        $alert_msg = "<div class='alert alert-success shadow-sm'><i class='fas fa-check-circle'></i> บันทึกผลการตรวจและอัปเดตคะแนนรวมเป็น $new_total_score เรียบร้อยแล้ว</div>";
    } catch (Exception $e) {
        $conn->rollBack();
        $alert_msg = "<div class='alert alert-danger'>เกิดข้อผิดพลาด: " . $e->getMessage() . "</div>";
    }
}

// 2. ดึงข้อมูลการสอบและนักศึกษา
$info_stmt = $conn->prepare("
    SELECT sr.*, u.fullname, u.username, e.title, e.course_code, e.id as exam_id 
    FROM student_results sr 
    JOIN users u ON sr.student_id = u.id 
    JOIN exams e ON sr.exam_id = e.id 
    WHERE sr.id = :result_id AND e.teacher_id = :teacher_id
");
$info_stmt->execute([':result_id' => $result_id, ':teacher_id' => $_SESSION['user_id']]);
$result_info = $info_stmt->fetch(PDO::FETCH_ASSOC);

if (!$result_info) {
    echo "<script>alert('ไม่พบข้อมูลการสอบนี้'); window.location.href='question_bank.php';</script>";
    exit();
}

// 🟢 3. ดึงคำตอบทั้งหมด (แก้ SQL ให้ไป JOIN กับตารางเชื่อมเพื่อเอา max_points ที่ถูกต้อง)
$ans_stmt = $conn->prepare("
    SELECT sa.id as ans_id, sa.student_answer, sa.points_received, sa.is_correct, 
           q.question_type, q.question_text, q.correct_answer, 
           eq.assigned_points as max_points
    FROM student_answers sa 
    JOIN questions q ON sa.question_id = q.id 
    JOIN student_results sr ON sa.result_id = sr.id
    JOIN exam_questions eq ON q.id = eq.question_id AND eq.exam_id = sr.exam_id
    WHERE sa.result_id = :result_id 
    ORDER BY eq.id ASC
");
$ans_stmt->execute([':result_id' => $result_id]);
$answers = $ans_stmt->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div id="content-wrapper" class="d-flex flex-column">
    <div id="content">
        <?php include 'includes/topbar.php'; ?>

        <div class="container-fluid">
            <div class="d-sm-flex align-items-center justify-content-between mb-4">
                <h1 class="h3 mb-0 text-gray-800">ตรวจข้อสอบ: <span class="text-primary"><?= htmlspecialchars($result_info['fullname']) ?></span></h1>
                <a href="exam_results.php?exam_id=<?= $result_info['exam_id'] ?>" class="btn btn-sm btn-secondary shadow-sm">
                    <i class="fas fa-arrow-left"></i> กลับหน้ารายชื่อ
                </a>
            </div>

            <?= $alert_msg ?>

            <div class="row">
                <div class="col-lg-12">
                    <div class="card shadow mb-4 border-left-primary">
                        <div class="card-body py-3 d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="m-0 font-weight-bold text-primary"><?= htmlspecialchars($result_info['course_code']) ?>: <?= htmlspecialchars($result_info['title']) ?></h5>
                                <p class="text-muted mb-0 small">รหัสนักศึกษา: <?= htmlspecialchars($result_info['username']) ?></p>
                            </div>
                            <div class="text-right">
                                <span class="text-xs font-weight-bold text-uppercase mb-1 d-block text-primary">คะแนนรวมสุทธิ</span>
                                <div class="h3 mb-0 font-weight-bold text-gray-800"><?= number_format($result_info['score'], 2) ?></div>
                            </div>
                        </div>
                    </div>

                    <form action="grade_student.php?result_id=<?= $result_id ?>" method="POST">
                        <input type="hidden" name="save_grades" value="1">
                        
                        <?php foreach($answers as $index => $ans): ?>
                            <div class="card shadow mb-4">
                                <div class="card-header py-3 d-flex justify-content-between align-items-center <?= ($ans['question_type'] == 'subjective' && $ans['points_received'] == 0) ? 'bg-light border-left-warning' : 'bg-white' ?>">
                                    <h6 class="m-0 font-weight-bold text-dark">ข้อที่ <?= $index + 1 ?> (<?= $ans['question_type'] == 'subjective' ? 'อัตนัย (เขียนตอบ)' : 'ตรวจอัตโนมัติ' ?>)</h6>
                                    <span class="badge badge-secondary p-2">คะแนนเต็ม <?= (float)$ans['max_points'] ?></span>
                                </div>
                                
                                <div class="card-body">
                                    <p class="mb-3 text-gray-900 font-weight-bold"><?= nl2br(htmlspecialchars($ans['question_text'])) ?></p>
                                    
                                    <div class="row">
                                        <div class="col-md-7 border-right">
                                            <label class="text-xs font-weight-bold text-primary text-uppercase">คำตอบของนักศึกษา:</label>
                                            <div class="p-3 bg-light rounded text-dark mb-3" style="min-height: 60px;">
                                                <?= empty($ans['student_answer']) ? '<i>(ไม่ได้ตอบ)</i>' : nl2br(htmlspecialchars($ans['student_answer'])) ?>
                                            </div>
                                            
                                            <label class="text-xs font-weight-bold text-success text-uppercase">เฉลย / แนวคำตอบอ้างอิง:</label>
                                            <div class="p-3 rounded mb-2" style="background-color: #f0fff4; border: 1px dashed #48bb78; min-height: 60px;">
                                                <?= nl2br(htmlspecialchars($ans['correct_answer'])) ?>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-5 pl-4 d-flex flex-column justify-content-center">
                                            
                                            <?php if($ans['question_type'] !== 'subjective'): ?>
                                                <div class="text-center">
                                                    <label class="font-weight-bold text-dark mb-3"><i class="fas fa-robot text-primary"></i> ผลการตรวจอัตโนมัติ</label>
                                                    <div class="p-3 rounded <?= $ans['is_correct'] == 1 ? 'bg-success text-white' : 'bg-danger text-white' ?>">
                                                        <h4 class="mb-1 font-weight-bold">
                                                            <?= $ans['is_correct'] == 1 ? '<i class="fas fa-check-circle"></i> ตอบถูก' : '<i class="fas fa-times-circle"></i> ตอบผิด' ?>
                                                        </h4>
                                                        <h6 class="mb-0">ได้คะแนน: <?= (float)$ans['points_received'] ?> / <?= (float)$ans['max_points'] ?></h6>
                                                    </div>
                                                </div>
                                                <input type="hidden" name="points_given[<?= $ans['ans_id'] ?>]" value="<?= (float)$ans['points_received'] ?>">

                                            <?php else: ?>
                                                <div class="form-group">
                                                    <label class="font-weight-bold text-dark"><i class="fas fa-marker text-warning"></i> ให้อาจารย์ประเมินคะแนน</label>
                                                    <div class="input-group">
                                                        <input type="number" 
                                                               name="points_given[<?= $ans['ans_id'] ?>]" 
                                                               class="form-control form-control-lg border-warning text-dark font-weight-bold" 
                                                               value="<?= (float)$ans['points_received'] ?>" 
                                                               step="0.1" 
                                                               min="0" 
                                                               max="<?= $ans['max_points'] ?>">
                                                        <div class="input-group-append">
                                                            <span class="input-group-text bg-warning text-dark font-weight-bold">/ <?= (float)$ans['max_points'] ?></span>
                                                        </div>
                                                    </div>
                                                    <small class="text-muted mt-2 d-block"><i class="fas fa-info-circle"></i> ระบุคะแนนได้ตามความเหมาะสม (มีทศนิยมได้)</small>
                                                </div>
                                            <?php endif; ?>

                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <div class="text-center mb-5 pb-5 mt-4">
                            <button type="submit" class="btn btn-success btn-lg px-5 shadow-lg">
                                <i class="fas fa-save"></i> บันทึกผลการตรวจและคำนวณคะแนนรวม
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php include 'includes/footer.php'; ?>