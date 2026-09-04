<?php

$retentionMonths = filter_var(
    env('ACTIVITY_LOG_RETENTION_MONTHS', 3),
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]],
);

return [
    'retention_months' => $retentionMonths === false ? 3 : $retentionMonths,
];
