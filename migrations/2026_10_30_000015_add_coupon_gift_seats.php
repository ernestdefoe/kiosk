<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Builder;

/*
 * Coupons, gifts and team seats on purchases and prices; and entitlements
 * unique per user, so a team purchase holds one per seat.
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('kiosk_prices', function ($table) {
            $table->unsignedSmallInteger('seats')->default(1);
        });
        $schema->table('kiosk_purchases', function ($table) {
            $table->string('coupon_code', 40)->nullable();
            $table->unsignedInteger('gifted_by')->nullable();
            $table->unsignedSmallInteger('seats')->default(1);
        });
        $schema->create('kiosk_seats', function ($table) {
            $table->unsignedInteger('purchase_id');
            $table->unsignedInteger('user_id');
            $table->dateTime('added_at');
            $table->primary(['purchase_id', 'user_id']);
            $table->foreign('purchase_id')->references('id')->on('kiosk_purchases')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        $schema->table('kiosk_entitlements', function ($table) {
            $table->dropUnique(['purchase_id', 'type', 'target_id']);
            $table->unique(['purchase_id', 'user_id', 'type', 'target_id']);
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('kiosk_entitlements', function ($table) {
            $table->dropUnique(['purchase_id', 'user_id', 'type', 'target_id']);
            $table->unique(['purchase_id', 'type', 'target_id']);
        });
        $schema->dropIfExists('kiosk_seats');
        $schema->table('kiosk_purchases', function ($table) {
            $table->dropColumn(['coupon_code', 'gifted_by', 'seats']);
        });
        $schema->table('kiosk_prices', function ($table) {
            $table->dropColumn('seats');
        });
    },
];
