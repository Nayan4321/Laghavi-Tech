<?php

namespace App\Http\Controllers;

class InboxController extends Controller
{
    /** Serves the mobile-first PWA shell; all data loads via the JSON API. */
    public function index()
    {
        return view('inbox');
    }
}
