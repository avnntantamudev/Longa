<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\SkillSuggestions;

use App\Modules\Admin\Filament\Resources\SkillSuggestions\Pages\ListSkillSuggestions;
use App\Modules\Users\Actions\SkillSuggestions;
use App\Modules\Users\Models\Skill;
use App\Modules\Users\Models\SkillSuggestion;
use App\Modules\Users\Models\User;
use App\Support\Api\ApiException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Áreas escritas pelos formadores ("Outra área"). Aprovar cria uma área nova ou junta a uma existente,
 * e aplica-se a todos os formadores que escreveram o mesmo.
 */
class SkillSuggestionResource extends Resource
{
    protected static ?string $model = SkillSuggestion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static string|UnitEnum|null $navigationGroup = 'Operações';

    protected static ?string $modelLabel = 'área sugerida';

    protected static ?string $pluralModelLabel = 'áreas sugeridas';

    protected static ?string $slug = 'areas-sugeridas';

    protected static ?int $navigationSort = 3;

    public static function getNavigationBadge(): ?string
    {
        $pending = SkillSuggestion::query()->where('status', SkillSuggestion::PENDING)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['trainer.user', 'skill']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label('Área escrita')->searchable()->weight('bold'),
                TextColumn::make('same')->label('Formadores')
                    ->state(fn (SkillSuggestion $r) => SkillSuggestion::query()->where('normalized', $r->normalized)->where('status', SkillSuggestion::PENDING)->count()),
                TextColumn::make('trainer.user.name')->label('Sugerida por'),
                TextColumn::make('status')->label('Estado')->badge()
                    ->formatStateUsing(fn (string $state) => ['pending' => 'Em análise', 'approved' => 'Aprovada', 'rejected' => 'Rejeitada'][$state] ?? $state)
                    ->color(fn (string $state) => ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger'][$state] ?? 'gray'),
                TextColumn::make('skill.name')->label('Área final')->placeholder('—'),
                TextColumn::make('created_at')->label('Quando')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')
                    ->options(['pending' => 'Em análise', 'approved' => 'Aprovadas', 'rejected' => 'Rejeitadas'])
                    ->default('pending'),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Aprovar')
                    ->color('success')
                    ->icon(Heroicon::OutlinedCheck)
                    ->visible(fn (SkillSuggestion $r) => $r->status === SkillSuggestion::PENDING)
                    ->modalHeading(fn (SkillSuggestion $r) => "Aprovar \"{$r->name}\"")
                    ->modalDescription('Aplica-se a todos os formadores que escreveram o mesmo. Passam a receber pedidos desta área.')
                    ->fillForm(fn (SkillSuggestion $r) => ['mode' => 'new', 'name' => $r->name])
                    ->schema([
                        Radio::make('mode')->label('Como aprovar')->required()->live()
                            ->options(['new' => 'Criar área nova', 'existing' => 'Juntar a uma área existente']),
                        TextInput::make('name')->label('Nome da área (como aparece na lista)')->maxLength(120)
                            ->visible(fn (Get $get) => $get('mode') === 'new')->required(fn (Get $get) => $get('mode') === 'new'),
                        Select::make('category')->label('Categoria')
                            ->options(fn () => Skill::query()->distinct()->orderBy('category')->pluck('category', 'category')->all())
                            ->searchable()
                            ->createOptionForm([TextInput::make('category')->label('Nova categoria')->required()->maxLength(60)])
                            ->createOptionUsing(fn (array $data) => $data['category'])
                            ->visible(fn (Get $get) => $get('mode') === 'new')->required(fn (Get $get) => $get('mode') === 'new'),
                        Select::make('skill_id')->label('Área existente')
                            ->options(fn () => Skill::query()->active()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->visible(fn (Get $get) => $get('mode') === 'existing')->required(fn (Get $get) => $get('mode') === 'existing'),
                    ])
                    ->action(function (SkillSuggestion $record, array $data) {
                        /** @var User $admin */
                        $admin = Auth::user();
                        try {
                            $count = $data['mode'] === 'existing'
                                ? app(SkillSuggestions::class)->approveAsExisting($record, $admin, Skill::query()->findOrFail($data['skill_id']))
                                : app(SkillSuggestions::class)->approveAsNew($record, $admin, (string) $data['name'], (string) $data['category']);
                        } catch (ApiException $e) {
                            Notification::make()->danger()->title('Não aprovada')->body($e->getMessage())->send();

                            return;
                        }
                        Notification::make()->success()->title("Aprovada para {$count} formador(es)")->send();
                    }),
                Action::make('reject')
                    ->label('Rejeitar')
                    ->color('danger')
                    ->icon(Heroicon::OutlinedXMark)
                    ->visible(fn (SkillSuggestion $r) => $r->status === SkillSuggestion::PENDING)
                    ->schema([TextInput::make('reason')->label('Motivo (o formador vai vê-lo)')->required()->minLength(5)->maxLength(255)])
                    ->action(function (SkillSuggestion $record, array $data) {
                        /** @var User $admin */
                        $admin = Auth::user();
                        try {
                            app(SkillSuggestions::class)->reject($record, $admin, (string) $data['reason']);
                        } catch (ApiException $e) {
                            Notification::make()->danger()->title('Não rejeitada')->body($e->getMessage())->send();

                            return;
                        }
                        Notification::make()->success()->title('Sugestão rejeitada')->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListSkillSuggestions::route('/')];
    }
}
