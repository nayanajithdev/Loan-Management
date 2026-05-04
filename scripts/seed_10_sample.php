<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = db();
ensure_user_schema($pdo);

$firstNames = ['Nimal','Kamal','Sunil','Ruwan','Kasun','Ajith','Saman','Roshan','Pradeep','Tharindu'];
$lastNames = ['Perera','Silva','Fernando','Bandara','Jayasuriya','Wijesinghe'];

$customerStmt = $pdo->prepare('INSERT INTO customers (customer_code, full_name, phone, nic, address, status) VALUES (:code,:name,:phone,:nic,:address,:status)');
$loanStmt = $pdo->prepare('INSERT INTO loans (loan_number, customer_id, principal_amount, interest_rate, total_amount, installment_frequency, installment_count, installment_amount, start_date, first_due_date, status, notes) VALUES (:loan_number,:customer_id,:principal_amount,:interest_rate,:total_amount,:frequency,:installment_count,:installment_amount,:start_date,:first_due_date,:status,:notes)');
$instStmt = $pdo->prepare('INSERT INTO loan_installments (loan_id, installment_no, due_date, due_amount, paid_amount, paid_on, status) VALUES (:loan_id,:installment_no,:due_date,:due_amount,:paid_amount,:paid_on,:status)');
$colStmt = $pdo->prepare('INSERT INTO collections (loan_id, installment_id, amount, collected_on, method, note) VALUES (:loan_id,:installment_id,:amount,:collected_on,:method,:note)');

try {
    $pdo->beginTransaction();

    $lastCustomerId = (int) ($pdo->query('SELECT COALESCE(MAX(id),0) FROM customers')->fetchColumn());
    $lastLoanId = (int) ($pdo->query('SELECT COALESCE(MAX(id),0) FROM loans')->fetchColumn());

    $baseDate = new DateTimeImmutable('-30 days');

    for ($i = 1; $i <= 10; $i++) {
        $customerCode = 'CUST-' . str_pad((string) ($lastCustomerId + $i), 5, '0', STR_PAD_LEFT);
        $name = $firstNames[$i - 1] . ' ' . $lastNames[array_rand($lastNames)];
        $phone = '07' . random_int(10000000, 99999999);

        $customerStmt->execute([
            'code' => $customerCode,
            'name' => $name,
            'phone' => $phone,
            'nic' => (string) random_int(600000000, 999999999) . 'V',
            'address' => 'No. ' . random_int(1, 200) . ', Seed Street',
            'status' => 'active',
        ]);

        $customerId = (int) $pdo->lastInsertId();

        $loanNumber = 'LN-' . str_pad((string) ($lastLoanId + $i), 6, '0', STR_PAD_LEFT);
        $principal = (float) random_int(30000, 200000);
        $interest = (float) random_int(8, 20);
        $total = round($principal + ($principal * $interest / 100), 2);

        $frequency = ['daily','weekly','monthly'][array_rand(['daily','weekly','monthly'])];
        $installmentCount = match ($frequency) {
            'daily' => random_int(25, 40),
            'weekly' => random_int(8, 12),
            default => random_int(3, 6),
        };
        $installmentAmount = round($total / $installmentCount, 2);

        $firstDueDateObj = $baseDate->modify('+' . random_int(0, 7) . ' days');
        $firstDueDate = $firstDueDateObj->format('Y-m-d');

        $loanStmt->execute([
            'loan_number' => $loanNumber,
            'customer_id' => $customerId,
            'principal_amount' => $principal,
            'interest_rate' => $interest,
            'total_amount' => $total,
            'frequency' => $frequency,
            'installment_count' => $installmentCount,
            'installment_amount' => $installmentAmount,
            'start_date' => $firstDueDateObj->modify('-2 days')->format('Y-m-d'),
            'first_due_date' => $firstDueDate,
            'status' => 'active',
            'notes' => 'Seed data loan',
        ]);

        $loanId = (int) $pdo->lastInsertId();
        $dueDate = $firstDueDateObj;
        $interval = frequency_interval($frequency);
        $pending = false;

        for ($n = 1; $n <= $installmentCount; $n++) {
            $due = $n === $installmentCount ? round($total - (($installmentCount - 1) * $installmentAmount), 2) : $installmentAmount;
            $today = new DateTimeImmutable(date('Y-m-d'));
            $status = 'pending';
            $paid = 0.0;
            $paidOn = null;

            if ($dueDate < $today) {
                $r = random_int(1, 100);
                if ($r <= 55) {
                    $status = 'paid';
                    $paid = $due;
                    $paidOn = $dueDate->modify('+' . random_int(0, 2) . ' days')->format('Y-m-d');
                } elseif ($r <= 75) {
                    $status = 'partial';
                    $paid = round($due * (random_int(25, 75) / 100), 2);
                } else {
                    $status = 'overdue';
                }
            }

            $instStmt->execute([
                'loan_id' => $loanId,
                'installment_no' => $n,
                'due_date' => $dueDate->format('Y-m-d'),
                'due_amount' => $due,
                'paid_amount' => $paid,
                'paid_on' => $paidOn,
                'status' => $status,
            ]);

            $instId = (int) $pdo->lastInsertId();
            if ($paid > 0) {
                $colStmt->execute([
                    'loan_id' => $loanId,
                    'installment_id' => $instId,
                    'amount' => $paid,
                    'collected_on' => $paidOn ?? $dueDate->format('Y-m-d'),
                    'method' => ['cash','bank','online'][array_rand(['cash','bank','online'])],
                    'note' => 'Seed collection',
                ]);
            }

            if (in_array($status, ['pending','partial','overdue'], true)) {
                $pending = true;
            }

            $dueDate = $dueDate->add($interval);
        }

        if (!$pending) {
            $pdo->prepare("UPDATE loans SET status = 'closed' WHERE id = :id")->execute(['id' => $loanId]);
        }
    }

    $pdo->commit();
    echo "Inserted 10 customers and 10 loans successfully.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo 'Seed failed: ' . $e->getMessage() . "\n";
    exit(1);
}