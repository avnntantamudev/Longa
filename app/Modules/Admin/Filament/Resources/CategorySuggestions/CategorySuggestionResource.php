<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\CategorySuggestions;

use App\Modules\Admin\Filament\Resources\CategorySuggestions\Pages\ListCategorySuggestions;
use App\Modules\Users\Actions\CategorySuggestions;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\CategorySuggestion;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\CategoryTree;
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
 * Áreas escritas pelos técnicos ("Outra área"). Aprovar cria uma área nova ou junta a uma existente,
 * e aplica-se a todos os técnicos que escreveram o mesmo.
 */
class CategorySuggestionResource extends Resource
{
    protected static ?string $model = CategorySuggestion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static string|UnitEnum|null $navigationGroup = 'Operações';

    protected static ?string $modelLabel = 'área sugerida';

    protected static ?string $pluralModelLabel = 'áreas sugeridas';

    protected static ?string $slug = 'areas-sugeridas';

    protected static ?int $navigationSort = 3;

    public static function getNavigationBadge(): ?string
    {
        $pending = CategorySuggestion::query()->where('status', CategorySuggestion::PENDING)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['provider.user', 'category']);
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
                TextColumn::make('same')->label('Técnicos')
                    ->state(fn (CategorySuggestion $r) => CategorySuggestion::query()->where('normalized', $r->normalized)->where('status', CategorySuggestion::PENDING)->count()),
                TextColumn::make('provider.user.name')->label('Sugerida por'),
                TextColumn::make('status')->label('Estado')->badge()
                    ->formatStateUsing(fn (string $state) => ['pending' => 'Em análise', 'approved' => 'Aprovada', 'rejected' => 'Rejeitada'][$state] ?? $state)
                    ->color(fn (string $state) => ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger'][$state] ?? 'gray'),
                TextColumn::make('category.name')->label('Área final')->placeholder('—'),
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
                    ->visible(fn (CategorySuggestion $r) => $r->status === CategorySuggestion::PENDING)
                    ->modalHeading(fn (CategorySuggestion $r) => "Aprovar \"{$r->name}\"")
                    ->modalDescription('Aplica-se a todos os técnicos que escreveram o mesmo. Passam a receber pedidos desta área.')
                    ->fillForm(fn (CategorySuggestion $r) => ['mode' => 'new', 'name' => $r->name])
                    ->schema([
                        Radio::make('mode')->label('Como aprovar')->required()->live()
                            ->options(['new' => 'Criar área nova', 'existing' => 'Juntar a uma área existente']),
                        TextInput::make('name')->label('Nome da área (como aparece na lista)')->maxLength(120)
                            ->visible(fn (Get $get) => $get('mode') === 'new')->required(fn (Get $get) => $get('mode') === 'new'),
                        Select::make('parent_id')->label('Dentro de (categoria-pai)')
                            ->options(fn () => app(CategoryTree::class)->nodes()->mapWithKeys(fn (Category $c) => [$c->id => $c->path()])->sort()->all())
                            ->searchable()
                            ->visible(fn (Get $get) => $get('mode') === 'new')->required(fn (Get $get) => $get('mode') === 'new'),
                        Select::make('category_id')->label('Área existente')
                            ->options(fn () => app(CategoryTree::class)->leaves()->mapWithKeys(fn (Category $c) => [$c->id => $c->path()])->all())
                            ->searchable()
                            ->visible(fn (Get $get) => $get('mode') === 'existing')->required(fn (Get $get) => $get('mode') === 'existing'),
                    ])
                    ->action(function (CategorySuggestion $record, array $data) {
                        /** @var User $admin */
                        $admin = Auth::user();
                        try {
                            $count = $data['mode'] === 'existing'
                                ? app(CategorySuggestions::class)->approveAsExisting($record, $admin, Category::query()->findOrFail($data['category_id']))
                                : app(CategorySuggestions::class)->approveAsNew($record, $admin, (string) $data['name'], Category::query()->findOrFail($data['parent_id']));
                        } catch (ApiException $e) {
                            Notification::make()->danger()->title('Não aprovada')->body($e->getMessage())->send();

                            return;
                        }
                        Notification::make()->success()->title("Aprovada para {$count} técnico(es)")->send();
                    }),
                Action::make('reject')
                    ->label('Rejeitar')
                    ->color('danger')
                    ->icon(Heroicon::OutlinedXMark)
                    ->visible(fn (CategorySuggestion $r) => $r->status === CategorySuggestion::PENDING)
                    ->schema([TextInput::make('reason')->label('Motivo (o técnico vai vê-lo)')->required()->minLength(5)->maxLength(255)])
                    ->action(function (CategorySuggestion $record, array $data) {
                        /** @var User $admin */
                        $admin = Auth::user();
                        try {
                            app(CategorySuggestions::class)->reject($record, $admin, (string) $data['reason']);
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
        return ['index' => ListCategorySuggestions::route('/')];
    }
}
