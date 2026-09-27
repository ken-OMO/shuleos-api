<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Teacher Duty Weekly Report</title>

    <style>
        @page {
            margin: 24mm 14mm 20mm 14mm;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10.5px;
            line-height: 1.45;
            color: #111;
        }

        .header {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }

        .header td {
            border: 0;
            vertical-align: middle;
        }

        .logo-cell {
            width: 82px;
        }

        .logo {
            width: 68px;
            height: 68px;
            object-fit: contain;
        }

        .school-name {
            text-align: center;
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 3px;
        }

        h1 {
            text-align: center;
            font-size: 15px;
            margin: 0;
        }

        .meta,
        .teachers,
        .evidence,
        .workflow {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }

        .meta td,
        .teachers th,
        .teachers td,
        .evidence th,
        .evidence td,
        .workflow td {
            border: 1px solid #444;
            padding: 5px 6px;
            vertical-align: top;
        }

        .teachers th,
        .evidence th {
            font-weight: bold;
            text-align: left;
        }

        .label {
            font-weight: bold;
            width: 18%;
        }

        .section {
            margin-top: 11px;
            margin-bottom: 4px;
            font-size: 11.5px;
            font-weight: bold;
        }

        .narrative {
            min-height: 35px;
            border: 1px solid #444;
            padding: 7px;
            margin-bottom: 8px;
            white-space: pre-wrap;
        }

        .muted {
            color: #555;
        }

        .signature-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 22px;
        }

        .signature-table td {
            width: 50%;
            border: 0;
            vertical-align: top;
            padding: 8px 4px;
        }

        .signature-line {
            margin-top: 22px;
        }

        .footer {
            position: fixed;
            left: 0;
            right: 0;
            bottom: -13mm;
            font-size: 8.5px;
            color: #555;
        }

        .footer-left {
            float: left;
        }

        .footer-right {
            float: right;
        }
        .page-number:after {
            content: counter(page);
        }

        thead {
            display: table-header-group;
        }

        tr {
            page-break-inside: avoid;
        }
    </style>
</head>

<body>
@php($printData = $printData ?? [])

<table class="header">
    <tr>
        <td class="logo-cell">
            @if($logo)
                <img
                    class="logo"
                    src="{{ $logo }}"
                    alt="School logo"
                >
            @endif
        </td>

        <td>
            <div class="school-name">
                {{ $printData['school_name'] ?? "\u{2014}" }}
            </div>

            <h1>
                {{ $printData['title'] ?? 'TEACHER DUTY WEEKLY REPORT' }}
            </h1>
        </td>

        <td class="logo-cell"></td>
    </tr>
</table>

<table class="meta">
    <tr>
        <td class="label">School:</td>
        <td colspan="3">
            {{ $printData['school_name'] ?? "\u{2014}" }}
        </td>
    </tr>

    <tr>
        <td class="label">Academic Year:</td>
        <td>
            {{ $printData['week']['academic_year'] ?? "\u{2014}" }}
        </td>

        <td class="label">Term:</td>
        <td>
            {{ $printData['week']['term'] ?? "\u{2014}" }}
        </td>
    </tr>

    <tr>
        <td class="label">Week:</td>
        <td>
            {{ $printData['week']['number'] ?? "\u{2014}" }}
        </td>

        <td class="label">Period:</td>
        <td>
            {{ $printData['week']['start_date'] ?? "\u{2014}" }}
            to
            {{ $printData['week']['end_date'] ?? "\u{2014}" }}
        </td>
    </tr>
</table>

<div class="section">Responsible Teacher(s)</div>

<table class="teachers">
    <thead>
        <tr>
            <th>Responsible Teacher</th>
            <th>TSC No.</th>
        </tr>
    </thead>

    <tbody>
        @forelse(($printData['teachers'] ?? []) as $teacher)
            <tr>
                <td>{{ $teacher['name'] ?? "\u{2014}" }}</td>
                <td>{{ $teacher['tsc_no'] ?? "\u{2014}" }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="2">{{ "\u{2014}" }}</td>
            </tr>
        @endforelse
    </tbody>
</table>

<div class="section">Summary</div>
<div class="narrative">
    {{ $printData['report']['summary'] ?? "\u{2014}" }}
</div>

<div class="section">Highlights</div>
<div class="narrative">
    {{ $printData['report']['highlights'] ?? "\u{2014}" }}
</div>

<div class="section">Challenges</div>
<div class="narrative">
    {{ $printData['report']['challenges'] ?? "\u{2014}" }}
</div>

<div class="section">Recommendations</div>
<div class="narrative">
    {{ $printData['report']['recommendations'] ?? "\u{2014}" }}
</div>

<div class="section">Weekly Evidence</div>

@php($evidence = $printData['evidence'] ?? [])

<table class="evidence">
    <thead>
        <tr>
            <th>Daily Reports</th>
            <th>Count</th>
        </tr>
    </thead>

    <tbody>
        <tr>
            <td>Expected</td>
            <td>
                {{ $evidence['expected_daily_report_count'] ?? "\u{2014}" }}
            </td>
        </tr>

        <tr>
            <td>Submitted</td>
            <td>
                {{ $evidence['submitted_daily_report_count'] ?? "\u{2014}" }}
            </td>
        </tr>

        <tr>
            <td>Late Submitted</td>
            <td>
                {{ $evidence['late_submitted_daily_report_count'] ?? "\u{2014}" }}
            </td>
        </tr>

        <tr>
            <td>Draft</td>
            <td>
                {{ $evidence['draft_daily_report_count'] ?? "\u{2014}" }}
            </td>
        </tr>

        <tr>
            <td>Overdue</td>
            <td>
                {{ $evidence['overdue_daily_report_count'] ?? "\u{2014}" }}
            </td>
        </tr>

        <tr>
            <td>Not Started</td>
            <td>
                {{ $evidence['not_started_daily_report_count'] ?? "\u{2014}" }}
            </td>
        </tr>
    </tbody>
</table>

<table class="evidence">
    <thead>
        <tr>
            <th>Occurrences</th>
            <th>Count</th>
        </tr>
    </thead>

    <tbody>
        <tr>
            <td>Total Occurrences</td>
            <td>
                {{ $evidence['total_occurrence_count'] ?? "\u{2014}" }}
            </td>
        </tr>

        @forelse(
            ($evidence['occurrence_category_breakdown'] ?? [])
            as $category => $count
        )
            <tr>
                <td>
                    {{ str_replace('_', ' ', ucfirst((string) $category)) }}
                </td>
                <td>{{ $count }}</td>
            </tr>
        @empty
            <tr>
                <td>Occurrence Categories</td>
                <td>{{ "\u{2014}" }}</td>
            </tr>
        @endforelse
    </tbody>
</table>

<div class="section">Submission &amp; Review</div>

@php($workflow = $printData['workflow'] ?? [])

<table class="workflow">
    <tr>
        <td class="label">Status</td>
        <td>
            {{ $workflow['status'] ?? "\u{2014}" }}
        </td>
    </tr>

    <tr>
        <td class="label">Submitted By</td>
        <td>
            {{ $workflow['submitted_by_name'] ?? "\u{2014}" }}
        </td>
    </tr>

    <tr>
        <td class="label">Submitted At</td>
        <td>
            {{ $workflow['submitted_at'] ?? "\u{2014}" }}
        </td>
    </tr>

    @if(!empty($workflow['reviewed_by_name']))
        <tr>
            <td class="label">Reviewed By</td>
            <td>
                {{ $workflow['reviewed_by_name'] }}
            </td>
        </tr>
    @endif

    @if(!empty($workflow['reviewed_at']))
        <tr>
            <td class="label">Reviewed At</td>
            <td>
                {{ $workflow['reviewed_at'] }}
            </td>
        </tr>
    @endif

    @if(!empty($workflow['review_comment']))
        <tr>
            <td class="label">Review Comment</td>
            <td>
                {{ $workflow['review_comment'] }}
            </td>
        </tr>
    @endif
</table>

<table class="signature-table">
    <tr>
        <td>
            <div class="signature-line">
                Teacher's Signature:
                __________________________
            </div>

            <div class="signature-line">
                Date:
                __________________
            </div>
        </td>

        <td>
            <div class="signature-line">
                School Stamp:
                ______________________________
            </div>
        </td>
    </tr>
</table>

<div class="footer">
    <span class="footer-left">
        Generated by ShuleOS
    </span>

    <span class="footer-right">
        Page <span class="page-number"></span>
    </span>
</div>

</body>
</html>
