import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

const withAlpha = (v) => `rgb(var(${v}) / <alpha-value>)`;

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './app/**/*.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                canvas:            withAlpha('--color-canvas'),
                surface:           withAlpha('--color-surface'),
                'surface-muted':   withAlpha('--color-surface-muted'),
                line:              withAlpha('--color-line'),

                content:           withAlpha('--color-content'),
                'content-muted':   withAlpha('--color-content-muted'),
                'content-onbrand': withAlpha('--color-content-onbrand'),

                brand: withAlpha('--color-brand'),
                link:  withAlpha('--color-link'),
                focus: withAlpha('--color-focus'),

                'action-primary': {
                    DEFAULT: withAlpha('--color-action-primary-bg'),
                    fg:      withAlpha('--color-action-primary-fg'),
                    hover:   withAlpha('--color-action-primary-hover'),
                },
                'action-confirm': {
                    DEFAULT: withAlpha('--color-action-confirm-bg'),
                    fg:      withAlpha('--color-action-confirm-fg'),
                    hover:   withAlpha('--color-action-confirm-hover'),
                },
                'action-danger': {
                    DEFAULT: withAlpha('--color-action-danger-bg'),
                    fg:      withAlpha('--color-action-danger-fg'),
                    hover:   withAlpha('--color-action-danger-hover'),
                },

                chrome: {
                    DEFAULT: withAlpha('--color-chrome-bg'),
                    fg:      withAlpha('--color-chrome-fg'),
                    muted:   withAlpha('--color-chrome-muted'),
                    hover:   withAlpha('--color-chrome-hover'),
                },

                'status-danger': {
                    DEFAULT: withAlpha('--color-status-danger-bg'),
                    fg:      withAlpha('--color-status-danger-fg'),
                    border:  withAlpha('--color-status-danger-border'),
                },
                'status-warning': {
                    DEFAULT: withAlpha('--color-status-warning-bg'),
                    fg:      withAlpha('--color-status-warning-fg'),
                    border:  withAlpha('--color-status-warning-border'),
                },
                'status-success': {
                    DEFAULT: withAlpha('--color-status-success-bg'),
                    fg:      withAlpha('--color-status-success-fg'),
                    border:  withAlpha('--color-status-success-border'),
                },
                'status-info': {
                    DEFAULT: withAlpha('--color-status-info-bg'),
                    fg:      withAlpha('--color-status-info-fg'),
                    border:  withAlpha('--color-status-info-border'),
                },
            },
            borderRadius: {
                control: 'var(--radius-control)',
                card:    'var(--radius-card)',
                badge:   'var(--radius-badge)',
                modal:   'var(--radius-modal)',
            },
            boxShadow: {
                card:    'var(--shadow-card)',
                popover: 'var(--shadow-popover)',
            },
        },
    },

    plugins: [forms],
};
