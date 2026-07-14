<?php

return ['teacher_correction_hours' => (int) env('ATTENDANCE_TEACHER_CORRECTION_HOURS', 24), 'late_alert_minutes' => (int) env('ATTENDANCE_LATE_ALERT_MINUTES', 30), 'chronic_absence_count' => (int) env('ATTENDANCE_CHRONIC_ABSENCE_COUNT', 3), 'risk' => ['repeated_absence' => (int) env('ATTENDANCE_RISK_ABSENCE', 3), 'repeated_lateness' => (int) env('ATTENDANCE_RISK_LATENESS', 3), 'low_rate' => (float) env('ATTENDANCE_RISK_LOW_RATE', 80)]];
