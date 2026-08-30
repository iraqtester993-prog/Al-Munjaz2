<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add indexes for the query shapes used on every operational refresh.
     *
     * The application intentionally uses separate assignment columns for the
     * three courier roles.  A single composite index cannot serve an OR over
     * those columns, so each role receives the same small, targeted index.
     * Explicit names make the rollback portable across MySQL and SQLite.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // Tenant-scoped mobile lists: tenant + soft-delete + status,
            // followed by cursor/latest-id pagination.
            $table->index(
                ['tenant_id', 'deleted_at', 'status', 'id'],
                'orders_tenant_deleted_status_id_index',
            );

            // The platform orders page is intentionally cross-tenant, so it
            // needs its own status/latest-id path rather than the tenant one.
            $table->index(
                ['status', 'deleted_at', 'id'],
                'orders_status_deleted_id_index',
            );

            // The dashboard's seven-day chart is intentionally cross-tenant
            // and filters a soft-deleted-safe date range before grouping.
            // This small index keeps that recurring chart from scanning the
            // complete order history as the platform grows.
            $table->index(
                ['deleted_at', 'date'],
                'orders_deleted_date_index',
            );

            // Courier queues and dashboard courier filters are OR queries
            // over these three assignment fields. MySQL can use index-merge
            // for that OR, while each status-specific cursor page remains
            // bounded and ordered by id.
            $table->index(
                ['courier_id', 'deleted_at', 'status', 'id'],
                'orders_courier_deleted_status_id_index',
            );
            $table->index(
                ['pickup_courier_id', 'deleted_at', 'status', 'id'],
                'orders_pickup_courier_deleted_status_id_index',
            );
            $table->index(
                ['delivery_courier_id', 'deleted_at', 'status', 'id'],
                'orders_delivery_courier_deleted_status_id_index',
            );
        });

        Schema::table('order_status_logs', function (Blueprint $table): void {
            // Full order sheets load one order's status history newest first.
            $table->index(
                ['order_id', 'created_at'],
                'order_status_logs_order_created_at_index',
            );
        });

        Schema::table('order_movements', function (Blueprint $table): void {
            // Same access pattern as status logs for the operational timeline.
            $table->index(
                ['order_id', 'occurred_at'],
                'order_movements_order_occurred_at_index',
            );
        });

        Schema::table('chats', function (Blueprint $table): void {
            // Mobile support inboxes are scoped to their owner then sorted by
            // their most recent activity.
            $table->index(['user_id', 'last_at'], 'chats_user_last_at_index');

            // Direct merchant/courier inboxes use the explicit counterpart
            // marker and participant id before sorting by recent activity.
            $table->index(
                ['counterparty_type', 'counterparty_id', 'last_at'],
                'chats_counterparty_type_id_last_at_index',
            );
        });

        Schema::table('chat_messages', function (Blueprint $table): void {
            // The incremental message feed already uses (chat_id, id). The
            // unread count instead uses its read timestamp, so it needs this
            // complementary range index.
            $table->index(
                ['chat_id', 'created_at'],
                'chat_messages_chat_created_at_index',
            );
        });

        Schema::table('notifications', function (Blueprint $table): void {
            // The mobile notification bridge reads a personal inbox by user,
            // unread state and newest id. Soft deletes are part of every
            // normal Eloquent query, so retain that predicate in the index.
            $table->index(
                ['user_id', 'deleted_at', 'read_at', 'id'],
                'notifications_user_deleted_read_id_index',
            );

            // Legacy tenant-wide notifications have a NULL user_id and are
            // merged into that same feed through an OR branch.
            $table->index(
                ['tenant_id', 'user_id', 'deleted_at', 'read_at', 'id'],
                'notifications_tenant_user_deleted_read_id_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropIndex('notifications_tenant_user_deleted_read_id_index');
            $table->dropIndex('notifications_user_deleted_read_id_index');
        });

        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->dropIndex('chat_messages_chat_created_at_index');
        });

        Schema::table('chats', function (Blueprint $table): void {
            $table->dropIndex('chats_counterparty_type_id_last_at_index');
            $table->dropIndex('chats_user_last_at_index');
        });

        Schema::table('order_movements', function (Blueprint $table): void {
            $table->dropIndex('order_movements_order_occurred_at_index');
        });

        Schema::table('order_status_logs', function (Blueprint $table): void {
            $table->dropIndex('order_status_logs_order_created_at_index');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_delivery_courier_deleted_status_id_index');
            $table->dropIndex('orders_pickup_courier_deleted_status_id_index');
            $table->dropIndex('orders_courier_deleted_status_id_index');
            $table->dropIndex('orders_deleted_date_index');
            $table->dropIndex('orders_status_deleted_id_index');
            $table->dropIndex('orders_tenant_deleted_status_id_index');
        });
    }
};
