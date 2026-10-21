<?php

namespace Webkul\Ondc\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OndcCancellationReasonSeeder extends Seeder
{
    public function run(): void
    {
        $reasons = [
            ['code'=>'001','reason'=>'Price of one or more items have changed due to which buyer was asked to make additional payment','triggers_rto'=>false,'who_can_use'=>'BNP','cost_attributed_to'=>'SNP','applicable_for_part_cancel'=>false],
            ['code'=>'002','reason'=>'One or more items in the Order not available','triggers_rto'=>false,'who_can_use'=>'SNP','cost_attributed_to'=>'SNP','applicable_for_part_cancel'=>true],
            ['code'=>'003','reason'=>'Product available at lower than order price','triggers_rto'=>true,'who_can_use'=>'BNP','cost_attributed_to'=>'SNP','applicable_for_part_cancel'=>false],
            ['code'=>'005','reason'=>'Merchant rejected the order','triggers_rto'=>false,'who_can_use'=>'SNP','cost_attributed_to'=>'SNP','applicable_for_part_cancel'=>false],
            ['code'=>'006','reason'=>'Order / fulfillment not received as per buyer app TAT SLA','triggers_rto'=>true,'who_can_use'=>'BNP','cost_attributed_to'=>'SNP/LSP','applicable_for_part_cancel'=>false],
            ['code'=>'007','reason'=>'Order / fulfillment not received as per buyer app TAT SLA','triggers_rto'=>true,'who_can_use'=>'LBNP','cost_attributed_to'=>'LSP','applicable_for_part_cancel'=>false],
            ['code'=>'008','reason'=>'Order / fulfillment not ready for pickup','triggers_rto'=>false,'who_can_use'=>'LSP','cost_attributed_to'=>'SNP','applicable_for_part_cancel'=>false],
            ['code'=>'009','reason'=>'Wrong product delivered','triggers_rto'=>true,'who_can_use'=>'BNP','cost_attributed_to'=>'SNP','applicable_for_part_cancel'=>false],
            ['code'=>'010','reason'=>'Buyer wants to modify address / other order details','triggers_rto'=>true,'who_can_use'=>'BNP','cost_attributed_to'=>'BNP','applicable_for_part_cancel'=>false],
            ['code'=>'011','reason'=>'Buyer not found or cannot be contacted','triggers_rto'=>true,'who_can_use'=>'LSP/SNP','cost_attributed_to'=>'BNP','applicable_for_part_cancel'=>false],
            ['code'=>'012','reason'=>'Buyer does not want product any more','triggers_rto'=>true,'who_can_use'=>'LSP/SNP','cost_attributed_to'=>'BNP','applicable_for_part_cancel'=>true],
            ['code'=>'013','reason'=>'Buyer refused to accept delivery','triggers_rto'=>true,'who_can_use'=>'LSP/SNP','cost_attributed_to'=>'BNP','applicable_for_part_cancel'=>false],
            ['code'=>'014','reason'=>'Address not found','triggers_rto'=>true,'who_can_use'=>'LSP/SNP','cost_attributed_to'=>'BNP','applicable_for_part_cancel'=>false],
            ['code'=>'015','reason'=>'Buyer not available at location','triggers_rto'=>true,'who_can_use'=>'LSP/SNP','cost_attributed_to'=>'BNP','applicable_for_part_cancel'=>false],
            ['code'=>'016','reason'=>'Accident / rain / strike / vehicle issues','triggers_rto'=>false,'who_can_use'=>'LSP','cost_attributed_to'=>'LSP','applicable_for_part_cancel'=>false],
            ['code'=>'017','reason'=>'Order delivery delayed or not possible','triggers_rto'=>false,'who_can_use'=>'LSP','cost_attributed_to'=>'LSP','applicable_for_part_cancel'=>false],
            ['code'=>'018','reason'=>'Delivery pin code not serviceable','triggers_rto'=>false,'who_can_use'=>'SNP','cost_attributed_to'=>'SNP','applicable_for_part_cancel'=>false],
            ['code'=>'019','reason'=>'Pickup pin code not serviceable','triggers_rto'=>false,'who_can_use'=>'SNP','cost_attributed_to'=>'SNP','applicable_for_part_cancel'=>false],
            ['code'=>'020','reason'=>'Order lost in transit','triggers_rto'=>false,'who_can_use'=>'LSP/SNP','cost_attributed_to'=>'LSP','applicable_for_part_cancel'=>false],
            ['code'=>'021','reason'=>'Packed order not complete','triggers_rto'=>false,'who_can_use'=>'LSP/SNP','cost_attributed_to'=>'SNP','applicable_for_part_cancel'=>false],
        ];

        $now = now();
        foreach ($reasons as &$r) {
            $r['is_active']   = true;
            $r['created_at']  = $now;
            $r['updated_at']  = $now;
        }

        DB::table('ondc_cancellation_reasons')->upsert(
            $reasons,
            ['code'],
            ['reason', 'triggers_rto', 'who_can_use', 'cost_attributed_to', 'applicable_for_part_cancel', 'updated_at']
        );
    }
}
