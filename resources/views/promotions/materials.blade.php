<x-ui.card id="materials" class="min-w-0 scroll-mt-6">
    <h2 class="text-xl font-bold">Материалы <span class="text-muted">{{ $materialCount }}</span></h2>
    <p class="mb-5 mt-1 text-sm text-muted">Баннеры, документы и видео для аптек. Логотип сохраняется в профиле компании.</p>
    @if($promotion->exists)
        <ul class="mb-5 divide-y divide-border">
            @forelse($promotion->attachments as $attachment)
                <li class="flex min-w-0 flex-wrap items-center justify-between gap-3 py-3">
                    <div class="min-w-0 flex-1 basis-48">
                        <p class="break-words font-medium [overflow-wrap:anywhere]">{{ $attachment->original_name }}</p>
                        <p class="mt-1 text-sm text-muted">{{ ['banner' => 'Баннер', 'logo' => 'Логотип компании', 'material' => 'Материал'][$attachment->role] ?? $attachment->role }} · {{ strtoupper(pathinfo($attachment->original_name, PATHINFO_EXTENSION)) }} · {{ $attachment->size >= 1048576 ? number_format($attachment->size / 1048576, 1, ',', ' ').' МБ' : number_format($attachment->size / 1024, 1, ',', ' ').' КБ' }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-ui.button :href="route('promotions.attachments.show', $attachment)" variant="secondary" :aria-label="'Открыть '.$attachment->original_name">Открыть</x-ui.button>
                        @if($canEdit)
                            <form method="post" action="{{ route('promotions.attachments.destroy', $attachment) }}">
                                @csrf <x-mode-version /> @method('DELETE')
                                <x-ui.button variant="danger" :aria-label="'Удалить '.$attachment->original_name">Удалить материал</x-ui.button>
                            </form>
                        @endif
                    </div>
                </li>
            @empty
                <li><x-ui.empty-state title="Материалы ещё не добавлены" description="Прикрепите постер, презентацию или условия акции, чтобы аптекам было проще узнать подробности." /></li>
            @endforelse
        </ul>
        @if($canEdit)
            <x-ui.form-scope form-key="materials">
                <form method="post" enctype="multipart/form-data" action="{{ route('promotions.attachments.store', $promotion) }}" data-promotion-form class="space-y-4 border-t border-border pt-5">
                    @csrf <x-mode-version />
                    <input type="hidden" name="_promotion_form" value="materials">
                    <h3 class="font-semibold">Загрузить файлы</h3>
                    <p class="text-sm text-muted">Выберите файлы в нужных категориях и загрузите всё одной кнопкой. Материалы и баннеры можно выбрать сразу по несколько.</p>
                    <x-ui.input name="materials[]" id="material-files" error-key="materials*" type="file" label="Материалы" accept=".jpg,.jpeg,.png,.webp,.pdf,.xlsx,.mp4" multiple hint="Каждый файл: JPEG, PNG, WebP до 5 МБ; PDF/XLSX до 20 МБ; MP4 до 100 МБ." />
                    <x-ui.input name="banners[]" id="banner-files" error-key="banners*" type="file" label="Баннеры" accept=".jpg,.jpeg,.png,.webp" multiple hint="Каждое изображение: JPEG, PNG или WebP до 5 МБ." />
                    <x-ui.input name="logo" id="company-logo" type="file" label="Логотип компании" accept=".jpg,.jpeg,.png,.webp" hint="Одно изображение JPEG, PNG или WebP до 5 МБ. Заменит логотип в профиле компании." />
                    <p class="text-xs text-muted">При ошибке выберите файлы повторно. Если один файл не пройдёт проверку, вся загрузка будет отменена.</p>
                    <x-ui.button>Загрузить все файлы</x-ui.button>
                </form>
            </x-ui.form-scope>
        @endif
    @else
        <x-ui.empty-state title="Материалы доступны после сохранения" description="Создайте черновик, чтобы загрузить баннеры, документы и видео." />
    @endif
</x-ui.card>
