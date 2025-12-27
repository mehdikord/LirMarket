<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * نمایش داشبورد
     */
    public function index()
    {
        return view('dashboard.index');
    }
}


