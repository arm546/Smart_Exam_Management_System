<?php
$page_title = 'ทำข้อสอบ';
require_once 'auth_guard.php';
require_role(['student']);
require_once 'config/db.php';

if (!isset($_GET['exam_id'])) {
    header("Location: join_exam.php");
    exit();
}

$exam_id = $_GET['exam_id'];
$student_id = $_SESSION['user_id'];
$current_time = date('Y-m-d H:i:s');

// 1. ตรวจสอบว่าเคยทำไปหรือยัง
$check_stmt = $conn->prepare("SELECT id FROM student_results WHERE exam_id = :exam_id AND student_id = :student_id");
$check_stmt->execute([':exam_id' => $exam_id, ':student_id' => $student_id]);
if ($check_stmt->rowCount() > 0) {
    echo "<script>alert('คุณได้ส่งข้อสอบชุดนี้ไปแล้ว ไม่สามารถทำซ้ำได้!'); window.location.href='index.php';</script>";
    exit();
}

// 2. ดึงข้อมูลชุดข้อสอบ
$stmt = $conn->prepare("SELECT * FROM exams WHERE id = :exam_id AND is_active = 1");
$stmt->execute([':exam_id' => $exam_id]);
$exam = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$exam) {
    echo "<script>alert('ไม่พบข้อสอบ หรือข้อสอบชุดนี้ถูกปิดรับคำตอบแล้ว'); window.location.href='join_exam.php';</script>";
    exit();
}

// ⏳ 3. ตรวจสอบเวลาเปิด-ปิดสอบ
if (!empty($exam['start_time']) && $current_time < $exam['start_time']) {
    $start_formatted = date('d/m/Y H:i', strtotime($exam['start_time']));
    echo "<script>alert('ยังไม่ถึงเวลาทำข้อสอบ\\nระบบจะเปิดให้เข้าสอบในเวลา: $start_formatted น.'); window.location.href='index.php';</script>";
    exit();
}
if (!empty($exam['end_time']) && $current_time > $exam['end_time']) {
    echo "<script>alert('หมดเวลาทำข้อสอบแล้ว! คุณไม่สามารถเข้าสอบชุดนี้ได้'); window.location.href='index.php';</script>";
    exit();
}

// ⏳ 4. จัดการระบบจับเวลาแบบป้องกันการรีเฟรชหน้าจอ (Session Timer)
$session_timer_key = 'exam_start_time_' . $exam_id;
if (!isset($_SESSION[$session_timer_key])) {
    // บันทึกเวลาเริ่มต้นเปิดข้อสอบครั้งแรก
    $_SESSION[$session_timer_key] = time(); 
}

$time_elapsed = time() - $_SESSION[$session_timer_key]; // เวลาที่ผ่านไปแล้ว (วินาที)
$time_limit_sec = ($exam['time_limit_minutes'] * 60) - $time_elapsed;

// ปรับลดเวลาหากเวลาใกล้จะถึง end_time ที่อาจารย์กำหนด
if (!empty($exam['end_time'])) {
    $seconds_to_end = strtotime($exam['end_time']) - time();
    if ($seconds_to_end > 0 && $seconds_to_end < $time_limit_sec) {
        $time_limit_sec = $seconds_to_end;
    }
}

// หากเวลาติดลบ (แปลว่าหมดเวลาแล้ว)
if ($time_limit_sec <= 0) {
    $time_limit_sec = 0;
}

// 🟢 5. ดึงโจทย์ทั้งหมด (แก้ไขใหม่ให้ดึงผ่านตารางเชื่อม exam_questions)
$q_sql = "SELECT q.*, eq.assigned_points as points 
          FROM questions q 
          JOIN exam_questions eq ON q.id = eq.question_id 
          WHERE eq.exam_id = :exam_id 
          ORDER BY eq.id ASC";
$q_stmt = $conn->prepare($q_sql);
$q_stmt->execute([':exam_id' => $exam_id]);
$questions = $q_stmt->fetchAll(PDO::FETCH_ASSOC);

// สลับลำดับข้อสอบถ้าระบบตั้งค่าไว้ 
if ($exam['shuffle_questions'] == 1) {
    shuffle($questions);
}

// 6. ประมวลผลคะแนนเมื่อกดส่ง (หรือถูกบังคับส่ง)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_exam'])) {
    $score = 0;
    $total_questions = count($questions);

    try {
        $conn->beginTransaction();

        $ins_score = $conn->prepare("INSERT INTO student_results (exam_id, student_id, score, total_questions) VALUES (:exam_id, :student_id, 0, :total_questions)");
        $time_taken = time() - $_SESSION[$session_timer_key];
        $ins_score->execute([':exam_id' => $exam_id, ':student_id' => $student_id, ':total_questions' => $total_questions]);
        $result_id = $conn->lastInsertId(); 

        $ins_ans = $conn->prepare("INSERT INTO student_answers (result_id, question_id, student_answer, is_correct, points_received) VALUES (:result_id, :question_id, :student_answer, :is_correct, :points_received)");

        // 🟢 ดึงเฉลยและคะแนนเพื่อตรวจ (แก้ไขให้ดึงคะแนนจากตารางเชื่อม)
        $check_q_sql = "SELECT q.id, q.question_type, q.correct_answer, eq.assigned_points as points 
                        FROM questions q 
                        JOIN exam_questions eq ON q.id = eq.question_id 
                        WHERE eq.exam_id = :exam_id";
        $check_q_stmt = $conn->prepare($check_q_sql);
        $check_q_stmt->execute([':exam_id' => $exam_id]);
        $db_questions = $check_q_stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($db_questions as $db_q) {
            $q_id = $db_q['id'];
            $ans_key = 'answer_' . $q_id;
            $student_ans_val = isset($_POST[$ans_key]) ? trim($_POST[$ans_key]) : '';
            $is_correct = null;
            $points_received = 0;

            // ตรวจเฉพาะ ปรนัย และ ถูก-ผิด
            if ($db_q['question_type'] !== 'subjective') {
                if ($student_ans_val === $db_q['correct_answer']) {
                    $is_correct = 1;
                    $points_received = (float)$db_q['points']; // ได้คะแนนตามที่ตั้งไว้ในชุดข้อสอบนั้นๆ
                    $score += $points_received;
                } else {
                    $is_correct = 0;
                }
            }

            $ins_ans->execute([
                ':result_id' => $result_id, 
                ':question_id' => $q_id, 
                ':student_answer' => $student_ans_val, 
                ':is_correct' => $is_correct,
                ':points_received' => $points_received
            ]);
        }

        // อัปเดตคะแนนรวม และเวลาที่ใช้
        $upd_score = $conn->prepare("UPDATE student_results SET score = :score, time_taken_seconds = :time_taken WHERE id = :result_id");
        $upd_score->execute([
            ':score' => $score, 
            ':time_taken' => $time_taken, 
            ':result_id' => $result_id
        ]);

        $conn->commit();

        // เคลียร์ Session เวลา และแจ้งเตือนเมื่อสอบเสร็จ
        unset($_SESSION[$session_timer_key]);
        echo "<script>
            localStorage.removeItem('exam_answers_{$exam_id}'); // ล้างคำตอบในเครื่อง
            alert('ส่งข้อสอบเรียบร้อยแล้ว!'); 
            window.location.href='index.php';
        </script>";
        exit();

    } catch (PDOException $e) {
        $conn->rollBack();
        echo "<script>alert('Error: " . $e->getMessage() . "');</script>";
    }
}

include 'includes/header.php';
?>

<div id="content-wrapper" class="d-flex flex-column w-100">
    <div id="content">
        <nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow sticky-top">
            <h5 class="m-0 font-weight-bold text-primary d-none d-md-block"><i class="fas fa-edit"></i> <?= htmlspecialchars($exam['course_code'] ?? '') ?>: <?= htmlspecialchars($exam['title']) ?></h5>
            <ul class="navbar-nav ml-auto">
                <li class="nav-item">
                    <div class="nav-link">
                        <span class="badge badge-danger p-2 shadow-sm" style="font-size: 1.2rem;">
                            <i class="fas fa-clock"></i> เวลาคงเหลือ: <span id="timer">--:--</span>
                        </span>
                    </div>
                </li>
            </ul>
        </nav>

        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-10">
                    
                    <div class="card shadow mb-4 border-left-warning">
                        <div class="card-body bg-light">
                            <div class="row">
                                <div class="col-md-8">
                                    <h5 class="font-weight-bold text-warning"><i class="fas fa-exclamation-triangle"></i> คำชี้แจงและข้อปฏิบัติ</h5>
                                    <p class="mb-0 text-dark"><?= !empty($exam['instructions']) ? nl2br(htmlspecialchars($exam['instructions'])) : 'โปรดอ่านและทำความเข้าใจโจทย์ให้ถี่ถ้วนก่อนตอบ เมื่อเวลาหมดระบบจะทำการส่งข้อสอบอัตโนมัติทันที' ?></p>
                                </div>
                                <div class="col-md-4 text-right">
                                    <span class="d-block text-muted small">รหัสวิชา: <?= htmlspecialchars($exam['course_code']) ?></span>
                                    <span class="d-block text-muted small">ห้องสอบ: <?= htmlspecialchars($exam['room_number'] ?? 'ไม่ระบุ') ?></span>
                                    <span class="d-block text-muted small">กลุ่มเรียน: <?= htmlspecialchars($exam['section'] ?? 'ไม่ระบุ') ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if($time_limit_sec <= 0): ?>
                        <div class="alert alert-danger text-center">
                            <h4 class="alert-heading">หมดเวลาการทำข้อสอบแล้ว!</h4>
                            <p>ระบบได้ทำการส่งกระดาษคำตอบของคุณไปเรียบร้อยแล้ว</p>
                            <a href="index.php" class="btn btn-primary mt-3">กลับสู่หน้าหลัก</a>
                        </div>
                    <?php else: ?>

                        <form action="take_exam.php?exam_id=<?= $exam_id ?>" method="POST" id="examForm">
                            <input type="hidden" name="submit_exam" value="1">
                            
                            <?php foreach($questions as $index => $q): ?>
                                <div class="card shadow mb-4">
                                    <div class="card-header py-3 d-flex justify-content-between align-items-center bg-gradient-light">
                                        <h6 class="m-0 font-weight-bold text-dark">
                                            ข้อที่ <?= $index + 1 ?>. 
                                            <span class="text-muted font-weight-normal text-xs">
                                                (<?= $q['question_type'] == 'multiple_choice' ? 'ปรนัย' : ($q['question_type'] == 'true_false' ? 'ถูก-ผิด' : 'อัตนัย') ?>)
                                            </span>
                                        </h6>
                                        <span class="badge badge-primary badge-pill"><?= (float)$q['points'] ?> คะแนน</span>
                                    </div>
                                    <div class="card-body">
                                        <p class="text-gray-900 mb-4" style="font-size: 1.1rem;"><?= nl2br(htmlspecialchars($q['question_text'])) ?></p>
                                        
                                        <?php if($q['question_type'] == 'multiple_choice'): ?>
                                            <?php 
                                                $options = ['A' => $q['option_a'], 'B' => $q['option_b'], 'C' => $q['option_c'], 'D' => $q['option_d']];
                                            ?>
                                            <?php foreach($options as $key => $val): ?>
                                                <div class="custom-control custom-radio mb-3">
                                                    <input type="radio" id="q_<?= $q['id'].$key ?>" name="answer_<?= $q['id'] ?>" class="custom-control-input answer-input" value="<?= $key ?>">
                                                    <label class="custom-control-label" for="q_<?= $q['id'].$key ?>"><?= htmlspecialchars($val) ?></label>
                                                </div>
                                            <?php endforeach; ?>

                                        <?php elseif($q['question_type'] == 'true_false'): ?>
                                            <div class="custom-control custom-radio mb-3">
                                                <input type="radio" id="q_<?= $q['id'] ?>_T" name="answer_<?= $q['id'] ?>" class="custom-control-input answer-input" value="True">
                                                <label class="custom-control-label" for="q_<?= $q['id'] ?>_T">ถูก (True)</label>
                                            </div>
                                            <div class="custom-control custom-radio mb-3">
                                                <input type="radio" id="q_<?= $q['id'] ?>_F" name="answer_<?= $q['id'] ?>" class="custom-control-input answer-input" value="False">
                                                <label class="custom-control-label" for="q_<?= $q['id'] ?>_F">ผิด (False)</label>
                                            </div>

                                        <?php elseif($q['question_type'] == 'subjective'): ?>
                                            <div class="form-group">
                                                <textarea class="form-control answer-input" name="answer_<?= $q['id'] ?>" rows="4" placeholder="พิมพ์คำตอบของคุณที่นี่..."></textarea>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <div class="text-center mb-5">
                                <button type="submit" id="submitBtn" class="btn btn-primary btn-lg px-5 shadow-lg" onclick="return confirm('คุณตรวจสอบคำตอบครบถ้วนแล้ว และยืนยันการส่งข้อสอบใช่หรือไม่?');">
                                    <i class="fas fa-paper-plane"></i> ส่งข้อสอบ
                                </button>
                            </div>
                        </form>

                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const examId = <?= $exam_id ?>;

// ==========================================
// 1. ระบบกู้คืนและบันทึกคำตอบ (Auto Save State)
// ==========================================
function saveAnswers() {
    let answers = {};
    const formData = new FormData(document.getElementById('examForm'));
    for (let [key, value] of formData.entries()) {
        if (key.startsWith('answer_')) {
            answers[key] = value;
        }
    }
    localStorage.setItem('exam_answers_' + examId, JSON.stringify(answers));
}

function loadSavedAnswers() {
    let saved = localStorage.getItem('exam_answers_' + examId);
    if (saved) {
        let answers = JSON.parse(saved);
        for (let key in answers) {
            let elements = document.getElementsByName(key);
            if (elements.length > 0) {
                if (elements[0].type === 'radio') {
                    elements.forEach(el => {
                        if (el.value === answers[key]) el.checked = true;
                    });
                } else if (elements[0].type === 'textarea') {
                    elements[0].value = answers[key];
                }
            }
        }
    }
}

// ผูก Event ให้ฟอร์มเซฟคำตอบทันทีที่มีการคลิกหรือพิมพ์
document.querySelectorAll('.answer-input').forEach(input => {
    input.addEventListener('change', saveAnswers);
    input.addEventListener('keyup', saveAnswers);
});

// โหลดคำตอบทันทีที่เปิดหน้าเว็บมา
window.addEventListener('load', loadSavedAnswers);


// ==========================================
// 2. ระบบนาฬิกาและบังคับส่ง (Bulletproof Auto-submit)
// ==========================================
let timeLeft = <?= $time_limit_sec ?>; 
const timerDisplay = document.getElementById('timer');
let isSubmitting = false;

function updateTimer() {
    if(timeLeft <= 0 && !isSubmitting) {
        isSubmitting = true; 
        clearInterval(timerInterval);
        
        const submitBtn = document.getElementById('submitBtn');
        if(submitBtn) {
            submitBtn.removeAttribute('onclick');
            localStorage.removeItem('exam_answers_' + examId);
            alert('หมดเวลาทำข้อสอบแล้ว! ระบบจะทำการบันทึกและส่งข้อสอบโดยอัตโนมัติ');
            submitBtn.click();
        }
        return;
    }

    let hours = Math.floor(timeLeft / 3600);
    let minutes = Math.floor((timeLeft % 3600) / 60);
    let seconds = timeLeft % 60;
    
    let displayStr = "";
    if (hours > 0) {
        displayStr += hours.toString().padStart(2, '0') + ":";
    }
    displayStr += minutes.toString().padStart(2, '0') + ":" + seconds.toString().padStart(2, '0');
    
    timerDisplay.innerHTML = displayStr;
    
    if (timeLeft <= 300) {
        timerDisplay.parentElement.classList.remove('badge-danger');
        timerDisplay.parentElement.classList.add('bg-danger', 'text-white');
    }
    
    timeLeft--;
}

if(document.getElementById('examForm')) {
    updateTimer(); 
    var timerInterval = setInterval(updateTimer, 1000);
}
</script>

<script src="vendor/jquery/jquery.min.js"></script>
<script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>