<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    protected $guarded = [];

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function isEligible(): bool
    {
        return $this->availability_reason === null && $this->proposed_unit_price === null
            && ! $this->offer->organization->price_list_hidden
            && $this->offer->price_list_import_id !== null
            && $this->offer->price_list_import_id === $this->offer->organization->active_price_list_import_id
            && ($this->offer->quantity === null || (float) $this->offer->quantity >= $this->quantity);
    }

    public function availabilityWarning(): ?string
    {
        return match ($this->availability_reason) {
            'missing_replacement' => 'Товар отсутствует в новом прайс-листе. Позиция сохранена, но пока недоступна для заказа.',
            'ambiguous_replacement' => 'В новом прайс-листе несколько подходящих предложений. Выберите нужное в каталоге.',
            'replacement_already_in_cart' => 'Новое предложение уже есть в корзине. Эта позиция сохранена отдельно; проверьте количество.',
            'supplier_hidden' => 'Прайс-лист временно скрыт. Заказ недоступен.',
            'out_of_stock' => 'Товара нет в наличии. Запрошенное количество сохранено.',
            'insufficient_stock' => 'Недостаточный остаток. Запрошенное количество сохранено; уменьшите его для заказа.',
            default => null,
        };
    }

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'original_snapshot' => 'array', 'offer_change' => 'array', 'unit_price' => 'decimal:2', 'proposed_unit_price' => 'decimal:2'];
    }
}
