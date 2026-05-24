<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    protected $fillable = [
        'name',
        'code',
        'credit_hours',
        'prerequisite',
    ];

    // ── Relationships ──────────────────────────────────────

    public function studentCourses()
    {
        return $this->hasMany(StudentCourses::class, 'course_id');
    }

    public function materialRequests()
    {
        return $this->hasMany(MaterialRequest::class, 'course_id');
    }
}
