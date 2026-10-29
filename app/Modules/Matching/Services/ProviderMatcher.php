<?php

declare(strict_types=1);

namespace App\Modules\Matching\Services;

use App\Modules\Matching\Support\MatchCandidate;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Enums\VerificationStatus;
use App\Modules\Wallet\Ledger\Accounts;
use App\Support\Settings\PlatformSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Encontra e ordena os técnicos elegíveis para um pedido (uma única consulta SQL).
 *
 * Elegível:
 *  - conta ativa e não apagada; perfil verificado, com onboarding concluído e "Disponível";
 *  - trabalha na categoria do pedido e aceita a modalidade (presencial/online);
 *  - presencial: a ≤ raio da ronda do despacho do local do pedido E o pedido dentro do raio de atuação do técnico;
 *  - com saldo disponível ≥ saldo mínimo, quando configurado (Definições → Despacho; 0 = desligado);
 *  - sem serviço ativa que se sobreponha ao horário pedido (com folga configurável);
 *  - não é o próprio cliente e ainda não recebeu oferta deste pedido.
 *
 * Ordenação: peso_score × score/100 + peso_distância × (1 − distância/raio), desempate pela distância.
 * Online: sem filtro de distância; ordena só pelo score.
 */
class ProviderMatcher
{
    public function __construct(private readonly PlatformSettings $settings) {}

    /**
     * @param  list<int>  $excludeProviderIds
     * @return list<MatchCandidate>
     */
    public function candidates(ServiceRequest $request, int $limit, array $excludeProviderIds = [], bool $scheduled = false, ?int $radiusM = null): array
    {
        $config = config('longa.matching');
        $radius = $radiusM ?? (int) $config['radius_m'];
        $minBalance = $this->settings->int('dispatch_min_balance');
        $weightScore = (float) $config['weight_score'];
        $weightDistance = (float) $config['weight_distance'];
        $buffer = (int) $config['conflict_buffer_minutes'];
        $start = $request->scheduled_at->copy()->subMinutes($buffer);
        $end = $request->scheduled_at->copy()->addMinutes($request->duration_minutes + $buffer);
        $online = $request->modality === 'online';

        // POINT(longitude, latitude) com SRID 4326 (ordem verificada, ver docs/database.md)
        $point = 'ST_SRID(POINT(?, ?), 4326)';
        $pointBindings = [$request->longitude, $request->latitude];

        $query = DB::table('provider_profiles as tp')
            ->join('users as u', 'u.id', '=', 'tp.user_id')
            ->join('category_provider_profile as stp', function ($join) use ($request) {
                $join->on('stp.provider_profile_id', '=', 'tp.id')->where('stp.category_id', '=', $request->category_id);
            })
            ->select(['tp.id', 'tp.user_id', 'tp.score'])
            ->selectRaw("ROUND(ST_Distance_Sphere(tp.location, {$point})) as distance_m", $pointBindings)
            ->where('u.status', UserStatus::Active->value)
            ->whereNull('u.deleted_at')
            ->where('tp.user_id', '!=', $request->client_id)
            ->where('tp.verification_status', VerificationStatus::Verified->value)
            ->where('tp.is_available', true)
            ->whereNotNull('tp.onboarding_completed_at')
            ->when(now()->gte(Carbon::parse((string) config('longa.providers.photo_required_from'))), fn ($q) => $q->whereNotNull('u.photo'))
            ->whereNotNull('tp.latitude')
            ->whereRaw('JSON_CONTAINS(tp.modalities, ?)', [json_encode($online ? 'online' : 'in_person')])
            ->when($excludeProviderIds !== [], fn ($q) => $q->whereNotIn('tp.id', $excludeProviderIds))
            // Saldo mínimo (carteira do técnico no livro-razão; sem conta = 0)
            ->when($minBalance > 0, fn ($q) => $q->whereRaw(
                "COALESCE((SELECT b.balance FROM ledger_accounts a JOIN ledger_account_balances b ON b.ledger_account_id = a.id WHERE a.code = CONCAT(?, ':', tp.id)), 0) >= ?",
                [Accounts::PROVIDER_AVAILABLE, $minBalance],
            ))
            // Conflito: serviço ativa do técnico que se sobrepõe ao horário pedido (com folga)
            ->whereNotExists(function ($q) use ($start, $end) {
                $q->selectRaw('1')
                    ->from('service_requests as s')
                    ->whereColumn('s.provider_profile_id', 'tp.id')
                    ->whereIn('s.state', array_map(fn (RequestState $st) => $st->value, RequestState::occupying()))
                    ->where('s.scheduled_at', '<', $end)
                    ->where('s.ends_at', '>', $start);
            });

        // VIP primeiro (dentro do raio), depois a ordenação normal
        $query->selectRaw(
            "EXISTS(SELECT 1 FROM provider_subscriptions ts WHERE ts.provider_profile_id = tp.id AND ts.status = 'active' AND ts.starts_at <= ? AND ts.ends_at > ?) as is_vip",
            [now(), now()],
        )->orderByDesc('is_vip');

        if ($online) {
            $query->selectRaw('(tp.score / 100) as rank_score')
                ->orderByDesc('rank_score');
        } else {
            if ($scheduled) {
                // Outro dia: dentro do raio, ou VIP da mesma província (qualquer distância)
                $query->whereRaw(
                    "(ST_Distance_Sphere(tp.location, {$point}) <= LEAST(?, tp.service_radius_m) OR (tp.province = ? AND EXISTS(SELECT 1 FROM provider_subscriptions vs WHERE vs.provider_profile_id = tp.id AND vs.status = 'active' AND vs.starts_at <= ? AND vs.ends_at > ?)))",
                    [...$pointBindings, $radius, $request->province, now(), now()],
                );
            } else {
                $query
                    // Pré-filtro pelo índice SPATIAL (retângulo do círculo) e depois a distância real
                    ->whereRaw("MBRContains(ST_Buffer({$point}, ?), tp.location)", [...$pointBindings, $radius])
                    ->whereRaw("ST_Distance_Sphere(tp.location, {$point}) <= LEAST(?, tp.service_radius_m)", [...$pointBindings, $radius]);
            }

            $query
                ->selectRaw(
                    "(? * tp.score / 100 + ? * (1 - ST_Distance_Sphere(tp.location, {$point}) / ?)) as rank_score",
                    [$weightScore, $weightDistance, ...$pointBindings, $radius],
                )
                ->orderByDesc('rank_score')
                ->orderBy('distance_m');
        }

        return $query->limit($limit)->get()->map(fn (object $row) => new MatchCandidate(
            providerProfileId: (int) $row->id,
            userId: (int) $row->user_id,
            distanceM: (int) $row->distance_m,
            score: (int) $row->score,
            rankScore: (float) $row->rank_score,
        ))->all();
    }
}
