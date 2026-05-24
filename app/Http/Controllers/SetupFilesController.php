<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Imports\StudentsImport;
use App\Models\SetupFile;
use Illuminate\Support\Facades\Storage;

class SetupFilesController extends Controller
{
    public function index(Request $request)
    {
        $setupFile = SetupFile::first();

        if(!$setupFile){
            return $this->error('Setup file not found', 404);
        }

        return $this->success($setupFile, 'Setup file fetched successfully');
    }

    public function import(Request $request)
    {
        $request->validate([
            'collage_list' => 'required|file|mimes:pdf',
            'student_formula' => 'required|file|mimes:xlsx,csv,xls',
        ]);

        (new StudentsImport)->queue($request->file('student_formula'));

        $setupFile = SetupFile::first();

        $collage_list_file = $request->file('collage_list');
        $collage_list_file_path = $collage_list_file->getClientOriginalName();
        $collage_list_file->storeAs('setup_files', $collage_list_file_path,'public');

        $student_formula_file = $request->file('student_formula');
        $student_formula_file_path = $student_formula_file->getClientOriginalName();
        $student_formula_file->storeAs('setup_files', $student_formula_file_path,'public');
        
        if($setupFile){
            Storage::delete($setupFile->collage_list); 
            Storage::delete($setupFile->student_formula);
            $setupFile->update([
                'collage_list' => "setup_files/$collage_list_file_path",
                'student_formula' => "setup_files/$student_formula_file_path",
            ]);
        }else{
            $setupFile = SetupFile::create([
                'collage_list' => "setup_files/$collage_list_file_path",
                'student_formula' => "setup_files/$student_formula_file_path",
            ]);
        }

        return $this->success($setupFile, 'Import queued successfully');
    }
}
