<?php

declare(strict_types=1);

namespace App\Modules\Matching\Services;

use App\Modules\Matching\Support\MatchCandidate;
use App\Modules\Requests\Enums\RequestState;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Users\Enums\PricingMode;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Enums\VerificationStatus;
use App\Modules\Wallet\Ledger\Accounts;
use App\Support\Settings\PlatformSettings;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Encontra e ordena os técnicos elegíveis para um pedido (uma única consulta SQL).
 *
 * Elegível:
 *  - conta ativa e não apagada; perfil verificado, com onboarding concluído e "Disponível";
 *  - trabalha na categoria do pedido e aceita a modalidade (presencial/online);
 *  - presencial: a ≤ raio da ronda do despacho do local do pedido E o pedido dentro do raio de atuação do técnico;
 *  - hora/pacote: com preço por hora na categoria ≤ preço máximo do cliente;
 *  - com saldo líquido (disponível − comissões em dívida) ≥ saldo mínimo (Definições → Despacho; −5.000 Kz por omissão);
 *  - sem serviço ativa que se sobreponha ao horário pedido (com folga configurável);
 *  - não é o próprio cliente e ainda não recebeu oferta deste pedido.
 *
 * Reputação = score; com 3 ou mais avaliações, a média entre o score e as estrelas (média × 20).
 * Ordenação: peso_score × reputação/100 + peso_distância × (1 − distância/raio), desempate pela distância.
 * Online: sem filtro de distância; ordena só pelo score.
 */
class ProviderMatcher
{
    public function __construct(private readonly PlatformSettings $settings) {}

    /**
     * Técnicos com contas na carteira, agrupados para somar o saldo líquido (disponível + dívida).
     */
    private function netBalances(): Builder
    {
        return DB::table('ledger_accounts as a')
            ->join('ledger_account_balances as b', 'b.ledger_account_id', '=', 'a.id')
            ->whereIn('a.type', [Accounts::PROVIDER_AVAILABLE, Accounts::PROVIDER_COMMISSION_DUE])
            ->whereNotNull('a.owner_id')
            ->groupBy('a.owner_id')
            ->select('a.owner_id');
    }

    /**
     * Técnicos qualificados (tp = provider_profiles, u = users): conta ativa e não apagada, perfil verificado,
     * onboarding concluído, foto (quando obrigatória) e local definido. São as condições que não dependem do
     * pedido nem do momento; o resto (disponível agora, categoria, distância, saldo, horário) fica em candidates().
     */
    public function qualified(): Builder
    {
        return DB::table('provider_profiles as tp')
            ->join('users as u', 'u.id', '=', 'tp.user_id')
            ->where('u.status', UserStatus::Active->value)
            ->whereNull('u.deleted_at')
            ->where('tp.verification_status', VerificationStatus::Verified->value)
            ->whereNotNull('tp.onboarding_completed_at')
            ->when(now()->gte(Carbon::parse((string) config('longa.providers.photo_required_from'))), fn ($q) => $q->whereNotNull('u.photo'))
            ->whereNotNull('tp.latitude');
    }

    /**
     * Ids das categorias com pelo menos um técnico qualificado (ver qualified()).
     *
     * @return list<int>
     */
    public function categoryIdsWithProviders(): array
    {
        return $this->qualified()
            ->join('category_provider_profile as stp', 'stp.provider_profile_id', '=', 'tp.id')
            ->distinct()
            ->pluck('stp.category_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Técnicos que podem fazer ESTE pedido (sem distância nem ordenação): qualificados, da categoria, disponíveis,
     * na modalidade, dentro do preço por hora do cliente, com o saldo mínimo e sem outro serviço à mesma hora.
     * Partilhado pelo despacho normal e pelo pedido direto ao mesmo técnico.
     */
    public function eligibleFor(ServiceRequest $request): Builder
    {
        $minBalance = $this->settings->int('dispatch_min_balance');
        $buffer = (int) config('longa.matching.conflict_buffer_minutes');
        $start = $request->scheduled_at->copy()->subMinutes($buffer);
        $end = $request->scheduled_at->copy()->addMinutes($request->duration_minutes + $buffer);
        $online = $request->modality === 'online';

        return $this->qualified()
            ->join('category_provider_profile as stp', function ($join) use ($request) {
                $join->on('stp.provider_profile_id', '=', 'tp.id')->where('stp.category_id', '=', $request->category_id);
            })
            ->where('tp.user_id', '!=', $request->client_id)
            ->where('tp.is_available', true)
            ->whereRaw('JSON_CONTAINS(tp.modalities, ?)', [json_encode($online ? 'online' : 'in_person')])
            // Hora/pacote: preço por hora do técnico definido e dentro do máximo do cliente
            ->when($request->pricing_mode === PricingMode::HourlyPackage && $request->max_hourly_rate !== null, fn ($q) => $q
                ->whereNotNull('stp.hourly_rate')->where('stp.hourly_rate', '<=', $request->max_hourly_rate))
            // Saldo mínimo: saldo líquido = disponível + dívida de comissões (negativa); sem contas = 0.
            // O conjunto de técnicos abaixo (ou acima) do mínimo calcula-se uma vez, não por técnico.
            ->when($minBalance <= 0,
                fn ($q) => $q->whereNotIn('tp.id', $this->netBalances()->havingRaw('SUM(b.balance) < ?', [$minBalance])),
                fn ($q) => $q->whereIn('tp.id', $this->netBalances()->havingRaw('SUM(b.balance) >= ?', [$minBalance])),
            )
            // Conflito: serviço ativo do técnico que se sobrepõe ao horário pedido (com folga)
            ->whereNotExists(function ($q) use ($start, $end) {
                $q->selectRaw('1')
                    ->from('service_requests as s')
                    ->whereColumn('s.provider_profile_id', 'tp.id')
                    ->whereIn('s.state', array_map(fn (RequestState $st) => $st->value, RequestState::occupying()))
                    ->where('s.scheduled_at', '<', $end)
                    ->where('s.ends_at', '>', $start);
            });
    }

    /**
     * Pedido direto ("pedir ao mesmo técnico"): o técnico indicado, se for VIP e puder fazer o pedido.
     * Sem limite de distância (o cliente já o conhece); o resto das regras é o do despacho normal.
     */
    public function directCandidate(ServiceRequest $request, int $providerProfileId): ?MatchCandidate
    {
        $row = $this->eligibleFor($request)
            ->where('tp.id', $providerProfileId)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('provider_subscriptions as ts')
                ->whereColumn('ts.provider_profile_id', 'tp.id')->where('ts.status', 'active')
                ->where('ts.starts_at', '<=', now())->where('ts.ends_at', '>', now()))
            ->select(['tp.id', 'tp.user_id', 'tp.score'])
            ->selectRaw('ROUND(ST_Distance_Sphere(tp.location, ST_SRID(POINT(?, ?), 4326))) as distance_m', [$request->longitude, $request->latitude])
            ->first();

        return $row === null ? null : new MatchCandidate(
            providerProfileId: (int) $row->id,
            userId: (int) $row->user_id,
            distanceM: (int) $row->distance_m,
            score: (int) $row->score,
            rankScore: 1.0,
        );
    }

    /**
     * @param  list<int>  $excludeProviderIds
     * @return list<MatchCandidate>
     */
    public function candidates(ServiceRequest $request, int $limit, array $excludeProviderIds = [], bool $scheduled = false, ?int $radiusM = null): array
    {
        $config = config('longa.matching');
        $radius = $radiusM ?? (int) $config['radius_m'];
        $weightScore = (float) $config['weight_score'];
        $weightDistance = (float) $config['weight_distance'];
        $online = $request->modality === 'online';
        $reputation = '(CASE WHEN tp.rating_count >= 3 THEN (tp.score + tp.rating_average * 20) / 2 ELSE tp.score END)';

        // POINT(longitude, latitude) com SRID 4326 (ordem verificada, ver docs/database.md)
        $point = 'ST_SRID(POINT(?, ?), 4326)';
        $pointBindings = [$request->longitude, $request->latitude];

        $query = $this->eligibleFor($request)
            ->select(['tp.id', 'tp.user_id', 'tp.score'])
            ->selectRaw("ROUND(ST_Distance_Sphere(tp.location, {$point})) as distance_m", $pointBindings)
            ->when($excludeProviderIds !== [], fn ($q) => $q->whereNotIn('tp.id', $excludeProviderIds));

        // VIP primeiro (dentro do raio), depois a ordenação normal
        $query->selectRaw(
            "EXISTS(SELECT 1 FROM provider_subscriptions ts WHERE ts.provider_profile_id = tp.id AND ts.status = 'active' AND ts.starts_at <= ? AND ts.ends_at > ?) as is_vip",
            [now(), now()],
        )->orderByDesc('is_vip');

        if ($online) {
            $query->selectRaw("({$reputation} / 100) as rank_score")
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
                    "(? * {$reputation} / 100 + ? * (1 - ST_Distance_Sphere(tp.location, {$point}) / ?)) as rank_score",
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
