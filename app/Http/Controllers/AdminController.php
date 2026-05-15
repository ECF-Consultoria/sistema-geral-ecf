<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

class AdminController extends Controller
{
    public function empresas()
    {
        return Inertia::render('Admin/Empresas');
    }

    public function relatorio()
    {
        return Inertia::render('Admin/Relatorio');
    }

    public function financeiro()
    {
        return Inertia::render('Admin/Financeiro');
    }

    public function inventario()
    {
        return Inertia::render('Admin/Inventario');
    }
}
