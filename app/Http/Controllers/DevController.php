<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

class DevController extends Controller
{
    public function index(): \Inertia\Response
    {
        return Inertia::render('Dev/Desenvolvimento');
    }
}
