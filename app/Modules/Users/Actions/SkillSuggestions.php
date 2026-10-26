<?php

declare(strict_types=1);

namespace App\Modules\Users\Actions;

use App\Modules\Notifications\Notifications\SkillSuggestionReviewedNotification;
use App\Modules\Users\Models\Skill;
use App\Modules\Users\Models\SkillSuggestion;
use App\Modules\Users\Models\TrainerProfile;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Outra área": o formador sugere, o admin decide.
 *
 * Aprovar aplica-se a todas as sugestões pendentes com o mesmo nome normalizado (outros formadores que
 * escreveram o mesmo) e liga a área a cada um, que passa a receber pedidos dessa área.
 */
class SkillSuggestions
{
    public const MAX_PENDING = 5;

    public function __construct(
        private readonly UpdateTrainerProfile $profiles,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Se o nome coincidir com uma área existente, liga-a logo em vez de criar uma sugestão.
     */
    public function suggest(TrainerProfile $trainer, string $name): SkillSuggestion
    {
        $name = Str::of($name)->squish()->limit(80, '')->toString();
        $normalized = Str::slug($name);
        if ($normalized === '') {
            throw new ApiException('validation_failed', 'Os dados enviados são inválidos.', 422, ['name' => ['Escreva o nome da área.']]);
        }

        $existingSkill = Skill::query()->active()->where('slug', $normalized)->first();
        $existing = SkillSuggestion::query()->where('trainer_profile_id', $trainer->id)->where('normalized', $normalized)->first();
        if ($existing !== null) {
            return $existing;
        }

        if ($existingSkill === null && SkillSuggestion::query()->where('trainer_profile_id', $trainer->id)->where('status', SkillSuggestion::PENDING)->count() >= self::MAX_PENDING) {
            throw new ApiException('too_many_suggestions', 'Já tem '.self::MAX_PENDING.' áreas em análise. Aguarde a decisão da LONGA.', 409);
        }

        return DB::transaction(function () use ($trainer, $name, $normalized, $existingSkill) {
            $suggestion = SkillSuggestion::query()->create(['trainer_profile_id' => $trainer->id, 'name' => $name, 'normalized' => $normalized]);

            if ($existingSkill !== null) {
                $suggestion->forceFill(['status' => SkillSuggestion::APPROVED, 'skill_id' => $existingSkill->id, 'review_note' => 'Já existia na lista', 'reviewed_at' => now()])->save();
                $trainer->skills()->syncWithoutDetaching([$existingSkill->id]);
                $this->profiles->refreshCompletion($trainer);
            }

            $this->audit->record('skills.suggested', $suggestion, ['name' => $name], $trainer->user_id);

            return $suggestion;
        });
    }

    /**
     * Aprovar criando uma área nova.
     *
     * @return int sugestões aprovadas (inclui as iguais de outros formadores)
     */
    public function approveAsNew(SkillSuggestion $suggestion, User $admin, string $name, string $category): int
    {
        $slug = Str::slug($name);
        if (Skill::query()->where('slug', $slug)->exists()) {
            throw new ApiException('skill_exists', "Já existe a área \"{$name}\". Junte a sugestão a essa área.", 409);
        }

        return DB::transaction(function () use ($suggestion, $admin, $name, $category, $slug) {
            $skill = Skill::query()->create(['name' => Str::squish($name), 'slug' => $slug, 'category' => Str::squish($category), 'active' => true]);
            $this->audit->record('skills.created', $skill, ['from_suggestion' => $suggestion->id], $admin->id);

            return $this->approve($suggestion, $admin, $skill, "Nova área: {$skill->name}");
        });
    }

    /**
     * Aprovar juntando a uma área que já existe (ex.: "excel avançado" → "Excel").
     */
    public function approveAsExisting(SkillSuggestion $suggestion, User $admin, Skill $skill): int
    {
        return DB::transaction(fn () => $this->approve($suggestion, $admin, $skill, "Junta a: {$skill->name}"));
    }

    public function reject(SkillSuggestion $suggestion, User $admin, string $reason): void
    {
        DB::transaction(function () use ($suggestion, $admin, $reason) {
            $locked = $this->lockPending($suggestion);
            $locked->forceFill(['status' => SkillSuggestion::REJECTED, 'review_note' => $reason, 'reviewed_by' => $admin->id, 'reviewed_at' => now()])->save();
            $this->audit->record('skills.suggestion_rejected', $locked, ['reason' => $reason], $admin->id);
            $locked->trainer->user->notify(new SkillSuggestionReviewedNotification($locked));
        });
    }

    private function approve(SkillSuggestion $suggestion, User $admin, Skill $skill, string $note): int
    {
        $this->lockPending($suggestion);

        $same = SkillSuggestion::query()
            ->where('normalized', $suggestion->normalized)
            ->where('status', SkillSuggestion::PENDING)
            ->lockForUpdate()
            ->with('trainer.user')
            ->get();

        foreach ($same as $item) {
            $item->forceFill(['status' => SkillSuggestion::APPROVED, 'skill_id' => $skill->id, 'review_note' => $note, 'reviewed_by' => $admin->id, 'reviewed_at' => now()])->save();
            $item->trainer->skills()->syncWithoutDetaching([$skill->id]);
            $this->profiles->refreshCompletion($item->trainer);
            $item->trainer->user->notify(new SkillSuggestionReviewedNotification($item->setRelation('skill', $skill)));
        }

        $this->audit->record('skills.suggestion_approved', $suggestion, ['skill_id' => $skill->id, 'count' => $same->count()], $admin->id);

        return $same->count();
    }

    private function lockPending(SkillSuggestion $suggestion): SkillSuggestion
    {
        /** @var SkillSuggestion $locked */
        $locked = SkillSuggestion::query()->lockForUpdate()->findOrFail($suggestion->id);
        if ($locked->status !== SkillSuggestion::PENDING) {
            throw new ApiException('suggestion_already_reviewed', 'Esta sugestão já foi analisada.', 409);
        }

        return $locked;
    }
}
