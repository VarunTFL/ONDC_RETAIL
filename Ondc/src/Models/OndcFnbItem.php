<?php

namespace Webkul\Ondc\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OndcFnbItem extends Model
{
    protected $table = 'ondc_fnb_items';

    protected $fillable = [
        'ondc_item_id', 'parent_item_id', 'catalog_id', 'type',
        'name', 'description', 'image', 'price', 'maximum_price', 'currency',
        'veg_status', 'category_id',
        'is_available', 'available_count', 'maximum_count',
        'preparation_time', 'is_returnable', 'is_cancellable',
        'statutory_info', 'tags',
    ];

    protected $casts = [
        'price'           => 'decimal:2',
        'maximum_price'   => 'decimal:2',
        'is_available'    => 'boolean',
        'is_returnable'   => 'boolean',
        'is_cancellable'  => 'boolean',
        'statutory_info'  => 'array',
        'tags'            => 'array',
    ];

    public const TYPE_ITEM          = 'item';
    public const TYPE_CUSTOMIZATION = 'customization';

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(OndcFnbCatalog::class, 'catalog_id');
    }

    public function inventoryEvents(): HasMany
    {
        return $this->hasMany(OndcFnbInventoryEvent::class, 'item_id');
    }

    public function isOutOfStock(): bool
    {
        return ! $this->is_available || $this->available_count <= 0;
    }

    public function markOutOfStock(string $triggeredBy = 'system', string $notes = ''): self
    {
        $previousCount = $this->available_count;
        $this->update(['is_available' => false, 'available_count' => 0]);
        OndcFnbInventoryEvent::create([
            'item_id'       => $this->id,
            'event_type'    => OndcFnbInventoryEvent::TYPE_OUT_OF_STOCK,
            'previous_count' => $previousCount,
            'new_count'     => 0,
            'triggered_by'  => $triggeredBy,
            'notes'         => $notes,
        ]);
        return $this;
    }

    public function restock(int $count, string $triggeredBy = 'system'): self
    {
        $previous = $this->available_count;
        $this->update([
            'is_available'    => true,
            'available_count' => $count,
            'maximum_count'   => max($count, $this->maximum_count),
        ]);
        OndcFnbInventoryEvent::create([
            'item_id'       => $this->id,
            'event_type'    => OndcFnbInventoryEvent::TYPE_BACK_IN_STOCK,
            'previous_count' => $previous,
            'new_count'     => $count,
            'triggered_by'  => $triggeredBy,
        ]);
        return $this;
    }
}
