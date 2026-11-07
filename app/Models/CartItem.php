<?php

namespace App\Models;

use App\Services\PromotionCart;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (CartItem $item): void {
            if (($item->offer_id === null) === ($item->promotion_item_id === null)) {
                throw new \LogicException('Cart item requires exactly one source.');
            }
        });
    }

    public function promotionItem(): BelongsTo
    {
        return $this->belongsTo(PromotionItem::class)->withTrashed();
    }

    public function supplier(): Organization
    {
        return $this->promotion_item_id !== null ? $this->promotionItem->promotion->organization : $this->offer->organization;
    }

    public function supplierId(): int
    {
        return $this->supplier()->id;
    }

    public function stock(): int|float|null
    {
        return $this->promotion_item_id !== null ? $this->promotionItem->stock : ($this->offer->quantity === null ? null : (float) $this->offer->quantity);
    }

    public function medicineId(): ?int
    {
        return $this->promotion_item_id !== null ? null : $this->offer->medicine_id;
    }

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
        if ($this->promotion_item_id !== null) {
            return $this->availability_reason === null && $this->promotionItem->availabilityReason($this->quantity) === null
                && $this->accepted_terms_version === app(PromotionCart::class)->version($this->promotionItem, $this->snapshot['supplier_discount_percent'] ?? null)
                && $this->offer_change === null;
        }

        return $this->availability_reason === null && $this->proposed_unit_price === null
            && ! $this->offer->organization->price_list_hidden
            && $this->offer->price_list_import_id !== null
            && $this->offer->price_list_import_id === $this->offer->organization->active_price_list_import_id
            && ($this->offer->quantity === null || (float) $this->offer->quantity >= $this->quantity);
    }

    public function availabilityWarning(): ?string
    {
        if ($this->promotion_item_id !== null && $this->availability_reason === null && $this->offer_change !== null) {
            return 'Условия акции изменились. Обновите корзину и подтвердите актуальные условия.';
        }

        return match ($this->availability_reason) {
            'promotion_inactive' => 'Акция не действует, снята с публикации или позиция удалена. Количество сохранено.',
            'promotion_quote_only' => 'Цена по запросу. Отправьте запрос компании из карточки акции.',
            'promotion_minimum' => 'Количество меньше текущего минимума акции.',
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
