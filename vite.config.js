import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import livewire from 'vite-plugin-livewire';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        livewire(),
    ],
});
