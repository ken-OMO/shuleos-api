param(
    [ValidateRange(1, 2000)]
    [int]$Learners = 251,
    [ValidateSet('processing', 'outputs')]
    [string]$Workload = 'processing'
)

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$php = (Get-Command php -ErrorAction Stop).Source
$runId = [guid]::NewGuid().ToString('N')
$logRoot = Join-Path $repoRoot 'storage/logs'
$workers = @()
$launchError = $null
$operation = 'full_processing'
$runnerArguments = "tests/Support/run_exam_processing_benchmark.php $Learners first-time"
if ($Workload -eq 'outputs') {
    $operation = 'report_card_generation'
    $runnerArguments = "tests/Support/run_exam_output_benchmark.php $Learners"
}

try {
    foreach ($number in 1,2) {
        $info = New-Object System.Diagnostics.ProcessStartInfo
        $info.FileName = $php
        $info.Arguments = $runnerArguments
        $info.WorkingDirectory = $repoRoot
        $info.UseShellExecute = $false
        $info.CreateNoWindow = $true
        $info.RedirectStandardOutput = $true
        $info.RedirectStandardError = $true

        $process = New-Object System.Diagnostics.Process
        $process.StartInfo = $info

        if (-not $process.Start()) {
            throw "Worker $number did not start."
        }

        $worker = [pscustomobject]@{
            Number = $number
            Process = $process
            OutputTask = $process.StandardOutput.ReadToEndAsync()
            ErrorTask = $process.StandardError.ReadToEndAsync()
            ExitCode = $null
            Output = $null
            Errors = $null
        }
        $workers += $worker

        Write-Host "Started worker $number; PID=$($process.Id); learners=$Learners"
    }
}
catch {
    $launchError = $_
}
finally {
    # Drain output asynchronously and allow every started runner to clean up.
    foreach ($worker in $workers) {
        $worker.Process.WaitForExit()
        $worker.ExitCode = $worker.Process.ExitCode
        $worker.Output = $worker.OutputTask.GetAwaiter().GetResult()
        $worker.Errors = $worker.ErrorTask.GetAwaiter().GetResult()

        $prefix = Join-Path $logRoot "multischool-$runId-$($worker.Number)"
        [IO.File]::WriteAllText(
            "$prefix.stdout.log", $worker.Output,
            [Text.UTF8Encoding]::new($false)
        )
        [IO.File]::WriteAllText(
            "$prefix.stderr.log", $worker.Errors,
            [Text.UTF8Encoding]::new($false)
        )

        Write-Host "Worker $($worker.Number) exit code: $($worker.ExitCode)"
        Write-Host "OUTPUT_FILE=$prefix.stdout.log"
        if ($worker.Errors) {
            Write-Host $worker.Errors
        }
        $worker.Process.Dispose()
    }
}

if ($launchError) {
    throw $launchError
}
if ($workers.Count -ne 2) {
    throw 'Expected two workers.'
}

$results = @()

foreach ($worker in $workers) {
    $rows = @($worker.Output -split '\r?\n' |
        Where-Object { $_.StartsWith('{') } |
        ForEach-Object { $_ | ConvertFrom-Json })

    $processing = @($rows | Where-Object {
        $_.operation -eq $operation -and $_.status -eq 'completed'
    })
    $complete = @($rows | Where-Object {
        $_.operation -eq 'completeness' -and $_.status -eq 'completed'
    })
    $cleanup = @($rows | Where-Object {
        $_.operation -eq 'cleanup' -and $_.status -eq 'completed' -and
        $_.synthetic_school_remaining -eq 0
    })
    $failures = @($rows | Where-Object { $_.status -eq 'failed' })

    if ($worker.ExitCode -ne 0 -or $failures.Count -gt 0 -or
        $processing.Count -ne 1 -or $complete.Count -ne 1 -or
        $cleanup.Count -ne 1) {
        throw "Worker $($worker.Number) failed verification. Inspect its saved logs before rerunning."
    }

    $summary = $processing[0].summary
    $verified = $complete[0].summary

    if ($summary.learners -ne $Learners -or
        ($Workload -eq 'processing' -and $summary.processed_operations -ne ($Learners * 6)) -or
        $verified.complete -ne $true -or
        $verified.learners -ne $Learners -or
        $verified.processed_results -ne ($Learners * 6) -or
        $verified.school_isolation_verified -ne $true -or
        -not $summary.school_id -or -not $summary.exam_id -or
        [double]$summary.backend_pid -le 0 -or
        [double]$summary.started_unix -le 0 -or
        [double]$summary.finished_unix -le [double]$summary.started_unix) {
        throw "Worker $($worker.Number) has invalid counts, ownership checks or timing."
    }

    $merit = $null
    if ($Workload -eq 'outputs') {
        $merits = @($rows | Where-Object {
            $_.operation -eq 'merit_generation' -and $_.status -eq 'completed'
        })
        if ($merits.Count -ne 1) {
            throw 'Expected one completed merit-generation stage.'
        }
        $merit = $merits[0]

        if ($summary.generated_count -ne $Learners -or
            $verified.merits -ne $Learners -or
            $verified.report_cards -ne $Learners -or
            $verified.report_card_details -ne ($Learners * 6) -or
            $merit.summary.generated_count -ne $Learners -or
            $merit.summary.school_id -ne $summary.school_id -or
            $merit.summary.exam_id -ne $summary.exam_id -or
            $merit.summary.backend_pid -ne $summary.backend_pid -or
            [double]$merit.summary.started_unix -le 0 -or
            [double]$merit.summary.finished_unix -le [double]$merit.summary.started_unix) {
            throw 'Output counts, identities or merit timing failed verification.'
        }
    }

    $results += [pscustomobject]@{
        merit = $merit
        worker = $worker.Number
        exit_code = $worker.ExitCode
        processing = $processing[0]
        completeness = $complete[0]
        cleanup = $cleanup[0]
    }
}

$a = $results[0].processing.summary
$b = $results[1].processing.summary

if ($a.school_id -eq $b.school_id -or
    $a.exam_id -eq $b.exam_id -or
    $a.backend_pid -eq $b.backend_pid) {
    throw 'Workers must use distinct schools, exams and database sessions.'
}

$overlap = [Math]::Min(
    [double]$a.finished_unix, [double]$b.finished_unix
) - [Math]::Max(
    [double]$a.started_unix, [double]$b.started_unix
)

if ($overlap -le 0) {
    throw 'Processing did not overlap; concurrent processing is unverified.'
}

$meritOverlap = $null
if ($Workload -eq 'outputs') {
    $ma = $results[0].merit.summary
    $mb = $results[1].merit.summary
    $meritOverlap = [Math]::Min(
        [double]$ma.finished_unix, [double]$mb.finished_unix
    ) - [Math]::Max(
        [double]$ma.started_unix, [double]$mb.started_unix
    )
    if ($meritOverlap -le 0) {
        throw 'Merit generation did not overlap; concurrency is unverified.'
    }
    Write-Host "MERIT_OVERLAP_SECONDS=$([Math]::Round($meritOverlap, 3))"
    Write-Host "REPORT_CARD_OVERLAP_SECONDS=$([Math]::Round($overlap, 3))"
}

$report = [ordered]@{
    workload = $Workload
    merit_overlap_seconds = $meritOverlap
    status = 'completed'
    scope = "two_school_service_$Workload"
    learners_per_school = $Learners
    total_processing_operations = $(if ($Workload -eq 'processing') { $Learners * 12 } else { 0 })
    overlap_seconds = [Math]::Round($overlap, 3)
    distinct_schools_and_sessions = $true
    result_counts_and_school_isolation_verified = $true
    both_fixtures_cleaned = $true
    workers = $results
}

$resultPath = Join-Path $logRoot "multischool-$runId-summary.json"
[IO.File]::WriteAllText(
    $resultPath, ($report | ConvertTo-Json -Depth 12),
    [Text.UTF8Encoding]::new($false)
)

$results | ForEach-Object {
    [pscustomobject]@{
        Worker = $_.worker
        ExitCode = $_.exit_code
        Learners = $_.processing.summary.learners
        Operations = $(if ($Workload -eq 'processing') { $_.processing.summary.processed_operations } else { $_.processing.summary.generated_count })
        Seconds = [Math]::Round($_.processing.elapsed_ms / 1000, 2)
        Queries = $_.processing.query_count
        PeakMB = $_.processing.peak_memory_mb
    }
} | Format-Table -AutoSize

Write-Host "WORKLOAD=$Workload; STAGE=$operation; OVERLAP_SECONDS=$([Math]::Round($overlap, 3))"
Write-Host 'RESULT_COUNTS_AND_SCHOOL_ISOLATION=PASS'
Write-Host 'BOTH_FIXTURES_CLEANED=YES'
Write-Host "RESULT_FILE=$resultPath"