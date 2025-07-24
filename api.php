<?php
// Dental Clinic API Backend
// --- CONFIG ---
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

$DB_HOST = 'localhost';
$DB_NAME = 'dental_clinic';
$DB_USER = 'root';
$DB_PASS = '';

// --- SET ENTITY EARLY ---
$entity = $_GET['entity'] ?? null;

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// --- HELPER FUNCTIONS ---
function json_input() {
    return json_decode(file_get_contents('php://input'), true);
}
function respond($data, $code=200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}
function not_found() { respond(['error'=>'Not found'], 404); }
function bad_request($msg='Bad request') { respond(['error'=>$msg], 400); }
function unauthorized() { respond(['error'=>'Unauthorized'], 401); }

function normalize_timings($timings) {
    $days = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
    $default = ['start'=>'09:00','end'=>'17:00','isOff'=>true];
    $result = [];
    foreach ($days as $day) {
        if (isset($timings[$day]) && is_array($timings[$day])) {
            $result[$day] = array_merge($default, $timings[$day]);
        } else {
            $result[$day] = $default;
        }
    }
    return $result;
}

// --- AUTH (simple sessionless for demo) ---
if ($_GET['entity'] === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_input();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([$data['username']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user && password_verify($data['password'], $user['password'])) {
        unset($user['password']);
        $user['permissions'] = json_decode($user['permissions'], true); // Ensure permissions is an array
        respond(['user'=>$user]);
    } else {
        unauthorized();
    }
}

// --- BACKUP & RESTORE & INTEGRITY CHECK ENDPOINTS ---
if ($entity === 'backup' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    // Database backup using mysqldump
    $backupFile = sys_get_temp_dir() . "/dental_clinic_backup_" . date('Ymd_His') . ".sql";
    $cmd = "mysqldump --user=$DB_USER --password=$DB_PASS --host=$DB_HOST $DB_NAME > $backupFile";
    system($cmd, $retval);
    if ($retval !== 0 || !file_exists($backupFile)) {
        respond(['error' => 'Backup failed'], 500);
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="dental_clinic_backup_"' . date('Ymd_His') . '.sql');
    readfile($backupFile);
    unlink($backupFile);
    exit;
}
if ($entity === 'restore' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Restore database from uploaded .sql file
    if (!isset($_FILES['sqlfile']) || $_FILES['sqlfile']['error'] !== UPLOAD_ERR_OK) {
        respond(['error' => 'No file uploaded or upload error'], 400);
    }
    $sqlFile = $_FILES['sqlfile']['tmp_name'];
    $cmd = "mysql --user=$DB_USER --password=$DB_PASS --host=$DB_HOST $DB_NAME < $sqlFile";
    system($cmd, $retval);
    if ($retval !== 0) {
        respond(['error' => 'Restore failed'], 500);
    }
    respond(['success' => true]);
}
if ($entity === 'integrity_check' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    // Check for missing tables/columns (simple version)
    $requiredTables = [
        'patients', 'doctors', 'procedures', 'appointments', 'treatments', 'transactions', 'users', 'daybooks', 'activity_logs', 'queue'
    ];
    $missing = [];
    $stmt = $pdo->query("SHOW TABLES");
    $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($requiredTables as $table) {
        if (!in_array($table, $existingTables)) {
            $missing[] = [ 'table' => $table, 'missing' => true ];
        }
    }
    // Optionally, check for required columns in each table (not implemented here for brevity)
    respond(['missing' => $missing]);
}
if ($entity === 'repair_schema' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Repair missing tables using schema.sql
    $schemaFile = __DIR__ . '/schema.sql';
    if (!file_exists($schemaFile)) {
        respond(['error' => 'schema.sql not found'], 500);
    }
    $cmd = "mysql --user=$DB_USER --password=$DB_PASS --host=$DB_HOST $DB_NAME < $schemaFile";
    system($cmd, $retval);
    if ($retval !== 0) {
        respond(['error' => 'Repair failed'], 500);
    }
    respond(['success' => true]);
}

// --- ENTITY ROUTING ---
$id = $_GET['id'] ?? null;

// --- App Settings API ---
if ($entity === 'app_settings') {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->query('SELECT settings FROM app_settings LIMIT 1');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            respond(json_decode($row['settings'], true));
        } else {
            respond(['error' => 'App settings not found'], 404);
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = file_get_contents('php://input');
        $json = json_decode($data, true);
        if (!is_array($json)) {
            respond(['error' => 'Invalid JSON'], 400);
        }
        $stmt = $pdo->prepare('UPDATE app_settings SET settings = ?, updated_at = NOW() WHERE id = 1');
        $stmt->execute([json_encode($json)]);
        respond(['success' => true]);
    } else {
        respond(['error' => 'Method not allowed'], 405);
    }
    exit;
}

// --- NEW: Allocate Payments to Treatments ---
if ($entity === 'allocate_payments' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_input();
    $allocations = $data['allocations'] ?? [];
    $patientId = $data['patientId'] ?? null;
    $description = $data['description'] ?? '';
    $dayBookDate = $data['dayBookDate'] ?? null;
    $userId = $data['userId'] ?? null;
    $amountReceived = floatval($data['amount'] ?? 0); // Total amount received
    if (!$patientId) {
        respond(['error' => 'Missing patientId'], 400);
    }
    $pdo->beginTransaction();
    try {
        $totalAllocated = 0;
        $treatmentIds = [];
        foreach ($allocations as $alloc) {
            $treatmentId = $alloc['treatmentId'] ?? null;
            $amount = floatval($alloc['amount'] ?? 0);
            if (!$treatmentId || $amount <= 0) continue;
            // Fetch current treatment
            $stmt = $pdo->prepare('SELECT * FROM treatments WHERE id = ?');
            $stmt->execute([$treatmentId]);
            $treatment = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$treatment) continue;
            $oldPaid = floatval($treatment['paidAmount']);
            $finalAmount = floatval($treatment['finalAmount']);
            $newPaid = $oldPaid + $amount;
            if ($newPaid > $finalAmount) $newPaid = $finalAmount; // Prevent overpay
            // Determine new status
            $newStatus = ($newPaid >= $finalAmount) ? 'Paid' : (($newPaid > 0) ? 'Partially Paid' : 'Unpaid');
            // Update treatment
            $stmt = $pdo->prepare('UPDATE treatments SET paidAmount=?, paymentStatus=? WHERE id=?');
            $stmt->execute([$newPaid, $newStatus, $treatmentId]);
            // Update patient balance
            $stmt = $pdo->prepare('UPDATE patients SET balance = balance - ? WHERE id = ?');
            $stmt->execute([$amount, $patientId]);
            $totalAllocated += $amount;
            $treatmentIds[] = $treatmentId;
        }
        // Handle single transaction for allocation + advance
        $advance = isset($data['amount']) ? floatval($data['amount']) - $totalAllocated : 0;
        // Fetch patient name for description
        $stmt = $pdo->prepare('SELECT name FROM patients WHERE id = ?');
        $stmt->execute([$patientId]);
        $patientRow = $stmt->fetch(PDO::FETCH_ASSOC);
        $patientName = $patientRow ? $patientRow['name'] : '';
        $txnDescription = '';
        if (count($treatmentIds) > 0) {
            $txnDescription = 'Payment (from "' . $patientName . '") for Treatment';
            if (count($treatmentIds) === 1) {
                $txnDescription .= ' #' . $treatmentIds[0];
            } else {
                $txnDescription .= 's #' . implode(', ', $treatmentIds);
            }
        }
        if ($advance > 0) {
            if ($txnDescription) {
                $txnDescription .= ', Advance: PKR ' . number_format($advance, 2);
            } else {
                $txnDescription = 'Payment (from "' . $patientName . '") Advance: PKR ' . number_format($advance, 2);
            }
        }
        if ($amountReceived > 0) {
            // Insert single transaction for the whole payment
            $stmt = $pdo->prepare('INSERT INTO transactions (patientId, treatmentId, date, description, type, amount, userId, dayBookDate, status, createdAt) VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, NOW())');
            $stmt->execute([
                $patientId,
                (count($treatmentIds) === 1 ? $treatmentIds[0] : null),
                $txnDescription ?: $description,
                'income',
                $amountReceived,
                $userId,
                $dayBookDate,
                'active'
            ]);
            // Update patient balance for advance (if any)
            if ($advance > 0) {
                $stmt = $pdo->prepare('UPDATE patients SET balance = balance - ? WHERE id = ?');
                $stmt->execute([$advance, $patientId]);
            }
        }
        // Fetch updated treatments, transactions, and patient for this patient
        $stmt = $pdo->prepare('SELECT * FROM treatments WHERE patientId = ?');
        $stmt->execute([$patientId]);
        $updatedTreatments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $pdo->prepare('SELECT * FROM transactions WHERE patientId = ?');
        $stmt->execute([$patientId]);
        $updatedTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $pdo->prepare('SELECT * FROM patients WHERE id = ?');
        $stmt->execute([$patientId]);
        $updatedPatient = $stmt->fetch(PDO::FETCH_ASSOC);
        $pdo->commit();
        respond(['treatments' => $updatedTreatments, 'transactions' => $updatedTransactions, 'patient' => $updatedPatient]);
    } catch (Exception $e) {
        $pdo->rollBack();
        respond(['error' => $e->getMessage()], 500);
    }
}

switch ($entity) {
    case 'patients':
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            if ($id) {
                $stmt = $pdo->prepare('SELECT * FROM patients WHERE id = ?');
                $stmt->execute([$id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $row ? respond($row) : not_found();
            } else {
                $stmt = $pdo->query('SELECT * FROM patients');
                respond($stmt->fetchAll(PDO::FETCH_ASSOC));
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = json_input();
            $stmt = $pdo->prepare('INSERT INTO patients (name, dob, phone, email, address, gender, medicalHistory, createdAt, balance) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)');
            $stmt->execute([
                $data['name'], $data['dob'], $data['phone'], $data['email'], $data['address'], $data['gender'], $data['medicalHistory'], $data['balance'] ?? 0
            ]);
            respond(['id'=>$pdo->lastInsertId()], 201);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'PUT' && $id) {
            $data = json_input();
            $stmt = $pdo->prepare('UPDATE patients SET name=?, dob=?, phone=?, email=?, address=?, gender=?, medicalHistory=?, isDeleted=?, deletedAt=?, balance=? WHERE id=?');
            $stmt->execute([
                $data['name'], $data['dob'], $data['phone'], $data['email'], $data['address'], $data['gender'], $data['medicalHistory'], $data['isDeleted']??0, $data['deletedAt'], $data['balance']??0, $id
            ]);
            respond(['success'=>true]);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE' && $id) {
            $stmt = $pdo->prepare('DELETE FROM patients WHERE id=?');
            $stmt->execute([$id]);
            respond(['success'=>true]);
        } else {
            bad_request();
        }
        break;
    case 'doctors':
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            if ($id) {
                $stmt = $pdo->prepare('SELECT * FROM doctors WHERE id = ?');
                $stmt->execute([$id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $row ? respond($row) : not_found();
            } else {
                $stmt = $pdo->query('SELECT * FROM doctors');
                respond($stmt->fetchAll(PDO::FETCH_ASSOC));
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = json_input();
            // Normalize timings
            $timings = isset($data['timings']) && is_array($data['timings']) ? normalize_timings($data['timings']) : normalize_timings([]);
            $stmt = $pdo->prepare('INSERT INTO doctors (name, specialty, gender, contactNumbers, timings, status) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $data['name'], $data['specialty'], $data['gender'], json_encode($data['contactNumbers']), json_encode($timings), $data['status'] ?? 'active'
            ]);
            respond(['id'=>$pdo->lastInsertId()], 201);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'PUT' && $id) {
            $data = json_input();
            // Normalize timings
            $timings = isset($data['timings']) && is_array($data['timings']) ? normalize_timings($data['timings']) : normalize_timings([]);
            $stmt = $pdo->prepare('UPDATE doctors SET name=?, specialty=?, gender=?, contactNumbers=?, timings=?, status=? WHERE id=?');
            $stmt->execute([
                $data['name'], $data['specialty'], $data['gender'], json_encode($data['contactNumbers']), json_encode($timings), $data['status'], $id
            ]);
            respond(['success'=>true]);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE' && $id) {
            $stmt = $pdo->prepare('DELETE FROM doctors WHERE id=?');
            $stmt->execute([$id]);
            respond(['success'=>true]);
        } else {
            bad_request();
        }
        break;
    case 'procedures':
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            if ($id) {
                $stmt = $pdo->prepare('SELECT * FROM procedures WHERE id = ?');
                $stmt->execute([$id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $row ? respond($row) : not_found();
            } else {
                $stmt = $pdo->query('SELECT * FROM procedures');
                respond($stmt->fetchAll(PDO::FETCH_ASSOC));
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = json_input();
            $stmt = $pdo->prepare('INSERT INTO procedures (name, description, cost, status) VALUES (?, ?, ?, ?)');
            $stmt->execute([
                $data['name'], $data['description'], $data['cost'], $data['status'] ?? 'active'
            ]);
            respond(['id'=>$pdo->lastInsertId()], 201);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'PUT' && $id) {
            $data = json_input();
            $stmt = $pdo->prepare('UPDATE procedures SET name=?, description=?, cost=?, status=? WHERE id=?');
            $stmt->execute([
                $data['name'], $data['description'], $data['cost'], $data['status'], $id
            ]);
            respond(['success'=>true]);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE' && $id) {
            $stmt = $pdo->prepare('DELETE FROM procedures WHERE id=?');
            $stmt->execute([$id]);
            respond(['success'=>true]);
        } else {
            bad_request();
        }
        break;
    case 'appointments':
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            if ($id) {
                $stmt = $pdo->prepare('SELECT * FROM appointments WHERE id = ?');
                $stmt->execute([$id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $row ? respond($row) : not_found();
            } else {
                $stmt = $pdo->query('SELECT * FROM appointments');
                respond($stmt->fetchAll(PDO::FETCH_ASSOC));
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = json_input();
            $stmt = $pdo->prepare('INSERT INTO appointments (patientId, patientName, date, time, reason, status, createdAt) VALUES (?, ?, ?, ?, ?, ?, NOW())');
            $stmt->execute([
                $data['patientId'], $data['patientName'], $data['date'], $data['time'], $data['reason'], $data['status'] ?? 'Scheduled'
            ]);
            respond(['id'=>$pdo->lastInsertId()], 201);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'PUT' && $id) {
            $data = json_input();
            // Fetch existing appointment row
            $stmt = $pdo->prepare('SELECT * FROM appointments WHERE id=?');
            $stmt->execute([$id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) bad_request('Appointment not found');
            $patientId = isset($data['patientId']) ? $data['patientId'] : $existing['patientId'];
            $patientName = isset($data['patientName']) ? $data['patientName'] : $existing['patientName'];
            $date = isset($data['date']) ? $data['date'] : $existing['date'];
            $time = isset($data['time']) ? $data['time'] : $existing['time'];
            $reason = isset($data['reason']) ? $data['reason'] : $existing['reason'];
            $status = isset($data['status']) ? $data['status'] : $existing['status'];
            $cancellationReason = array_key_exists('cancellationReason', $data) ? $data['cancellationReason'] : $existing['cancellationReason'];
            $cancellationDate = array_key_exists('cancellationDate', $data) ? $data['cancellationDate'] : $existing['cancellationDate'];
            $stmt = $pdo->prepare('UPDATE appointments SET patientId=?, patientName=?, date=?, time=?, reason=?, status=?, cancellationReason=?, cancellationDate=?, updatedAt=NOW() WHERE id=?');
            $stmt->execute([
                $patientId, $patientName, $date, $time, $reason, $status, $cancellationReason, $cancellationDate, $id
            ]);
            respond(['success'=>true]);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE' && $id) {
            $stmt = $pdo->prepare('DELETE FROM appointments WHERE id=?');
            $stmt->execute([$id]);
            respond(['success'=>true]);
        } else {
            bad_request();
        }
        break;
    case 'treatments':
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            if ($id) {
                $stmt = $pdo->prepare('SELECT * FROM treatments WHERE id = ?');
                $stmt->execute([$id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $row ? respond($row) : not_found();
            } else {
                $stmt = $pdo->query('SELECT * FROM treatments');
                respond($stmt->fetchAll(PDO::FETCH_ASSOC));
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = json_input();
            $pdo->beginTransaction();
            try {
                // 1. Insert treatment
                $stmt = $pdo->prepare('INSERT INTO treatments (patientId, doctorId, date, notes, procedures, totalCost, totalDiscount, finalAmount, overallDiscount, userId, paidAmount, paymentStatus) VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $data['patientId'], $data['doctorId'], $data['notes'], json_encode($data['procedures']), $data['totalCost'], $data['totalDiscount'], $data['finalAmount'], $data['overallDiscount'], $data['userId'], $data['paidAmount'], $data['paymentStatus']
                ]);
                $treatmentId = $pdo->lastInsertId();

                // 2. Update patient balance
                if (!empty($data['patientId'])) {
                    // Fetch current patient balance (advance/credit)
                    $stmt = $pdo->prepare('SELECT balance FROM patients WHERE id = ?');
                    $stmt->execute([$data['patientId']]);
                    $patientRow = $stmt->fetch(PDO::FETCH_ASSOC);
                    $currentBalance = isset($patientRow['balance']) ? floatval($patientRow['balance']) : 0;
                    $finalAmount = floatval($data['finalAmount']);
                    $paidAmount = floatval($data['paidAmount']);

                    // Calculate how much of paidAmount is covered by advance (negative balance)
                    $advanceUsed = 0;
                    if ($currentBalance < 0 && $paidAmount > 0) {
                        $advanceUsed = min(abs($currentBalance), $paidAmount);
                    }
                    $newPayment = $paidAmount - $advanceUsed;
                    if ($newPayment < 0) $newPayment = 0;

                    // Update patient balance: add unpaid portion
                    $stmt = $pdo->prepare('UPDATE patients SET balance = balance + ? WHERE id = ?');
                    $stmt->execute([$finalAmount - $paidAmount, $data['patientId']]);

                    // If advance was used, increase balance by advanceUsed (since it's now consumed)
                    if ($advanceUsed > 0) {
                        $stmt = $pdo->prepare('UPDATE patients SET balance = balance + ? WHERE id = ?');
                        $stmt->execute([$advanceUsed, $data['patientId']]);
                    }

                    // 3. Only create a transaction for the new payment (not for advance used)
                    if ($newPayment > 0) {
                        $stmt = $pdo->prepare('INSERT INTO transactions (patientId, treatmentId, date, description, type, amount, userId, dayBookDate, status, createdAt) VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, NOW())');
                        $stmt->execute([
                            $data['patientId'],
                            $treatmentId,
                            'Treatment Payment - ' . ($data['patientName'] ?? ''),
                            'income',
                            $newPayment,
                            $data['userId'] ?? null,
                            $data['dayBookDate'] ?? null,
                            'active'
                        ]);
                    }
                }

                // 4. Mark patient as completed in queue for today
                $today = date('Y-m-d');
                $stmt = $pdo->prepare('UPDATE queue SET status = ? WHERE patientId = ? AND date = ? AND status != ?');
                $stmt->execute(['Completed', $data['patientId'], $today, 'Completed']);
                if ($stmt->rowCount() === 0) {
                    // No queue entry existed for this patient today, return error (do not insert new row)
                    $pdo->rollBack();
                    respond(['error' => 'No queue entry found for this patient today. Please add to queue first.'], 400);
                }

                $pdo->commit();
                respond(['id' => $treatmentId], 201);
            } catch (Exception $e) {
                $pdo->rollBack();
                respond(['error' => $e->getMessage()], 500);
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'PUT' && $id) {
            $data = json_input();
            $stmt = $pdo->prepare('UPDATE treatments SET patientId=?, doctorId=?, date=?, notes=?, procedures=?, totalCost=?, totalDiscount=?, finalAmount=?, overallDiscount=?, userId=?, paidAmount=?, paymentStatus=? WHERE id=?');
            $stmt->execute([
                $data['patientId'], $data['doctorId'], $data['date'], $data['notes'], json_encode($data['procedures']), $data['totalCost'], $data['totalDiscount'], $data['finalAmount'], $data['overallDiscount'], $data['userId'], $data['paidAmount'], $data['paymentStatus'], $id
            ]);
            respond(['success'=>true]);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE' && $id) {
            $stmt = $pdo->prepare('DELETE FROM treatments WHERE id=?');
            $stmt->execute([$id]);
            respond(['success'=>true]);
        } else {
            bad_request();
        }
        break;
    case 'transactions':
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            if ($id) {
                $stmt = $pdo->prepare('SELECT * FROM transactions WHERE id = ?');
                $stmt->execute([$id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $row ? respond($row) : not_found();
            } else {
                $stmt = $pdo->query('SELECT * FROM transactions');
                respond($stmt->fetchAll(PDO::FETCH_ASSOC));
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = json_input();
            $stmt = $pdo->prepare('INSERT INTO transactions (patientId, treatmentId, date, description, type, amount, userId, dayBookDate, status, createdAt) VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, NOW())');
            $stmt->execute([
                $data['patientId'] ?? null,
                $data['treatmentId'] ?? null,
                $data['description'] ?? '',
                $data['type'] ?? '',
                $data['amount'] ?? 0,
                $data['userId'] ?? null,
                $data['dayBookDate'] ?? null,
                $data['status'] ?? 'active'
            ]);
            // If this is a payment (income) and patientId is present, update patient balance
            if (!empty($data['patientId']) && $data['type'] === 'income') {
                $stmt = $pdo->prepare('UPDATE patients SET balance = balance - ? WHERE id = ?');
                $stmt->execute([floatval($data['amount']), $data['patientId']]);
            }
            respond(['id'=>$pdo->lastInsertId()], 201);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'PUT' && $id) {
            $data = json_input();
            // Fetch existing transaction row
            $stmt = $pdo->prepare('SELECT * FROM transactions WHERE id=?');
            $stmt->execute([$id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) bad_request('Transaction not found');
            $patientId = isset($data['patientId']) ? $data['patientId'] : $existing['patientId'];
            $treatmentId = isset($data['treatmentId']) ? $data['treatmentId'] : $existing['treatmentId'];
            $date = isset($data['date']) ? $data['date'] : $existing['date'];
            $description = isset($data['description']) ? $data['description'] : $existing['description'];
            $type = isset($data['type']) ? $data['type'] : $existing['type'];
            $amount = isset($data['amount']) ? $data['amount'] : $existing['amount'];
            $userId = isset($data['userId']) ? $data['userId'] : $existing['userId'];
            $dayBookDate = isset($data['dayBookDate']) ? $data['dayBookDate'] : $existing['dayBookDate'];
            $status = isset($data['status']) ? $data['status'] : $existing['status'];
            $stmt = $pdo->prepare('UPDATE transactions SET patientId=?, treatmentId=?, date=?, description=?, type=?, amount=?, userId=?, dayBookDate=?, status=?, updatedAt=NOW() WHERE id=?');
            $stmt->execute([
                $patientId, $treatmentId, $date, $description, $type, $amount, $userId, $dayBookDate, $status, $id
            ]);
            respond(['success'=>true]);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE' && $id) {
            $stmt = $pdo->prepare('DELETE FROM transactions WHERE id=?');
            $stmt->execute([$id]);
            respond(['success'=>true]);
        } else {
            bad_request();
        }
        break;
    case 'users':
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            if ($id) {
                $stmt = $pdo->prepare('SELECT id, username, permissions FROM users WHERE id = ?');
                $stmt->execute([$id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $row['permissions'] = json_decode($row['permissions'], true); // Ensure permissions is an array
                    respond($row);
                } else {
                    not_found();
                }
            } else {
                $stmt = $pdo->query('SELECT id, username, permissions FROM users');
                $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($users as &$user) {
                    $user['permissions'] = json_decode($user['permissions'], true); // Ensure permissions is an array
                }
                respond($users);
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = json_input();
            $stmt = $pdo->prepare('INSERT INTO users (username, password, permissions) VALUES (?, ?, ?)');
            $stmt->execute([
                $data['username'], password_hash($data['password'], PASSWORD_DEFAULT), json_encode($data['permissions'])
            ]);
            respond(['id'=>$pdo->lastInsertId()], 201);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'PUT' && $id) {
            $data = json_input();
            $fields = ['username = ?'];
            $params = [$data['username']];
            if (!empty($data['password'])) {
                $fields[] = 'password = ?';
                $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
            }
            $fields[] = 'permissions = ?';
            $params[] = json_encode($data['permissions']);
            $params[] = $id;
            $stmt = $pdo->prepare('UPDATE users SET '.implode(', ', $fields).' WHERE id=?');
            $stmt->execute($params);
            respond(['success'=>true]);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE' && $id) {
            $stmt = $pdo->prepare('DELETE FROM users WHERE id=?');
            $stmt->execute([$id]);
            respond(['success'=>true]);
        } else {
            bad_request();
        }
        break;
    case 'daybooks':
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $stmt = $pdo->query('SELECT * FROM daybooks');
            respond($stmt->fetchAll(PDO::FETCH_ASSOC));
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = json_input();
            // Only require date, status, openingBalance
            $stmt = $pdo->prepare('INSERT INTO daybooks (date, status, openingBalance) VALUES (?, ?, ?)');
            $stmt->execute([
                $data['date'], $data['status'] ?? 'open', $data['openingBalance']
            ]);
            respond(['success'=>true], 201);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
            $data = json_input();
            // Fetch existing daybook row
            $stmt = $pdo->prepare('SELECT * FROM daybooks WHERE date=?');
            $stmt->execute([$data['date']]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) bad_request('Daybook not found');
            $status = $data['status'] ?? $existing['status'];
            $openingBalance = isset($data['openingBalance']) ? $data['openingBalance'] : $existing['openingBalance'];
            $settledAmount = isset($data['settledAmount']) ? $data['settledAmount'] : $existing['settledAmount'];
            $notes = isset($data['notes']) ? $data['notes'] : $existing['notes'];
            // Calculate income and expense for this day
            $stmt = $pdo->prepare('SELECT SUM(amount) as income FROM transactions WHERE dayBookDate=? AND type="income" AND status="active"');
            $stmt->execute([$data['date']]);
            $income = floatval($stmt->fetchColumn() ?: 0);
            $stmt = $pdo->prepare('SELECT SUM(amount) as expense FROM transactions WHERE dayBookDate=? AND type="expense" AND status="active"');
            $stmt->execute([$data['date']]);
            $expense = floatval($stmt->fetchColumn() ?: 0);
            // Calculate closing balance
            $closingBalance = floatval($openingBalance) + $income - $expense - floatval($settledAmount);
            $stmt = $pdo->prepare('UPDATE daybooks SET status=?, openingBalance=?, closingBalance=?, settledAmount=?, notes=? WHERE date=?');
            $stmt->execute([
                $status, $openingBalance, $closingBalance, $settledAmount, $notes, $data['date']
            ]);
            respond([
                'success'=>true,
                'status'=>$status,
                'openingBalance'=>$openingBalance,
                'closingBalance'=>$closingBalance,
                'settledAmount'=>$settledAmount,
                'notes'=>$notes
            ]);
        } else {
            bad_request();
        }
        break;
    case 'activity_logs':
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $stmt = $pdo->query('SELECT * FROM activity_logs');
            respond($stmt->fetchAll(PDO::FETCH_ASSOC));
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = json_input();
            $stmt = $pdo->prepare('INSERT INTO activity_logs (date, userId, type, entity, description) VALUES (NOW(), ?, ?, ?, ?)');
            $stmt->execute([
                $data['userId'], $data['type'], $data['entity'], $data['description']
            ]);
            respond(['success'=>true], 201);
        } else {
            bad_request();
        }
        break;
    case 'queue':
        // Reset queue for previous days if requested
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['reset'])) {
            // Clear ALL queue entries, not just previous days
            $stmt = $pdo->prepare('DELETE FROM queue');
            $stmt->execute();
            // Log activity
            $userId = $_POST['userId'] ?? ($_GET['userId'] ?? null);
            $logStmt = $pdo->prepare('INSERT INTO activity_logs (date, userId, type, entity, description) VALUES (NOW(), ?, ?, ?, ?)');
            $logStmt->execute([
                $userId,
                'delete',
                'queue',
                'Queue was reset (all entries cleared)'
            ]);
            respond(['success'=>true]);
        }
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $date = $_GET['date'] ?? date('Y-m-d');
            $stmt = $pdo->prepare('SELECT * FROM queue WHERE date = ?');
            $stmt->execute([$date]);
            respond($stmt->fetchAll(PDO::FETCH_ASSOC));
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = json_input();
            $date = $data['date'] ?? date('Y-m-d');
            // Check if a queue row for this patient and date already exists
            $checkStmt = $pdo->prepare('SELECT * FROM queue WHERE patientId = ? AND date = ?');
            $checkStmt->execute([$data['patientId'], $date]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                // If status is Completed or Cancelled, allow frontend to handle reactivation or slip regeneration
                if ($existing['status'] === 'Completed' || $existing['status'] === 'Cancelled') {
                    respond(['existing' => $existing], 200);
                }
                // For any other status, block duplicate entry
                respond(['error' => 'Patient is already in the queue for today', 'queue' => $existing], 409);
            }
            $queueNumberStmt = $pdo->prepare('SELECT MAX(queueNumber) as maxNum FROM queue WHERE date = ?');
            $queueNumberStmt->execute([$date]);
            $maxNum = $queueNumberStmt->fetchColumn();
            $maxNum = is_numeric($maxNum) ? (int)$maxNum : 0;
            $newQueueNumber = $maxNum + 1;
            $stmt = $pdo->prepare('INSERT INTO queue (patientId, queueNumber, status, date) VALUES (?, ?, ?, ?)');
            $stmt->execute([
                $data['patientId'], $newQueueNumber, $data['status'] ?? 'Waiting', $date
            ]);
            respond(['id'=>$pdo->lastInsertId()], 201);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'PUT' && $id) {
            $data = json_input();
            $stmt = $pdo->prepare('UPDATE queue SET patientId=?, queueNumber=?, status=?, date=? WHERE id=?');
            $stmt->execute([
                $data['patientId'], $data['queueNumber'], $data['status'], $data['date'], $id
            ]);
            respond(['success'=>true]);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE' && $id) {
            $stmt = $pdo->prepare('DELETE FROM queue WHERE id=?');
            $stmt->execute([$id]);
            respond(['success'=>true]);
        } else {
            bad_request();
        }
        break;
    default:
        not_found();
}
// --- END --- 