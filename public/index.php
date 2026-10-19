<?php

use Aws\S3\S3Client;
use Bramus\Router\Router;
use Dompdf\Dompdf;

require __DIR__ . '/../vendor/autoload.php';

function readCsv(string $filename): array
{
    $path = __DIR__ . '/../data/' . $filename;
    $handle = fopen($path, 'rb');

    if ($handle === false) {
        throw new RuntimeException("Unable to open CSV file: {$path}");
    }

    try {
        $headers = fgetcsv($handle);
        if ($headers === false || $headers === [null]) {
            throw new RuntimeException("CSV file has no header row: {$path}");
        }

        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
        $rows = [];

        while (($values = fgetcsv($handle)) !== false) {
            if ($values === [null]) {
                continue;
            }
            if (count($values) !== count($headers)) {
                throw new RuntimeException("CSV row has an unexpected number of columns: {$path}");
            }

            $rows[] = array_combine($headers, $values);
        }

        return $rows;
    } finally {
        fclose($handle);
    }
}

function jsonResponse(array $body, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_THROW_ON_ERROR);
}

function apiError(Throwable $error): void
{
    error_log((string) $error);
    jsonResponse(['success' => false, 'message' => 'An internal server error occurred.'], 500);
}

function findPayrollRecord(string $payrollId): ?array
{
    foreach (readCsv('payroll.csv') as $record) {
        if ((string) ($record['id'] ?? $record['payroll_id'] ?? '') === $payrollId) {
            return $record;
        }
    }

    return null;
}

function findEmployee(string $employeeId): ?array
{
    foreach (readCsv('employees.csv') as $employee) {
        if ((string) ($employee['id'] ?? $employee['employee_id'] ?? '') === $employeeId) {
            return $employee;
        }
    }

    return null;
}

function payrollFigures(array $record): array
{
    $gross = (float) ($record['gross_pay'] ?? 0);
    $net = (float) ($record['net_pay'] ?? 0);

    return [
        'gross_pay' => $gross,
        'deductions' => isset($record['deductions']) ? (float) $record['deductions'] : $gross - $net,
        'net_pay' => $net,
    ];
}

function employeeName(?array $employee): string
{
    if ($employee === null) {
        return 'Unknown Employee';
    }

    return $employee['full_name']
        ?? $employee['name']
        ?? trim(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? ''));
}

function payslipDetails(string $payrollId, array $record): array
{
    $employee = findEmployee((string) ($record['employee_id'] ?? ''));

    return [
        'payroll_id' => (int) $payrollId,
        'employee' => $employee,
        'employee_name' => employeeName($employee),
        'pay_period_start' => $record['pay_period_start'] ?? null,
        'pay_period_end' => $record['pay_period_end'] ?? null,
        ...payrollFigures($record),
    ];
}

$router = new Router();
$router->setBasePath('');

$router->get('/health', function () {
    jsonResponse(['success' => true, 'status' => 'ok']);
});

$router->get('/api/employees', function () {
    try {
        jsonResponse(['success' => true, 'data' => readCsv('employees.csv')]);
    } catch (Throwable $error) {
        apiError($error);
    }
});

$router->get('/api/reports/payroll', function () {
    try {
        $payroll = readCsv('payroll.csv');
        $totals = ['gross' => 0.0, 'deductions' => 0.0, 'net' => 0.0];

        foreach ($payroll as $record) {
            $figures = payrollFigures($record);
            $totals['gross'] += $figures['gross_pay'];
            $totals['deductions'] += $figures['deductions'];
            $totals['net'] += $figures['net_pay'];
        }

        jsonResponse([
            'success' => true,
            'data' => [
                'total_payroll_records' => count($payroll),
                'total_gross_pay' => round($totals['gross'], 2),
                'total_deductions' => round($totals['deductions'], 2),
                'total_net_pay' => round($totals['net'], 2),
            ],
        ]);
    } catch (Throwable $error) {
        apiError($error);
    }
});

$router->get('/api/payslips/([0-9]+)', function ($payrollId) {
    try {
        $record = findPayrollRecord($payrollId);
        if ($record === null) {
            jsonResponse(['success' => false, 'message' => 'Payslip not found.'], 404);
            return;
        }

        $details = payslipDetails($payrollId, $record);
        unset($details['employee']);
        jsonResponse(['success' => true, 'data' => $details]);
    } catch (Throwable $error) {
        apiError($error);
    }
});

$router->post('/api/payslips/([0-9]+)/generate', function ($payrollId) {
    try {
        $record = findPayrollRecord($payrollId);
        if ($record === null) {
            jsonResponse(['success' => false, 'message' => 'Payslip not found.'], 404);
            return;
        }

        $details = payslipDetails($payrollId, $record);
        $employee = $details['employee'];
        $escape = static fn ($value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<h1>Employee Payslip</h1><table>'
            . '<tr><th>Payroll ID</th><td>' . $escape($details['payroll_id']) . '</td></tr>'
            . '<tr><th>Employee</th><td>' . $escape($details['employee_name']) . '</td></tr>'
            . '<tr><th>Employee ID</th><td>' . $escape($record['employee_id'] ?? '') . '</td></tr>'
            . '<tr><th>Email</th><td>' . $escape($employee['email'] ?? '') . '</td></tr>'
            . '<tr><th>Department ID</th><td>' . $escape($employee['department_id'] ?? '') . '</td></tr>'
            . '<tr><th>Pay period</th><td>' . $escape($details['pay_period_start']) . ' to '
            . $escape($details['pay_period_end']) . '</td></tr>'
            . '<tr><th>Gross pay</th><td>' . number_format($details['gross_pay'], 2) . '</td></tr>'
            . '<tr><th>Deductions</th><td>' . number_format($details['deductions'], 2) . '</td></tr>'
            . '<tr><th>Net pay</th><td>' . number_format($details['net_pay'], 2) . '</td></tr>'
            . '</table><style>body{font-family: sans-serif}table{border-collapse:collapse;width:100%}'
            . 'th,td{border:1px solid #ccc;padding:8px;text-align:left}th{width:30%}</style>';

        $dompdf = new Dompdf();
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        $bucket = getenv('S3_BUCKET');
        if ($bucket === false || $bucket === '') {
            throw new RuntimeException('S3_BUCKET environment variable is not configured.');
        }

        $region = getenv('S3_REGION') ?: 'ap-southeast-1';
        $key = "payslips/{$payrollId}.pdf";
        $s3 = new S3Client(['version' => 'latest', 'region' => $region]);
        $s3->putObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'Body' => $dompdf->output(),
            'ContentType' => 'application/pdf',
        ]);
        $downloadCommand = $s3->getCommand('GetObject', [
            'Bucket' => $bucket,
            'Key' => $key,
        ]);
        $downloadUrl = (string) $s3->createPresignedRequest($downloadCommand, '+15 minutes')->getUri();

        jsonResponse([
            'success' => true,
            'message' => 'Payslip generated and uploaded.',
            'data' => [
                'payroll_id' => (int) $payrollId,
                's3_key' => $key,
                'download_url' => $downloadUrl,
            ],
        ]);
    } catch (Throwable $error) {
        apiError($error);
    }
});

$router->set404(function () {
    jsonResponse(['success' => false, 'message' => 'Endpoint not found.'], 404);
});

$router->run();
