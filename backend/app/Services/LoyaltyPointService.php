<?php

namespace App\Services;

use App\Models\LoyaltyAccount;
use App\Models\LoyaltyEntry;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

/**
 * Sole writer for the loyalty read model and its immutable ledger.
 *
 * Monetary wallets are intentionally not read or written here.  Every caller
 * must use an explicit entry type; a reference (source_type/source_id) makes
 * an award idempotent across duplicate HTTP requests or queued work.
 */
class LoyaltyPointService
{
    public const DELIVERY_REWARD = 'delivery_reward';

    public const DELIVERY_SOURCE = 'order_delivery';

    public const POINTS_PER_DELIVERY_KEY = 'loyalty_points_per_delivery';

    public function credit(
        User $user,
        int $points,
        string $type,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?string $note = null,
    ): LoyaltyEntry {
        if ($points <= 0) {
            throw new InvalidArgumentException('قيمة نقاط الإضافة يجب أن تكون أكبر من صفر.');
        }

        return $this->adjust($user, $points, $type, $sourceType, $sourceId, $note);
    }

    public function debit(
        User $user,
        int $points,
        string $type,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?string $note = null,
    ): LoyaltyEntry {
        if ($points <= 0) {
            throw new InvalidArgumentException('قيمة نقاط الخصم يجب أن تكون أكبر من صفر.');
        }

        return $this->adjust($user, -$points, $type, $sourceType, $sourceId, $note);
    }

    /**
     * Apply a signed points adjustment.  A negative resulting balance is
     * rejected, and a referenced operation can only produce one ledger row.
     */
    public function adjust(
        User $user,
        int $points,
        string $type,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?string $note = null,
    ): LoyaltyEntry {
        $this->validateAdjustment($points, $type, $sourceType, $sourceId, $note);

        try {
            return DB::transaction(function () use ($user, $points, $type, $sourceType, $sourceId, $note): LoyaltyEntry {
                // Serialising through the user row also makes first account
                // creation safe for concurrent requests of the same user.
                User::query()->lockForUpdate()->findOrFail($user->id);

                if ($sourceType !== null) {
                    $existing = $this->entryForSource($type, $sourceType, $sourceId, true);
                    if ($existing) {
                        return $this->assertSourceBelongsToUser($existing, $user);
                    }
                }

                $account = $this->accountForUpdate($user->id);
                $balanceAfter = (int) $account->balance + $points;

                if ($balanceAfter < 0) {
                    throw ValidationException::withMessages([
                        'points' => ['رصيد نقاط الولاء لا يكفي لهذه العملية.'],
                    ]);
                }

                $account->forceFill(['balance' => $balanceAfter])->save();

                return LoyaltyEntry::create([
                    'loyalty_account_id' => $account->id,
                    'user_id' => $user->id,
                    'points' => $points,
                    'balance_after' => $balanceAfter,
                    'type' => $type,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'note' => $note,
                ]);
            });
        } catch (QueryException $exception) {
            // If two independent workers race on the same referenced source,
            // the DB unique key wins. Its losing transaction is rolled back,
            // including the balance update, then returns the original entry.
            if ($sourceType !== null) {
                $existing = $this->entryForSource($type, $sourceType, $sourceId);
                if ($existing) {
                    return $this->assertSourceBelongsToUser($existing, $user);
                }
            }

            throw $exception;
        }
    }

    /**
     * Award the configured number of points to the courier that completed a
     * delivery.  The order row and ledger source key together make retries
     * safely idempotent.
     */
    public function creditForDelivery(Order $order): ?LoyaltyEntry
    {
        return DB::transaction(function () use ($order): ?LoyaltyEntry {
            $delivery = Order::withoutGlobalScopes()->lockForUpdate()->findOrFail($order->id);

            if ($delivery->status !== 'delivered') {
                throw new LogicException('لا يمكن منح نقاط ولاء قبل تسليم الطلب.');
            }

            $existing = $this->entryForSource(self::DELIVERY_REWARD, self::DELIVERY_SOURCE, $delivery->id, true);
            if ($existing) {
                return $existing;
            }

            $points = $this->pointsPerDelivery();
            if ($points === 0) {
                return null;
            }

            // The primary courier owns every new order. Fallbacks only keep
            // rewards for an old completed record readable during migration.
            $courierId = $delivery->courier_id ?: $delivery->delivery_courier_id ?: $delivery->pickup_courier_id;
            // Older records and exceptional operator flows can mark an order
            // delivered without an eligible courier. Settlement must still
            // complete; reward points are simply not created for that order.
            $courier = $courierId
                ? User::withoutGlobalScopes()->find($courierId)
                : null;

            if (! $courier || ! $courier->isCourierRole()) {
                return null;
            }

            return $this->credit(
                $courier,
                $points,
                self::DELIVERY_REWARD,
                self::DELIVERY_SOURCE,
                $delivery->id,
                'نقاط إتمام الطلب '.$delivery->track_no,
            );
        });
    }

    public function pointsPerDelivery(): int
    {
        $configured = Setting::get(self::POINTS_PER_DELIVERY_KEY, 10);

        return is_numeric($configured) ? max(0, (int) $configured) : 10;
    }

    private function accountForUpdate(int $userId): LoyaltyAccount
    {
        $account = LoyaltyAccount::query()
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first();

        if ($account) {
            return $account;
        }

        LoyaltyAccount::create([
            'user_id' => $userId,
            'balance' => 0,
        ]);

        return LoyaltyAccount::query()
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function entryForSource(string $type, string $sourceType, ?int $sourceId, bool $lock = false): ?LoyaltyEntry
    {
        $query = LoyaltyEntry::query()
            ->where('type', $type)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function assertSourceBelongsToUser(LoyaltyEntry $entry, User $user): LoyaltyEntry
    {
        if ((int) $entry->user_id !== (int) $user->id) {
            throw new LogicException('مرجع نقاط الولاء مستخدم مسبقاً لحساب آخر.');
        }

        return $entry;
    }

    private function validateAdjustment(int $points, string &$type, ?string &$sourceType, ?int $sourceId, ?string $note): void
    {
        if ($points === 0) {
            throw new InvalidArgumentException('قيمة تعديل نقاط الولاء لا يمكن أن تكون صفراً.');
        }

        $type = trim($type);
        if ($type === '' || mb_strlen($type) > 60) {
            throw new InvalidArgumentException('نوع قيد نقاط الولاء غير صالح.');
        }

        $sourceType = $sourceType === null ? null : trim($sourceType);
        if ($sourceType === '') {
            $sourceType = null;
        }

        if (($sourceType === null) !== ($sourceId === null)) {
            throw new InvalidArgumentException('يجب حفظ نوع ومعرّف المصدر معاً أو تركهما فارغين.');
        }

        if ($sourceType !== null && mb_strlen($sourceType) > 80) {
            throw new InvalidArgumentException('نوع مصدر نقاط الولاء غير صالح.');
        }

        if ($sourceId !== null && $sourceId <= 0) {
            throw new InvalidArgumentException('معرّف مصدر نقاط الولاء غير صالح.');
        }

        if ($note !== null && mb_strlen($note) > 500) {
            throw new InvalidArgumentException('ملاحظة نقاط الولاء أطول من الحد المسموح.');
        }
    }
}
