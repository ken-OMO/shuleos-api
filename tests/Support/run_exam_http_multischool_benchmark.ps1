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
        $info.Arguments = "tests/Support/run_exam_http_benchmark.php $Learners"
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

$stages = @('http_summary', 'http_details', 'http_pdf_sample')
$results = @()

foreach ($worker in $workers) {
    $rows = @($worker.Output -split '\r?\n' |
        Where-Object { $_.StartsWith('{') } |
        ForEach-Object { $_ | ConvertFrom-Json })

    if ($worker.ExitCode -ne 0 -or
        @($rows | Where-Object { $_.status -eq 'failed' }).Count -gt 0) {
        throw "Worker $($worker.Number) failed. Inspect its saved logs before rerunning."
    }

    $cleanup = @($rows | Where-Object {
        $_.operation -eq 'cleanup' -and $_.status -eq 'completed' -and
        $_.synthetic_school_remaining -eq 0
    })
    $complete = @($rows | Where-Object {
        $_.operation -eq 'completeness' -and $_.status -eq 'completed' -and
        $_.summary.school_isolation_verified -eq $true -and
        $_.summary.report_cards -eq $Learners -and
        $_.summary.report_card_details -eq ($Learners * 6)
    })

    if ($cleanup.Count -ne 1 -or $complete.Count -ne 1) {
        throw 'Output coverage or fixture cleanup verification failed.'
    }

    $measurements = @{}
    foreach ($stage in $stages) {
        $matches = @($rows | Where-Object {
            $_.operation -eq $stage -and $_.status -eq 'completed'
        })
        if ($matches.Count -ne 1) {
            throw "Missing completed stage: $stage"
        }

        $summary = $matches[0].summary
        $expectedRequests = 1
        if ($stage -eq 'http_pdf_sample') {
            $expectedRequests = [Math]::Min(10, $Learners)
        }

        if ($summary.learners -ne $Learners -or
            $summary.requests -ne $expectedRequests -or
            $summary.response_validation_passed -ne $true -or
            $summary.middleware_enabled -ne $true -or
            [double]$summary.response_bytes -le 0 -or
            [double]$summary.backend_pid -le 0 -or
            -not $summary.school_id -or -not $summary.exam_id -or
            [double]$summary.started_unix -le 0 -or
            [double]$summary.finished_unix -le [double]$summary.started_unix) {
            throw "Invalid HTTP measurement: $stage"
        }

        $measurements[$stage] = $matches[0]
    }

    $results += [pscustomobject]@{
        worker = $worker.Number
        exit_code = $worker.ExitCode
        stages = $measurements
    }
}

$overlaps = @{}
foreach ($stage in $stages) {
    $a = $results[0].stages[$stage].summary
    $b = $results[1].stages[$stage].summary

    if ($a.school_id -eq $b.school_id -or
        $a.exam_id -eq $b.exam_id -or
        $a.backend_pid -eq $b.backend_pid) {
        throw "Distinct schools and sessions required: $stage"
    }

    $overlap = [Math]::Min(
        [double]$a.finished_unix, [double]$b.finished_unix
    ) - [Math]::Max(
        [double]$a.started_unix, [double]$b.started_unix
    )
    if ($overlap -le 0) {
        throw "Stage did not overlap: $stage. Do not claim concurrency for this stage."
    }
    $overlaps[$stage] = [Math]::Round($overlap, 3)
}

$report = [ordered]@{
    status = 'completed'
    scope = 'authenticated_http_kernel_without_network_transport'
    learners_per_school = $Learners
    pdf_sample_per_school = [Math]::Min(10, $Learners)
    overlaps_seconds = $overlaps
    both_fixtures_cleaned = $true
    workers = $results
}

$resultPath = Join-Path $logRoot "multischool-http-$runId-summary.json"
[IO.File]::WriteAllText(
    $resultPath, ($report | ConvertTo-Json -Depth 12),
    [Text.UTF8Encoding]::new($false)
)

$display = foreach ($result in $results) {
    foreach ($stage in $stages) {
        $measurement = $result.stages[$stage]
        [pscustomobject]@{
            Worker = $result.worker
            Stage = $stage
            Requests = $measurement.summary.requests
            Seconds = [Math]::Round($measurement.elapsed_ms / 1000, 2)
            Queries = $measurement.query_count
            PeakMB = $measurement.peak_memory_mb
            ResponseBytes = $measurement.summary.response_bytes
        }
    }
}
$display | Format-Table -AutoSize
$overlaps | ConvertTo-Json -Compress
Write-Host 'HTTP_RESPONSE_VALIDATION=PASS'
Write-Host 'BOTH_FIXTURES_CLEANED=YES'
Write-Host "RESULT_FILE=$resultPath"