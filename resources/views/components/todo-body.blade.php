@props(['body'])

@if ($body !== null)
    <div
        {{ $attributes->merge([
            'class' => 'text-sm leading-6 text-slate-600 [&_a]:text-sky-700 [&_a]:underline [&_a]:underline-offset-2 [&_code]:rounded [&_code]:bg-slate-100 [&_code]:px-1 [&_code]:py-0.5 [&_li]:ml-5 [&_li]:list-disc [&_li+li]:mt-1 [&_p+ul]:mt-2 [&_p]:m-0 [&_strong]:font-semibold [&_ul]:my-2 [&_ul]:pl-0',
        ]) }}>
        {!! \Illuminate\Support\Str::of($body)->markdown([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]) !!}
    </div>
@endif
