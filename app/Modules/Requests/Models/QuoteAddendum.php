<?php

declare(strict_types=1);

namespace App\Modules\Requests\Models;

use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Adenda ao orçamento aceite: trabalho extra submetido pelo técnico e aprovado pelo cliente antes de ser feito
 * (e pela equipa quando passa o limite percentual do orçamento original).
 *
 * @property int $id
 * @property int $service_request_id
 * @property int $quote_id
 * @property int $provider_profile_id
 * @property string $description
 * @property int $amount
 * @property int $percent_of_quote
 * @property string $status
 * @property bool $team_review
 * @property \Illuminate\Support\Carbon|null $client_decided_at
 * @property string|null $client_note
 * @property int|null $reviewed_by
 * @property \Illuminate\Support\Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read ServiceRequest $request
 * @property-read Quote $quote
 * @property-read ProviderProfile $provider
 * @property-read User|null $reviewer
 */
#[Table('quote_addenda')]
#[Fillable(['service_request_id', 'quote_id', 'provider_profile_id', 'description', 'amount', 'percent_of_quote', 'team_review'])]
class QuoteAddendum extends Model
{
    public const PENDING_CLIENT = 'pending_client';

    public const PENDING_TEAM = 'pending_team';

    /** Aprovada; no pagamento digital, à espera de o cliente pagar */
    public const APPROVED = 'approved';

    /** Paga pelo cliente (digital) e junta ao escrow */
    public const PAID = 'paid';

    public const REJECTED = 'rejected';

    protected $attributes = ['status' => self::PENDING_CLIENT, 'team_review' => false];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'percent_of_quote' => 'integer',
            'team_review' => 'boolean',
            'client_decided_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ServiceRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class, 'service_request_id');
    }

    /**
     * @return BelongsTo<Quote, $this>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /**
     * @return BelongsTo<ProviderProfile, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(ProviderProfile::class, 'provider_profile_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function label(): string
    {
        return match ($this->status) {
            self::PENDING_CLIENT => 'À espera do cliente',
            self::PENDING_TEAM => 'Em revisão pela equipa',
            self::APPROVED => 'Aprovada',
            self::PAID => 'Paga',
            default => 'Recusada',
        };
    }
}
