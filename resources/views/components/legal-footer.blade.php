<footer class="border-t border-border bg-surface">
    <nav class="page-container flex flex-wrap gap-2 pt-5 {{ auth()->user()?->isPharmacyMode() ? 'pb-24 md:pb-5' : 'pb-5' }}" aria-label="Правовые документы" lang="ru">
        <x-ui.button variant="ghost" :href="route('legal.privacy')">Политика конфиденциальности</x-ui.button>
        <x-ui.button variant="ghost" :href="route('legal.terms')">Условия использования</x-ui.button>
    </nav>
</footer>
