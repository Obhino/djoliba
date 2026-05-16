import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        current: String
    }

    connect() {
        // 1. Déterminer le thème initial
        const savedTheme = localStorage.getItem('theme');
        const systemTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        const initialTheme = savedTheme || systemTheme || 'light';

        console.log('Theme connected. Initial:', initialTheme);

        // 2. Appliquer le thème
        this.applyTheme(initialTheme);
    }

    toggle() {
        console.log('Toggle clicked');
        const isCurrentlyDark = this.element.classList.contains('dark');
        const newTheme = isCurrentlyDark ? 'light' : 'dark';
        
        console.log('Current state is dark?', isCurrentlyDark);
        console.log('Switching to:', newTheme);
        
        this.applyTheme(newTheme);
    }

    applyTheme(theme) {
        this.currentValue = theme;
        
        if (theme === 'dark') {
            this.element.classList.add('dark');
            this.element.style.colorScheme = 'dark';
        } else {
            this.element.classList.remove('dark');
            this.element.style.colorScheme = 'light';
        }

        localStorage.setItem('theme', theme);
        this.dispatch('change', { detail: { theme } });
    }
}
