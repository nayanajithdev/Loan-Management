<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$pageTitle = 'About';
$activePage = 'about';

require __DIR__ . '/../includes/layout_start.php';
?>

<section class="panel about-page">
    <div class="panel-head">
        <h3 class="panel-title">About LoanDesk</h3>
    </div>

    <p>
        LoanDesk is a multi-user loan management system designed for small and growing lending businesses.
        It helps teams manage customers, loans, and collections from one secure dashboard.
    </p>

    <h4>Core Modules</h4>
    <ul class="about-list">
        <li><strong>Customer Management:</strong> customer profiles, notes, and documents.</li>
        <li><strong>Loan Management:</strong> loan records, manual loan numbers, and repayment schedules.</li>
        <li><strong>Collections:</strong> today collection flow, collection history, and overdue tracking.</li>
        <li><strong>Reports:</strong> portfolio and operational performance summaries.</li>
        <li><strong>Operations:</strong> role-based access, activity logs, backup, and restore.</li>
    </ul>

    <h4>User Roles</h4>
    <ul class="about-list">
        <li><strong>Owner:</strong> full system control and security ownership.</li>
        <li><strong>Manager:</strong> day-to-day management across teams and records.</li>
        <li><strong>Collector:</strong> collection-focused access based on assigned permissions.</li>
    </ul>

    <h4>Privacy & Data Use</h4>
    <p>
        This system stores business operational data, including customer details, loan records, and collection logs,
        for internal business use only. Access is restricted by user role permissions.
    </p>

    <h4>Security Notice</h4>
    <ul class="about-list">
        <li>Password-protected user accounts with role-based authorization.</li>
        <li>Audit-ready activity logs for key system actions.</li>
        <li>Backup and restore tools for business continuity.</li>
    </ul>

    <h4>Support & Updates</h4>
    <p>
        For software updates, support, and maintenance, contact your system developer.
        Update notifications are shown inside the application when available.
    </p>

    <div class="about-note">
        LoanDesk v1.1 - Multi-User Loan Management System
    </div>
</section>

<?php require __DIR__ . '/../includes/layout_end.php';
