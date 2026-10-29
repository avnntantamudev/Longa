<?php

declare(strict_types=1);

namespace App\Modules\Requests\Http\Requests;

use App\Modules\Users\Enums\FieldType;
use App\Modules\Users\Enums\PricingMode;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Rules\InAngola;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Pedido do cliente (BISCA, Fase 2.1): categoria, respostas ao formulário da categoria (com fotos ou vídeo quando
 * previsto), localização e modalidade permitida pela categoria. O valor só é pedido nos serviços por hora/pacote:
 * no preço fixo vem da tabela da categoria e nos orçamentos é definido pelo orçamento aceite.
 * Com ficheiros, o pedido vai em multipart/form-data (answers[chave] e answers[chave][] para várias fotos).
 */
class StoreServiceRequestRequest extends FormRequest
{
    private Category|false|null $category = false;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $lead = (int) config('longa.requests.min_lead_minutes');
        $category = $this->category();
        $price = $category?->effectivePrice();

        return [
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('active', true)],
            /** Tema do serviço (opcional: por omissão, o nome da categoria). @example Manutenção do ar condicionado da sala */
            'title' => ['nullable', 'string', 'min:3', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            /** O valor nunca é enviado: fixo = tabela da categoria; orçamento = orçamento aceite; hora/pacote = preço do técnico. */
            'amount' => ['prohibited'],
            /** Hora/pacote: preço máximo por hora que aceita pagar (dentro dos limites da categoria). @example 5000 */
            'max_hourly_rate' => $category?->pricing_mode === PricingMode::HourlyPackage
                ? ['required', 'integer', 'min:'.($price->hourly_min ?? 500), 'max:'.($price->hourly_max ?? 1_000_000)]
                : ['prohibited'],
            /** Hora/pacote: pacote de aulas (GET /api/v1/categories/{id}/form → packages). Sem pacote: uma aula. */
            'category_package_id' => $category?->pricing_mode === PricingMode::HourlyPackage
                ? ['nullable', 'integer', Rule::in(array_map(fn ($p) => $p->id, $category->effectivePackages()))]
                : ['prohibited'],
            /** Respostas ao formulário da categoria (GET /api/v1/categories/{id}/form), por chave do campo. */
            'answers' => ['nullable', 'array'],
            ...$this->answerRules($category),
            /** Data e hora pretendidas (ISO 8601). @example 2026-10-24T14:00:00+01:00 */
            'scheduled_at' => ['required', 'date', 'after:'.now()->addMinutes($lead)->toDateTimeString()],
            /** Duração do serviço em minutos (30 a 480). @example 60 */
            'duration_minutes' => ['sometimes', 'integer', 'min:30', 'max:480', 'multiple_of:15'],
            /** "electronic" (Multicaixa, retido em escrow) ou "cash" (pago em mão ao técnico). */
            'payment_method' => ['sometimes', Rule::in(['electronic', 'cash'])],
            /** Só as modalidades que a categoria permite. */
            'modality' => ['required', Rule::in($category?->effectiveModalities() ?? ['in_person', 'online'])],
            /** Província (lista de 21). Sem valor: Luanda. @example Luanda */
            'province' => ['sometimes', 'string', Rule::in(config('locations.provinces'))],
            'municipality' => ['required', 'string', 'max:80'],
            'neighborhood' => ['required', 'string', 'max:80'],
            'latitude' => ['required', 'numeric', new InAngola],
            'longitude' => ['required', 'numeric'],
        ];
    }

    /**
     * Regras de cada campo do formulário da categoria (com os herdados das categorias-pai).
     *
     * @return array<string, mixed>
     */
    private function answerRules(?Category $category): array
    {
        if ($category === null) {
            return [];
        }

        $media = config('longa.requests.media');
        $rules = [];
        foreach ($category->formFields() as $field) {
            $key = "answers.{$field->key}";
            $presence = $field->required ? 'required' : 'nullable';
            match ($field->type) {
                FieldType::Text => $rules[$key] = [$presence, 'string', 'max:1000'],
                FieldType::Number => $rules[$key] = [$presence, 'numeric', 'min:0', 'max:1000000000'],
                FieldType::Choice => $rules[$key] = [$presence, 'string', Rule::in($field->options ?? [])],
                FieldType::Photos => [
                    $rules[$key] = [$presence, 'array', 'max:'.$media['max_photos']],
                    $rules["{$key}.*"] = ['file', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.$media['photo_max_kb']],
                ],
                FieldType::Video => $rules[$key] = [$presence, 'file', 'mimes:mp4,mov,webm', 'mimetypes:video/mp4,video/quicktime,video/webm', 'max:'.$media['video_max_kb']],
            };
        }

        return $rules;
    }

    public function category(): ?Category
    {
        if ($this->category === false) {
            $id = $this->input('category_id');
            $this->category = is_numeric($id) ? Category::query()->with('fields')->find((int) $id) : null;
        }

        return $this->category;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'scheduled_at.after' => 'O serviço tem de ser marcado com pelo menos '.config('longa.requests.min_lead_minutes').' minutos de antecedência.',
            'amount.prohibited' => 'O valor deste serviço é definido pela tabela, pelo orçamento ou pelo preço do técnico.',
            'max_hourly_rate.min' => 'O preço por hora mínimo neste serviço é :min Kz.',
            'max_hourly_rate.max' => 'O preço por hora máximo neste serviço é :max Kz.',
            'modality.in' => 'Esta modalidade não está disponível para este serviço.',
            'answers.*.*.mimes' => 'Só são aceites fotos JPG, PNG ou WebP.',
            'answers.*.mimes' => 'Formato de ficheiro não aceite.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $labels = [];
        foreach ($this->category()?->formFields() ?? [] as $field) {
            $labels["answers.{$field->key}"] = mb_strtolower($field->label);
        }

        return [
            ...$labels,
            'category_id' => 'serviço', 'title' => 'tema', 'description' => 'descrição', 'amount' => 'valor',
            'max_hourly_rate' => 'preço máximo por hora', 'category_package_id' => 'pacote',
            'scheduled_at' => 'data e hora', 'duration_minutes' => 'duração', 'payment_method' => 'forma de pagamento', 'modality' => 'modalidade', 'municipality' => 'município', 'neighborhood' => 'bairro',
        ];
    }

    /**
     * @return array{category_id: int, title: string|null, description: string|null, max_hourly_rate: int|null, category_package_id: int|null, answers: array<string, mixed>, scheduled_at: string, duration_minutes: int, payment_method: string, modality: string, province: string, municipality: string, neighborhood: string, latitude: float, longitude: float}
     */
    public function requestData(): array
    {
        $maxRate = $this->validated('max_hourly_rate');
        $package = $this->validated('category_package_id');

        return [
            'category_id' => (int) $this->validated('category_id'),
            'title' => $this->validated('title'),
            'description' => $this->validated('description'),
            'max_hourly_rate' => $maxRate === null ? null : (int) $maxRate,
            'category_package_id' => $package === null ? null : (int) $package,
            'answers' => $this->textAnswers(),
            'scheduled_at' => (string) $this->validated('scheduled_at'),
            'duration_minutes' => (int) ($this->validated('duration_minutes') ?? 60),
            'payment_method' => (string) ($this->validated('payment_method') ?? 'electronic'),
            'modality' => (string) $this->validated('modality'),
            'province' => (string) ($this->validated('province') ?? 'Luanda'),
            'municipality' => (string) $this->validated('municipality'),
            'neighborhood' => (string) $this->validated('neighborhood'),
            'latitude' => (float) $this->validated('latitude'),
            'longitude' => (float) $this->validated('longitude'),
        ];
    }

    /**
     * Respostas de texto, número e escolha (só os campos do formulário; o resto é ignorado).
     *
     * @return array<string, mixed>
     */
    private function textAnswers(): array
    {
        $answers = [];
        foreach ($this->category()?->formFields() ?? [] as $field) {
            $value = $this->validated("answers.{$field->key}");
            if ($value === null || in_array($field->type, [FieldType::Photos, FieldType::Video], true)) {
                continue;
            }
            $text = (string) $value;
            $answers[$field->key] = $field->type === FieldType::Number ? (str_contains($text, '.') ? (float) $text : (int) $text) : $text;
        }

        return $answers;
    }

    /**
     * Ficheiros enviados por campo do formulário.
     *
     * @return array<string, list<UploadedFile>>
     */
    public function mediaFiles(): array
    {
        $files = [];
        foreach ($this->category()?->formFields() ?? [] as $field) {
            if (! in_array($field->type, [FieldType::Photos, FieldType::Video], true)) {
                continue;
            }
            $value = $this->file("answers.{$field->key}");
            $list = array_values(array_filter(is_array($value) ? $value : [$value], fn ($f) => $f instanceof UploadedFile));
            if ($list !== []) {
                $files[$field->key] = $list;
            }
        }

        return $files;
    }
}
