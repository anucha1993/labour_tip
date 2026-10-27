<?php

namespace App\Http\Controllers\ExportExcelExpire;

use Illuminate\Http\Request;
use PhpParser\Node\Expr\New_;
use App\Http\Controllers\Controller;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\labourexpire\LabourExpireVisa;
use App\Exports\labourexpire\LabourExpireWorkpremit;

class LabourExpireWorkpremitController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }
    public function export()
    {
        return Excel::download(New LabourExpireWorkpremit,'LabourExpireWorkpremit.xlsx');

    }

}
