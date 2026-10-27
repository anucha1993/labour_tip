<?php

namespace App\Http\Controllers\ExportExcelExpire;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;


use App\Exports\labourexpire\LabourExpireDay90;
use Maatwebsite\Excel\Facades\Excel;
use PhpParser\Node\Expr\New_;

class LabourExpireDay90Controller extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }
    
    public function export()
    {
        return Excel::download(New LabourExpireDay90,'LabourExpireDay90.xlsx');
    }
}
