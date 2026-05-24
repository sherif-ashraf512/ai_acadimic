<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SetupFile extends Model
{
    protected $fillable = [
        'collage_list',
        'student_formula',
    ];

    public function getCollageListAttribute($value)
    {
        if($value){
            return asset("storage/$value");
        }
        return null;
    }

    public function getStudentFormulaAttribute($value)
    {
        if($value){
            return asset("storage/$value");
        }
        return null;
    }
}
