<?php

namespace Webkul\Ondc\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OndcReverseQcResult extends Model
{
    protected $table = 'ondc_reverse_qc_results';

    protected $fillable = [
        'return_request_id',
        'qc_status',
        'qc_initiated_at',
        'qc_completed_at',
        'pickup_proof_code',
        'delivery_proof_code',
        'qc_notes',
        'failure_reason',
        'approved_refund',
        'inspector',
        'evidence_urls',
        'checklist',
    ];

    protected $casts = [
        'qc_initiated_at' => 'datetime',
        'qc_completed_at' => 'datetime',
        'approved_refund' => 'decimal:2',
        'evidence_urls'   => 'array',
        'checklist'       => 'array',
    ];

    public const QC_INITIATED    = 'INITIATED';
    public const QC_PICKUP_DONE  = 'PICKUP_DONE';
    public const QC_IN_PROGRESS  = 'IN_PROGRESS';
    public const QC_PASSED       = 'PASSED';
    public const QC_FAILED       = 'FAILED';
    public const QC_PARTIAL_PASS = 'PARTIAL_PASS';

    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(OndcReturnRequest::class, 'return_request_id');
    }
}
