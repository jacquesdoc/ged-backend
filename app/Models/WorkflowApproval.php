<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkflowApproval extends Model
{
    use HasFactory;

    protected $fillable = [
        'workflow_id', 'approver_id', 'step',
        'status', 'comment', 'acted_at',
    ];

    protected $casts = [
        'acted_at' => 'datetime',
        'step'     => 'integer',
    ];

    public function workflow()
    {
        return $this->belongsTo(Workflow::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}