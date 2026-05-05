<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Workflow extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_id', 'requested_by', 'type',
        'status', 'current_step', 'steps',
        'notes', 'due_date', 'completed_at',
    ];

    protected $casts = [
        'steps'        => 'array',
        'due_date'     => 'datetime',
        'completed_at' => 'datetime',
    ];

    const STATUS_PENDING   = 'pending';
    const STATUS_IN_REVIEW = 'in_review';
    const STATUS_APPROVED  = 'approved';
    const STATUS_REJECTED  = 'rejected';
    const STATUS_CANCELLED = 'cancelled';

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvals()
    {
        return $this->hasMany(WorkflowApproval::class)->with('approver');
    }
}