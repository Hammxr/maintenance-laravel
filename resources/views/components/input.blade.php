@props(['disabled' => false])

<input {{ $disabled ? 'disabled' : '' }} {!! $attributes->merge(['class' => 'bg-white border-gray-300 focus:border-emerald-500 text-black focus:ring-emerald-500 rounded-md shadow-sm']) !!}>
