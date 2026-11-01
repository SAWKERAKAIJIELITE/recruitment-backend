<?php

// Reports are printed in Arabic (they are read by candidates); these English lines are for messages to staff only.
return [
    'too_many_rows' => 'The report holds :count applications; this format holds at most :max: narrow the filters.',
] + require __DIR__ . '/../ar/reports.php';
