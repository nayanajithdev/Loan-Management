<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_permission('loans.view', 'pages/loans.php');

$loanId = (int) ($_GET['loan_id'] ?? 0);
if ($loanId > 0) {
    redirect('pages/loan_edit.php?loan_id=' . $loanId);
}

set_flash('error', 'Open a loan first, then edit it from Loan Details.');
redirect('pages/loans.php');
