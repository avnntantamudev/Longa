<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Pages;

use App\Modules\Users\Models\User;
use App\Support\Settings\PlatformSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use UnitEnum;

/**
 * Definições de negócio (comissões, VIP, pedidos agendados). Cada alteração fica na auditoria.
 * As alterações valem para operações novas; aulas e subscrições já feitas mantêm os seus valores.
 *
 * @property-read Schema $form
 */
class Settings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Finanças';

    protected static ?string $title = 'Definições';

    protected static ?string $slug = 'definicoes';

    protected static ?int $navigationSort = 9;

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(PlatformSettings $settings): void
    {
        $this->form->fill($settings->values());
    }

    public function form(Schema $schema): Schema
    {
        $field = fn (string $key) => TextInput::make($key)
            ->label(PlatformSettings::DEFINITIONS[$key]['label'])
            ->integer()->required()
            ->minValue(PlatformSettings::DEFINITIONS[$key]['min'])
            ->maxValue(PlatformSettings::DEFINITIONS[$key]['max']);

        return $schema->statePath('data')->components([
            Section::make('Comissões')->columns(2)->schema([$field('commission_percent'), $field('vip_commission_percent')]),
            Section::make('Formador VIP')->columns(2)->schema([$field('vip_price'), $field('vip_duration_days')]),
            Section::make('Pedidos para outro dia')->schema([$field('scheduled_request_close_hours')]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->footer([
                    Actions::make([
                        Action::make('save')->label('Guardar')->requiresConfirmation()
                            ->modalDescription('Os novos valores aplicam-se a pedidos, aulas e subscrições a partir de agora.')
                            ->action(fn (PlatformSettings $settings) => $this->save($settings)),
                    ]),
                ]),
        ]);
    }

    public function save(PlatformSettings $settings): void
    {
        /** @var array<string, int> $values */
        $values = $this->form->getState();
        /** @var User $admin */
        $admin = Auth::user();

        try {
            $settings->update($values, $admin);
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('Não guardado')->body($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title('Definições guardadas')->send();
    }
}
