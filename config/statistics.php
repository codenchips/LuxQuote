<?php

return [
    'high_value_threshold' => max(0, (float) env('STATISTICS_HIGH_VALUE_THRESHOLD', 25000)),
    'inactive_days' => max(1, (int) env('STATISTICS_INACTIVE_DAYS', 30)),
    'max_range_days' => max(31, (int) env('STATISTICS_MAX_RANGE_DAYS', 3650)),
];
