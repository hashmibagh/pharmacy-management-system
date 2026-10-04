/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{js,jsx}'],
  darkMode: 'class', // theme toggled via ThemeContext
  theme: {
    extend: {
      colors: {
        brand: {
          50: '#eef8ff',
          100: '#d9effd',
          200: '#bce3fc',
          300: '#8ed1fa',
          400: '#59b7f6',
          500: '#3497ec',
          600: '#1e7bd2',
          700: '#1a63ab',
          800: '#1c558d',
          900: '#1d4874',
          950: '#142e4d',
        },
      },
      fontFamily: {
        sans: [
          'Inter',
          'system-ui',
          '-apple-system',
          'Segoe UI',
          'Roboto',
          'sans-serif',
        ],
      },
      boxShadow: {
        glass: '0 8px 32px 0 rgba(31, 38, 135, 0.12)',
      },
      keyframes: {
        shimmer: {
          '100%': { transform: 'translateX(100%)' },
        },
      },
      animation: {
        shimmer: 'shimmer 1.5s infinite',
      },
    },
  },
  plugins: [],
};
