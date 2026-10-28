<?php

declare(strict_types=1);

namespace App\Modules\Users\Actions;

use App\Modules\Notifications\Notifications\CategorySuggestionReviewedNotification;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\CategorySuggestion;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Outra área": o técnico sugere, o admin decide.
 *
 * Aprovar aplica-se a todas as sugestões pendentes com o mesmo nome normalizado (outros técnicos que
 * escreveram o mesmo) e liga a área a cada um, que passa a receber pedidos dessa área.
 */
class CategorySuggestions
{
    public const MAX_PENDING = 5;

    public function __construct(
        private readonly UpdateProviderProfile $profiles,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Se o nome coincidir com uma área existente, liga-a logo em vez de criar uma sugestão.
     */
    public function suggest(ProviderProfile $provider, string $name): CategorySuggestion
    {
        $name = Str::of($name)->squish()->limit(80, '')->toString();
        $normalized = Str::slug($name);
        if ($normalized === '') {
            throw new ApiException('validation_failed', 'Os dados enviados são inválidos.', 422, ['name' => ['Escreva o nome da área.']]);
        }

        $existingCategory = Category::query()->active()->where('slug', $normalized)->first();
        $existing = CategorySuggestion::query()->where('provider_profile_id', $provider->id)->where('normalized', $normalized)->first();
        if ($existing !== null) {
            return $existing;
        }

        if ($existingCategory === null && CategorySuggestion::query()->where('provider_profile_id', $provider->id)->where('status', CategorySuggestion::PENDING)->count() >= self::MAX_PENDING) {
            throw new ApiException('too_many_suggestions', 'Já tem '.self::MAX_PENDING.' áreas em análise. Aguarde a decisão do BISCA.', 409);
        }

        return DB::transaction(function () use ($provider, $name, $normalized, $existingCategory) {
            $suggestion = CategorySuggestion::query()->create(['provider_profile_id' => $provider->id, 'name' => $name, 'normalized' => $normalized]);

            if ($existingCategory !== null) {
                $suggestion->forceFill(['status' => CategorySuggestion::APPROVED, 'category_id' => $existingCategory->id, 'review_note' => 'Já existia na lista', 'reviewed_at' => now()])->save();
                $provider->categories()->syncWithoutDetaching([$existingCategory->id]);
                $this->profiles->refreshCompletion($provider);
            }

            $this->audit->record('categories.suggested', $suggestion, ['name' => $name], $provider->user_id);

            return $suggestion;
        });
    }

    /**
     * Aprovar criando uma área nova.
     *
     * @return int sugestões aprovadas (inclui as iguais de outros técnicos)
     */
    public function approveAsNew(CategorySuggestion $suggestion, User $admin, string $name, string $area): int
    {
        $slug = Str::slug($name);
        if (Category::query()->where('slug', $slug)->exists()) {
            throw new ApiException('category_exists', "Já existe a área \"{$name}\". Junte a sugestão a essa área.", 409);
        }

        return DB::transaction(function () use ($suggestion, $admin, $name, $area, $slug) {
            $category = Category::query()->create(['name' => Str::squish($name), 'slug' => $slug, 'area' => Str::squish($area), 'active' => true]);
            $this->audit->record('categories.created', $category, ['from_suggestion' => $suggestion->id], $admin->id);

            return $this->approve($suggestion, $admin, $category, "Nova área: {$category->name}");
        });
    }

    /**
     * Aprovar juntando a uma área que já existe (ex.: "excel avançado" → "Excel").
     */
    public function approveAsExisting(CategorySuggestion $suggestion, User $admin, Category $category): int
    {
        return DB::transaction(fn () => $this->approve($suggestion, $admin, $category, "Junta a: {$category->name}"));
    }

    public function reject(CategorySuggestion $suggestion, User $admin, string $reason): void
    {
        DB::transaction(function () use ($suggestion, $admin, $reason) {
            $locked = $this->lockPending($suggestion);
            $locked->forceFill(['status' => CategorySuggestion::REJECTED, 'review_note' => $reason, 'reviewed_by' => $admin->id, 'reviewed_at' => now()])->save();
            $this->audit->record('categories.suggestion_rejected', $locked, ['reason' => $reason], $admin->id);
            $locked->provider->user->notify(new CategorySuggestionReviewedNotification($locked));
        });
    }

    private function approve(CategorySuggestion $suggestion, User $admin, Category $category, string $note): int
    {
        $this->lockPending($suggestion);

        $same = CategorySuggestion::query()
            ->where('normalized', $suggestion->normalized)
            ->where('status', CategorySuggestion::PENDING)
            ->lockForUpdate()
            ->with('provider.user')
            ->get();

        foreach ($same as $item) {
            $item->forceFill(['status' => CategorySuggestion::APPROVED, 'category_id' => $category->id, 'review_note' => $note, 'reviewed_by' => $admin->id, 'reviewed_at' => now()])->save();
            $item->provider->categories()->syncWithoutDetaching([$category->id]);
            $this->profiles->refreshCompletion($item->provider);
            $item->provider->user->notify(new CategorySuggestionReviewedNotification($item->setRelation('category', $category)));
        }

        $this->audit->record('categories.suggestion_approved', $suggestion, ['category_id' => $category->id, 'count' => $same->count()], $admin->id);

        return $same->count();
    }

    private function lockPending(CategorySuggestion $suggestion): CategorySuggestion
    {
        /** @var CategorySuggestion $locked */
        $locked = CategorySuggestion::query()->lockForUpdate()->findOrFail($suggestion->id);
        if ($locked->status !== CategorySuggestion::PENDING) {
            throw new ApiException('suggestion_already_reviewed', 'Esta sugestão já foi analisada.', 409);
        }

        return $locked;
    }
}
