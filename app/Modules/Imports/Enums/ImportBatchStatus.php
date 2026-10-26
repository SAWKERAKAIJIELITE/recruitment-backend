<?php

namespace App\Modules\Imports\Enums;

enum ImportBatchStatus: string
{
    /** Uploaded and checked; rows can be corrected, reviewed and excluded until approval. */
    case Pending = 'pending';

    /** Approved: its valid rows became applications. Nothing in the batch changes any more. */
    case Approved = 'approved';

    /** Cancelled before approval: nothing was imported. The same file may be uploaded again. */
    case Cancelled = 'cancelled';
}
