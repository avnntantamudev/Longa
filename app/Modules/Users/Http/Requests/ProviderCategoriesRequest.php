<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Requests;

use App\Modules\Users\Enums\PricingMode;
use App\Modules\Users\Models\Category;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Categorias do técnico e, nas categorias por hora/pacote (ex.: aulas), o preço por hora dele, dentro dos limites
 * da categoria. Sem preço por hora, o técnico não recebe pedidos dessas categorias.
 */
class ProviderCategoriesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Áreas de serviço (1 a 10). @example [1, 4] */
            'category_ids' => ['required', 'array', 'min:1', 'max:10'],
            'category_ids.*' => ['integer', 'distinct', Rule::exists('categories', 'id')->where('active', true)],
            /** Preço por hora (Kz) por categoria por hora/pacote: {"12": 5000}. */
            'hourly_rates' => ['nullable', 'array'],
            'hourly_rates.*' => ['nullable', 'integer', $this->withinCategoryLimits(...)],
        ];
    }

    /**
     * O preço por hora tem de estar dentro dos limites da categoria (e só se aplica às categorias por hora/pacote).
     */
    private function withinCategoryLimits(string $attribute, mixed $value, Closure $fail): void
    {
        $id = (int) substr($attribute, strlen('hourly_rates.'));
        $category = Category::query()->find($id);
        if ($category === null || $category->pricing_mode !== PricingMode::HourlyPackage) {
            $fail('Esta categoria não tem preço por hora.');

            return;
        }
        $price = $category->effectivePrice();
        $min = $price->hourly_min ?? 0;
        $max = $price->hourly_max ?? PHP_INT_MAX;
        if ((int) $value < $min || (int) $value > $max) {
            $fail("O preço por hora em {$category->name} tem de estar entre ".number_format($min, 0, ',', '.').' e '.number_format($max, 0, ',', '.').' Kz.');
        }
    }

    /**
     * @return list<int>
     */
    public function categoryIds(): array
    {
        return array_map('intval', (array) $this->validated('category_ids'));
    }

    /**
     * Preço por hora por categoria escolhida (só as que vieram com valor).
     *
     * @return array<int, int>
     */
    public function hourlyRates(): array
    {
        $rates = [];
        foreach ((array) ($this->validated('hourly_rates') ?? []) as $id => $rate) {
            if ($rate !== null && in_array((int) $id, $this->categoryIds(), true)) {
                $rates[(int) $id] = (int) $rate;
            }
        }

        return $rates;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['category_ids' => 'áreas de serviço', 'hourly_rates' => 'preço por hora'];
    }
}
