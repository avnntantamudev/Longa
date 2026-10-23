<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\Disputes\Pages;

use App\Modules\Admin\Filament\Resources\Disputes\DisputeResource;
use App\Modules\Admin\Filament\Support\SessionSections;
use App\Modules\Sessions\Actions\ResolveDispute;
use App\Modules\Sessions\Models\Dispute;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

/**
 * Decisão com dupla confirmação: o formulário mostra o efeito em dinheiro e exige a nota e a palavra
 * CONFIRMAR; a ação de domínio volta a bloquear a disputa (outro admin pode ter decidido entretanto).
 *
 * @property Dispute $record
 */
class ViewDispute extends ViewRecord
{
    protected static string $resource = DisputeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->decision('release', ResolveDispute::RELEASE, 'Libertar ao formador', 'success'),
            $this->decision('refund', ResolveDispute::REFUND, 'Reembolsar tudo', 'danger'),
            $this->decision('partial', ResolveDispute::PARTIAL, 'Reembolsar parte', 'warning'),
        ];
    }

    private function decision(string $name, string $decision, string $label, string $color): Action
    {
        return Action::make($name)
            ->label($label)
            ->color($color)
            ->visible(fn () => $this->record->status === Dispute::OPEN)
            ->modalHeading($label)
            ->modalDescription(fn () => $this->effect($decision))
            ->modalSubmitActionLabel('Confirmar decisão')
            ->schema(array_values(array_filter([
                $decision === ResolveDispute::PARTIAL
                    ? TextInput::make('trainer_share')->label('Parte do formador antes da comissão (Kz)')
                        ->integer()->required()->minValue(1)->maxValue(fn () => $this->record->session->amount - 1)
                    : null,
                Textarea::make('note')->label('Fundamentação (fica no histórico)')->required()->minLength(5)->maxLength(1000),
                TextInput::make('confirmation')->label('Escreva CONFIRMAR')->required()->in(['CONFIRMAR'])
                    ->validationMessages(['in' => 'Escreva CONFIRMAR para validar a decisão.']),
            ])))
            ->action(function (array $data) use ($decision) {
                /** @var User $admin */
                $admin = Auth::user();

                try {
                    app(ResolveDispute::class)->handle(
                        $this->record,
                        $admin,
                        $decision,
                        isset($data['trainer_share']) ? (int) $data['trainer_share'] : null,
                        (string) $data['note'],
                    );
                } catch (ApiException $e) {
                    Notification::make()->danger()->title('Decisão não aplicada')->body($e->getMessage())->send();
                    $this->record->refresh();

                    return;
                }

                $this->record->refresh();
                Notification::make()->success()->title('Disputa resolvida')->send();
            });
    }

    private function effect(string $decision): string
    {
        $amount = SessionSections::kz($this->record->session->amount);

        return match ($decision) {
            ResolveDispute::RELEASE => "O formador recebe {$amount} menos a comissão. A aula fica concluída.",
            ResolveDispute::REFUND => "O aluno é reembolsado em {$amount}. O formador não recebe nada.",
            default => 'O formador recebe a parte indicada menos a comissão; o resto é reembolsado ao aluno.',
        }.' Esta decisão move dinheiro e não pode ser desfeita.';
    }
}
