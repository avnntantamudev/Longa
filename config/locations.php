<?php

declare(strict_types=1);

/*
| Zonas de Luanda para escolha manual de local (quando o utilizador não partilha a geolocalização).
| Coordenadas aproximadas do centro de cada zona [AJUSTAR com dados oficiais].
| Os nomes de municípios seguem o uso corrente; a reforma administrativa de 2024 alterou alguns limites.
*/

return [
    'bounds' => [
        // Retângulo que contém Angola, incluindo Cabinda
        'lat_min' => -18.1,
        'lat_max' => -4.3,
        'lng_min' => 11.6,
        'lng_max' => 24.1,
    ],

    'zones' => [
        ['municipality' => 'Talatona', 'neighborhood' => 'Talatona', 'lat' => -8.9167, 'lng' => 13.1833],
        ['municipality' => 'Talatona', 'neighborhood' => 'Camama', 'lat' => -8.9400, 'lng' => 13.2400],
        ['municipality' => 'Belas', 'neighborhood' => 'Benfica', 'lat' => -8.9740, 'lng' => 13.1660],
        ['municipality' => 'Belas', 'neighborhood' => 'Morro Bento', 'lat' => -8.8930, 'lng' => 13.1880],
        ['municipality' => 'Kilamba Kiaxi', 'neighborhood' => 'Kilamba', 'lat' => -8.9940, 'lng' => 13.2690],
        ['municipality' => 'Kilamba Kiaxi', 'neighborhood' => 'Golfe', 'lat' => -8.8930, 'lng' => 13.2620],
        ['municipality' => 'Luanda', 'neighborhood' => 'Ingombota', 'lat' => -8.8160, 'lng' => 13.2340],
        ['municipality' => 'Luanda', 'neighborhood' => 'Maianga', 'lat' => -8.8320, 'lng' => 13.2330],
        ['municipality' => 'Luanda', 'neighborhood' => 'Alvalade', 'lat' => -8.8400, 'lng' => 13.2420],
        ['municipality' => 'Luanda', 'neighborhood' => 'Rangel', 'lat' => -8.8320, 'lng' => 13.2590],
        ['municipality' => 'Luanda', 'neighborhood' => 'Sambizanga', 'lat' => -8.8070, 'lng' => 13.2560],
        ['municipality' => 'Cazenga', 'neighborhood' => 'Cazenga', 'lat' => -8.8300, 'lng' => 13.2900],
        ['municipality' => 'Viana', 'neighborhood' => 'Viana', 'lat' => -8.9035, 'lng' => 13.3742],
        ['municipality' => 'Viana', 'neighborhood' => 'Zango', 'lat' => -8.9500, 'lng' => 13.4400],
        ['municipality' => 'Cacuaco', 'neighborhood' => 'Cacuaco', 'lat' => -8.7770, 'lng' => 13.3700],
    ],
];
