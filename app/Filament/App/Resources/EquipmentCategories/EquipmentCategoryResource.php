<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\EquipmentCategories;

use App\Filament\App\Resources\EquipmentCategories\Pages\CreateEquipmentCategory;
use App\Filament\App\Resources\EquipmentCategories\Pages\EditEquipmentCategory;
use App\Filament\App\Resources\EquipmentCategories\Pages\ListEquipmentCategories;
use App\Models\EquipmentCategory;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EquipmentCategoryResource extends Resource
{
    #[\Override]
    protected static ?string $model = EquipmentCategory::class;

    #[\Override]
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    #[\Override]
    protected static string|\UnitEnum|null $navigationGroup = 'Asset Management';

    #[\Override]
    protected static ?int $navigationSort = 3;

    #[\Override]
    protected static ?string $navigationLabel = 'Categories';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    // Matches the unique index on (team_id, name). Scoped
                    // rather than plain `unique` because Laravel's rule
                    // bypasses global scopes and would otherwise report a
                    // clash against another team's category.
                    ->scopedUnique(ignoreRecord: true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
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
            'index' => ListEquipmentCategories::route('/'),
            'create' => CreateEquipmentCategory::route('/create'),
            'edit' => EditEquipmentCategory::route('/{record}/edit'),
        ];
    }
}
