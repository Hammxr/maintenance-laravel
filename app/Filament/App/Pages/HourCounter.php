<?php

namespace App\Filament\App\Pages;

use App\Models\Equipment;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class HourCounter extends Page implements HasTable
{
    use InteractsWithTable;

    #[\Override]
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-clock';

    #[\Override]
    protected static string | \UnitEnum | null $navigationGroup = 'Asset Management';

    #[\Override]
    protected static ?string $navigationLabel = 'Hour Counter';

    #[\Override]
    protected static ?int $navigationSort = 2;

    #[\Override]
    protected string $view = 'filament.app.pages.hour-counter';

    /**
     * This is the single source of truth hour-based maintenance schedules
     * are measured against — updating a reading here immediately re-checks
     * that equipment's hour-based schedules, auto-creating the next work
     * order if it just became due.
     */
    public function table(Table $table): Table
    {
        return $table
            ->query(Equipment::query())
            ->columns([
                TextColumn::make('name')
                    ->label('Equipment')
                    ->searchable()
                    ->sortable(),

                TextInputColumn::make('current_hours')
                    ->label('Current Hours')
                    ->type('number')
                    ->rules(['nullable', 'integer', 'min:0'])
                    ->sortable()
                    ->updateStateUsing(function ($record, $state) {
                        if ($state === null) {
                            $record->update([
                                'current_hours' => null,
                                'current_hours_recorded_at' => null,
                            ]);

                            return null;
                        }

                        $record->updateCurrentHours((int) $state);

                        return $state;
                    }),
            ])
            ->defaultSort('name');
    }
}
