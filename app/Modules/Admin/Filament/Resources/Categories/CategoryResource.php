<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\Categories;

use App\Modules\Admin\Filament\Concerns\AdminOnly;
use App\Modules\Admin\Filament\Resources\Categories\Pages\CreateCategory;
use App\Modules\Admin\Filament\Resources\Categories\Pages\EditCategory;
use App\Modules\Admin\Filament\Resources\Categories\Pages\ListCategories;
use App\Modules\Users\Enums\FieldType;
use App\Modules\Users\Enums\PricingMode;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Services\CategoryTree;
use BackedEnum;
use Closure;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Árvore de categorias do BISCA: modo de preço, modalidades, documentos exigidos, preços de referência e o
 * formulário de cada categoria (as subcategorias herdam os campos). Só o admin, porque inclui preços.
 */
class CategoryResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = Category::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Catálogo';

    protected static ?string $modelLabel = 'categoria';

    protected static ?string $pluralModelLabel = 'categorias';

    protected static ?string $slug = 'categorias';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['parent.parent.parent', 'price'])->withCount(['children', 'providers']);
    }

    public static function form(Schema $schema): Schema
    {
        $modes = collect(PricingMode::cases())->mapWithKeys(fn (PricingMode $m) => [$m->value => $m->label()])->all();
        $types = collect(FieldType::cases())->mapWithKeys(fn (FieldType $t) => [$t->value => $t->label()])->all();

        return $schema->components([
            Section::make('Categoria')->columns(2)->schema([
                TextInput::make('name')->label('Nome')->required()->maxLength(120)->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, Get $get, ?string $state) => $get('slug') ? null : $set('slug', Str::slug((string) $state))),
                TextInput::make('slug')->label('Identificador (URL)')->required()->maxLength(140)->unique(ignoreRecord: true)->alphaDash(),
                Select::make('parent_id')->label('Dentro de (categoria-pai)')->placeholder('Nenhuma (categoria principal)')
                    ->options(fn (?Category $record) => app(CategoryTree::class)->nodes()
                        ->reject(fn (Category $c) => $record !== null && $c->id === $record->id)
                        ->mapWithKeys(fn (Category $c) => [$c->id => $c->path()])->sort()->all())
                    ->searchable()
                    ->rule(fn (?Category $record) => function (string $attribute, mixed $value, Closure $fail) use ($record) {
                        $parent = $value === null ? null : Category::query()->find($value);
                        if ($record !== null && ! $record->canHaveParent($parent)) {
                            $fail('Uma categoria não pode ficar dentro de si própria nem de uma subcategoria sua.');
                        }
                    }),
                Select::make('pricing_mode')->label('Modo de preço')->required()->default(PricingMode::Quote->value)->options($modes),
                CheckboxList::make('modalities')->label('Modalidades (vazio = as da categoria-pai)')
                    ->options(['in_person' => 'Presencial', 'online' => 'Online'])->columns(2),
                TagsInput::make('required_documents')->label('Documentos exigidos ao técnico')
                    ->placeholder('ex.: certificado_profissional')->helperText('Somam-se aos das categorias-pai.'),
                Textarea::make('description')->label('Descrição')->maxLength(500)->columnSpanFull(),
                TextInput::make('icon')->label('Ícone')->maxLength(40),
                TextInput::make('sort_order')->label('Ordem')->integer()->minValue(0)->default(0),
                Toggle::make('active')->label('Ativa')->default(true),
            ]),
            Section::make('Preços (Kz)')->relationship('price')->columns(3)
                ->description('Vazio = usa os da categoria-pai. O intervalo de referência gera alertas para orçamentos fora do normal.')
                ->schema([
                    TextInput::make('fixed_price')->label('Preço fixo')->integer()->minValue(0),
                    TextInput::make('reference_min')->label('Referência mínima')->integer()->minValue(0),
                    TextInput::make('reference_max')->label('Referência máxima')->integer()->minValue(0),
                    TextInput::make('hourly_min')->label('Por hora: mínimo')->integer()->minValue(0),
                    TextInput::make('hourly_max')->label('Por hora: máximo')->integer()->minValue(0),
                    TextInput::make('visit_fee')->label('Taxa de visita')->integer()->minValue(0),
                ]),
            Section::make('Pacotes de aulas')->description('Só para "por hora ou pacote". Vazio = usa os da categoria-pai.')->schema([
                Repeater::make('packages')->relationship()->label('Pacotes')->orderColumn('sort_order')->reorderable()
                    ->columns(3)->defaultItems(0)->addActionLabel('Adicionar pacote')
                    ->schema([
                        TextInput::make('sessions')->label('Aulas')->integer()->required()->minValue(2)->maxValue(100),
                        TextInput::make('discount_percent')->label('Desconto (%)')->integer()->required()->minValue(0)->maxValue(50)->default(0),
                        Toggle::make('active')->label('Ativo')->default(true),
                    ]),
            ]),
            Section::make('Formulário do pedido')->description('Campos próprios desta categoria; os das categorias-pai aparecem antes.')->schema([
                Repeater::make('fields')->relationship()->label('Campos')->orderColumn('sort_order')->reorderable()->collapsible()
                    ->itemLabel(fn (array $state) => $state['label'] ?? null)
                    ->columns(3)->defaultItems(0)->addActionLabel('Adicionar campo')
                    ->schema([
                        TextInput::make('label')->label('Pergunta')->required()->maxLength(120),
                        TextInput::make('key')->label('Chave')->required()->maxLength(60)->alphaDash(),
                        Select::make('type')->label('Tipo')->required()->live()->options($types),
                        TagsInput::make('options')->label('Escolhas')->columnSpan(2)
                            ->visible(fn (Get $get) => $get('type') === FieldType::Choice->value)
                            ->required(fn (Get $get) => $get('type') === FieldType::Choice->value),
                        Toggle::make('required')->label('Obrigatório'),
                        TextInput::make('help')->label('Ajuda')->maxLength(200)->columnSpanFull(),
                    ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $modes = collect(PricingMode::cases())->mapWithKeys(fn (PricingMode $m) => [$m->value => $m->label()])->all();

        return $table
            ->columns([
                TextColumn::make('path')->label('Categoria')->state(fn (Category $r) => $r->path())->wrap()
                    ->searchable(query: fn (Builder $query, string $search) => $query->where('name', 'like', "%{$search}%")),
                TextColumn::make('pricing_mode')->label('Preço')->badge()->formatStateUsing(fn (PricingMode $state) => $state->label()),
                TextColumn::make('price_summary')->label('Valores (Kz)')->placeholder('—')->state(fn (Category $r) => self::priceSummary($r)),
                TextColumn::make('children_count')->label('Subcategorias'),
                TextColumn::make('providers_count')->label('Técnicos'),
                IconColumn::make('active')->label('Ativa')->boolean(),
            ])
            ->defaultSort('sort_order')
            ->filters([SelectFilter::make('pricing_mode')->label('Modo de preço')->options($modes)])
            ->recordActions([EditAction::make()]);
    }

    private static function priceSummary(Category $category): ?string
    {
        $p = $category->price;
        if ($p === null) {
            return null;
        }
        $kz = fn (?int $v) => number_format((int) $v, 0, ',', '.');

        $parts = array_filter([
            $p->fixed_price !== null ? 'fixo '.$kz($p->fixed_price) : null,
            $p->reference_min !== null || $p->reference_max !== null ? 'ref. '.$kz($p->reference_min).'–'.$kz($p->reference_max) : null,
            $p->hourly_min !== null || $p->hourly_max !== null ? 'hora '.$kz($p->hourly_min).'–'.$kz($p->hourly_max) : null,
            $p->visit_fee !== null ? 'visita '.$kz($p->visit_fee) : null,
        ]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
            'create' => CreateCategory::route('/criar'),
            'edit' => EditCategory::route('/{record}/editar'),
        ];
    }
}
