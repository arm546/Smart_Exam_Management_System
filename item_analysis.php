<?php
$page_title = 'วิเคราะห์คุณภาพข้อสอบ (Item Analysis)';
require_once 'auth_guard.php';
require_role(['teacher', 'admin']);
require_once 'config/db.php';

if (!isset($_GET['exam_id'])) {
    header("Location: reports.php");
    exit();
}

$exam_id = $_GET['exam_id'];

// 1. ดึงข้อมูลชุดข้อสอบ
$stmt = $conn->prepare("SELECT * FROM exams WHERE id = :exam_id");
$stmt->execute([':exam_id' => $exam_id]);
$exam = $stmt->fetch(PDO::FETCH_ASSOC);

// 2. ดึงผลสอบทั้งหมดของชุดข้อสอบนี้ เรียงตามคะแนนจากมากไปน้อย (เพื่อแบ่งกลุ่ม Upper / Lower)
$res_stmt = $conn->prepare("SELECT id, score FROM student_results WHERE exam_id = :exam_id ORDER BY score DESC");
$res_stmt->execute([':exam_id' => $exam_id]);
$all_results = $res_stmt->fetchAll(PDO::FETCH_ASSOC);

$total_students = count($all_results);

// ฟังก์ชันแปลผลค่า p (ความยาก)
function interpretDifficulty($p) {
    if ($p >= 0.81) return ['text' => 'ง่ายมาก', 'class' => 'badge-danger', 'advice' => 'ควรปรับให้ยากขึ้นหรือตัดทิ้ง'];
    if ($p >= 0.60) return ['text' => 'ค่อนข้างง่าย', 'class' => 'badge-info', 'advice' => 'ใช้งานได้'];
    if ($p >= 0.40) return ['text' => 'ปานกลาง', 'class' => 'badge-success', 'advice' => 'ดีมาก ข้อสอบมีคุณภาพ'];
    if ($p >= 0.20) return ['text' => 'ค่อนข้างยาก', 'class' => 'badge-info', 'advice' => 'ใช้งานได้'];
    return ['text' => 'ยากมาก', 'class' => 'badge-danger', 'advice' => 'ควรปรับให้ง่ายขึ้นหรือตัดทิ้ง'];
}

// ฟังก์ชันแปลผลค่า D (อำนาจจำแนก)
function interpretDiscrimination($d) {
    if ($d >= 0.40) return ['text' => 'จำแนกได้ดีมาก', 'class' => 'badge-success'];
    if ($d >= 0.20) return ['text' => 'จำแนกได้พอใช้', 'class' => 'badge-info'];
    if ($d >= 0.00) return ['text' => 'จำแนกได้ต่ำ', 'class' => 'badge-warning'];
    return ['text' => 'จำแนกกลับทาง (มีปัญหา)', 'class' => 'badge-danger'];
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div id="content-wrapper" class="d-flex flex-column">
    <div id="content">
        <?php include 'includes/topbar.php'; ?>

        <div class="container-fluid">
            <div class="d-sm-flex align-items-center justify-content-between mb-4">
                <h1 class="h3 mb-0 text-gray-800"><i class="fas fa-microscope text-primary"></i> วิเคราะห์คุณภาพข้อสอบรายข้อ</h1>
                <a href="exam_results.php?exam_id=<?= $exam_id ?>" class="btn btn-sm btn-secondary shadow-sm">
                    <i class="fas fa-arrow-left"></i> กลับหน้ารายชื่อผู้เข้าสอบ
                </a>
            </div>

            <?php if ($total_students < 2): ?>
                <div class="alert alert-warning shadow-sm">
                    <i class="fas fa-exclamation-triangle"></i> ข้อมูลไม่เพียงพอสำหรับการวิเคราะห์ทางสถิติ (ต้องมีผู้ทำข้อสอบอย่างน้อย 2 คนขึ้นไป)
                </div>
            <?php else: 
                // แบ่งกลุ่มผู้สอบออกเป็น กลุ่มเก่ง (Upper) และ กลุ่มอ่อน (Lower) แบบครึ่งๆ (50%)
                $half = floor($total_students / 2);
                $upper_group = array_slice($all_results, 0, $half);
                $lower_group = array_slice($all_results, -$half); // เอาตัวท้ายสุดเท่าจำนวน half
                
                $upper_ids = array_column($upper_group, 'id');
                $lower_ids = array_column($lower_group, 'id');
            ?>
                
                <div class="card shadow mb-4 border-left-primary">
                    <div class="card-body">
                        <h5 class="font-weight-bold text-primary"><?= htmlspecialchars($exam['course_code']) ?>: <?= htmlspecialchars($exam['title']) ?></h5>
                        <p class="mb-0">จำนวนผู้เข้าสอบทั้งหมด: <strong><?= $total_students ?></strong> คน 
                        <br><small class="text-muted">ระบบคำนวณโดยแบ่งกลุ่มเก่ง (Upper) <?= $half ?> คน และกลุ่มอ่อน (Lower) <?= $half ?> คน ตามคะแนนรวม</small></p>
                    </div>
                </div>

                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-dark">ตารางวิเคราะห์ความยาก ($p$) และ อำนาจจำแนก ($D$) เฉพาะข้อปรนัยและถูก-ผิด</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover" width="100%" cellspacing="0">
                                <thead class="bg-light text-center">
                                    <tr>
                                        <th width="5%">ข้อที่</th>
                                        <th width="35%">คำถาม</th>
                                        <th width="10%">ตอบถูกทั้งหมด</th>
                                        <th width="15%">ค่าความยาก ($p$)</th>
                                        <th width="15%">ค่าอำนาจจำแนก ($D$)</th>
                                        <th width="20%">ข้อเสนอแนะระบบ AI</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    // 3. ดึงโจทย์เฉพาะ ปรนัย และ ถูก-ผิด มาวิเคราะห์ (อัตนัยวิเคราะห์ทางสถิติแบบ Dichotomous ไม่ได้)
                                    $q_stmt = $conn->prepare("SELECT q.id, q.question_text, q.question_type 
                                                              FROM exam_questions eq 
                                                              JOIN questions q ON eq.question_id = q.id 
                                                              WHERE eq.exam_id = :exam_id AND q.question_type != 'subjective' 
                                                              ORDER BY eq.id ASC");
                                    $q_stmt->execute([':exam_id' => $exam_id]);
                                    $questions = $q_stmt->fetchAll(PDO::FETCH_ASSOC);

                                    foreach ($questions as $index => $q):
                                        // หาจำนวนคนที่ตอบถูกทั้งหมดในข้อนี้
                                        $ans_stmt = $conn->prepare("SELECT result_id FROM student_answers WHERE question_id = :qid AND result_id IN (SELECT id FROM student_results WHERE exam_id = :exam_id) AND is_correct = 1");
                                        $ans_stmt->execute([':qid' => $q['id'], ':exam_id' => $exam_id]);
                                        $correct_results = $ans_stmt->fetchAll(PDO::FETCH_COLUMN);
                                        
                                        $total_correct = count($correct_results);
                                        
                                        // นับจำนวนตอบถูกในกลุ่ม Upper และ Lower
                                        $upper_correct = count(array_intersect($correct_results, $upper_ids));
                                        $lower_correct = count(array_intersect($correct_results, $lower_ids));

                                        // ----------------------------------------------------
                                        // การคำนวณทางสถิติ (Item Analysis Core Logic)
                                        // ----------------------------------------------------
                                        // 1. ค่าความยาก (p) = คนตอบถูกทั้งหมด / คนสอบทั้งหมด
                                        $p_value = $total_students > 0 ? ($total_correct / $total_students) : 0;
                                        
                                        // 2. ค่าอำนาจจำแนก (D) = (คนเก่งตอบถูก - คนอ่อนตอบถูก) / จำนวนคนกลุ่มใดกลุ่มหนึ่ง
                                        $d_value = $half > 0 ? (($upper_correct - $lower_correct) / $half) : 0;

                                        $p_res = interpretDifficulty($p_value);
                                        $d_res = interpretDiscrimination($d_value);
                                    ?>
                                    <tr>
                                        <td class="text-center align-middle font-weight-bold"><?= $index + 1 ?></td>
                                        <td class="small align-middle"><?= mb_strimwidth(htmlspecialchars($q['question_text']), 0, 100, '...') ?></td>
                                        <td class="text-center align-middle"><?= $total_correct ?> / <?= $total_students ?></td>
                                        <td class="text-center align-middle">
                                            <strong><?= number_format($p_value, 2) ?></strong><br>
                                            <span class="badge <?= $p_res['class'] ?> p-1 mt-1"><?= $p_res['text'] ?></span>
                                        </td>
                                        <td class="text-center align-middle">
                                            <strong><?= number_format($d_value, 2) ?></strong><br>
                                            <span class="badge <?= $d_res['class'] ?> p-1 mt-1"><?= $d_res['text'] ?></span>
                                        </td>
                                        <td class="text-center align-middle small text-dark font-weight-bold">
                                            <?php if ($d_value < 0): ?>
                                                <span class="text-danger"><i class="fas fa-times-circle"></i> ข้อสอบมีปัญหา ตรวจสอบเฉลย</span>
                                            <?php else: ?>
                                                <?= $p_res['advice'] ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php include 'includes/footer.php'; ?>