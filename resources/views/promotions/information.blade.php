<x-ui.card id="information" class="min-w-0 scroll-mt-6">
    <h2 class="text-xl font-bold">Основная информация</h2>
    <p class="mb-5 mt-1 text-sm text-muted">Название, условия и сроки, которые увидят аптеки.</p>
    <x-ui.form-scope form-key="information">
        <form method="post" enctype="multipart/form-data" action="{{ $promotion->exists ? route('promotions.update', $promotion) : route('promotions.store') }}" @if($canEdit) data-promotion-form @endif>
            @csrf <x-mode-version /> @if($promotion->exists) @method('PATCH') @endif
            <input type="hidden" name="_promotion_form" value="information">
            <fieldset @disabled(! $canEdit) class="min-w-0 space-y-6">
                <div class="space-y-3">
                    <h3 class="font-semibold">Компания и название</h3>
                    <x-ui.select name="organization_id" label="Компания">
                        @foreach($companies as $company)<option value="{{ $company->id }}" @selected((old('_promotion_form') === 'information' ? old('organization_id') : $promotion->organization_id) == $company->id)>{{ $company->name }}</option>@endforeach
                    </x-ui.select>
                    <x-ui.input name="title" label="Заголовок" :value="$promotion->title" maxlength="255" required />
                </div>
                <div class="space-y-3 border-t border-border pt-5">
                    <h3 class="font-semibold">Описание и условия</h3>
                    <x-ui.textarea name="description" label="Описание" :value="$promotion->description" rows="4" />
                    <x-ui.textarea name="conditions" label="Условия" :value="$promotion->conditions" rows="3" />
                </div>
                <div class="space-y-3 border-t border-border pt-5">
                    <h3 class="font-semibold">Сроки и география</h3>
                    <div class="grid min-w-0 gap-3 sm:grid-cols-2">
                        <x-ui.date-picker name="starts_on" label="Начало" :value="$promotion->starts_on?->toDateString()" required />
                        <x-ui.date-picker name="ends_on" label="Окончание" min-field="starts_on" :value="$promotion->ends_on?->toDateString()" required />
                    </div>
                    <p class="text-xs text-muted">Даты включительно, часовой пояс Asia/Dushanbe.</p>
                    <x-ui.select name="city" label="Город">
                        <option value="">Все города</option>
                        @foreach($cities as $city)<option value="{{ $city }}" @selected((old('_promotion_form') === 'information' ? old('city') : $promotion->city) === $city)>{{ $city }}</option>@endforeach
                    </x-ui.select>
                </div>
                <div class="space-y-3 border-t border-border pt-5">
                    <h3 class="font-semibold">Постер акции</h3>
                    @if($promotion->exists && ($poster = $promotion->attachments->firstWhere('role', 'banner')))
                        <img src="{{ route('promotions.attachments.show', $poster) }}" alt="Постер акции {{ $promotion->title }}" class="h-36 max-w-full rounded-control border border-border object-contain">
                    @endif
                    <x-ui.input name="poster" type="file" label="Загрузить постер" accept=".jpg,.jpeg,.png,.webp" hint="Необязательно. JPEG, PNG или WebP до 5 МБ. Новый постер заменит текущий. При ошибке выберите файл повторно." />
                </div>
                @if($canEdit)<x-ui.button>Сохранить информацию</x-ui.button>@endif
            </fieldset>
        </form>
    </x-ui.form-scope>
</x-ui.card>
