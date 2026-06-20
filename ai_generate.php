<?php
$page_title = 'AI ช่วยสร้างข้อสอบจาก PDF';
require_once 'auth_guard.php';
require_role(['teacher', 'admin']);
require_once 'config/db.php';
require 'vendor/autoload.php';

$teacher_id = $_SESSION['user_id'];
$alert_msg = "";
$GEMINI_API_KEY = "AIzaSyBWOE7lrYc_xB8cS0uezKO3YAvH2HGCjf4"; 

// ดึงรายวิชามาแสดงใน Dropdown
$stmt = $conn->prepare("SELECT id, course_code, title FROM exams WHERE teacher_id = :teacher_id ORDER BY id DESC");
$stmt->execute([':teacher_id' => $teacher_id]);
$exams = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ==========================================
// [STEP 3] ทำงานเมื่อกดยืนยันบันทึกลง DB
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_questions'])) {
    $exam_id = $_POST['exam_id'];
    $questions = $_POST['questions']; 

    $cat_stmt = $conn->prepare("SELECT course_code FROM exams WHERE id = ?");
    $cat_stmt->execute([$exam_id]);
    $course_code = $cat_stmt->fetchColumn();
    $ai_category = $course_code ? $course_code . " (AI Generated)" : "AI Generated";

    try {
        $conn->beginTransaction();
        $ins_q = $conn->prepare("INSERT INTO questions (category, difficulty, question_type, question_text, option_a, option_b, option_c, option_d, correct_answer) VALUES (:cat, :diff, 'multiple_choice', :q, :a, :b, :c, :d, :ans)");
        $ins_eq = $conn->prepare("INSERT INTO exam_questions (exam_id, question_id, assigned_points) VALUES (:exam_id, :qid, 1.00)");
        
        $success_count = 0;
        foreach ($questions as $q) {
            $ins_q->execute([
                ':cat' => $ai_category,
                ':diff' => $q['difficulty'],
                ':q' => trim($q['question']), 
                ':a' => trim($q['a']), 
                ':b' => trim($q['b']), 
                ':c' => trim($q['c']), 
                ':d' => trim($q['d']), 
                ':ans' => strtoupper(trim($q['answer']))
            ]);
            $new_q_id = $conn->lastInsertId();
            
            $ins_eq->execute([
                ':exam_id' => $exam_id,
                ':qid' => $new_q_id
            ]);
            $success_count++;
        }
        $conn->commit();
        $alert_msg = "<div class='alert alert-success'><i class='fas fa-check-circle'></i> บันทึกข้อสอบเข้าสู่ระบบสำเร็จจำนวน $success_count ข้อ! <a href='manage_questions.php?exam_id=$exam_id' class='font-weight-bold alert-link'>คลิกดูข้อสอบที่นี่</a></div>";
    } catch (Exception $e) {
        $conn->rollBack();
        $alert_msg = "<div class='alert alert-danger'>เกิดข้อผิดพลาดในการบันทึก: " . $e->getMessage() . "</div>";
    }
}

$show_preview = false;
$preview_questions = [];

// ==========================================
// [STEP 2.5] ทำงานเมื่ออาจารย์สั่ง AI "ปรับแก้ข้อสอบชุดนี้" ในหน้า Preview
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['refine_ai'])) {
    $exam_id = $_POST['exam_id'];
    $difficulty = $_POST['difficulty'] ?? 'medium';
    $refine_instruction = trim($_POST['refine_instruction']);
    $current_questions = $_POST['questions']; // ข้อสอบที่แสดงอยู่บนหน้าเว็บปัจจุบัน

    if (!empty($refine_instruction)) {
        // ให้ AI อ่านข้อสอบชุดเดิม แล้วแก้ตามคำสั่งใหม่
        $prompt = "คุณคืออาจารย์ผู้เชี่ยวชาญ นี่คือชุดข้อสอบปัจจุบัน (รูปแบบ JSON):\n";
        $prompt .= json_encode($current_questions, JSON_UNESCAPED_UNICODE) . "\n\n";
        $prompt .= "คำสั่งเพิ่มเติมจากอาจารย์: " . $refine_instruction . "\n";
        $prompt .= "จงปรับปรุงข้อสอบชุดนี้ตามคำสั่งอย่างเคร่งครัด และคืนค่ากลับมาเป็น JSON Array โครงสร้างเดิม\n";
        
        $request_data = [
            "contents" => [["parts" => [["text" => $prompt]]]],
            "generationConfig" => [
                "responseMimeType" => "application/json",
                "responseSchema" => [
                    "type" => "ARRAY",
                    "items" => [
                        "type" => "OBJECT",
                        "properties" => [
                            "question" => ["type" => "STRING"],
                            "a" => ["type" => "STRING"],
                            "b" => ["type" => "STRING"],
                            "c" => ["type" => "STRING"],
                            "d" => ["type" => "STRING"],
                            "answer" => ["type" => "STRING"]
                        ],
                        "required" => ["question", "a", "b", "c", "d", "answer"]
                    ]
                ]
            ]
        ];

        $json_payload = json_encode($request_data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
        $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent?key=" . $GEMINI_API_KEY);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Content-Length: ' . strlen($json_payload)]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_payload);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60); 
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code == 200) {
            $result = json_decode($response, true);
            $ai_text = $result['candidates'][0]['content']['parts'][0]['text'];
            $preview_questions = json_decode($ai_text, true);
            $show_preview = true;
            $alert_msg = "<div class='alert alert-info'><i class='fas fa-info-circle'></i> AI ทำการปรับแก้ข้อสอบตามคำสั่งของคุณเรียบร้อยแล้ว ตรวจสอบด้านล่างได้เลยครับ</div>";
        } else {
            $alert_msg = "<div class='alert alert-danger'>เกิดข้อผิดพลาดในการปรับแก้: HTTP $http_code</div>";
            $preview_questions = $current_questions; // ถ้ายิงพัง ให้โชว์ชุดเดิม
            $show_preview = true;
        }
    } else {
        $preview_questions = $current_questions;
        $show_preview = true;
    }
}

// ==========================================
// [STEP 2] ทำงานเมื่อกดปุ่มเจน AI ครั้งแรก
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_ai'])) {
    $exam_id = $_POST['exam_id'];
    $num_questions = (int)$_POST['num_questions'];
    $difficulty = $_POST['difficulty'];
    $custom_prompt = trim($_POST['custom_prompt']);
    $content_text = "";

    if (isset($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] == 0) {
        $pdf_path = $_FILES['pdf_file']['tmp_name'];
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($pdf_path);
            $content_text = $pdf->getText();
        } catch (Exception $e) {
            $alert_msg = "<div class='alert alert-danger'>ไม่สามารถอ่านไฟล์ PDF ได้: " . $e->getMessage() . "</div>";
        }
    } else {
        $content_text = trim($_POST['content_text']);
    }

    if (!empty($content_text)) {
        $content_text = mb_substr($content_text, 0, 100000);
        $prompt = "คุณคืออาจารย์มหาวิทยาลัยผู้เชี่ยวชาญ จงอ่านเนื้อหาต่อไปนี้และสร้างโจทย์ข้อสอบแบบปรนัย 4 ตัวเลือก จำนวน $num_questions ข้อ เป็นภาษาไทย\n";
        $prompt .= "ระดับความยากของข้อสอบ: " . ($difficulty == 'easy' ? 'ง่าย' : ($difficulty == 'hard' ? 'ยาก วิเคราะห์ซับซ้อน' : 'ปานกลาง')) . "\n";
        
        if(!empty($custom_prompt)){
            $prompt .= "คำสั่งเพิ่มเติมพิเศษจากอาจารย์: $custom_prompt\n";
        }
        
        $prompt .= "เนื้อหาบทเรียน:\n" . $content_text;
        $prompt = mb_convert_encoding($prompt, 'UTF-8', 'UTF-8');

        $request_data = [
            "contents" => [["parts" => [["text" => $prompt]]]],
            "generationConfig" => [
                "responseMimeType" => "application/json",
                "responseSchema" => [
                    "type" => "ARRAY",
                    "items" => [
                        "type" => "OBJECT",
                        "properties" => [
                            "question" => ["type" => "STRING"],
                            "a" => ["type" => "STRING"],
                            "b" => ["type" => "STRING"],
                            "c" => ["type" => "STRING"],
                            "d" => ["type" => "STRING"],
                            "answer" => ["type" => "STRING"]
                        ],
                        "required" => ["question", "a", "b", "c", "d", "answer"]
                    ]
                ]
            ]
        ];

        $json_payload = json_encode($request_data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
        $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent?key=" . $GEMINI_API_KEY);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Content-Length: ' . strlen($json_payload)]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_payload);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60); 
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code == 200) {
            $result = json_decode($response, true);
            $ai_text = $result['candidates'][0]['content']['parts'][0]['text'];
            $questions_array = json_decode($ai_text, true);

            if (is_array($questions_array)) {
                $show_preview = true;
                $preview_questions = $questions_array;
                $_POST['difficulty'] = $difficulty; // ส่งความยากไปหน้า preview
            } else {
                $alert_msg = "<div class='alert alert-warning'>AI ประมวลผลสำเร็จ แต่อ่านรูปแบบข้อมูลไม่ได้ ลองอีกครั้ง</div>";
            }
        } else {
            $alert_msg = "<div class='alert alert-danger'><strong>API Error: HTTP $http_code</strong></div>";
        }
    } else {
        $alert_msg = "<div class='alert alert-warning'>กรุณาอัปโหลด PDF หรือใส่เนื้อหาข้อความ</div>";
    }
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div id="content-wrapper" class="d-flex flex-column">
    <div id="content">
        <?php include 'includes/topbar.php'; ?>
        <div class="container-fluid">
            <h1 class="h3 mb-4 text-gray-800"><i class="fas fa-robot text-primary"></i> AI Generator (PDF Support)</h1>
            <?= $alert_msg ?>

            <?php if ($show_preview): // [หน้าต่าง Preview] ?>
                
                <div class="card shadow mb-4 border-left-success">
                    <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                        <h6 class="m-0 font-weight-bold text-success"><i class="fas fa-edit"></i> ตรวจสอบและแก้ไขข้อสอบก่อนบันทึก</h6>
                    </div>
                    <div class="card-body bg-light">
                        <form action="ai_generate.php" method="POST" id="previewForm">
                            <input type="hidden" name="exam_id" value="<?= htmlspecialchars($_POST['exam_id']) ?>">
                            <input type="hidden" name="difficulty" value="<?= htmlspecialchars($_POST['difficulty'] ?? 'medium') ?>">
                            
                            <?php foreach ($preview_questions as $index => $q): ?>
                                <div class="card mb-4 shadow-sm">
                                    <div class="card-body">
                                        <h5 class="font-weight-bold text-dark">ข้อที่ <?= $index + 1 ?></h5>
                                        <input type="hidden" name="questions[<?= $index ?>][difficulty]" value="<?= htmlspecialchars($_POST['difficulty'] ?? 'medium') ?>">
                                        
                                        <div class="form-group">
                                            <label>โจทย์คำถาม</label>
                                            <textarea name="questions[<?= $index ?>][question]" class="form-control" rows="2" required><?= htmlspecialchars($q['question'] ?? '') ?></textarea>
                                        </div>
                                        
                                        <div class="row">
                                            <div class="col-md-6 form-group">
                                                <div class="input-group">
                                                    <div class="input-group-prepend"><span class="input-group-text">A</span></div>
                                                    <input type="text" name="questions[<?= $index ?>][a]" class="form-control" value="<?= htmlspecialchars($q['a'] ?? '') ?>" required>
                                                </div>
                                            </div>
                                            <div class="col-md-6 form-group">
                                                <div class="input-group">
                                                    <div class="input-group-prepend"><span class="input-group-text">B</span></div>
                                                    <input type="text" name="questions[<?= $index ?>][b]" class="form-control" value="<?= htmlspecialchars($q['b'] ?? '') ?>" required>
                                                </div>
                                            </div>
                                            <div class="col-md-6 form-group">
                                                <div class="input-group">
                                                    <div class="input-group-prepend"><span class="input-group-text">C</span></div>
                                                    <input type="text" name="questions[<?= $index ?>][c]" class="form-control" value="<?= htmlspecialchars($q['c'] ?? '') ?>" required>
                                                </div>
                                            </div>
                                            <div class="col-md-6 form-group">
                                                <div class="input-group">
                                                    <div class="input-group-prepend"><span class="input-group-text">D</span></div>
                                                    <input type="text" name="questions[<?= $index ?>][d]" class="form-control" value="<?= htmlspecialchars($q['d'] ?? '') ?>" required>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="form-group w-50">
                                            <label class="font-weight-bold text-success">เฉลยที่ถูกต้อง</label>
                                            <select name="questions[<?= $index ?>][answer]" class="form-control font-weight-bold">
                                                <option value="A" <?= (isset($q['answer']) && strtoupper($q['answer']) == 'A') ? 'selected' : '' ?>>ตัวเลือก A</option>
                                                <option value="B" <?= (isset($q['answer']) && strtoupper($q['answer']) == 'B') ? 'selected' : '' ?>>ตัวเลือก B</option>
                                                <option value="C" <?= (isset($q['answer']) && strtoupper($q['answer']) == 'C') ? 'selected' : '' ?>>ตัวเลือก C</option>
                                                <option value="D" <?= (isset($q['answer']) && strtoupper($q['answer']) == 'D') ? 'selected' : '' ?>>ตัวเลือก D</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                            <hr>
                            <div class="card bg-gradient-info text-white shadow mb-4">
                                <div class="card-body">
                                    <h5 class="font-weight-bold"><i class="fas fa-magic"></i> สั่ง AI ปรับปรุงข้อสอบชุดนี้</h5>
                                    <p class="mb-2">หากต้องการให้แก้โจทย์บางข้อ หรือเปลี่ยนแนวทางใหม่ พิมพ์สั่งในกล่องนี้ได้เลยครับ</p>
                                    <div class="input-group">
                                        <input type="text" name="refine_instruction" class="form-control" placeholder="เช่น ขอให้ข้อ 1-2 ยากขึ้น, เปลี่ยนเฉลยข้อ 3 เป็น ก, ไม่เอาโจทย์คำนวณ">
                                        <div class="input-group-append">
                                            <button type="submit" name="refine_ai" class="btn btn-dark" onclick="showLoadingOverlay()"><i class="fas fa-sync"></i> ให้ AI ปรับปรุงใหม่</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <hr>
                            
                            <button type="submit" name="save_questions" class="btn btn-success btn-lg btn-block shadow" onclick="showLoadingOverlay()"><i class="fas fa-save"></i> ยืนยันข้อมูลและบันทึกลงระบบ</button>
                            <a href="ai_generate.php" class="btn btn-secondary btn-block mt-2">ยกเลิกและเริ่มใหม่</a>
                        </form>
                    </div>
                </div>

            <?php else: // [หน้าต่างตั้งค่าก่อนเจเนอเรต] ?>

                <div class="row">
                    <div class="col-lg-8">
                        <div class="card shadow mb-4 border-bottom-primary">
                            <div class="card-body">
                                <form action="ai_generate.php" method="POST" enctype="multipart/form-data" id="aiGenForm">
                                    <input type="hidden" name="generate_ai" value="1">
                                    
                                    <div class="form-row">
                                        <div class="form-group col-md-6">
                                            <label class="font-weight-bold">ชุดข้อสอบปลายทาง</label>
                                            <select name="exam_id" class="form-control searchable-select" style="width: 100%;" data-placeholder="-- คลิกที่นี่เพื่อเลือก หรือ พิมพ์ค้นหา --" required>
                                                <option value="" selected></option>
                                                <?php foreach($exams as $ex): ?>
                                                    <option value="<?= $ex['id'] ?>"><?= htmlspecialchars($ex['course_code']) ?>: <?= htmlspecialchars($ex['title']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group col-md-3">
                                            <label class="font-weight-bold">จำนวนข้อ</label>
                                            <input type="number" name="num_questions" class="form-control" value="5" min="1" max="50" required>
                                        </div>
                                        <div class="form-group col-md-3">
                                            <label class="font-weight-bold">ระดับความยาก</label>
                                            <select name="difficulty" class="form-control">
                                                <option value="easy">ง่าย (ความจำ)</option>
                                                <option value="medium" selected>ปานกลาง (ประยุกต์)</option>
                                                <option value="hard">ยาก (วิเคราะห์)</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="font-weight-bold text-info"><i class="fas fa-comment-dots"></i> คำสั่งเพิ่มเติมให้ AI (Optional)</label>
                                        <input type="text" name="custom_prompt" class="form-control" placeholder="เช่น เน้นออกสอบเรื่องระบบฐานข้อมูล, ไม่เอาโจทย์คำนวณ ฯลฯ">
                                    </div>

                                    <hr>

                                    <div class="form-group">
                                        <label class="font-weight-bold">อัปโหลดไฟล์สื่อการสอน (PDF)</label>
                                        <input type="file" name="pdf_file" class="form-control-file" accept=".pdf">
                                    </div>
                                    <div class="form-group">
                                        <label class="font-weight-bold">หรือ วางเนื้อหาข้อความ</label>
                                        <textarea name="content_text" class="form-control" rows="4"></textarea>
                                    </div>

                                    <button type="submit" class="btn btn-primary btn-block btn-lg shadow" onclick="showLoadingOverlay()">
                                        <i class="fas fa-magic"></i> เริ่มวิเคราะห์และสร้างข้อสอบ (AI)
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

            <?php endif; ?>
        </div>
    </div>

    <div id="loadingOverlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:9999; flex-direction:column; justify-content:center; align-items:center;">
        <div class="spinner-border text-light" style="width: 4rem; height: 4rem;" role="status"></div>
        <h4 class="text-light mt-3" id="loadingText">ระบบกำลังประมวลผล...</h4>
        <p class="text-light">กรุณารอสักครู่ อย่าเพิ่งปิดหน้าต่างนี้</p>
    </div>

    <script>
    function showLoadingOverlay() {
        document.getElementById('loadingOverlay').style.display = 'flex';
    }
    </script>

<?php include 'includes/footer.php'; ?>