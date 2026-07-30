<?php

namespace App\Filament\Pages;

use App\Support\Brand;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use UnitEnum;

class ManageBranding extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.manage-branding';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaintBrush;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $title = 'Branding';

    /** @var array<string, mixed> */
    public ?array $data = [];

    /**
     * Only super admins may change how the whole app looks.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'name' => Brand::name(),
            'primary_color' => Brand::primaryColor(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Application name')
                    ->required()
                    ->maxLength(60)
                    ->helperText('Shown in the sidebar, page titles and the admin panel.'),
                ColorPicker::make('primary_color')
                    ->label('Primary colour')
                    ->required()
                    ->helperText('Accent colour across the app and admin panel.'),
                FileUpload::make('logo_upload')
                    ->label('Logo')
                    ->image()
                    ->storeFiles(false)
                    ->maxSize(1024)
                    ->helperText('PNG or SVG, up to 1 MB. Leave empty to keep the current logo.'),
                FileUpload::make('favicon_upload')
                    ->label('Favicon')
                    ->image()
                    ->storeFiles(false)
                    ->maxSize(512)
                    ->helperText('A small square icon, up to 512 KB. Leave empty to keep the current favicon.'),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save branding')
                ->action('save'),
        ];
    }

    public function save(): void
    {
        $state = $this->form->getState();

        Brand::set('brand.name', ($state['name'] ?? '') ?: Brand::DEFAULT_NAME);
        Brand::set('brand.primary_color', ($state['primary_color'] ?? '') ?: Brand::DEFAULT_COLOR);

        $this->storeImage($state['logo_upload'] ?? null, 'brand.logo_data');
        $this->storeImage($state['favicon_upload'] ?? null, 'brand.favicon_data');

        Notification::make()
            ->title('Branding updated')
            ->body('Reload the app to see the new branding everywhere.')
            ->success()
            ->send();
    }

    /**
     * Persist an uploaded image as a base64 data URI, so it survives on
     * ephemeral / object-storage infrastructure without a shared filesystem.
     */
    private function storeImage(mixed $file, string $key): void
    {
        if (is_array($file)) {
            $file = reset($file);
        }

        if (! $file instanceof TemporaryUploadedFile) {
            return;
        }

        $mime = $file->getMimeType() ?: 'image/png';
        Brand::set($key, 'data:'.$mime.';base64,'.base64_encode($file->get()));
    }
}
