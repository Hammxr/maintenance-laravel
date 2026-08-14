<?php

namespace App\Filament\App\Resources\Equipment;

use App\Filament\App\Resources\Equipment\Pages\CreateEquipment;
use App\Filament\App\Resources\Equipment\Pages\EditEquipment;
use App\Filament\App\Resources\Equipment\Pages\ListEquipment;
use App\Models\Equipment;
use App\Models\LineLeader;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EquipmentResource extends Resource
{
    #[\Override]
    protected static ?string $model = Equipment::class;

    #[\Override]
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-wrench-screwdriver';

    #[\Override]
    protected static string|\UnitEnum|null $navigationGroup = 'Asset Management';

    #[\Override]
    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Basic Information')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Textarea::make('description')
                            ->rows(3),
                        TextInput::make('serial_number')
                            // Laravel's `unique` rule ignores global scopes, so
                            // it would report a clash against another team's
                            // equipment. `sensor_id` below stays globally
                            // unique because the ingestion API looks up
                            // equipment by it across all teams.
                            ->scopedUnique(ignoreRecord: true)
                            ->maxLength(255),
                        TextInput::make('model')
                            ->maxLength(255),
                        TextInput::make('manufacturer')
                            ->maxLength(255),
                    ])->columns(2),

                Section::make('Classification')
                    ->schema([
                        Select::make('category')
                            ->options([
                                'HVAC' => 'HVAC',
                                'Electrical' => 'Electrical',
                                'Plumbing' => 'Plumbing',
                                'Mechanical' => 'Mechanical',
                                'IT Equipment' => 'IT Equipment',
                                'Safety Equipment' => 'Safety Equipment',
                                'Vehicles' => 'Vehicles',
                                'Other' => 'Other',
                            ])
                            ->searchable(),
                        TextInput::make('location')
                            ->maxLength(255),
                        Select::make('status')
                            ->options([
                                'active' => 'Active',
                                'inactive' => 'Inactive',
                                'under_maintenance' => 'Under Maintenance',
                                'retired' => 'Retired',
                            ])
                            ->default('active')
                            ->required(),
                        Select::make('criticality')
                            ->options([
                                'low' => 'Low',
                                'medium' => 'Medium',
                                'high' => 'High',
                                'critical' => 'Critical',
                            ])
                            ->default('medium')
                            ->required(),
                        TextInput::make('current_hours')
                            ->label('Current Operating Hours')
                            ->numeric()
                            ->minValue(0)
                            ->suffix('hrs')
                            ->helperText('Update this whenever you take a meter reading — hour-based maintenance schedules use it to know when they\'re due.'),
                        Select::make('line_leader_id')
                            ->label('Line Leader')
                            // Scoped to the current tenant by hand: Filament
                            // doesn't apply tenancy to a Select's relationship
                            // query, so without this you'd see every team's
                            // line leaders in the dropdown.
                            ->relationship(
                                name: 'lineLeader',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query) => $query
                                    ->where('team_id', Filament::getTenant()?->id)
                                    ->orderBy('name'),
                            )
                            ->searchable()
                            ->preload()
                            ->placeholder('Not assigned')
                            ->helperText('Optional — leave blank if this equipment isn\'t filed under a line leader.')
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('notes')
                                    ->maxLength(255)
                                    ->helperText('Optional, e.g. which line or area they cover.'),
                            ])
                            // Same reason as above — the tenant has to be set
                            // explicitly or an inline-created line leader would
                            // be orphaned and invisible to the filter.
                            ->createOptionUsing(fn (array $data): int => LineLeader::create([
                                'name' => $data['name'],
                                'notes' => $data['notes'] ?? null,
                                'team_id' => Filament::getTenant()?->id,
                            ])->getKey()),
                    ])->columns(2),

                Section::make('Purchase Information')
                    ->schema([
                        DatePicker::make('purchase_date'),
                        DatePicker::make('warranty_expiry'),
                        Select::make('company_id')
                            ->relationship('company', 'name')
                            ->searchable()
                            ->preload(),
                    ])->columns(3),

                Section::make('IoT Sensor Configuration')
                    ->schema([
                        Forms\Components\Toggle::make('sensor_enabled')
                            ->label('Enable IoT Sensor')
                            ->reactive()
                            ->helperText('Enable real-time monitoring for this equipment'),

                        Select::make('sensor_type')
                            ->label('Sensor Type')
                            ->options([
                                'temperature' => 'Temperature',
                                'vibration' => 'Vibration',
                                'pressure' => 'Pressure',
                                'humidity' => 'Humidity',
                                'power' => 'Power Consumption',
                                'flow' => 'Flow Rate',
                                'multi-sensor' => 'Multi-Sensor',
                            ])
                            ->searchable()
                            ->visible(fn ($get) => $get('sensor_enabled')),

                        TextInput::make('sensor_id')
                            ->label('Sensor ID')
                            ->unique(ignoreRecord: true)
                            ->helperText('Unique identifier for the IoT sensor')
                            ->visible(fn ($get) => $get('sensor_enabled')),

                        Forms\Components\KeyValue::make('sensor_config')
                            ->label('Sensor Configuration')
                            ->helperText('Configure thresholds and sensor parameters (JSON format)')
                            ->visible(fn ($get) => $get('sensor_enabled'))
                            ->columnSpanFull(),
                    ])->columns(3)
                    ->collapsible(),

                Section::make('Additional Notes')
                    ->schema([
                        Textarea::make('notes')
                            ->rows(4),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['company:company_id,name', 'team:id,name', 'lineLeader:id,name']))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('serial_number')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('location')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('lineLeader.name')
                    ->label('Line Leader')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                BadgeColumn::make('status')
                    ->colors([
                        'success' => 'active',
                        'warning' => 'under_maintenance',
                        'danger' => 'retired',
                        'secondary' => 'inactive',
                    ]),
                BadgeColumn::make('criticality')
                    ->colors([
                        'success' => 'low',
                        'warning' => 'medium',
                        'danger' => 'high',
                        'danger' => 'critical',
                    ]),
                TextColumn::make('current_hours')
                    ->label('Hours')
                    ->suffix(' hrs')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('manufacturer')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('model')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('purchase_date')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('warranty_expiry')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('company.name')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                BadgeColumn::make('sensor_enabled')
                    ->label('IoT Sensor')
                    ->formatStateUsing(fn ($state) => $state ? 'Enabled' : 'Disabled')
                    ->colors([
                        'success' => fn ($state) => $state === true,
                        'secondary' => fn ($state) => $state === false,
                    ])
                    ->icon(fn ($state) => $state ? 'heroicon-o-signal' : null)
                    ->toggleable(),

                TextColumn::make('sensor_type')
                    ->label('Sensor Type')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('last_sensor_reading_at')
                    ->label('Last Sensor Reading')
                    ->dateTime()
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                        'under_maintenance' => 'Under Maintenance',
                        'retired' => 'Retired',
                    ]),
                SelectFilter::make('criticality')
                    ->options([
                        'low' => 'Low',
                        'medium' => 'Medium',
                        'high' => 'High',
                        'critical' => 'Critical',
                    ]),
                SelectFilter::make('category')
                    ->options([
                        'HVAC' => 'HVAC',
                        'Electrical' => 'Electrical',
                        'Plumbing' => 'Plumbing',
                        'Mechanical' => 'Mechanical',
                        'IT Equipment' => 'IT Equipment',
                        'Safety Equipment' => 'Safety Equipment',
                        'Vehicles' => 'Vehicles',
                        'Other' => 'Other',
                    ]),
                SelectFilter::make('line_leader_id')
                    ->label('Line Leader')
                    ->relationship(
                        name: 'lineLeader',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query) => $query
                            ->where('team_id', Filament::getTenant()?->id)
                            ->orderBy('name'),
                    )
                    ->searchable()
                    ->preload(),
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

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEquipment::route('/'),
            'create' => CreateEquipment::route('/create'),
            'edit' => EditEquipment::route('/{record}/edit'),
        ];
    }
}
