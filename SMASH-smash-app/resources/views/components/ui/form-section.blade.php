@props([
    'title',
    'description' => null,
])

{{--
    One block of a create/edit form: title + description on the left, fields on the right (stacked on mobile).
    Stack several inside a <form>; finish with <x-ui.form-actions>.
--}}
<div {{ $attributes->class('grid gap-x-12 gap-y-5 border-t border-line py-8 first:border-t-0 first:pt-0 lg:grid-cols-[minmax(0,17rem)_minmax(0,1fr)]') }}>
    <div class="min-w-0">
        <h2 class="font-serif text-h4 text-heading">{{ $title }}</h2>
        @if ($description)
            <p class="mt-2 text-sm leading-relaxed text-ink-soft">{{ $description }}</p>
        @endif
    </div>
    <div class="grid min-w-0 max-w-2xl gap-6">
        {{ $slot }}
    </div>
</div>
