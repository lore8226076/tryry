<?php

namespace App\Service;

use App\Models\CharacterDeploySlot;
use App\Models\GddbItems;
use App\Models\UserEquipmentAttribute;
use App\Models\UserEquipmentPower;
use App\Models\UserEquipmentSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SurgameEquipmentService
{
    const EQUIPMENT_DISMANTLE_ID = 191; // 裝備拆解後道具ID

    // 角色拿到裝備
    public function giveEquipment($uid, $itemId): ?int
    {
        try {
            return DB::transaction(function () use ($uid, $itemId) {
                // 建立玩家裝備紀錄
                $equipmentId = $this->storeEquipment($uid, $itemId);
                if (! $equipmentId) {
                    throw new \Exception('建立裝備失敗');
                }

                // 生成隨機屬性
                $attributes = $this->generateRandomAttributes($itemId);
                if (empty($attributes)) {
                    throw new \Exception('隨機屬性生成失敗');
                }

                // 附加屬性
                $giveAttr = $this->attachAttributes($uid, $equipmentId, $attributes);
                if (! $giveAttr) {
                    throw new \Exception('附加屬性失敗');
                }

                // 裝備戰力
                $ok = $this->updateEquipmentPower($equipmentId);
                if (! $ok) {
                    throw new \Exception('裝備戰力計算失敗');
                }

                return $equipmentId;
            });
        } catch (\Throwable $e) {
            Log::error('角色給予裝備 失敗', [
                'uid' => $uid,
                'item_id' => $itemId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    // 角色一鍵換裝
    public function autoEquip($uid, $slotId): bool|array
    {
        try {
            $bestEquipments = [];
            DB::transaction(function () use ($uid, $slotId, &$bestEquipments) {
                // 1) 全部脫裝
                if (! $this->unequipAll($uid, $slotId)) {
                    throw new \RuntimeException('脫裝失敗');
                }
                // 2) 拿最佳裝備
                $bestEquipments = $this->getBestEquipments($uid);

                // 3) 逐件裝上
                foreach ($bestEquipments as $bestEquipment) {
                    $ok = $this->equipEquipment(
                        $uid,
                        $bestEquipment['equipment_id'],
                        $slotId,
                        $bestEquipment['position']
                    );
                    if (! $ok) {
                        throw new \RuntimeException('穿裝失敗');
                    }
                }
            }, 3);

            return $bestEquipments;
        } catch (\Throwable $e) {
            \Log::error('autoEquip rollback', [
                'uid' => $uid, 'slot' => $slotId, 'err' => $e->getMessage(),
            ]);

            return false;
        }
    }

    // 角色綁裝備
    public function equipEquipment($uid, $newEquipmentId, $slotId, $position): bool
    {
        // 新裝備
        $newEquipment = UserEquipmentSession::where(['id' => $newEquipmentId, 'uid' => $uid])->first();
        if (! $newEquipment) {
            return false;
        }
        // 找舊裝備
        $currentEquipment = UserEquipmentSession::where([
            'uid' => $uid,
            'slot_id' => $slotId,
            'position' => $position,
        ])->first();

        if ($currentEquipment && $currentEquipment->equipment_id === $newEquipment->id) {
            // 同一件裝備，不處理
            return true;
        }

        if ($currentEquipment) {
            DB::transaction(function () use ($currentEquipment, $newEquipment, $slotId, $position) {
                // 舊裝備解除綁定
                if ($currentEquipment) {
                    $currentEquipment->update([
                        'slot_id' => null,
                        'position' => null,
                        'is_used' => 0,
                    ]);
                }

                // 新裝備綁定
                $newEquipment->update([
                    'slot_id' => $slotId,
                    'position' => $position,
                    'is_used' => 1,
                ]);
            });
        } else {
            // 直接綁定
            $newEquipment->update([
                'slot_id' => $slotId,
                'position' => $position,
                'is_used' => 1,
            ]);
        }

        return true;
    }

    // 取得角色當前裝備
    public function getUserEquipment($uid): array
    {
        $currentEquipment = UserEquipmentSession::where('uid', $uid)
            ->with(['attributes', 'baseAttributes','item'])
            ->get()
            ->map(function ($equip) use ($uid) {
                return $this->formatterUserEquipment($equip->toArray(), $uid);
            })->toArray();

        return $currentEquipment;
    }

    // 取得角色當前已穿戴的所有裝備
    public function getHasUseEquipments($uid, $slotId = null): array
    {
        $equipmentQuery = UserEquipmentSession::with(['attributes', 'baseAttributes'])
            ->where('uid', $uid)
            ->where('is_used', 1);
        if ($slotId !== null) {
            $equipmentQuery->where('slot_id', $slotId)
                ->orderBy('position', 'asc');
        }
        $currentEquipments = $equipmentQuery->get()->map(function ($equip) use ($uid) {
            return $this->formatterUserEquipment($equip->toArray(), $uid);
        })->toArray();

        return $currentEquipments;
    }

    // 透過裝備ID取得裝備
    public function getEquipmentById($equipmentId): ?array
    {
        $equipment = UserEquipmentSession::with(['attributes', 'baseAttributes'])
            ->where('id', $equipmentId)
            ->first();
        if ($equipment) {
            $equipment = $this->formatterUserEquipment($equipment->toArray(), $equipment?->uid);
        } else {
            $equipment = null;
        }

        return $equipment;
    }

    // 隨機裝備的屬性
    public function generateRandomAttributes($itemId): array
    {
        // 確認裝備
        if (! self::isEquipment($itemId)) {
            return [];
        }

        // 裝備與對應的品質
        $item = GddbItems::with(['equipment.quality'])
            ->where([
                'region' => 'Surgame',
                'category' => 'Equipment',
                'item_id' => $itemId,
            ])->first();

        if (! $item || ! $item->equipment || ! $item->equipment->quality) {
            return [];
        }
        $q = $item->equipment->quality;

        // 抽的次數
        $draws = max(1, (int) ($q->ex_attr_amount ?? 1));

        // 屬性清單
        $candidates = [];
        foreach (['atk', 'hp', 'def'] as $key) {
            $col = "ex_attr_{$key}";
            $raw = $q->{$col} ?? null;

            if (is_string($raw) && trim($raw) !== '') {
                $arr = json_decode($raw, true);
                if (is_array($arr) && count($arr) === 2) {
                    [$min, $max] = $arr;
                } else {
                    // 手動拆字串
                    $raw = trim($raw, "[] \t\n\r\0\x0B");
                    $parts = array_map('trim', explode(',', $raw));
                    if (count($parts) === 2) {
                        [$min, $max] = $parts;
                    } else {
                        continue;
                    }
                }

                $min = (int) $min;
                $max = (int) $max;
                if ($min > $max) {
                    [$min, $max] = [$max, $min];
                }

                $candidates[$key] = [$min, $max];
            }
        }

        if (empty($candidates)) {
            return [];
        }

        // 抽 N 次
        $attributes = [];
        for ($i = 0; $i < $draws; $i++) {
            $attrKey = array_rand($candidates);
            [$min, $max] = $candidates[$attrKey];
            $value = random_int($min, $max);

            $attributes[] = [
                'type' => $attrKey,
                'value' => $value,
            ];
        }

        return $attributes;
    }

    // 為裝備附上屬性
    public function attachAttributes($uid, $equipmentId, $attributes): bool
    {
        $equipment = UserEquipmentSession::where('id', $equipmentId)->first();
        if (! $equipment) {
            return false;
        }

        // 取得裝備可附加屬性上限
        $item = GddbItems::where(['region' => 'Surgame', 'category' => 'Equipment', 'item_id' => $equipment->item_id])->first();
        if (! $item || ! $item->equipment) {
            return false;
        }
        $maxAttributes = $item->equipment->quality->ex_attr_amount ?? 1;
        if (count($attributes) > $maxAttributes) {
            return false;
        }

        foreach ($attributes as $attribute) {
            UserEquipmentAttribute::create([
                'uid' => $uid,
                'equipment_id' => $equipmentId,
                'attribute_name' => $attribute['type'],
                'attribute_value' => $attribute['value'],
            ]);
        }

        return true;
    }

    // salvage 裝備
    public function salvageEquipment($uid, $equipmentIds): array
    {
        try {
            return DB::transaction(function () use ($uid, $equipmentIds) {
                $dismantleItems = [];
                foreach ($equipmentIds as $equipmentId) {
                    $equipment = UserEquipmentSession::where(['id' => $equipmentId, 'uid' => $uid])->first();
                    if (! $equipment) {
                        throw new \Exception("裝備不存在: {$equipmentId}");
                    }
                    // 取得分解後的道具數量
                    $dismantleInfo = $this->getDismantleQty($equipment->item_id);
                    if (! $dismantleInfo) {
                        throw new \Exception("無法分解此裝備: {$equipmentId}");
                    }

                    // 累積道具數量
                    if (isset($dismantleItems[$dismantleInfo['item_id']])) {
                        $dismantleItems[$dismantleInfo['item_id']] += $dismantleInfo['amount'];
                    } else {
                        $dismantleItems[$dismantleInfo['item_id']] = $dismantleInfo['amount'];
                    }

                    // 刪除屬性
                    UserEquipmentAttribute::where('equipment_id', $equipmentId)->delete();
                    // 刪除戰力
                    UserEquipmentPower::where('equipment_id', $equipmentId)->delete();
                    // 刪除裝備
                    $equipment->delete();
                }

                // 回傳分解後的道具
                $resultItems = [];
                foreach ($dismantleItems as $itemId => $qty) {
                    $resultItems[] = [
                        'item_id' => $itemId,
                        'manager_id' => GddbItems::where(['region' => 'Surgame', 'item_id' => $itemId])->value('manager_id'),
                        'qty' => $qty,
                    ];
                }

                // return $resultItems;
                return ['success' => 1, 'status' => true, 'data' => $resultItems];
            });
        } catch (\Throwable $e) {
            Log::error('裝備分解 失敗', [
                'uid' => $uid,
                'equipment_ids' => $equipmentIds,
                'error' => $e->getMessage(),
            ]);

            return ['success' => 0, 'status' => false, 'error' => $e->getMessage()];
        }
    }

    // 裝備戰力計算並存回資料庫
    public function updateEquipmentPower($equipmentId): bool
    {
        // 取得裝備基礎數值
        $equipment = UserEquipmentSession::with('attributes', 'baseAttributes')->where('id', $equipmentId)->first();
        $atk = 0;
        $hp = 0;
        $def = 0;
        // 基礎數值
        $attr = $equipment->baseAttributes;
        if ($attr) {
            $atk += (int) $attr->base_atk;
            $hp += (int) $attr->base_hp;
            $def += (int) $attr->base_def;
        }

        // 額外數值
        foreach ($equipment->attributes as $attr) {
            switch ($attr->attribute_name) {
                case 'atk':
                    $atk += (int) $attr->attribute_value;
                    break;
                case 'hp':
                    $hp += (int) $attr->attribute_value;
                    break;
                case 'def':
                    $def += (int) $attr->attribute_value;
                    break;
            }
        }

        $basePower = $this->calPower($atk, $hp, $def);
        $position = (int) substr($equipment->baseAttributes->type, -1);

        // 寫入資料庫方便mapping
        $powerAry = [
            'uid' => $equipment->uid,
            'equipment_id' => $equipment->id,
            'position' => $position - 1,
            'power' => $this->calPower($atk, $hp, $def),
        ];

        $powerRecord = UserEquipmentPower::where(['uid' => $equipment->uid, 'equipment_id' => $equipment->id])->first();
        if ($powerRecord) {
            $powerRecord->update($powerAry);
        } else {
            UserEquipmentPower::create($powerAry);
        }

        return true;

    }

    // 道具是否為裝備檢查
    public function isEquipment($itemId)
    {
        $equipmentCacheKey = 'equipment_item_ids';
        $equipmentItemIds = Cache::remember($equipmentCacheKey, 3600, function () {
            return GddbItems::where(['region' => 'Surgame', 'category' => 'Equipment'])->pluck('item_id')->toArray();
        });

        return in_array($itemId, $equipmentItemIds);
    }

    // 分解後對應道具數量
    public function getDismantleQty($itemId)
    {
        // 檢查是否為裝備
        if (! self::isEquipment($itemId)) {
            return null;
        }

        // 取得裝備品質
        $item = GddbItems::where(['region' => 'Surgame', 'category' => 'Equipment', 'item_id' => $itemId])->first();
        if (! $item || ! $item->equipment) {
            return null;
        }

        $itemAmount = $item->equipment->quality->recycle_value ?? 0;
        if ($itemAmount <= 0) {
            return null;
        }

        return [
            'item_id' => self::EQUIPMENT_DISMANTLE_ID,
            'amount' => $itemAmount,
        ];
    }

    // 檢查該位置是否能使用該裝備
    public function checkEquipmentCanUse($equipmentId, $position)
    {
        $equipment = UserEquipmentSession::whereHas('baseAttributes', function ($item) use ($position) {
            $item->where('slot_position', $position);
        })->where(['id' => $equipmentId])->first();
        if (! $equipment) {
            return false;
        }

        return true;
    }

    // 檢查使用者是否擁有所選裝備
    public function checkUserHasEquipment($uid, $equipmentIds): array
    {
        $userEquipmentUids = array_column($this->getUserEquipment($uid), 'equipment_uid');
        if (empty($userEquipmentUids)) {
            return [
                'success' => 0,
                'status' => false,
                'error' => ErrorService::errorCode(__METHOD__, 'EQUIPMENT:0003'),
            ];
        }
        if (! empty(array_diff($equipmentIds, $userEquipmentUids))) {
            $errorAry = ErrorService::errorCode(__METHOD__, 'EQUIPMENT:0007');
            $errorAry['equipment_uids'] = array_values(array_diff($equipmentIds, $userEquipmentUids));

            return [
                'success' => 0,
                'status' => false,
                'error' => $errorAry,
            ];
        } else {
            return [
                'success' => 1,
                'status' => true,
            ];
        }
    }

    // 批次將道具ids轉為item_ids
    public function convertEquipmentIdsToItemIds($equipmentIds): array
    {
        $itemIds = UserEquipmentSession::whereIn('id', $equipmentIds)
            ->pluck('item_id')
            ->toArray();

        return $itemIds;
    }

    // 讓裝備改為未使用
    public function unequipAll($uid, $slotId): bool
    {
        try {
            $updated = UserEquipmentSession::where(['uid' => $uid, 'slot_id' => $slotId])
                ->update([
                    'slot_id' => null,
                    'position' => null,
                    'is_used' => 0,
                ]);
        } catch (\Throwable $e) {
            Log::error('角色裝備改為未使用 失敗', [
                'uid' => $uid,
                'slot_id' => $slotId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return $updated !== false;
    }

    // 取得高戰力裝備
    public function getBestEquipments($uid): array
    {
        $bestEquipments = [];
        for ($position = 0; $position <= 5; $position++) {
            $equipments = UserEquipmentPower::whereHas('equipment', function ($query) {
                $query->where('is_used', 0);
            })
                ->where('uid', $uid)
                ->whereIn('position', range(0, 5))
                ->orderBy('power', 'desc')
                ->get(['position', 'power', 'equipment_id']);
            $bestEquipments = $equipments
                ->groupBy('position')
                ->map(function ($group) {
                    return $group->first()->toArray();
                })
                ->values()
                ->all();
        }

        return $bestEquipments;
    }

    private function storeEquipment($uid, $itemId): int
    {
        return UserEquipmentSession::create([
            'uid' => $uid,
            'item_id' => $itemId,
        ])->id;
    }

    private function formatterUserEquipment(array $equipment, $uid = null): array
    {
        return [
            'equipment_uid' => $equipment['equipment_uid'] ?? null,
            'item_id' => $equipment['item_id'] ?? null,
            'manager_id' => $equipment['base_attributes']['unique_id'] ?? null,
            'slot_position' => $equipment['base_attributes']['slot_position'] ?? null,
            'deploy_index' => $this->getSlotIndexById($uid, $equipment['slot_id']) ?? -1,
            'equip_index' => $equipment['position'] ?? -1,
            'is_used' => ! empty($equipment['is_used']) ? 1 : 0,
            'attributes' => array_map(function ($attr) {
                return [
                    'attribute_name' => $attr['attribute_name'],
                    'attribute_value' => intval($attr['attribute_value']),
                ];
            }, $equipment['attributes'] ?? []),
        ];
    }

    // 透過slot_id取得slot_index
    private function getSlotIndexById($uid, $slotId): ?int
    {
        $slot = CharacterDeploySlot::where(['uid' => $uid, 'id' => $slotId])->first();
        if ($slot) {
            return $slot->position;
        } else {
            return null;
        }
    }

    public function calPower($atk = 0, $hp = 0, $def = 0)
    {
        $power = intval($atk * 2 + $hp * 1 + $def * 4);

        return $power;
    }
}
