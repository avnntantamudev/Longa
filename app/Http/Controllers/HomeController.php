<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Home', [
            'profiles' => [
                ['key' => 'student', 'title' => 'Aluno', 'description' => 'Encontre um formador perto de si e marque aulas.'],
                ['key' => 'trainer', 'title' => 'Formador', 'description' => 'Partilhe o que sabe e receba pelas suas aulas.'],
                ['key' => 'company', 'title' => 'Empresa (RH)', 'description' => 'Forme a sua equipa e acompanhe os resultados.'],
            ],
        ]);
    }
}
