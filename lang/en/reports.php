<?php

// Reports are printed in Arabic (they are read by candidates); these English lines are for messages to staff only.
return [
    'too_many_rows' => 'The report holds :count rows; this format holds at most :max: narrow the filters or the period.',
    'empty_period' => 'The year and the dates sent do not overlap: the period is empty.',
] + require __DIR__ . '/../ar/reports.php';
