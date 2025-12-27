<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class RequestsController extends Controller
{
    /**
     * نمایش لیست درخواست‌ها
     */
    public function index()
    {
        return view('requests.index');
    }
}


