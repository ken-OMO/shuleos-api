<?php

return ['teacher_correction_hours' => (int) env('ATTENDANCE_TEACHER_CORRECTION_HOURS', 24), 'late_alert_minutes' => (int) env('ATTENDANCE_LATE_ALERT_MINUTES', 30), 'chronic_absence_count' => (int) env('ATTENDANCE_CHRONIC_ABSENCE_COUNT', 3)];
