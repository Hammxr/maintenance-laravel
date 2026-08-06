<?php
namespace App\Filament\App\Pages;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
class EditProfile extends Page implements HasForms
{
    use InteractsWithForms;
    #[\Override]
    protected string $view = 'filament.pages.edit-profile';
    #[\Override]
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-chart-bar';
    public User $user;
    public function mount(): void
    {
        $this->user = Auth::user();
        $this->form->fill([
            'name'  => $this->user->name,
            'email' => $this->user->email,
        ]);
    }
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label('Email Address')
                    ->required()
                    ->maxLength(255),
            ])
            ->statePath('data');
    }
    public function submit(): void
    {
        $state = $this->form->getState();
        $this->user->forceFill([
            'name'  => $state['name'],
            'email' => $state['email'],
        ])->save();
        Filament::notify('success', 'Your profile has been updated.');
    }
    public function getBreadcrumbs(): array
    {
        return [
            url()->current() => 'Edit Profile',
        ];
    }
}
