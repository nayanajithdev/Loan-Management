<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$loanId = (int) ($_POST['loan_id'] ?? $_GET['loan_id'] ?? 0);
if ($loanId > 0) {
    set_flash('error', 'Extend Loan is now handled from Loan Details edit mode.');
    redirect('pages/loan_edit.php?loan_id=' . $loanId);
}

set_flash('error', 'Open a loan first, then edit it from Loan Details.');
redirect('pages/loans.php');
