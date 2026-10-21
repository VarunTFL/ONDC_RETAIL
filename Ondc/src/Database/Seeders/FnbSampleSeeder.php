<?php

namespace Webkul\Ondc\Database\Seeders;

use Illuminate\Database\Seeder;
use Webkul\Ondc\Models\OndcFnbCatalog;
use Webkul\Ondc\Models\OndcFnbCustomizationGroup;
use Webkul\Ondc\Models\OndcFnbItem;

class FnbSampleSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = OndcFnbCatalog::updateOrCreate(
            ['provider_id' => 'rest_pizza_palace'],
            [
                'name'             => 'Pizza Palace',
                'cuisine'          => 'Italian',
                'is_veg_only'      => false,
                'fssai_license'    => '12345678901234',
                'city'             => 'std:080',
                'gps'              => '12.9716,77.5946',
                'address'          => [
                    'name'      => 'Pizza Palace',
                    'building'  => 'Tower A',
                    'locality'  => 'MG Road',
                    'city'      => 'Bengaluru',
                    'state'     => 'Karnataka',
                    'area_code' => '560001',
                ],
                'phone'            => '+91-9876543210',
                'preparation_time' => 'PT30M',
            ]
        );

        // Pizza items
        $pizza = OndcFnbItem::updateOrCreate(
            ['ondc_item_id' => 'item_margherita_pizza'],
            [
                'catalog_id'      => $catalog->id,
                'parent_item_id'  => 'item_margherita_pizza',
                'type'            => 'item',
                'name'            => 'Margherita Pizza',
                'description'     => 'Classic pizza with mozzarella and basil',
                'price'           => 299,
                'maximum_price'   => 299,
                'veg_status'      => 'veg',
                'available_count' => 50,
                'maximum_count'   => 50,
                'category_id'     => 'F&B',
                'preparation_time' => 'PT20M',
                'image'           => 'https://example.com/margherita.jpg',
                'is_returnable'   => false,
                'is_cancellable'  => true,
            ]
        );

        $bbq = OndcFnbItem::updateOrCreate(
            ['ondc_item_id' => 'item_bbq_chicken_pizza'],
            [
                'catalog_id'       => $catalog->id,
                'parent_item_id'   => 'item_bbq_chicken_pizza',
                'type'             => 'item',
                'name'             => 'BBQ Chicken Pizza',
                'description'      => 'Smoky BBQ sauce, grilled chicken, onions',
                'price'            => 449,
                'maximum_price'    => 449,
                'veg_status'       => 'non-veg',
                'available_count'  => 30,
                'maximum_count'    => 30,
                'category_id'      => 'F&B',
                'preparation_time' => 'PT25M',
                'image'            => 'https://example.com/bbq.jpg',
                'is_returnable'    => false,
                'is_cancellable'   => true,
            ]
        );

        // Out-of-stock item for testing
        OndcFnbItem::updateOrCreate(
            ['ondc_item_id' => 'item_special_pizza'],
            [
                'catalog_id'       => $catalog->id,
                'parent_item_id'   => 'item_special_pizza',
                'type'             => 'item',
                'name'             => 'Special Italian Pizza',
                'description'      => 'Limited edition seasonal pizza',
                'price'            => 599,
                'veg_status'       => 'veg',
                'available_count'  => 0,    // OUT OF STOCK
                'is_available'     => false,
                'category_id'      => 'F&B',
            ]
        );

        // Customization groups
        $sizeGroup = OndcFnbCustomizationGroup::updateOrCreate(
            ['group_id' => 'CG_SIZE'],
            [
                'catalog_id'    => $catalog->id,
                'parent_item_id' => $pizza->ondc_item_id,
                'name'          => 'Choose your size',
                'min_select'    => 1,
                'max_select'    => 1,
                'is_required'   => true,
                'sort_order'    => 1,
            ]
        );

        $toppingGroup = OndcFnbCustomizationGroup::updateOrCreate(
            ['group_id' => 'CG_TOPPING'],
            [
                'catalog_id'     => $catalog->id,
                'parent_item_id' => $pizza->ondc_item_id,
                'name'           => 'Add toppings',
                'min_select'     => 0,
                'max_select'     => 5,
                'is_required'    => false,
                'sort_order'     => 2,
            ]
        );

        // Customization items
        $sizes = [
            ['CUST_SIZE_S', 'Small (8")',   0,  'CG_SIZE'],
            ['CUST_SIZE_M', 'Medium (10")', 50, 'CG_SIZE'],
            ['CUST_SIZE_L', 'Large (12")', 100, 'CG_SIZE'],
        ];
        foreach ($sizes as [$id, $name, $price, $group]) {
            OndcFnbItem::updateOrCreate(
                ['ondc_item_id' => $id],
                [
                    'catalog_id'      => $catalog->id,
                    'parent_item_id'  => $group,
                    'type'            => 'customization',
                    'name'            => $name,
                    'price'           => $price,
                    'veg_status'      => 'veg',
                    'available_count' => 99,
                ]
            );
        }

        $toppings = [
            ['CUST_EXTRA_CHEESE',   'Extra Cheese',   30, 'veg'],
            ['CUST_OLIVES',         'Olives',         20, 'veg'],
            ['CUST_PEPPERONI',      'Pepperoni',      40, 'non-veg'],
            ['CUST_MUSHROOMS',      'Mushrooms',      25, 'veg'],
        ];
        foreach ($toppings as [$id, $name, $price, $veg]) {
            OndcFnbItem::updateOrCreate(
                ['ondc_item_id' => $id],
                [
                    'catalog_id'      => $catalog->id,
                    'parent_item_id'  => 'CG_TOPPING',
                    'type'            => 'customization',
                    'name'            => $name,
                    'price'           => $price,
                    'veg_status'      => $veg,
                    'available_count' => 99,
                ]
            );
        }

        $this->command?->info('Seeded F&B catalog: Pizza Palace with 3 items + 2 customization groups + 7 customization options');
    }
}
