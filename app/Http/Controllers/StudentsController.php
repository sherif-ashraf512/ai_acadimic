<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;


class StudentsController extends Controller
{

    public function index(Request $request)
    {
        $per_page = $request->per_page ?? 10;
        $page = $request->page ?? 1;
        $search = $request->search;
        $level = $request->level;
        
        $students = User::where('role', 'student');

        if($search){
            $students->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")->orWhere('national_id', 'like', "%{$search}%");
        }

        if($level){
            $students->where('level', 'like', "%{$level}%");
        }

        $students = $students->paginate($per_page, ['*'], 'page', $page);
        return $this->paginated($students, 'Students fetched successfully');
    }

    public function show(Request $request, $id)
    {
        $student = User::where('role', 'student')->where('id', $id)->first();
        if(!$student){
            return $this->error('Student not found', 404);
        }

        return $this->success($student, 'Student fetched successfully');
    }
}
