<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\LineLeaders;

use App\Filament\App\Resources\LineLeaders\Pages\CreateLineLeader;
use App\Filament\App\Resources\LineLeaders\Pages\EditLineLeader;
use App\Filament\App\Resources\LineLeaders\Pages\ListLineLeaders;
use App\Models\LineLeader;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LineLeaderResource extends Resource
{
    #[\Override]
    protected static ?string $model = LineLeader::class;

    #[\Override]
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    #[\Override]
    protected static string|\UnitEnum|null $navigationGroup = 'Asset Management';

    #[\Override]
    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    // Matches the unique index on (team_id, name) — two
                    // same-named line leaders in one team would make the
                    // equipment filter ambiguous. Scoped rather than plain
                    // `unique` because Laravel's rule bypasses global scopes,
                    // so it would otherwise report a clash against another
                    // team's line leader and leak its existence.
                    ->scopedUnique(ignoreRecord: true),
                TextInput::make('notes')
                    ->maxLength(255)
                    ->helperText('Optional, e.g. which line or area they cover.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('notes')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('equipment_count')
                    ->label('Equipment')
                    ->counts('equipment')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLineLeaders::route('/'),
            'create' => CreateLineLeader::route('/create'),
            'edit' => EditLineLeader::route('/{record}/edit'),
        ];
    }
}
