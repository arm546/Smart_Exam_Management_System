<?php
$page_title = 'จัดการโจทย์และคลังข้อสอบ';
require_once 'auth_guard.php';
require_role(['teacher', 'admin']);
require_once 'config/db.php';

if (!isset($_GET['exam_id'])) {
    header("Location: question_bank.php");
    exit();
}

$exam_id = $_GET['exam_id'];
$teacher_id = $_SESSION['user_id'];
$alert_msg = "";

// แจ้งเตือนเมื่อสุ่มดึงข้อสอบสำเร็จ
if (isset($_GET['bulk_success'])) {
    $count = (int)$_GET['bulk_success'];
    $alert_msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-magic'></i> ระบบได้ทำการสุ่มดึงโจทย์จากคลังเข้าสู่ชุดข้อสอบนี้สำเร็จจำนวน <b>$count</b> ข้อเรียบร้อยแล้ว!<button type='button' class='close' data-dismiss='alert'>&times;</button></div>";
}

// 1. ดึงข้อมูลชุดข้อสอบนี้มาแสดง
$stmt = $conn->prepare("SELECT * FROM exams WHERE id = :exam_id AND teacher_id = :teacher_id");
$stmt->execute([':exam_id' => $exam_id, ':teacher_id' => $teacher_id]);
$exam = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$exam) {
    echo "<script>alert('ไม่พบข้อมูลข้อสอบ หรือคุณไม่มีสิทธิ์เข้าถึงชุดข้อสอบนี้'); window.location.href='question_bank.php';</script>";
    exit();
}

// ---------------------------------------------------------
// 🆕 ระบบ Export โจทย์เป็นไฟล์ CSV (Excel)
// ---------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] == 'export_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="questions_' . $exam['course_code'] . '_' . date('Ymd_His') . '.csv"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // พิมพ์ BOM ให้ Excel อ่านไทยได้
    
    // หัวคอลัมน์
    fputcsv($output, ['หมวดหมู่', 'ความยาก (easy/medium/hard)', 'ประเภท (multiple_choice/true_false/subjective)', 'คะแนน', 'คำถาม', 'A', 'B', 'C', 'D', 'เฉลย']);
    
    // ดึงโจทย์ทั้งหมดในชุดนี้
    $ex_stmt = $conn->prepare("SELECT q.*, eq.assigned_points FROM questions q JOIN exam_questions eq ON q.id = eq.question_id WHERE eq.exam_id = ?");
    $ex_stmt->execute([$exam_id]);
    
    while ($row = $ex_stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['category'], 
            $row['difficulty'], 
            $row['question_type'], 
            $row['assigned_points'], 
            $row['question_text'], 
            $row['option_a'], 
            $row['option_b'], 
            $row['option_c'], 
            $row['option_d'], 
            $row['correct_answer']
        ]);
    }
    fclose($output);
    exit();
}

// 🔴 ระบบเอาโจทย์ออกจากชุดข้อสอบ
if (isset($_GET['remove_q_id'])) {
    $remove_q_id = $_GET['remove_q_id'];
    $del_stmt = $conn->prepare("DELETE FROM exam_questions WHERE question_id = :qid AND exam_id = :exam_id");
    if($del_stmt->execute([':qid' => $remove_q_id, ':exam_id' => $exam_id])) {
        echo "<script>window.location.href='manage_questions.php?exam_id=$exam_id';</script>";
        exit();
    }
}

// 🟢 ระบบจัดการ POST (เพิ่มโจทย์ใหม่, ดึงจากคลัง, สุ่มกลุ่ม, แก้ไข, Import CSV)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // --- 0. Import โจทย์จากไฟล์ CSV ---
    if ($_POST['action'] == 'import_csv') {
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
            $file = $_FILES['csv_file']['tmp_name'];
            $handle = fopen($file, "r");
            fgetcsv($handle, 1000, ","); // อ่านข้าม Header แถวแรก
            
            $success = 0; $fail = 0;
            
            try {
                $conn->beginTransaction();
                $ins_q = $conn->prepare("INSERT INTO questions (category, difficulty, question_type, question_text, option_a, option_b, option_c, option_d, correct_answer) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ins_eq = $conn->prepare("INSERT INTO exam_questions (exam_id, question_id, assigned_points) VALUES (?, ?, ?)");

                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    if(empty(array_filter($data))) continue; 
                    
                    $cat = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', trim($data[0])); 
                    if(empty($cat)) $cat = 'ทั่วไป';
                    
                    $diff = trim($data[1]) ?: 'medium';
                    $type = trim($data[2]) ?: 'multiple_choice';
                    $pts = (float)(trim($data[3]) ?: 1);
                    $text = trim($data[4]);
                    
                    if(empty($text)) { $fail++; continue; }
                    
                    $opt_a = !empty(trim($data[5])) ? trim($data[5]) : null;
                    $opt_b = !empty(trim($data[6])) ? trim($data[6]) : null;
                    $opt_c = !empty(trim($data[7])) ? trim($data[7]) : null;
                    $opt_d = !empty(trim($data[8])) ? trim($data[8]) : null;
                    $ans = trim($data[9]);

                    $ins_q->execute([$cat, $diff, $type, $text, $opt_a, $opt_b, $opt_c, $opt_d, $ans]);
                    $new_q_id = $conn->lastInsertId();
                    
                    $ins_eq->execute([$exam_id, $new_q_id, $pts]);
                    $success++;
                }
                $conn->commit();
                fclose($handle);
                $alert_msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-check-circle'></i> นำเข้าโจทย์ผ่าน CSV สำเร็จ <b>$success</b> ข้อ (ข้ามรายการผิดพลาด $fail ข้อ)<button type='button' class='close' data-dismiss='alert'>&times;</button></div>";
            } catch (Exception $e) {
                $conn->rollBack();
                $alert_msg = "<div class='alert alert-danger'><i class='fas fa-exclamation-triangle'></i> เกิดข้อผิดพลาดในการนำเข้า: " . $e->getMessage() . "</div>";
            }
        }
    }
    // --- 1. สร้างโจทย์ใหม่ (บันทึกเข้าคลัง + ผูกเข้าชุดข้อสอบนี้) ---
    elseif ($_POST['action'] == 'add_new_question') {
        $category = trim($_POST['category']);
        $difficulty = $_POST['difficulty'];
        $question_type = $_POST['question_type'];
        $question_text = trim($_POST['question_text']);
        $points = (float)$_POST['points'];
        
        $option_a = null; $option_b = null; $option_c = null; $option_d = null; $correct_answer = "";
        if ($question_type === 'multiple_choice') {
            $option_a = trim($_POST['option_a']); $option_b = trim($_POST['option_b']);
            $option_c = trim($_POST['option_c']); $option_d = trim($_POST['option_d']);
            $correct_answer = $_POST['correct_answer_mc'];
        } elseif ($question_type === 'true_false') {
            $correct_answer = $_POST['correct_answer_tf'];
        } elseif ($question_type === 'subjective') {
            $correct_answer = trim($_POST['correct_answer_sub']);
        }

        try {
            $conn->beginTransaction();
            $sql_q = "INSERT INTO questions (category, difficulty, question_type, question_text, option_a, option_b, option_c, option_d, correct_answer) VALUES (:cat, :diff, :qt, :qt_text, :oa, :ob, :oc, :od, :ans)";
            $ins_q = $conn->prepare($sql_q);
            $ins_q->execute([':cat'=>$category, ':diff'=>$difficulty, ':qt'=>$question_type, ':qt_text'=>$question_text, ':oa'=>$option_a, ':ob'=>$option_b, ':oc'=>$option_c, ':od'=>$option_d, ':ans'=>$correct_answer]);
            $new_question_id = $conn->lastInsertId();

            $sql_eq = "INSERT INTO exam_questions (exam_id, question_id, assigned_points) VALUES (:eid, :qid, :pts)";
            $ins_eq = $conn->prepare($sql_eq);
            $ins_eq->execute([':eid'=>$exam_id, ':qid'=>$new_question_id, ':pts'=>$points]);
            $conn->commit();
            $alert_msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-check-circle'></i> สร้างและเพิ่มโจทย์ลงชุดข้อสอบเรียบร้อย!<button type='button' class='close' data-dismiss='alert'>&times;</button></div>";
        } catch (PDOException $e) {
            $conn->rollBack();
            $alert_msg = "<div class='alert alert-danger'>เกิดข้อผิดพลาด: " . $e->getMessage() . "</div>";
        }
    } 
    // --- 2. ดึงโจทย์ที่มีอยู่แล้วจากคลังมาใช้งาน (ทีละข้อ) ---
    elseif ($_POST['action'] == 'add_from_bank') {
        $question_id = $_POST['question_id'];
        $points = (float)$_POST['points'];
        try {
            $sql_eq = "INSERT INTO exam_questions (exam_id, question_id, assigned_points) VALUES (:eid, :qid, :pts)";
            $ins_eq = $conn->prepare($sql_eq);
            $ins_eq->execute([':eid'=>$exam_id, ':qid'=>$question_id, ':pts'=>$points]);
            $alert_msg = "<div class='alert alert-success alert-dismissible fade show'><i class='fas fa-link'></i> ดึงโจทย์จากคลังมาใช้งานเรียบร้อย!<button type='button' class='close' data-dismiss='alert'>&times;</button></div>";
        } catch (PDOException $e) {
            $alert_msg = "<div class='alert alert-danger'>ข้อผิดพลาด (โจทย์อาจมีอยู่ในชุดนี้แล้ว): " . $e->getMessage() . "</div>";
        }
    }
    // --- 🆕 3. ฟีเจอร์ใหม่: ดึงโจทย์แบบสุ่มตามจำนวนที่กำหนด (Bulk Random Import) ---
    elseif ($_POST['action'] == 'add_bulk_random') {
        $qty = (int)$_POST['bulk_qty'];
        $diff_filter = $_POST['bulk_difficulty'];
        $pts = (float)$_POST['bulk_points'];
        
        if ($qty > 0) {
            try {
                $conn->beginTransaction();
                
                $bulk_query = "SELECT id FROM questions 
                               WHERE id NOT IN (SELECT question_id FROM exam_questions WHERE exam_id = :exam_id) 
                               AND category LIKE :course_code";
                
                if ($diff_filter !== 'all') {
                    $bulk_query .= " AND difficulty = :diff";
                }
                
                $bulk_query .= " ORDER BY RAND() LIMIT :limit";
                
                $stmt_bulk = $conn->prepare($bulk_query);
                $stmt_bulk->bindValue(':exam_id', $exam_id, PDO::PARAM_INT);
                $stmt_bulk->bindValue(':course_code', '%' . $exam['course_code'] . '%', PDO::PARAM_STR);
                if ($diff_filter !== 'all') {
                    $stmt_bulk->bindValue(':diff', $diff_filter, PDO::PARAM_STR);
                }
                $stmt_bulk->bindValue(':limit', $qty, PDO::PARAM_INT);
                $stmt_bulk->execute();
                
                $pulled_qs = $stmt_bulk->fetchAll(PDO::FETCH_ASSOC);
                
                $ins_eq = $conn->prepare("INSERT INTO exam_questions (exam_id, question_id, assigned_points) VALUES (:eid, :qid, :pts)");
                $actual_added = 0;
                foreach ($pulled_qs as $p_q) {
                    $ins_eq->execute([':eid' => $exam_id, ':qid' => $p_q['id'], ':pts' => $pts]);
                    $actual_added++;
                }
                
                $conn->commit();
                
                echo "<script>window.location.href='manage_questions.php?exam_id=$exam_id&bulk_success=$actual_added';</script>";
                exit();
            } catch (Exception $e) {
                $conn->rollBack();
                $alert_msg = "<div class='alert alert-danger'>เกิดข้อผิดพลาดในการดึงข้อสอบแบบกลุ่ม: " . $e->getMessage() . "</div>";
            }
        }
    }
    // --- 4. แก้ไขโจทย์ ---
    elseif ($_POST['action'] == 'edit_question') {
        $q_id = $_POST['question_id'];
        $category = trim($_POST['category']);
        $difficulty = $_POST['difficulty'];
        $question_type = $_POST['question_type'];
        $question_text = trim($_POST['question_text']);
        $points = (float)$_POST['points'];
        
        $option_a = null; $option_b = null; $option_c = null; $option_d = null; $correct_answer = "";
        if ($question_type === 'multiple_choice') {
            $option_a = trim($_POST['option_a']); $option_b = trim($_POST['option_b']);
            $option_c = trim($_POST['option_c']); $option_d = trim($_POST['option_d']);
            $correct_answer = $_POST['correct_answer_mc'];
        } elseif ($question_type === 'true_false') {
            $correct_answer = $_POST['correct_answer_tf'];
        } elseif ($question_type === 'subjective') {
            $correct_answer = trim($_POST['correct_answer_sub']);
        }

        try {
            $conn->beginTransaction();
            $upd_q = $conn->prepare("UPDATE questions SET category=:cat, difficulty=:diff, question_type=:qt, question_text=:q, option_a=:a, option_b=:b, option_c=:c, option_d=:d, correct_answer=:ans WHERE id=:id");
            $upd_q->execute([':cat'=>$category, ':diff'=>$difficulty, ':qt'=>$question_type, ':q'=>$question_text, ':a'=>$option_a, ':b'=>$option_b, ':c'=>$option_c, ':d'=>$option_d, ':ans'=>$correct_answer, ':id'=>$q_id]);
            
            $upd_eq = $conn->prepare("UPDATE exam_questions SET assigned_points=:pts WHERE exam_id=:eid AND question_id=:qid");
            $upd_eq->execute([':pts'=>$points, ':eid'=>$exam_id, ':qid'=>$q_id]);
            $conn->commit();
            $alert_msg = "<div class='alert alert-info alert-dismissible fade show'><i class='fas fa-check-circle'></i> บันทึกการแก้ไขเรียบร้อย!<button type='button' class='close' data-dismiss='alert'>&times;</button></div>";
        } catch (PDOException $e) {
            $conn->rollBack();
            $alert_msg = "<div class='alert alert-danger'>เกิดข้อผิดพลาด: " . $e->getMessage() . "</div>";
        }
    }
}

// ดึงรายการโจทย์เฉพาะที่ "ผูกอยู่กับชุดข้อสอบนี้" 
$q_stmt = $conn->prepare("SELECT q.*, eq.assigned_points as points FROM questions q JOIN exam_questions eq ON q.id = eq.question_id WHERE eq.exam_id = :exam_id ORDER BY eq.id ASC");
$q_stmt->execute([':exam_id' => $exam_id]);
$questions = $q_stmt->fetchAll(PDO::FETCH_ASSOC);

$total_exam_points = 0;
foreach($questions as $q) { $total_exam_points += (float)$q['points']; }

// 🟢 ดึงรายการโจทย์จากคลังกลาง + คำนวณค่า p-value (ความยากง่ายจากการสอบจริง)
$current_course = $exam['course_code'];
$bank_stmt = $conn->prepare("
    SELECT q.*, 
           (SELECT COUNT(*) FROM student_answers sa WHERE sa.question_id = q.id AND sa.is_correct IS NOT NULL) as total_ans,
           (SELECT COUNT(*) FROM student_answers sa WHERE sa.question_id = q.id AND sa.is_correct = 1) as correct_ans
    FROM questions q 
    WHERE q.id NOT IN (SELECT question_id FROM exam_questions WHERE exam_id = :exam_id) 
    AND q.category LIKE :course_code 
    ORDER BY q.difficulty ASC, q.id DESC
");
$bank_stmt->execute([':exam_id' => $exam_id, ':course_code' => '%' . $current_course . '%']);
$bank_questions = $bank_stmt->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div id="content-wrapper" class="d-flex flex-column">
    <div id="content">
        <?php include 'includes/topbar.php'; ?>

        <div class="container-fluid">
            <div class="d-sm-flex align-items-center justify-content-between mb-4">
                <h1 class="h3 mb-0 text-gray-800"><i class="fas fa-layer-group text-primary"></i> จัดการโจทย์ข้อสอบ</h1>
                <div>
                    <a href="manage_questions.php?exam_id=<?= $exam_id ?>&action=export_csv" class="btn btn-sm btn-info shadow-sm mr-2">
                        <i class="fas fa-file-excel fa-sm text-white-50"></i> ส่งออก Template/โจทย์ (CSV)
                    </a>
                    <button class="btn btn-sm btn-success shadow-sm mr-2" data-toggle="modal" data-target="#importCsvModal">
                        <i class="fas fa-file-import fa-sm text-white-50"></i> นำเข้าโจทย์ (CSV)
                    </button>
                    <a href="question_bank.php" class="d-none d-sm-inline-block btn btn-sm btn-secondary shadow-sm">
                        <i class="fas fa-arrow-left fa-sm text-white-50"></i> กลับไปหน้ารวมวิชา
                    </a>
                </div>
            </div>

            <div class="card shadow mb-4 border-left-info">
                <div class="card-body py-3 d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="m-0 font-weight-bold text-info"><?= htmlspecialchars($exam['course_code']) ?>: <?= htmlspecialchars($exam['title']) ?></h5>
                        <small class="text-muted"><i class="fas fa-clock"></i> ให้เวลาทำ <?= $exam['time_limit_minutes'] ?> นาที | ห้องสอบ: <?= htmlspecialchars($exam['room_number'] ?? 'ไม่ระบุ') ?></small>
                    </div>
                    <div class="text-right">
                        <span class="badge badge-primary p-2" style="font-size: 14px;">โจทย์ในชุดนี้: <?= count($questions) ?> ข้อ (รวม <?= $total_exam_points ?> คะแนน)</span>
                    </div>
                </div>
            </div>

            <?= $alert_msg ?>

            <div class="row">
                <div class="col-xl-4 col-lg-5">
                    <div class="card shadow mb-4 border-bottom-primary">
                        <div class="card-header py-3 bg-gradient-light">
                            <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-plus"></i> สร้างโจทย์ใหม่เข้าคลัง</h6>
                        </div>
                        <div class="card-body">
                            <form action="manage_questions.php?exam_id=<?= $exam_id ?>" method="POST">
                                <input type="hidden" name="action" value="add_new_question">
                                
                                <div class="form-row">
                                    <div class="form-group col-md-6">
                                        <label class="font-weight-bold">หมวดหมู่/บทเรียน</label>
                                        <input type="text" class="form-control" name="category" placeholder="เช่น บทที่ 1" required>
                                    </div>
                                    <div class="form-group col-md-6">
                                        <label class="font-weight-bold">ระดับความยาก</label>
                                        <select class="form-control" name="difficulty">
                                            <option value="easy">ง่าย (Easy)</option>
                                            <option value="medium" selected>ปานกลาง (Medium)</option>
                                            <option value="hard">ยาก (Hard)</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-row border-top pt-3 mt-1">
                                    <div class="form-group col-md-8">
                                        <label class="font-weight-bold">รูปแบบโจทย์</label>
                                        <select class="form-control" name="question_type" id="qTypeSelect" onchange="toggleQuestionFields('add')" required>
                                            <option value="multiple_choice">แบบเลือกตอบ (ปรนัย)</option>
                                            <option value="true_false">แบบถูก-ผิด</option>
                                            <option value="subjective">แบบอัตนัย (เขียนตอบ)</option>
                                        </select>
                                    </div>
                                    <div class="form-group col-md-4">
                                        <label class="font-weight-bold text-danger">คะแนน</label>
                                        <input type="number" step="0.25" min="0" class="form-control" name="points" value="1" required>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label>คำถาม / โจทย์</label>
                                    <textarea class="form-control" name="question_text" rows="3" required></textarea>
                                </div>
                                
                                <div id="mc_fields_add">
                                    <div class="form-group"><label>ตัวเลือก A</label><input type="text" class="form-control form-control-sm" name="option_a"></div>
                                    <div class="form-group"><label>ตัวเลือก B</label><input type="text" class="form-control form-control-sm" name="option_b"></div>
                                    <div class="form-group"><label>ตัวเลือก C</label><input type="text" class="form-control form-control-sm" name="option_c"></div>
                                    <div class="form-group"><label>ตัวเลือก D</label><input type="text" class="form-control form-control-sm" name="option_d"></div>
                                    <div class="form-group mt-3">
                                        <label class="font-weight-bold text-success">คำตอบที่ถูกต้อง (ปรนัย)</label>
                                        <select class="form-control" name="correct_answer_mc">
                                            <option value="" disabled selected>-- เลือกเฉลย --</option>
                                            <option value="A">ตัวเลือก A</option>
                                            <option value="B">ตัวเลือก B</option>
                                            <option value="C">ตัวเลือก C</option>
                                            <option value="D">ตัวเลือก D</option>
                                        </select>
                                    </div>
                                </div>

                                <div id="tf_fields_add" style="display:none;">
                                    <div class="form-group mt-3">
                                        <label class="font-weight-bold text-success">คำตอบที่ถูกต้อง (ถูก-ผิด)</label>
                                        <select class="form-control" name="correct_answer_tf">
                                            <option value="" disabled selected>-- เลือกเฉลย --</option>
                                            <option value="True">ถูก (True)</option>
                                            <option value="False">ผิด (False)</option>
                                        </select>
                                    </div>
                                </div>

                                <div id="sub_fields_add" style="display:none;">
                                    <div class="form-group mt-3">
                                        <label class="font-weight-bold text-success">แนวคำตอบอ้างอิง (สำหรับตรวจ)</label>
                                        <textarea class="form-control" name="correct_answer_sub" rows="4" placeholder="พิมพ์เกณฑ์หรือแนวคำตอบที่นี่..."></textarea>
                                    </div>
                                </div>
                                
                                <hr>
                                <button type="submit" class="btn btn-primary btn-block shadow-sm"><i class="fas fa-save"></i> บันทึกโจทย์</button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-xl-8 col-lg-7">
                    <div class="card shadow mb-4">
                        <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                            <h6 class="m-0 font-weight-bold text-primary">รายการโจทย์ที่ใช้ในวิชานี้</h6>
                            <button class="btn btn-success btn-sm shadow-sm" data-toggle="modal" data-target="#bankModal">
                                <i class="fas fa-search-plus"></i> ดึงโจทย์จากคลังข้อสอบ
                            </button>
                        </div>
                        <div class="card-body">
                            <?php if(count($questions) > 0): ?>
                                <?php foreach($questions as $index => $q): ?>
                                    <div class="mb-4 pb-3 border-bottom position-relative">
                                        <div class="position-absolute" style="top: 0; right: 0;">
                                            <button class="btn btn-warning btn-sm" data-toggle="modal" data-target="#editModal<?= $q['id'] ?>" title="แก้ไข"><i class="fas fa-edit"></i></button>
                                            <a href="manage_questions.php?exam_id=<?= $exam_id ?>&remove_q_id=<?= $q['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('ยืนยันการเอาโจทย์ข้อนี้ออกจากชุดข้อสอบ? (ข้อมูลยังอยู่ในคลัง)');" title="เอาออกจากชุดนี้"><i class="fas fa-minus-circle"></i></a>
                                        </div>

                                        <h6 class="font-weight-bold pr-5">
                                            ข้อที่ <?= $index + 1 ?>: 
                                            <span class="badge badge-danger"><?= $q['points'] ?> คะแนน</span>
                                            <span class="badge badge-dark"><?= htmlspecialchars($q['category'] ?? 'ทั่วไป') ?></span>
                                            
                                            <?php
                                                if($q['difficulty'] == 'easy') echo "<span class='badge badge-success'>ง่าย</span>";
                                                elseif($q['difficulty'] == 'hard') echo "<span class='badge badge-danger'>ยาก</span>";
                                                else echo "<span class='badge badge-warning text-dark'>ปานกลาง</span>";
                                            ?>

                                            <br><br><?= nl2br(htmlspecialchars($q['question_text'])) ?>
                                        </h6>
                                        
                                        <?php if($q['question_type'] == 'multiple_choice'): ?>
                                            <div class="row pl-3 mt-2 text-sm">
                                                <div class="col-md-6 mb-1 <?= $q['correct_answer'] == 'A' ? 'text-success font-weight-bold' : '' ?>">A. <?= htmlspecialchars($q['option_a']) ?> <?= $q['correct_answer'] == 'A' ? '<i class="fas fa-check-circle"></i>' : '' ?></div>
                                                <div class="col-md-6 mb-1 <?= $q['correct_answer'] == 'B' ? 'text-success font-weight-bold' : '' ?>">B. <?= htmlspecialchars($q['option_b']) ?> <?= $q['correct_answer'] == 'B' ? '<i class="fas fa-check-circle"></i>' : '' ?></div>
                                                <div class="col-md-6 mb-1 <?= $q['correct_answer'] == 'C' ? 'text-success font-weight-bold' : '' ?>">C. <?= htmlspecialchars($q['option_c']) ?> <?= $q['correct_answer'] == 'C' ? '<i class="fas fa-check-circle"></i>' : '' ?></div>
                                                <div class="col-md-6 mb-1 <?= $q['correct_answer'] == 'D' ? 'text-success font-weight-bold' : '' ?>">D. <?= htmlspecialchars($q['option_d']) ?> <?= $q['correct_answer'] == 'D' ? '<i class="fas fa-check-circle"></i>' : '' ?></div>
                                            </div>
                                        <?php elseif($q['question_type'] == 'true_false'): ?>
                                            <div class="pl-3 mt-2 font-weight-bold text-success text-sm">
                                                เฉลย: <?= $q['correct_answer'] == 'True' ? 'ถูก (True)' : 'ผิด (False)' ?> <i class="fas fa-check-circle"></i>
                                            </div>
                                        <?php else: ?>
                                            <div class="pl-3 mt-2 p-3 bg-light rounded text-success text-sm">
                                                <strong><i class="fas fa-check-circle"></i> แนวคำตอบอ้างอิง:</strong><br>
                                                <?= nl2br(htmlspecialchars($q['correct_answer'])) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="modal fade" id="editModal<?= $q['id'] ?>" tabindex="-1" role="dialog" aria-hidden="true">
                                        <div class="modal-dialog" role="document">
                                            <div class="modal-content">
                                                <div class="modal-header bg-warning text-dark">
                                                    <h5 class="modal-title font-weight-bold"><i class="fas fa-edit"></i> แก้ไขโจทย์ข้อ <?= $index + 1 ?></h5>
                                                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                                                </div>
                                                <form action="manage_questions.php?exam_id=<?= $exam_id ?>" method="POST">
                                                    <div class="modal-body">
                                                        <div class="alert alert-info small"><i class="fas fa-info-circle"></i> การแก้ไขข้อความโจทย์ จะส่งผลต่อทุกชุดข้อสอบที่ใช้โจทย์ข้อนี้อยู่ด้วย</div>
                                                        <input type="hidden" name="action" value="edit_question">
                                                        <input type="hidden" name="question_id" value="<?= $q['id'] ?>">
                                                        
                                                        <div class="form-row">
                                                            <div class="form-group col-md-6">
                                                                <label class="font-weight-bold">หมวดหมู่</label>
                                                                <input type="text" class="form-control" name="category" value="<?= htmlspecialchars($q['category'] ?? 'ทั่วไป') ?>" required>
                                                            </div>
                                                            <div class="form-group col-md-6">
                                                                <label class="font-weight-bold">ความยาก</label>
                                                                <select class="form-control" name="difficulty">
                                                                    <option value="easy" <?= $q['difficulty']=='easy'?'selected':'' ?>>ง่าย</option>
                                                                    <option value="medium" <?= $q['difficulty']=='medium'?'selected':'' ?>>ปานกลาง</option>
                                                                    <option value="hard" <?= $q['difficulty']=='hard'?'selected':'' ?>>ยาก</option>
                                                                </select>
                                                            </div>
                                                        </div>

                                                        <div class="form-row">
                                                            <div class="form-group col-md-8">
                                                                <label class="font-weight-bold">ประเภท</label>
                                                                <select class="form-control" name="question_type" id="qTypeSelect_<?= $q['id'] ?>" onchange="toggleQuestionFields('<?= $q['id'] ?>')" required>
                                                                    <option value="multiple_choice" <?= $q['question_type']=='multiple_choice'?'selected':'' ?>>แบบเลือกตอบ (ปรนัย)</option>
                                                                    <option value="true_false" <?= $q['question_type']=='true_false'?'selected':'' ?>>แบบถูก-ผิด</option>
                                                                    <option value="subjective" <?= $q['question_type']=='subjective'?'selected':'' ?>>แบบอัตนัย (เขียนตอบ)</option>
                                                                </select>
                                                            </div>
                                                            <div class="form-group col-md-4">
                                                                <label class="font-weight-bold text-danger">คะแนน</label>
                                                                <input type="number" step="0.25" min="0" class="form-control" name="points" value="<?= $q['points'] ?>" required>
                                                            </div>
                                                        </div>

                                                        <div class="form-group"><label>คำถาม / โจทย์</label><textarea class="form-control" name="question_text" rows="3" required><?= htmlspecialchars($q['question_text']) ?></textarea></div>
                                                        
                                                        <div id="mc_fields_<?= $q['id'] ?>" style="display: <?= $q['question_type']=='multiple_choice'?'block':'none' ?>;">
                                                            <div class="form-group"><label>A.</label><input type="text" class="form-control form-control-sm" name="option_a" value="<?= htmlspecialchars($q['option_a'] ?? '') ?>"></div>
                                                            <div class="form-group"><label>B.</label><input type="text" class="form-control form-control-sm" name="option_b" value="<?= htmlspecialchars($q['option_b'] ?? '') ?>"></div>
                                                            <div class="form-group"><label>C.</label><input type="text" class="form-control form-control-sm" name="option_c" value="<?= htmlspecialchars($q['option_c'] ?? '') ?>"></div>
                                                            <div class="form-group"><label>D.</label><input type="text" class="form-control form-control-sm" name="option_d" value="<?= htmlspecialchars($q['option_d'] ?? '') ?>"></div>
                                                            <div class="form-group mt-3"><label class="text-success font-weight-bold">เฉลย</label>
                                                                <select class="form-control" name="correct_answer_mc">
                                                                    <option value="A" <?= $q['correct_answer']=='A'?'selected':'' ?>>A</option>
                                                                    <option value="B" <?= $q['correct_answer']=='B'?'selected':'' ?>>B</option>
                                                                    <option value="C" <?= $q['correct_answer']=='C'?'selected':'' ?>>C</option>
                                                                    <option value="D" <?= $q['correct_answer']=='D'?'selected':'' ?>>D</option>
                                                                </select>
                                                            </div>
                                                        </div>

                                                        <div id="tf_fields_<?= $q['id'] ?>" style="display: <?= $q['question_type']=='true_false'?'block':'none' ?>;">
                                                            <div class="form-group mt-3"><label class="text-success font-weight-bold">เฉลย (ถูก-ผิด)</label>
                                                                <select class="form-control" name="correct_answer_tf">
                                                                    <option value="True" <?= $q['correct_answer']=='True'?'selected':'' ?>>ถูก (True)</option>
                                                                    <option value="False" <?= $q['correct_answer']=='False'?'selected':'' ?>>ผิด (False)</option>
                                                                </select>
                                                            </div>
                                                        </div>

                                                        <div id="sub_fields_<?= $q['id'] ?>" style="display: <?= $q['question_type']=='subjective'?'block':'none' ?>;">
                                                            <div class="form-group mt-3"><label class="text-success font-weight-bold">แนวคำตอบอ้างอิง</label>
                                                                <textarea class="form-control" name="correct_answer_sub" rows="4"><?= $q['question_type']=='subjective'?htmlspecialchars($q['correct_answer']):'' ?></textarea>
                                                            </div>
                                                        </div>

                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-dismiss="modal">ยกเลิก</button>
                                                        <button type="submit" class="btn btn-warning text-dark font-weight-bold"><i class="fas fa-save"></i> บันทึกการแก้ไข</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-5 text-muted">
                                    <i class="fas fa-clipboard-list fa-3x mb-3 text-gray-300"></i>
                                    <p>ยังไม่มีโจทย์ในชุดข้อสอบนี้<br>สามารถเพิ่มโจทย์ใหม่ หรือดึงจากคลังข้อสอบได้</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal fade" id="bankModal" tabindex="-1" role="dialog" aria-hidden="true">
                <div class="modal-dialog modal-xl" role="document">
                    <div class="modal-content">
                        <div class="modal-header bg-success text-white">
                            <h5 class="modal-title font-weight-bold"><i class="fas fa-database"></i> เลือกโจทย์จากคลังข้อสอบส่วนกลาง</h5>
                            <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                        </div>
                        <div class="modal-body bg-light">
                            
                            <div class="card mb-4 bg-white border-left-success shadow-sm">
                                <div class="card-body">
                                    <h6 class="font-weight-bold text-success mb-2"><i class="fas fa-magic"></i> ฟีเจอร์ขั้นสูง: ดึงโจทย์แบบระบุจำนวน (Bulk / สุ่มเป็นกลุ่ม)</h6>
                                    <form action="manage_questions.php?exam_id=<?= $exam_id ?>" method="POST" class="form-inline">
                                        <input type="hidden" name="action" value="add_bulk_random">
                                        
                                        <div class="form-group mr-3 mb-2">
                                            <div class="input-group input-group-sm">
                                                <div class="input-group-prepend"><span class="input-group-text bg-success text-white font-weight-bold">ต้องการดึงโจทย์สุ่ม</span></div>
                                                <input type="number" name="bulk_qty" class="form-control text-center font-weight-bold" style="width: 90px;" placeholder="เช่น 33" min="1" required>
                                                <div class="input-group-append"><span class="input-group-text font-weight-bold">ข้อ</span></div>
                                            </div>
                                        </div>

                                        <div class="form-group mr-3 mb-2">
                                            <select name="bulk_difficulty" class="form-control form-control-sm font-weight-bold text-dark">
                                                <option value="all">คละระดับความยาก (ทั้งหมดในคลัง)</option>
                                                <option value="easy">สุ่มเฉพาะข้อที่ตั้งค่าเป็น: ง่าย</option>
                                                <option value="medium">สุ่มเฉพาะข้อที่ตั้งค่าเป็น: ปานกลาง</option>
                                                <option value="hard">สุ่มเฉพาะข้อที่ตั้งค่าเป็น: ยาก</option>
                                            </select>
                                        </div>

                                        <div class="form-group mr-3 mb-2">
                                            <div class="input-group input-group-sm">
                                                <div class="input-group-prepend"><span class="input-group-text">ตั้งคะแนนข้อละ</span></div>
                                                <input type="number" name="bulk_points" class="form-control text-center font-weight-bold" style="width: 70px;" value="1" step="0.25" min="0">
                                                <div class="input-group-append"><span class="input-group-text">คะแนน</span></div>
                                            </div>
                                        </div>

                                        <button type="submit" class="btn btn-success btn-sm mb-2 font-weight-bold shadow-sm" onclick="return confirm('ระบบจะทำการสุ่มเลือกข้อสอบตามจำนวนและเงื่อนไขที่กำหนด ยืนยันการดึงข้อมูลกลุ่มใช่หรือไม่?')">
                                            <i class="fas fa-random"></i> สุ่มดึงข้อสอบเข้าชุดทันที
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-bordered bg-white table-hover" id="dataTableBank" width="100%">
                                    <thead class="thead-light text-center">
                                        <tr>
                                            <th width="15%">หมวดหมู่</th>
                                            <th width="22%">ระดับความยาก (ตั้งไว้ vs วิเคราะห์จริง)</th>
                                            <th width="45%">เนื้อหาโจทย์</th>
                                            <th width="18%">ดึงทีละข้อ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($bank_questions as $bq): 
                                            // 🆕 ประมวลผลและแปลงค่าความยากง่าย (p-value) จากประวัติการสอบของข้อนี้
                                            $total_ans = (int)$bq['total_ans'];
                                            $correct_ans = (int)$bq['correct_ans'];
                                            $p_badge = '<span class="badge badge-secondary shadow-sm" title="ข้อสอบนี้ยังไม่มีประวัติการส่งคำตอบในระบบ"><i class="fas fa-database"></i> ยังไม่มีสถิติสอบ</span>';
                                            
                                            if ($total_ans > 0) {
                                                $p_val = $correct_ans / $total_ans;
                                                $p_str = number_format($p_val, 2);
                                                if ($p_val >= 0.81) $p_badge = '<span class="badge badge-danger shadow-sm">สถิติ: ง่ายมาก (p='.$p_str.')</span>';
                                                elseif ($p_val >= 0.60) $p_badge = '<span class="badge badge-info shadow-sm">สถิติ: ค่อนข้างง่าย (p='.$p_str.')</span>';
                                                elseif ($p_val >= 0.40) $p_badge = '<span class="badge badge-success shadow-sm">สถิติ: ปานกลาง (p='.$p_str.')</span>';
                                                elseif ($p_val >= 0.20) $p_badge = '<span class="badge badge-info shadow-sm">สถิติ: ค่อนข้างยาก (p='.$p_str.')</span>';
                                                else $p_badge = '<span class="badge badge-danger shadow-sm">สถิติ: ยากมาก (p='.$p_str.')</span>';
                                            }
                                        ?>
                                        <tr>
                                            <td class="align-middle text-center"><span class="badge badge-dark"><?= htmlspecialchars($bq['category'] ?? 'ทั่วไป') ?></span></td>
                                            <td class="align-middle text-center">
                                                <?php
                                                    if($bq['difficulty'] == 'easy') echo "<span class='badge badge-success shadow-sm'>ง่าย (Easy)</span>";
                                                    elseif($bq['difficulty'] == 'hard') echo "<span class='badge badge-danger shadow-sm'>ยาก (Hard)</span>";
                                                    else echo "<span class='badge badge-warning text-dark shadow-sm'>ปานกลาง (Medium)</span>";
                                                ?>
                                                <div class="mt-2 small font-weight-bold text-dark"><?= $p_badge ?></div>
                                            </td>
                                            <td class="small align-middle">
                                                <div style="max-height: 90px; overflow-y: auto;" class="text-dark">
                                                    <strong>(<?= $bq['question_type'] == 'multiple_choice' ? 'ปรนัย' : ($bq['question_type'] == 'true_false' ? 'ถูก-ผิด' : 'อัตนัย') ?>)</strong><br>
                                                    <?= nl2br(htmlspecialchars($bq['question_text'])) ?>
                                                </div>
                                            </td>
                                            <td class="text-center align-middle">
                                                <form action="manage_questions.php?exam_id=<?= $exam_id ?>" method="POST" class="form-inline justify-content-center">
                                                    <input type="hidden" name="action" value="add_from_bank">
                                                    <input type="hidden" name="question_id" value="<?= $bq['id'] ?>">
                                                    <div class="input-group input-group-sm w-100">
                                                        <input type="number" name="points" class="form-control text-center font-weight-bold" value="1" step="0.25" min="0" style="max-width: 55px;">
                                                        <div class="input-group-append">
                                                            <button type="submit" class="btn btn-primary font-weight-bold shadow-sm"><i class="fas fa-plus"></i> เพิ่ม</button>
                                                        </div>
                                                    </div>
                                                </form>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="modal fade" id="importCsvModal" tabindex="-1" role="dialog" aria-hidden="true">
                <div class="modal-dialog modal-lg" role="document">
                    <div class="modal-content text-dark">
                        <form action="manage_questions.php?exam_id=<?= $exam_id ?>" method="POST" enctype="multipart/form-data">
                            <div class="modal-header bg-success text-white">
                                <h5 class="modal-title"><i class="fas fa-file-excel"></i> นำเข้าโจทย์ด้วยไฟล์ CSV</h5>
                                <button class="close text-white" type="button" data-dismiss="modal">×</button>
                            </div>
                            <div class="modal-body">
                                <input type="hidden" name="action" value="import_csv">
                                
                                <div class="form-group text-center border p-4 bg-light rounded">
                                    <label class="font-weight-bold h5">เลือกไฟล์ CSV (UTF-8)</label><br>
                                    <input type="file" name="csv_file" class="form-control-file d-inline-block w-auto mt-2" accept=".csv" required>
                                </div>
                                
                                <div class="alert alert-info small mb-0 mt-3">
                                    <strong><i class="fas fa-info-circle"></i> คำแนะนำคอลัมน์ (ต้องมีแถว Header และเรียงตามนี้):</strong>
                                    <ol class="mb-0 mt-1 pl-3">
                                        <li><strong>หมวดหมู่:</strong> เช่น บทที่ 1</li>
                                        <li><strong>ความยาก:</strong> พิมพ์ <code class="text-dark">easy</code>, <code class="text-dark">medium</code> หรือ <code class="text-dark">hard</code></li>
                                        <li><strong>ประเภทโจทย์:</strong> พิมพ์ <code class="text-dark">multiple_choice</code>, <code class="text-dark">true_false</code> หรือ <code class="text-dark">subjective</code></li>
                                        <li><strong>คะแนน:</strong> เช่น 1 หรือ 1.5</li>
                                        <li><strong>คำถาม:</strong> เนื้อหาโจทย์</li>
                                        <li><strong>A, B, C, D:</strong> ข้อความตัวเลือก (ถ้าเป็นถูก-ผิด หรืออัตนัย ให้เว้นว่างไว้)</li>
                                        <li><strong>เฉลย:</strong> พิมพ์ A, B, C, D (สำหรับปรนัย) หรือ True, False (สำหรับถูก-ผิด) หรือแนวคำตอบ (สำหรับอัตนัย)</li>
                                    </ol>
                                    <hr>
                                    <em>* แนะนำให้กดปุ่ม <b>"ส่งออก Template"</b> เพื่อนำไฟล์ไปแก้ไขแล้วอัปโหลดกลับเข้ามาครับ</em>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button class="btn btn-secondary" type="button" data-dismiss="modal">ยกเลิก</button>
                                <button type="submit" class="btn btn-success"><i class="fas fa-upload"></i> อัปโหลดโจทย์เข้าระบบ</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        </div>
    </div>

<?php include 'includes/footer.php'; ?>

<script>
function toggleQuestionFields(id) {
    const type = document.getElementById(id === 'add' ? 'qTypeSelect' : 'qTypeSelect_' + id).value;
    
    document.getElementById('mc_fields_' + id).style.display = 'none';
    document.getElementById('tf_fields_' + id).style.display = 'none';
    document.getElementById('sub_fields_' + id).style.display = 'none';
    
    if (type === 'multiple_choice') {
        document.getElementById('mc_fields_' + id).style.display = 'block';
    } else if (type === 'true_false') {
        document.getElementById('tf_fields_' + id).style.display = 'block';
    } else if (type === 'subjective') {
        document.getElementById('sub_fields_' + id).style.display = 'block';
    }
}

$(document).ready(function() {
    if ($('#dataTableBank').length) {
        $('#dataTableBank').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/th.json' },
            pageLength: 5,
            lengthMenu: [[5, 10, 25, -1], [5, 10, 25, "ทั้งหมด"]]
        });
    }
});
</script>