<x-filament-panels::page>
    <form wire:submit="generateReport">
        {{ $this->form }}

        <div class="mt-6 flex gap-3">
            <x-filament::button type="submit">
                Generate Report
            </x-filament::button>

            @if($entries !== null)
                <x-filament::button color="danger" wire:click="exportPdf">
                    Export PDF
                </x-filament::button>
            @endif
        </div>
    </form>

    @if($entries !== null)
        <div class="mt-8">
            <x-filament::section>
                <x-slot name="heading">
                    Changes ({{ count($entries) }})
                </x-slot>

                @if(count($entries) > 0)
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead class="border-b border-gray-200">
                                <tr>
                                    <th class="px-4 py-3 text-sm font-semibold text-gray-900">Date</th>
                                    <th class="px-4 py-3 text-sm font-semibold text-gray-900">Changed By</th>
                                    <th class="px-4 py-3 text-sm font-semibold text-gray-900">Type</th>
                                    <th class="px-4 py-3 text-sm font-semibold text-gray-900">Record</th>
                                    <th class="px-4 py-3 text-sm font-semibold text-gray-900">Event</th>
                                    <th class="px-4 py-3 text-sm font-semibold text-gray-900">Changes</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @foreach($entries as $entry)
                                    <tr>
                                        <td class="px-4 py-3 text-sm text-gray-500 whitespace-nowrap">{{ \Carbon\Carbon::parse($entry['date'])->format('M j, Y g:ia') }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-900 whitespace-nowrap">{{ $entry['causer_name'] }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $entry['subject_type'] }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-900 whitespace-nowrap">{{ $entry['subject_name'] }}</td>
                                        <td class="px-4 py-3 text-sm">
                                            <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-medium
                                                @if($entry['event'] === 'Created') bg-green-100 text-green-700
                                                @elseif($entry['event'] === 'Deleted') bg-red-100 text-red-700
                                                @else bg-blue-100 text-blue-700
                                                @endif">
                                                {{ $entry['event'] }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-700">
                                            @if(count($entry['changes']) > 0)
                                                <ul class="space-y-0.5">
                                                    @foreach($entry['changes'] as $change)
                                                        <li>{{ $change }}</li>
                                                    @endforeach
                                                </ul>
                                            @else
                                                <span class="text-gray-400">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-sm text-gray-500">No changes were logged for this period.</p>
                @endif
            </x-filament::section>
        </div>
    @endif
</x-filament-panels::page>
