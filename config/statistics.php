<?php

return [
    'high_value_threshold' => max(0, (float) env('STATISTICS_HIGH_VALUE_THRESHOLD', 25000)),
    'inactive_days' => max(1, (int) env('STATISTICS_INACTIVE_DAYS', 30)),
];
