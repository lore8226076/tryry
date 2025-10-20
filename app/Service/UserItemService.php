<?php

namespace App\Service;

use App\Models\GddbItems;
use App\Models\Settings;
use App\Models\UserItemLogs;
use App\Models\UserItems;
use App\Models\Users;
use DB;
use Illuminate\Support\Facades\Cache;

class UserItemService
{
    public static function getItemLists()
    {
        return Cache::remember('item_data_cache', 300, function () {
            return GddbItems::get()->map(function ($item) {
                // 布林欄位
                $boolFields = ['exchangable', 'network', 'show', 'auto_gen'];

                return collect($item->toArray())
                    ->except(['id', 'created_at', 'updated_at'])
                    ->map(function ($value, $key) use ($boolFields) {
                        if (in_array($key, $boolFields, true)) {
                            return $value ? 'TRUE' : 'FALSE';
                        }

                        if (is_null($value)) {
                            return '';
                        }

                        return (string) $value;
                    })
                    ->all();
            })->toArray();
        });

    }

    // "Item ID": "8200120",
    // "LocalizationName": "02_special_head_200120",
    // "LocalizationDescription": "0",
    // "Category": "AvatarItem",
    // "Type": "Avatar_Head",
    // "Style": "None",
    // "Price": "0",
    // "Exchangable": "FALSE",
    // "ManagerId": "200120",
    // "Network": "FALSE",
    // "Npc ID": "-1",
    // "SortWeight": "0",
    // "Show": "TRUE",
    // "Subtype": "None",
    // "AutoGen": "FALSE",
    // "Region": "Avatar\r"
    public static function getItem($item_id)
    {
        $itemLists = self::getItemLists();
        foreach ($itemLists as $item) {
            if (isset($item['item_id']) && $item['item_id'] == $item_id) {
                return $item;
            }
        }

        return ['error' => '未找到對應的 item_id'];
    }

    public static function getItemByManagerId($region, $manager_id)
    {
        $items = self::getItemLists();

        $match = collect($items)->first(function ($item) use ($region, $manager_id) {
            return $item['manager_id'] === (string) $manager_id
                && $item['region'] === $region;
        });

        // 新增一個欄位避免查詢失敗
        if ($match) {
            $match['Item ID'] = $match['item_id'];
        }

        return $match ?? ['error' => '未找到對應的 item_id'];

    }

    /**
     * type => 1  => __('初始發放'),
     * 2  => __('系統發放'),

     * 10 => __('商城購買'),
     * 11 => __('儲值購買'),

     * 40 => __('商城取消購買'),
     * 41 => __('儲值取消購買'),
     * 50 => __('角色星級提升'),
     * 60 => __('巡邏獎勵'),
     * 61 => __('角色軍階任務獎勵'),
     * 62 => __('角色軍階升級獎勵'),
     * 70 => __('裝備獎勵'),
     * 71 => __('裝備分解'),
     * 72 => __('裝備強化'),
     * 73 => __('裝備精煉'),
     * 80 => __('禮包道具'),
     * 81 => __('寶箱道具'),
     * 90 => __('獲得寶物'),
     * 91 => __('合成寶物'),
     * 92 => __('寶物分解'),
     * user_id
     * uid
     * item_id
     * qty 增加/減少數量
     * is_lock 是否綁定
     * memo 備註
     * user_mall_order_id 遊戲內購買訂單ID
     * user_pay_order_id 金流儲值購買訂單ID
     **/
    public static function addItem($type, $user_id, $uid, $item_id, $qty, $is_lock, $memo, $user_mall_order_id = null, $user_pay_order_id = null, $user_gacha_order_id = null)
    {
        $item = UserItemService::getItem($item_id);
        \Log::info('新增道具', ['data' => $item, 'item_id' => $item_id]);

        if ($item['region'] !== 'Surgame') {
            // 以下指過濾非Surgame的道具
            if (empty($item['region']) && $item['type'] != 'Currency' && $item['type'] != 'TaskPoint') {
                \Log::error('道具region錯誤', [
                    'item_id' => $item_id,
                    'region' => $item['region'],
                    'type' => $item['type'],
                ]);

                return ['success' => 0, 'error_code' => 'MallOrder:0005'];
            }

            if (empty($item['category'])) {
                \Log::error('道具category錯誤', [
                    'item_id' => $item_id,
                    'category' => $item['category'],
                    'type' => $item['type'],
                ]);

                return ['success' => 0, 'error_code' => 'MallOrder:0006'];
            }

            if (empty($item['type'])) {
                \Log::error('道具type錯誤', [
                    'item_id' => $item_id,
                    'type' => $item['type'],
                ]);

                return ['success' => 0, 'error_code' => 'MallOrder:0007'];
            }
        }

        $userItem = UserItems::where('user_id', $user_id)->where('item_id', $item_id)->where('is_lock', $is_lock)->first();
        if (trim($item['region']) === trim(UserItems::REGION_AVATAR)) {
            if ($qty > 1) {
                \Log::error('道具qty錯誤', [
                    'item_id' => $item_id,
                    'qty' => $qty,
                ]);

                return ['success' => 0, 'error_code' => 'MallOrder:0008'];
            }
            if ($userItem) {
                // 回傳轉換後的道具資料
                $convertItem = self::convertItem($item_id, $qty, $user_id);
                if ($convertItem['success'] == 1) {
                    return [
                        'success' => 1,
                        'error_code' => '',
                        'item_id' => $convertItem['item_id'],
                        'qty' => $convertItem['qty'],
                        'been_convert_item' => $item_id,
                    ];
                }

                \Log::error('道具userItem錯誤', [
                    'item_id' => $item_id,
                    'user_id' => $user_id,
                ]);

                return ['success' => 0, 'error_code' => 'MallOrder:0012'];
            }
        }

        try {
            return DB::transaction(function () use (
                $type, $user_id, $uid, $item_id, $qty, $is_lock, $memo,
                $user_mall_order_id, $user_pay_order_id, $user_gacha_order_id, $item, $userItem
            ) {
                if (empty($userItem)) {
                    $userItem = new UserItems;
                    $userItem->user_id = $user_id;
                    $userItem->uid = $uid;
                    $userItem->item_id = $item_id;
                    $userItem->manager_id = $item['manager_id'];
                    $userItem->is_lock = $is_lock;
                    $userItem->region = $item['region'];
                    $userItem->category = $item['category'];
                    $userItem->type = $item['type'];
                    $userItem->qty = 0;
                    $userItem->save();
                }

                $original_qty = $userItem->qty;
                $userItem->qty += $qty;
                if ($userItem->region !== $item['region']) {
                    $userItem->region = $item['region'];
                }

                if ($userItem->save()) {
                    UserItemLogs::changeQty(
                        $type,
                        $user_id,
                        $userItem->id,
                        $item_id,
                        $userItem->manager_id,
                        $original_qty,
                        $qty,
                        $memo,
                        $user_mall_order_id,
                        $user_pay_order_id,
                        $user_gacha_order_id
                    );
                }

                return ['success' => 1, 'error_code' => ''];
            });
        } catch (\Exception $e) {
            \Log::error("使用者道具新增失敗: {$e->getMessage()}", [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ['success' => 0, 'error_code' => 'other'];
        }
    }

    // 道具轉換成對應數量的道具
    public static function convertItem($item_id, $qty, $user_id)
    {
        // avatar_to_ticket
        $setting = Settings::where('name', 'avatar_to_ticket')->first();
        if (empty($setting)) {
            $avatar_to_ticket = ['SSR' => 30, 'SR' => 7, 'R' => 1, 'N' => 1];
        } else {
            $avatar_to_ticket = json_decode($setting->value, true);
        }

        $item = self::getItem($item_id);
        $user = Users::where('id', $user_id)->first();

        // 用rarity 轉成取得道具數量
        $rarity = $item['rarity'];
        $qty = $avatar_to_ticket[$rarity];
        $item_id = 102;

        // 直接發獎勵
        self::addItem(1, $user_id, $user->uid, $item_id, $qty, 1, '重複取得道具轉為扭蛋券', null, null, null);

        return ['success' => 1, 'error_code' => '', 'item_id' => $item_id, 'qty' => $qty];
    }

    /** 移除道具 */
    public static function removeItem($type, $user_id, $uid, $item_id, $qty, $is_lock, $memo, $user_mall_order_id = null, $user_pay_order_id = null, $user_gacha_order_id = null)
    {
        $item = self::getItem($item_id);
        \Log::info('移除道具', ['data' => $item, 'item_id' => $item_id]);
        if ($item['region'] !== 'Surgame') {
            // 以下指過濾非Surgame的道具
            if (empty($item['region']) && $item['type'] != 'Currency' && $item['type'] != 'TaskPoint') {
                return ['success' => 0, 'error_code' => 'MallOrder:0005'];
            }

            if (empty($item['category'])) {
                return ['success' => 0, 'error_code' => 'MallOrder:0006'];
            }

            if (empty($item['type'])) {
                return ['success' => 0, 'error_code' => 'MallOrder:0007'];
            }
        }

        // 如果是貨幣, 但不存在設為0
        if ($item['type'] == 'Currency' && ! UserItems::where('user_id', $user_id)->where('item_id', $item_id)->exists()) {
            self::initCurrency($user_id, $item_id);
        }
        $userItem = UserItems::where('user_id', $user_id)
            ->where('item_id', $item_id)
            ->where('is_lock', $is_lock)
            ->first();

        if (! $userItem) {
            \Log::error('移除道具失敗', [
                'item_id' => $item_id,
                'user_id' => $user_id,
            ]);

            return ['success' => 0, 'error_code' => 'MallOrder:0013']; // 找不到要扣除的道具
        }
        if ($userItem->qty < $qty) {
            \Log::error('移除道具數量不足', [
                'item_id' => $item_id,
                'user_id' => $user_id,
                'current_qty' => $userItem->qty,
                'required_qty' => $qty,
            ]);

            return ['success' => 0, 'error_code' => 'MallOrder:0010']; // 數量不足無法扣除
        }

        try {
            return DB::transaction(function () use (
                $userItem,
                $qty,
                $type,
                $user_id,
                $item_id,
                $memo,
                $user_mall_order_id,
                $user_pay_order_id,
                $user_gacha_order_id
            ) {
                $original_qty = $userItem->qty;
                $userItem->qty -= $qty;

                if ($userItem->qty < 0) {
                    $userItem->qty = 0;
                }

                if ($userItem->save()) {
                    UserItemLogs::changeQty(
                        $type,
                        $user_id,
                        $userItem->id,
                        $item_id,
                        $userItem->manager_id,
                        $original_qty,
                        -$qty,
                        $memo,
                        $user_mall_order_id,
                        $user_pay_order_id,
                        $user_gacha_order_id
                    );
                }

                return ['success' => 1, 'error_code' => ''];
            });
        } catch (\Exception $e) {
            \Log::error('使用者移除道具失敗', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ['success' => 0, 'error_code' => $e->getMessage()];
        }

        return ['success' => 0, 'error_code' => 'other'];
    }

    public static function addItems($items)
    {
        if (empty($items)) {
            return ['success' => 0, 'error_code' => 'UserItem:0001'];
        }

        try {
            return DB::transaction(function () use ($items) {
                $userIds = array_unique(array_column($items, 'user_id'));
                $itemIds = array_unique(array_column($items, 'item_id'));

                // 批量查詢 UserItems
                $existingItems = UserItems::whereIn('user_id', $userIds)
                    ->whereIn('item_id', $itemIds)
                    ->get()
                    ->keyBy(fn ($item) => $item->user_id.'_'.$item->item_id);

                $newItems = [];
                $updatedItems = [];
                $itemLogs = [];

                foreach ($items as $item) {
                    $key = $item['user_id'].'_'.$item['item_id'];

                    if (isset($existingItems[$key])) {
                        $userItem = $existingItems[$key];
                        if ($userItem->region === 'Avatar') {
                            continue;
                        }

                        // 更新數量
                        $original_qty = $userItem->qty;
                        $userItem->qty += $item['qty'];

                        $updatedItems[] = [
                            'id' => $userItem->id,
                            'user_id' => $userItem->user_id,
                            'qty' => $userItem->qty,
                            'updated_at' => now(),
                        ];

                        $user_item_id = $userItem->id;
                        $manager_id = $userItem->manager_id;
                    } else {
                        // 取得道具資料
                        $itemData = UserItemService::getItem($item['item_id']);
                        if (isset($itemData['error'])) {
                            \Log::debug('扭蛋取資料失敗:'.json_encode($item['item_id']));
                            throw new \Exception('GachaOrder:0006');
                        }

                        $newItems[] = [
                            'user_id' => $item['user_id'],
                            'uid' => $item['uid'],
                            'item_id' => $item['item_id'],
                            'manager_id' => $itemData['manager_id'],
                            'is_lock' => 1,
                            'region' => $itemData['region'],
                            'category' => $itemData['category'],
                            'type' => $itemData['type'],
                            'qty' => $item['qty'],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];

                        $user_item_id = 0;
                        $manager_id = $itemData['manager_id'];
                        $original_qty = 0;
                    }

                    // 記錄變更日誌
                    $itemLogs[] = [
                        'type' => $item['type'],
                        'user_id' => $item['user_id'],
                        'user_item_id' => $user_item_id,
                        'item_id' => $item['item_id'],
                        'manager_id' => $manager_id,
                        'original_qty' => $original_qty,
                        'qty' => $item['qty'],
                        'after_qty' => $original_qty + $item['qty'],
                        'memo' => $item['memo'],
                        'user_mall_order_id' => $item['user_mall_order_id'] ?? null,
                        'user_pay_order_id' => $item['user_pay_order_id'] ?? null,
                        'user_gacha_order_id' => $item['user_gacha_order_id'] ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                // 批量插入新道具
                if (! empty($newItems)) {
                    UserItems::insert($newItems);

                    $existingItems = UserItems::whereIn('user_id', $userIds)
                        ->whereIn('item_id', $itemIds)
                        ->get()
                        ->keyBy(fn ($item) => $item->user_id.'_'.$item->item_id);

                    foreach ($itemLogs as $key => $itemLog) {
                        if (isset($existingItems[$itemLog['user_id'].'_'.$itemLog['item_id']])) {
                            $itemLogs[$key]['user_item_id'] =
                            $existingItems[$itemLog['user_id'].'_'.$itemLog['item_id']]->id;
                        }
                    }
                }

                // 更新已存在的道具
                if (! empty($updatedItems)) {
                    foreach ($updatedItems as $item) {
                        UserItems::where('id', $item['id'])->update([
                            'qty' => $item['qty'],
                            'updated_at' => now(),
                        ]);
                    }
                }

                // 批量插入日誌
                if (! empty($itemLogs)) {
                    UserItemLogs::insert($itemLogs);
                }

                return ['success' => 1, 'error_code' => ''];
            });
        } catch (\Exception $e) {
            \Log::error('批量新增道具失敗', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ['success' => 0, 'error_code' => 'BulkAddItems:Error'];
        }

    }

    /** 使用者, 資源id, 資源數量, 需求數量, 額外資源id, 額外資源數量, 額外需求數量 */
    public function checkResource($userId, int $itemId, int $requiredAmount): array
    {
        if (empty($userId)) {
            return ['success' => 0, 'error_code' => 'AUTH:0006'];
        }

        if ($itemId <= 0) {
            return ['success' => 0, 'error_code' => 'MallOrder:0004'];
        }

        if ($requiredAmount <= 0) {
            return ['success' => 0, 'error_code' => 'UserItem:0001'];
        }

        // 抓取道具
        $item = UserItems::where('user_id', $userId)
            ->where('item_id', $itemId)
            ->first();
        if (! $item) {
            return ['success' => 0, 'error_code' => 'UserItem:0005'];
        }

        if ($item->qty < $requiredAmount) {
            return ['success' => 0, 'error_code' => 'UserItem:0003'];
        }

        return ['success' => 1, 'error_code' => ''];
    }

    /** 取得特定道具item_id+amount */
    public function getFormattedItems($uid, $itemIds)
    {
        $itemIds = array_unique($itemIds);

        $formatted = [];
        foreach ($itemIds as $itemId) {
            $item = UserItems::where('uid', $uid)->where('item_id', $itemId)->first();
            if ($item) {
                $formatted[] = [
                    'item_id' => $item->item_id,
                    'amount' => $item->qty,
                ];
            }
        }

        return $formatted;
    }

    // 初始化貨幣
    private static function initCurrency($user_id, $item_id)
    {
        $user = Users::where('id', $user_id)->first();
        $item = self::getItem($item_id);
        if ($item['type'] == 'Currency') {
            UserItems::create([
                'user_id' => $user_id,
                'uid' => $user->uid,
                'item_id' => $item_id,
                'is_lock' => 1,
                'region' => 0,
                'category' => 'Item',
                'type' => 'Currency',
                'qty' => 0,
            ]);
        }
    }
}
