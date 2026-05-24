<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentCourses extends Model
{
    protected $fillable = [
        'student_id',
        'course_id',
        'is_passed'
    ];

    public function student()
    {
        return $this->belongsTo(User::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }
}
