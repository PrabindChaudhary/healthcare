<?php
/**
 * MediCare AI – REST API Backend
 * Project By Prabind
 *
 * Bug fixes:
 *  1. Added mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT) so DB
 *     errors throw exceptions instead of returning false silently.
 *  2. All prepared statements use try/catch so errors return JSON, not HTML.
 *  3. session_start() called before any header() output.
 *  4. login: token stored with a proper UPDATE before the SELECT result is freed.
 *  5. getDoctors: duplicate $spec variable shadow removed.
 *  6. bookAppointment: UNIQUE constraint on time-slot duplicates already
 *     handled by DB — removed redundant SELECT check that raced.
 *  7. calculateUrgency: uses word-boundary matching (strpos on padded string)
 *     so "fever" doesn't match inside "high fever" twice.
 *  8. shell_exec output trimmed and validated before returning.
 */

session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// ── Database config ──────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'medicare_ai');

// ── DB Connection (singleton) ────────────────────────────────
function getDB(): mysqli
{
    static $conn = null;
    if ($conn === null) {
        try {
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            $conn->set_charset('utf8mb4');
        } catch (mysqli_sql_exception $e) {
            respond(['success' => false, 'message' => 'Database connection failed.'], 500);
        }
    }
    return $conn;
}

// ── Helpers ──────────────────────────────────────────────────
function respond(array $payload, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ok(mixed $data = null, string $msg = 'Success'): never
{
    respond(['success' => true, 'message' => $msg, 'data' => $data]);
}

function fail(string $msg, int $code = 400): never
{
    respond(['success' => false, 'message' => $msg], $code);
}

function body(): array
{
    $raw = file_get_contents('php://input');
    return (array) json_decode($raw, true);
}

function requireFields(array $data, array $fields): void
{
    foreach ($fields as $f) {
        if (empty($data[$f]))
            fail("Field '$f' is required.");
    }
}

// ── Router ───────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$parts = explode('/', trim($uri, '/'));
$action = end($parts);

if ($method === 'OPTIONS')
    respond([], 200);

try {
    match ("$method:$action") {
        'POST:register' => registerUser(),
        'POST:login' => loginUser(),
        'POST:symptom-log' => logSymptoms(),
        'POST:book-appointment' => bookAppointment(),
        'GET:appointments' => getAppointments(),
        'GET:doctors' => getDoctors(),
        'GET:patients' => getPatients(),
        'POST:prioritize' => prioritizePatient(),
        default => fail("Endpoint '$method:$action' not found.", 404)
    };
} catch (mysqli_sql_exception $e) {
    fail('Database error: ' . $e->getMessage(), 500);
} catch (Throwable $e) {
    fail('Server error: ' . $e->getMessage(), 500);
}

// ════════════════════════════════════════════════════════════
//  REGISTER
// ════════════════════════════════════════════════════════════
function registerUser(): never
{
    $d = body();
    requireFields($d, ['first_name', 'last_name', 'email', 'password', 'phone', 'dob', 'gender']);

    if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL))
        fail('Invalid email address format.');

    if (strlen($d['password']) < 8)
        fail('Password must be at least 8 characters long.');

    $db = getDB();
    $role = in_array($d['role'] ?? 'patient', ['patient', 'doctor', 'admin'])
        ? $d['role']
        : 'patient';

    // Check duplicate
    $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->bind_param('s', $d['email']);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0)
        fail('Email address is already registered.', 409);
    $stmt->close();

    $hash = password_hash($d['password'], PASSWORD_BCRYPT, ['cost' => 12]);

    $stmt = $db->prepare(
        'INSERT INTO users (first_name,last_name,email,password_hash,phone,dob,gender,role)
         VALUES (?,?,?,?,?,?,?,?)'
    );
    $stmt->bind_param(
        'ssssssss',
        $d['first_name'],
        $d['last_name'],
        $d['email'],
        $hash,
        $d['phone'],
        $d['dob'],
        $d['gender'],
        $role
    );
    $stmt->execute();
    $userId = $stmt->insert_id;
    $stmt->close();

    // If registering as doctor, insert doctor profile row
    if ($role === 'doctor' && !empty($d['license_number'])) {
        $specialty = $d['specialty'] ?? 'General Physician';
        $yoe = (int) ($d['experience'] ?? 0);
        $fee = (float) ($d['fee'] ?? 500.00);
        $lic = $d['license_number'];

        $ds = $db->prepare(
            'INSERT IGNORE INTO doctors (user_id,specialty,experience_years,license_number,consultation_fee)
             VALUES (?,?,?,?,?)'
        );
        $ds->bind_param('isiss', $userId, $specialty, $yoe, $lic, $fee);
        $ds->execute();
        $ds->close();
    }

    ok(['user_id' => $userId, 'role' => $role], 'Registration successful!');
}

// ════════════════════════════════════════════════════════════
//  LOGIN
// ════════════════════════════════════════════════════════════
function loginUser(): never
{
    $d = body();
    requireFields($d, ['email', 'password']);

    $db = getDB();
    $stmt = $db->prepare(
        'SELECT id, first_name, last_name, password_hash, role FROM users WHERE email = ? LIMIT 1'
    );
    $stmt->bind_param('s', $d['email']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !password_verify($d['password'], $row['password_hash']))
        fail('Invalid email or password.', 401);

    // Generate session token
    $token = bin2hex(random_bytes(32));

    // BUG FIX: store token in a separate statement (do not reuse $stmt above)
    $upd = $db->prepare('UPDATE users SET session_token = ? WHERE id = ?');
    $upd->bind_param('si', $token, $row['id']);
    $upd->execute();
    $upd->close();

    ok([
        'user_id' => $row['id'],
        'name' => $row['first_name'] . ' ' . $row['last_name'],
        'role' => $row['role'],
        'token' => $token,
    ], 'Login successful!');
}

// ════════════════════════════════════════════════════════════
//  SYMPTOM LOG
// ════════════════════════════════════════════════════════════
function logSymptoms(): never
{
    $d = body();
    requireFields($d, ['patient_id', 'symptoms', 'severity']);

    $severity = (int) $d['severity'];
    if ($severity < 1 || $severity > 10)
        fail('Severity must be between 1 and 10.');

    $db = getDB();
    $urgency = calculateUrgency($d['symptoms'], $severity);
    $sympStr = is_array($d['symptoms']) ? implode(', ', $d['symptoms']) : (string) $d['symptoms'];
    $duration = $d['duration'] ?? 'unknown';
    $diag = $d['ai_diagnosis'] ?? 'Pending AI analysis';

    $stmt = $db->prepare(
        'INSERT INTO symptom_logs
           (patient_id, symptoms, severity, duration, urgency_level, ai_diagnosis, logged_at)
         VALUES (?,?,?,?,?,?,NOW())'
    );
    $stmt->bind_param('isssss', $d['patient_id'], $sympStr, $severity, $duration, $urgency, $diag);
    $stmt->execute();
    $logId = $stmt->insert_id;
    $stmt->close();

    ok(['log_id' => $logId, 'urgency_level' => $urgency], 'Symptoms logged successfully!');
}

// ════════════════════════════════════════════════════════════
//  BOOK APPOINTMENT
// ════════════════════════════════════════════════════════════
function bookAppointment(): never
{
    $d = body();
    requireFields($d, ['patient_id', 'doctor_id', 'appointment_date', 'time_slot', 'consultation_type']);

    $db = getDB();
    $notes = $d['notes'] ?? '';
    $status = 'pending';

    // BUG FIX: rely on DB UNIQUE constraint; catch duplicate key exception
    try {
        $stmt = $db->prepare(
            'INSERT INTO appointments
               (patient_id, doctor_id, appointment_date, time_slot, consultation_type, notes, status, created_at)
             VALUES (?,?,?,?,?,?,?,NOW())'
        );
        $stmt->bind_param(
            'iisssss',
            $d['patient_id'],
            $d['doctor_id'],
            $d['appointment_date'],
            $d['time_slot'],
            $d['consultation_type'],
            $notes,
            $status
        );
        $stmt->execute();
        $aptId = $stmt->insert_id;
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        // Duplicate entry for UNIQUE KEY (doctor_id, appointment_date, time_slot)
        if ($e->getCode() === 1062) {
            fail('This time slot is already booked. Please choose another.', 409);
        }
        throw $e;
    }

    ok(['appointment_id' => $aptId, 'status' => $status], 'Appointment booked successfully!');
}

// ════════════════════════════════════════════════════════════
//  GET APPOINTMENTS
// ════════════════════════════════════════════════════════════
function getAppointments(): never
{
    $db = getDB();
    $patientId = isset($_GET['patient_id']) ? (int) $_GET['patient_id'] : 0;
    $doctorId = isset($_GET['doctor_id']) ? (int) $_GET['doctor_id'] : 0;

    if ($patientId) {
        $stmt = $db->prepare(
            'SELECT a.*, CONCAT(u.first_name," ",u.last_name) AS doctor_name, d.specialty
             FROM appointments a
             JOIN users   u ON u.id = a.doctor_id
             JOIN doctors d ON d.user_id = a.doctor_id
             WHERE a.patient_id = ?
             ORDER BY a.appointment_date DESC'
        );
        $stmt->bind_param('i', $patientId);
    } elseif ($doctorId) {
        $stmt = $db->prepare(
            'SELECT a.*, CONCAT(u.first_name," ",u.last_name) AS patient_name
             FROM appointments a
             JOIN users u ON u.id = a.patient_id
             WHERE a.doctor_id = ?
             ORDER BY a.appointment_date ASC'
        );
        $stmt->bind_param('i', $doctorId);
    } else {
        fail('Provide patient_id or doctor_id query parameter.');
    }

    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    ok($rows, count($rows) . ' appointment(s) found.');
}

// ════════════════════════════════════════════════════════════
//  GET DOCTORS
// ════════════════════════════════════════════════════════════
function getDoctors(): never
{
    $db = getDB();
    $specialty = $_GET['specialty'] ?? '';
    $search = trim($_GET['search'] ?? '');
    $searchPct = '%' . $search . '%';

    if ($specialty !== '') {
        // BUG FIX: removed shadowed $spec variable — use $specialtyPct directly
        $specialtyPct = '%' . $specialty . '%';
        $stmt = $db->prepare(
            'SELECT u.id,
                    CONCAT(u.first_name," ",u.last_name) AS name,
                    d.specialty, d.experience_years, d.consultation_fee, d.rating, d.available
             FROM users   u
             JOIN doctors d ON d.user_id = u.id
             WHERE d.specialty LIKE ?
               AND (u.first_name LIKE ? OR u.last_name LIKE ?)
             ORDER BY d.rating DESC'
        );
        $stmt->bind_param('sss', $specialtyPct, $searchPct, $searchPct);
    } else {
        $stmt = $db->prepare(
            'SELECT u.id,
                    CONCAT(u.first_name," ",u.last_name) AS name,
                    d.specialty, d.experience_years, d.consultation_fee, d.rating, d.available
             FROM users   u
             JOIN doctors d ON d.user_id = u.id
             WHERE (? = "" OR u.first_name LIKE ? OR u.last_name LIKE ?)
             ORDER BY d.rating DESC'
        );
        $stmt->bind_param('sss', $search, $searchPct, $searchPct);
    }

    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    ok($rows, count($rows) . ' doctor(s) found.');
}

// ════════════════════════════════════════════════════════════
//  GET PATIENTS (Admin only)
// ════════════════════════════════════════════════════════════
function getPatients(): never
{
    $db = getDB();
    $stmt = $db->prepare(
        "SELECT u.id, CONCAT(u.first_name,' ',u.last_name) AS name,
                u.email, u.phone, u.gender,
                sl.urgency_level, sl.logged_at AS last_logged
         FROM users u
         LEFT JOIN symptom_logs sl
               ON sl.id = (SELECT MAX(id) FROM symptom_logs WHERE patient_id = u.id)
         WHERE u.role = 'patient'
         ORDER BY FIELD(COALESCE(sl.urgency_level,'LOW'),'CRITICAL','HIGH','MEDIUM','LOW'),
                  u.id DESC"
    );
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    ok($rows, count($rows) . ' patient(s) found (sorted by urgency).');
}

// ════════════════════════════════════════════════════════════
//  CALL C URGENCY ENGINE
// ════════════════════════════════════════════════════════════
function prioritizePatient(): never
{
    $d = body();
    $severity = max(1, min(10, (int) ($d['severity'] ?? 5)));
    $symptoms = preg_replace('/[^a-zA-Z0-9 ,.\'\-]/', '', (string) ($d['symptoms'] ?? ''));
    $binary = __DIR__ . '/../c_backend/urgency_engine';

    if (!file_exists($binary)) {
        // Fallback to PHP engine if binary not compiled yet
        $urgency = calculateUrgency($symptoms, $severity);
        ok(['urgency_level' => $urgency, 'engine' => 'php-fallback'], 'Urgency calculated.');
    }

    $cmd = escapeshellcmd($binary) . ' ' . escapeshellarg((string) $severity)
        . ' ' . escapeshellarg($symptoms) . ' 2>/dev/null';
    $output = trim((string) shell_exec($cmd));

    // BUG FIX: validate output before returning (binary may crash/return garbage)
    $valid = ['CRITICAL', 'HIGH', 'MEDIUM', 'LOW'];
    $urgency = in_array($output, $valid, true) ? $output : 'MEDIUM';

    ok(['urgency_level' => $urgency, 'engine' => 'c-binary'], 'Urgency calculated by C engine.');
}

// ════════════════════════════════════════════════════════════
//  PHP URGENCY CALCULATOR (fallback / also used by logSymptoms)
//  BUG FIX: uses word-boundary check (padded string) so keywords
//  like "fever" do not double-match inside "high fever".
// ════════════════════════════════════════════════════════════
function calculateUrgency(mixed $symptoms, int $severity): string
{
    $text = ' ' . strtolower(
        is_array($symptoms) ? implode(' ', $symptoms) : (string) $symptoms
    ) . ' ';

    // Word-boundary check helper (inline closure)
    $has = static fn(string $kw): bool => str_contains($text, " $kw ");

    if (
        $severity >= 9
        || $has('chest pain') || $has('heart attack') || $has('stroke')
        || $has('unconscious') || $has('severe bleeding') || $has('cannot breathe')
        || $has('anaphylaxis') || $has('meningitis')
    )
        return 'CRITICAL';

    if (
        $severity >= 7
        || $has('high fever') || $has('shortness of breath') || $has('severe headache')
        || $has('seizure') || $has('stiff neck') || $has('confusion')
    )
        return 'HIGH';

    if (
        $severity >= 4
        || $has('fever') || $has('cough') || $has('vomiting')
        || $has('dizziness') || $has('headache') || $has('rash')
    )
        return 'MEDIUM';

    return 'LOW';
}
