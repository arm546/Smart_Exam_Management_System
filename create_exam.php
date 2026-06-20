<?php
$page_title = 'สร้างชุดข้อสอบใหม่';
require_once 'auth_guard.php';
require_role(['teacher', 'admin']);
require_once 'config/db.php';

$alert_msg = "";

$teacher_id = $_SESSION['user_id']; // รับค่า session

// ดึงรายวิชาที่อาจารย์ท่านนี้มีสิทธิ์สอน
$stmt_my_courses = $conn->prepare("
    SELECT c.course_code, c.course_name 
    FROM courses c
    JOIN teacher_courses tc ON c.id = tc.course_id
    WHERE tc.teacher_id = :teacher_id AND c.is_active = 1
    ORDER BY c.course_code ASC
");
$stmt_my_courses->execute([':teacher_id' => $teacher_id]);
$my_courses = $stmt_my_courses->fetchAll(PDO::FETCH_ASSOC);

// เมื่อมีการกดปุ่ม "บันทึกข้อมูล"
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ข้อมูลพื้นฐาน
    $course_code = trim($_POST['course_code']);
    $title = trim($_POST['title']);
    $exam_pin = trim($_POST['exam_pin']);
    
    // รับค่าโหมดการจับเวลา (period = ช่วงเวลา, duration = จับเวลา)
    $time_mode = $_POST['time_mode'] ?? 'period';
    
    if ($time_mode === 'period') {
        $start_time = !empty($_POST['start_time']) ? $_POST['start_time'] : null;
        $end_time = !empty($_POST['end_time']) ? $_POST['end_time'] : null;
        
        // ให้ระบบคำนวณเวลา (นาที) อัตโนมัติจาก Start-End เพื่อเก็บลงฐานข้อมูลป้องกันบั๊กหน้าสอบ
        if ($start_time && $end_time) {
            $time_limit_minutes = round((strtotime($end_time) - strtotime($start_time)) / 60);
        } else {
            $time_limit_minutes = 60;
        }
    } else {
        // โหมดกำหนดเวลาสอบ (นาที)
        $start_time = null;
        $end_time = null;
        $time_limit_minutes = (int)$_POST['time_limit_minutes'];
    }

    $room_number = trim($_POST['room_number']);
    $section = trim($_POST['section']);
    $exam_set = trim($_POST['exam_set']);
    $shuffle_questions = isset($_POST['shuffle_questions']) ? (int)$_POST['shuffle_questions'] : 1;
    $instructions = trim($_POST['instructions']);
    
    $teacher_id = $_SESSION['user_id'];

    // --- ตรวจสอบความถูกต้องของเวลาตามโหมดที่เลือก ---
    $time_error = false;
    $current_time = date('Y-m-d H:i'); // เอาตัว T ออกให้รูปแบบตรงกับ Flatpickr

    if ($time_mode === 'period') {
        if (empty($start_time) || empty($end_time)) {
            $alert_msg = "<div class='alert alert-warning'><i class='fas fa-exclamation-triangle'></i> กรุณาระบุเวลาเริ่มต้นและสิ้นสุด</div>";
            $time_error = true;
        // ใช้ strtotime() แปลงเป็นตัวเลขเวลา (Timestamp) ก่อนเทียบกัน ชัวร์ 100%
        } elseif ($start_time && strtotime($start_time) < strtotime($current_time)) {
            $alert_msg = "<div class='alert alert-warning'><i class='fas fa-exclamation-triangle'></i> ไม่สามารถตั้งเวลาเริ่มต้นย้อนหลังได้</div>";
            $time_error = true;
        } elseif ($start_time && $end_time && strtotime($end_time) <= strtotime($start_time)) {
            $alert_msg = "<div class='alert alert-warning'><i class='fas fa-exclamation-triangle'></i> เวลาสิ้นสุดต้องอยู่หลังเวลาเริ่มต้น</div>";
            $time_error = true;
        }
    }
    // --- สิ้นสุดส่วนที่เพิ่มใหม่ ---

    // ถ้าไม่มี error เรื่องเวลา ค่อยทำการบันทึกลง Database
    if (!$time_error) {
        try {
            // บันทึกข้อมูลลงตาราง exams
            $sql = "INSERT INTO exams (teacher_id, course_code, title, exam_pin, start_time, end_time, time_limit_minutes, room_number, section, exam_set, shuffle_questions, instructions) 
                    VALUES (:teacher_id, :course_code, :title, :exam_pin, :start_time, :end_time, :time_limit_minutes, :room_number, :section, :exam_set, :shuffle_questions, :instructions)";
            $stmt = $conn->prepare($sql);
            
            $stmt->execute([
                ':teacher_id' => $teacher_id,
                ':course_code' => $course_code,
                ':title' => $title,
                ':exam_pin' => $exam_pin,
                ':start_time' => $start_time,
                ':end_time' => $end_time,
                ':time_limit_minutes' => $time_limit_minutes,
                ':room_number' => $room_number,
                ':section' => $section,
                ':exam_set' => $exam_set,
                ':shuffle_questions' => $shuffle_questions,
                ':instructions' => $instructions
            ]);

            $alert_msg = "<div class='alert alert-success'><i class='fas fa-check-circle'></i> สร้างชุดข้อสอบสำเร็จ! <a href='question_bank.php' class='alert-link'>ไปยังคลังข้อสอบเพื่อเพิ่มโจทย์</a></div>";
        } catch (PDOException $e) {
            $alert_msg = "<div class='alert alert-danger'><i class='fas fa-exclamation-triangle'></i> เกิดข้อผิดพลาด: " . $e->getMessage() . "</div>";
        }
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
                <h1 class="h3 mb-0 text-gray-800"><i class="fas fa-file-medical text-primary"></i> สร้างชุดข้อสอบใหม่</h1>
            </div>

            <?= $alert_msg ?>

            <div class="row justify-content-center">
                <div class="col-lg-10">
                    <form action="create_exam.php" method="POST">
                        <div class="card shadow mb-4 border-bottom-primary">
                            <div class="card-header py-3 bg-gradient-primary">
                                <h6 class="m-0 font-weight-bold text-white"><i class="fas fa-info-circle"></i> ข้อมูลพื้นฐานของการสอบ</h6>
                            </div>
                            <div class="card-body">
                                <div class="form-row">
                                    <div class="form-group col-md-3">
                                        <label for="course_code" class="font-weight-bold">รหัสวิชา <span class="text-danger">*</span></label>
                                        <select class="form-control" id="course_code" name="course_code" required>
                                            <option value="" disabled selected>-- เลือกรายวิชา --</option>
                                            <?php foreach($my_courses as $c): ?>
                                                <option value="<?= htmlspecialchars($c['course_code']) ?>">
                                                    <?= htmlspecialchars($c['course_code']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group col-md-6">
                                        <label for="title" class="font-weight-bold">ชื่อการสอบ / หัวข้อ <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="title" name="title" placeholder="เช่น สอบกลางภาค วิศวกรรมซอฟต์แวร์" required>
                                    </div>
                                    <div class="form-group col-md-3">
                                        <label for="exam_set" class="font-weight-bold">ชุดข้อสอบ (Set)</label>
                                        <select class="form-control" name="exam_set" id="exam_set">
                                            <option value="A">ชุด A</option>
                                            <option value="B">ชุด B</option>
                                            <option value="C">ชุด C</option>
                                            <option value="None">ไม่ระบุ</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group col-md-4">
                                        <label for="section" class="font-weight-bold">กลุ่มเรียน (Section)</label>
                                        <input type="text" class="form-control" id="section" name="section" placeholder="เช่น 01 หรือ เหมารวม">
                                    </div>
                                    <div class="form-group col-md-4">
                                        <label for="room_number" class="font-weight-bold">ห้องสอบ (Room)</label>
                                        <input type="text" class="form-control" id="room_number" name="room_number" placeholder="เช่น 2-425">
                                    </div>
                                    <div class="form-group col-md-4">
                                        <label for="exam_pin" class="font-weight-bold">รหัสเข้าสอบ (PIN) <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <input type="text" class="form-control text-primary font-weight-bold" id="exam_pin" name="exam_pin" maxlength="6" required readonly>
                                            <div class="input-group-append">
                                                <button class="btn btn-outline-secondary" type="button" onclick="generatePIN()"><i class="fas fa-sync-alt"></i></button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card shadow mb-4 border-bottom-success">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-success"><i class="fas fa-clock"></i> การตั้งค่าเวลาและเงื่อนไขการสอบ</h6>
                            </div>
                            <div class="card-body">
                                <div class="form-group mb-4 p-3 bg-light rounded border">
                                    <label class="font-weight-bold d-block mb-2">รูปแบบการกำหนดเวลาสอบ <span class="text-danger">*</span></label>
                                    <div class="custom-control custom-radio custom-control-inline">
                                        <input type="radio" id="timeModePeriod" name="time_mode" class="custom-control-input" value="period" checked onchange="toggleTimeMode()">
                                        <label class="custom-control-label" for="timeModePeriod">แบบกำหนดช่วงเวลา (ระบุเวลา เริ่มต้น-สิ้นสุด)</label>
                                    </div>
                                    <div class="custom-control custom-radio custom-control-inline">
                                        <input type="radio" id="timeModeDuration" name="time_mode" class="custom-control-input" value="duration" onchange="toggleTimeMode()">
                                        <label class="custom-control-label" for="timeModeDuration">แบบกำหนดระยะเวลาอิสระ (ให้เวลาทำกี่นาที)</label>
                                    </div>
                                </div>

                                <div class="form-row" id="periodInputs">
                                    <div class="form-group col-md-6">
                                        <label for="start_time" class="font-weight-bold">เวลาเริ่มต้น (Start Time) <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control bg-white" id="start_time" name="start_time" placeholder="คลิกเพื่อเลือกวันที่และเวลา" required readonly>
                                    </div>
                                    <div class="form-group col-md-6">
                                        <label for="end_time" class="font-weight-bold">เวลาสิ้นสุด (End Time) <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control bg-white" id="end_time" name="end_time" placeholder="คลิกเพื่อเลือกวันที่และเวลา" required readonly>
                                    </div>
                                </div>

                                <div class="form-row" id="durationInputs" style="display: none;">
                                    <div class="form-group col-md-6">
                                        <label for="time_limit_minutes" class="font-weight-bold">เวลาทำข้อสอบ (นาที) <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control" id="time_limit_minutes" name="time_limit_minutes" value="60" min="1">
                                        <small class="form-text text-muted"><i class="fas fa-info-circle"></i> ระบบจะเริ่มจับเวลาเมื่อนักศึกษากดเริ่มทำข้อสอบ</small>
                                    </div>
                                </div>

                                <div class="form-group mt-3">
                                    <label class="font-weight-bold">ระบบป้องกันการลอกเลียนแบบ</label>
                                    <div class="custom-control custom-switch">
                                        <input type="checkbox" class="custom-control-input" id="shuffleSwitch" name="shuffle_questions" value="1" checked>
                                        <label class="custom-control-label" for="shuffleSwitch">สลับลำดับข้อสอบ (Shuffle Questions)</label>
                                    </div>
                                </div>

                                <div class="form-group mt-3">
                                    <label for="instructions" class="font-weight-bold">คำชี้แจง / กฎการสอบ (Instructions)</label>
                                    <textarea class="form-control" id="instructions" name="instructions" rows="4" placeholder="เช่น 1. ห้ามใช้เครื่องคิดเลข 2. ทุจริตปรับตกทันที..."></textarea>
                                </div>

                                <hr>
                                <button type="submit" class="btn btn-primary btn-lg btn-block shadow"><i class="fas fa-save"></i> บันทึกและสร้างชุดข้อสอบ</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

        </div>
    </div>
<?php include 'includes/footer.php'; ?>

<script>
    function generatePIN() {
        const pin = Math.floor(100000 + Math.random() * 900000);
        document.getElementById('exam_pin').value = pin;
    }

    function toggleTimeMode() {
        const isPeriod = document.getElementById('timeModePeriod').checked;
        const periodInputs = document.getElementById('periodInputs');
        const durationInputs = document.getElementById('durationInputs');
        const startInput = document.getElementById('start_time');
        const endInput = document.getElementById('end_time');
        const durationInput = document.getElementById('time_limit_minutes');

        if (isPeriod) {
            periodInputs.style.display = 'flex';
            durationInputs.style.display = 'none';
            startInput.required = true;
            endInput.required = true;
            durationInput.required = false;
        } else {
            periodInputs.style.display = 'none';
            durationInputs.style.display = 'flex';
            startInput.required = false;
            endInput.required = false;
            durationInput.required = true;
        }
    }

    // แก้ไขฟังก์ชันนี้เพื่อใช้ Flatpickr
    let fpStart, fpEnd;
    function setupDateValidation() {
        fpStart = flatpickr("#start_time", {
            enableTime: true,
            dateFormat: "Y-m-d H:i",
            time_24hr: true, // บังคับ 24 ชม. ตัดปัญหา AM/PM
            minDate: "today",
            locale: "th",
            onChange: function(selectedDates, dateStr, instance) {
                // เมื่อเลือกวันเริ่มแล้ว ให้ไปจำกัดวันสิ้นสุดห้ามเลือกก่อนวันเริ่ม
                if (fpEnd) {
                    fpEnd.set("minDate", dateStr || "today");
                }
            }
        });

        fpEnd = flatpickr("#end_time", {
            enableTime: true,
            dateFormat: "Y-m-d H:i",
            time_24hr: true, // บังคับ 24 ชม.
            minDate: "today",
            locale: "th"
        });
    }

    window.onload = function() {
        generatePIN();
        setupDateValidation();
        toggleTimeMode();
    };
</script>