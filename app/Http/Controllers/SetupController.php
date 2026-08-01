<?php

namespace App\Http\Controllers;

class SetupController extends Controller
{
    public function initialize()
    {
        return view('setup.initialize');
    }

    public function accountingBasics()
    {
        return view('help.accounting-basics');
    }
}
