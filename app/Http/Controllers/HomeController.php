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
                ['key' => 'student', 'icon' => 'search', 'title' => 'Para Alunos', 'description' => 'Encontre formadores verificados, presenciais ou online, por especialidade, preço e localização.'],
                ['key' => 'trainer', 'icon' => 'user', 'title' => 'Para Formadores', 'description' => 'Formalize o seu ensino, receba pagamentos seguros e chegue a mais alunos.'],
                ['key' => 'company', 'icon' => 'calendar', 'title' => 'Para Empresas', 'description' => 'Forme a sua equipa com formadores certificados e acompanhe presenças e certificados.'],
            ],
            'highlights' => [
                ['icon' => 'map-pin', 'title' => 'Formadores perto de si', 'description' => 'Veja quem ensina no seu bairro e a que distância está.'],
                ['icon' => 'check-badge', 'title' => 'Verificados pela LONGA', 'description' => 'Identidade e competências confirmadas antes de aparecerem na pesquisa.'],
                ['icon' => 'wallet', 'title' => 'Pagamento seguro, recibo legal', 'description' => 'O pagamento só é libertado depois de a aula ser confirmada com QR.'],
            ],
        ]);
    }
}
