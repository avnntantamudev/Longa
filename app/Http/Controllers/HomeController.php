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
                ['key' => 'client', 'icon' => 'search', 'title' => 'Para Clientes', 'description' => 'Encontre técnicos verificados, presenciais ou online, por especialidade, preço e localização.'],
                ['key' => 'provider', 'icon' => 'user', 'title' => 'Para Técnicos', 'description' => 'Formalize o seu trabalho, receba pagamentos seguros e chegue a mais clientes.'],
                ['key' => 'company', 'icon' => 'calendar', 'title' => 'Para Empresas', 'description' => 'Contrate técnicos verificados para a empresa e acompanhe os serviços e os custos.'],
            ],
            'highlights' => [
                ['icon' => 'map-pin', 'title' => 'Técnicos perto de si', 'description' => 'Veja quem trabalha no seu bairro e a que distância está.'],
                ['icon' => 'check-badge', 'title' => 'Verificados pelo BISCA', 'description' => 'Identidade e competências confirmadas antes de aparecerem na pesquisa.'],
                ['icon' => 'wallet', 'title' => 'Pagamento seguro, recibo legal', 'description' => 'O pagamento só é libertado depois de o serviço ser confirmado com QR.'],
            ],
        ]);
    }
}
