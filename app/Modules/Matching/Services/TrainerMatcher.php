<?php

declare(strict_types=1);

namespace App\Modules\Matching\Services;

use App\Modules\Matching\Support\MatchCandidate;
use App\Modules\Requests\Models\TrainingRequest;
use App\Modules\Sessions\Enums\SessionState;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Enums\VerificationStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Encontra e ordena os formadores elegíveis para um pedido (uma única consulta SQL).
 *
 * Elegível:
 *  - conta ativa e não apagada; perfil verificado, com onboarding concluído e "Disponível";
 *  - ensina a área do pedido e aceita a modalidade (presencial/online);
 *  - presencial: a ≤ raio do matching (5 km) do local do pedido E o pedido dentro do raio de atuação do formador;
 *  - sem aula ativa que se sobreponha ao horário pedido (com folga configurável);
 *  - não é o próprio aluno e ainda não recebeu oferta deste pedido.
 *
 * Ordenação: peso_score × score/100 + peso_distância × (1 − distância/raio), desempate pela distância.
 * Online: sem filtro de distância; ordena só pelo score.
 */
class TrainerMatcher
{
    /**
     * @param  list<int>  $excludeTrainerIds
     * @return list<MatchCandidate>
     */
    public function candidates(TrainingRequest $request, int $limit, array $excludeTrainerIds = []): array
    {
        $config = config('longa.matching');
        $radius = (int) $config['radius_m'];
        $weightScore = (float) $config['weight_score'];
        $weightDistance = (float) $config['weight_distance'];
        $buffer = (int) $config['conflict_buffer_minutes'];
        $start = $request->scheduled_at->copy()->subMinutes($buffer);
        $end = $request->scheduled_at->copy()->addMinutes($request->duration_minutes + $buffer);
        $online = $request->modality === 'online';

        // POINT(longitude, latitude) com SRID 4326 (ordem verificada, ver docs/database.md)
        $point = 'ST_SRID(POINT(?, ?), 4326)';
        $pointBindings = [$request->longitude, $request->latitude];

        $query = DB::table('trainer_profiles as tp')
            ->join('users as u', 'u.id', '=', 'tp.user_id')
            ->join('skill_trainer_profile as stp', function ($join) use ($request) {
                $join->on('stp.trainer_profile_id', '=', 'tp.id')->where('stp.skill_id', '=', $request->skill_id);
            })
            ->select(['tp.id', 'tp.user_id', 'tp.score'])
            ->selectRaw("ROUND(ST_Distance_Sphere(tp.location, {$point})) as distance_m", $pointBindings)
            ->where('u.status', UserStatus::Active->value)
            ->whereNull('u.deleted_at')
            ->where('tp.user_id', '!=', $request->student_id)
            ->where('tp.verification_status', VerificationStatus::Verified->value)
            ->where('tp.is_available', true)
            ->whereNotNull('tp.onboarding_completed_at')
            ->when(now()->gte(Carbon::parse((string) config('longa.trainers.photo_required_from'))), fn ($q) => $q->whereNotNull('u.photo'))
            ->whereNotNull('tp.latitude')
            ->whereRaw('JSON_CONTAINS(tp.modalities, ?)', [json_encode($online ? 'online' : 'in_person')])
            ->when($excludeTrainerIds !== [], fn ($q) => $q->whereNotIn('tp.id', $excludeTrainerIds))
            // Conflito: aula ativa do formador que se sobrepõe ao horário pedido (com folga)
            ->whereNotExists(function ($q) use ($start, $end) {
                $q->selectRaw('1')
                    ->from('training_sessions as s')
                    ->whereColumn('s.trainer_profile_id', 'tp.id')
                    ->whereIn('s.state', array_map(fn (SessionState $st) => $st->value, SessionState::occupying()))
                    ->where('s.scheduled_at', '<', $end)
                    ->where('s.ends_at', '>', $start);
            });

        // VIP primeiro (dentro do raio), depois a ordenação normal
        $query->selectRaw(
            "EXISTS(SELECT 1 FROM trainer_subscriptions ts WHERE ts.trainer_profile_id = tp.id AND ts.status = 'active' AND ts.starts_at <= ? AND ts.ends_at > ?) as is_vip",
            [now(), now()],
        )->orderByDesc('is_vip');

        if ($online) {
            $query->selectRaw('(tp.score / 100) as rank_score')
                ->orderByDesc('rank_score');
        } else {
            $query
                // Pré-filtro pelo índice SPATIAL (retângulo do círculo) e depois a distância real
                ->whereRaw("MBRContains(ST_Buffer({$point}, ?), tp.location)", [...$pointBindings, $radius])
                ->whereRaw("ST_Distance_Sphere(tp.location, {$point}) <= LEAST(?, tp.service_radius_m)", [...$pointBindings, $radius])
                ->selectRaw(
                    "(? * tp.score / 100 + ? * (1 - ST_Distance_Sphere(tp.location, {$point}) / ?)) as rank_score",
                    [$weightScore, $weightDistance, ...$pointBindings, $radius],
                )
                ->orderByDesc('rank_score')
                ->orderBy('distance_m');
        }

        return $query->limit($limit)->get()->map(fn (object $row) => new MatchCandidate(
            trainerProfileId: (int) $row->id,
            userId: (int) $row->user_id,
            distanceM: (int) $row->distance_m,
            score: (int) $row->score,
            rankScore: (float) $row->rank_score,
        ))->all();
    }
}
