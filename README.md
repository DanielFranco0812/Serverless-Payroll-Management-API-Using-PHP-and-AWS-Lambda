# Serverless Payroll Management API

A PHP payroll API deployed to AWS Lambda with Bref and API Gateway. Employee and payroll records are read from CSV files in `data/`; generated PDF payslips are uploaded to S3.

## Requirements

- PHP 8.2
- Composer
- Node.js and npm
- An AWS account, an existing S3 bucket, and AWS credentials configured locally

## Install and configure

```powershell
composer install
npm install -g serverless
```

The Serverless configuration uses the existing bucket `payroll-franco` in `ap-southeast-1`. Change `S3_BUCKET` in `serverless.yml` if your bucket has a different name. The Lambda execution role is granted `s3:PutObject` only for `payslips/*`.

Configure AWS deployment credentials on your machine using a local AWS profile or the AWS CLI. Do not commit credentials or put access keys in application code.

## Deploy

```powershell
npx serverless deploy
```

Serverless prints the API Gateway URL after deployment. Use that URL as `<API_BASE_URL>` below.

## Endpoints

| Method | Path | Description |
| --- | --- | --- |
| GET | `/api/employees` | Return employee CSV rows as JSON. |
| GET | `/api/reports/payroll` | Return payroll count and gross, deductions, and net totals. Deductions are gross pay minus net pay when not provided by the CSV. |
| GET | `/api/payslips/{payroll_id}` | Return an individual payroll record with employee and pay details. |
| POST | `/api/payslips/{payroll_id}/generate` | Generate a PDF payslip, upload it to S3, and return its S3 key and URL. |

Example requests:

```powershell
Invoke-RestMethod -Method Get -Uri "<API_BASE_URL>/api/employees"
Invoke-RestMethod -Method Get -Uri "<API_BASE_URL>/api/reports/payroll"
Invoke-RestMethod -Method Get -Uri "<API_BASE_URL>/api/payslips/1"
Invoke-RestMethod -Method Post -Uri "<API_BASE_URL>/api/payslips/1/generate"
```

The payroll ID in the URL must exist in `data/payroll.csv`. The generated object is stored at `payslips/{payroll_id}.pdf` in the configured bucket.