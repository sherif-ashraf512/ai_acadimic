<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SetupFile extends Model
{
    protected $fillable = [
        'collage_list',
        'student_formula',
        'processing_status',
        'processing_error',
        'processed_at',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
    ];

    // ── Accessors ──────────────────────────────────────────────────────────────

    public function getCollageListAttribute($value): ?string
    {
        return $value ? asset("storage/{$value}") : null;
    }

    public function getStudentFormulaAttribute($value): ?string
    {
        return $value ? asset("storage/{$value}") : null;
    }
}
