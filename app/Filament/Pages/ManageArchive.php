<?php

namespace App\Filament\Pages;

use App\Support\Archive;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class ManageArchive extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.manage-archive';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $title = 'Archive';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'after_days' => Archive::afterDays() ?? 0,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('after_days')
                    ->label('Archive finished tasks after')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(3650)
                    ->required()
                    ->suffix('days')
                    ->helperText('Counted from when the task entered a completed status, not from its last edit. '
                        .'Set to 0 to turn automatic archiving off — the Archive button on a task keeps working either way.'),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save')
                ->action('save'),

            // Waiting until 03:00 to find out whether the window is right is a
            // poor way to learn it. This runs the same sweep, now.
            Action::make('sweep')
                ->label('Run the sweep now')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Archives every task finished longer ago than the window above. Tasks can be restored individually afterwards.')
                ->action('sweep'),
        ];
    }

    public function save(): void
    {
        $days = (int) ($this->form->getState()['after_days'] ?? 0);

        Archive::setAfterDays($days > 0 ? $days : null);

        Notification::make()
            ->title($days > 0
                ? "Finished tasks will be archived after {$days} days"
                : 'Automatic archiving is off')
            ->success()
            ->send();
    }

    public function sweep(): void
    {
        $archived = Archive::sweep();

        Notification::make()
            ->title($archived === 0 ? 'Nothing to archive' : "Archived {$archived} task(s)")
            ->body($archived === 0
                ? 'No finished task is older than the window.'
                : 'They are in the Archive section and can be restored from there.')
            ->success()
            ->send();
    }
}
