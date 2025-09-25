<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserEquipmentSession;
use App\Models\UserItems;
use App\Models\Users;
use App\Service\ErrorService;
use App\Service\ItemPackageService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class PackageController extends Controller
{
    public function __construct(Request $request)
    {
        $origin = $request->header('Origin');
        $referer = $request->header('Referer');
        $referrerDomain = parse_url($origin, PHP_URL_HOST) ?? parse_url($referer, PHP_URL_HOST);
        if ($referrerDomain != config('services.API_PASS_DOMAIN')) {
            $this->middleware('auth:api', ['except' => []]);
        }
    }

    public function getAllItems(Request $request)
    {
        $user = auth()->guard('api')->user();
        if (empty($user)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }
        // 非裝備
        $items = UserItems::with(['item', 'item.itemPackage'])->where('category', '!=', 'Equipment')->where(['user_id' => $user->id, 'region' => 'Surgame'])->get();
        $items = $this->formatUserInventory($items);
        // 裝備
        $equipments = UserEquipmentSession::with('attributes')->where('uid', $user->uid)->get();
        $equipments = $this->formatUserEquipments($equipments);
        // 符文
        $runes = []; // TODO: 符文系統尚未開發

        $results = $this->formatPackages($items, $equipments, $runes);

        return response()->json(['data' => $results], 200);
    }

    // 使用背包物品
    public function useItem(Request $request, ItemPackageService $svc)
    {
        $user = Users::where('uid', auth()->guard('api')->user()?->uid)->first();
        if (empty($user)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }
        $itemId = (int) $request->input('item_id', 0);
        if ($itemId <= 0) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'INVENTORY:0001'), 422);
        }
        $amount = (int) $request->input('amount', 1);
        if ($amount <= 0) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'INVENTORY:0004'), 422);
        }
        $selectedItemIds = $request->input('selected_item_id', []);
        if (is_string($selectedItemIds)) {
            $selectedItemIds = json_decode($selectedItemIds, true);
            if (! is_array($selectedItemIds)) {
                return response()->json(ErrorService::errorCode(__METHOD__, 'INVENTORY:0005'), 422);
            }
        }
        // 如果是自選包, 陣列長度應該要等於amount
        if (! empty($selectedItemIds) && count($selectedItemIds) != $amount) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'INVENTORY:0007'), 422);
        }

        try {
            $result = $svc->openPackage($user, $itemId, $selectedItemIds, $amount);
            if ($result['success'] != 1) {
                return response()->json(ErrorService::errorCode(__METHOD__, 'INVENTORY:0005'), 422);
            }

            return response()->json(['data' => $result['data']], 200);
        } catch (\Exception $e) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'INVENTORY:0005'), 422);
        }

        return response()->json(['data' => $result['data']], 200);
    }

    private function formatUserInventory(Collection $items)
    {
        $newItems = $items->map(function ($item) {
            return [
                'item_id' => $item->item_id,
                'manager_id' => $item->manager_id,
                'qty' => (int) $item->qty,
                'type' => $item->item?->itemPackage?->use_necessary > 1 ? 'shard' : 'item',
            ];
        })->values()->all();

        $results = [];
        foreach ($newItems as $item) {
            $results['item'] ??= [];
            $results['shard'] ??= [];
            if ($item['type'] == 'item') {
                $results['item'][] = [
                    'item_id' => $item['item_id'],
                    'manager_id' => $item['manager_id'],
                    'qty' => $item['qty'],
                ];
            } else {
                $results['shard'][] = [
                    'item_id' => $item['item_id'],
                    'manager_id' => $item['manager_id'],
                    'qty' => $item['qty'],
                ];
            }
        }

        return $results;
    }

    private function formatUserEquipments(Collection $equipments)
    {
        return $equipments->map(fn ($eq) => [
            'equipment_uid' => $eq->uid,
            'item_id' => $eq->item_id,
            'manager_id' => $eq->item?->manager_id,
            'slot_position' => (int) $eq->baseAttributes->slot_position,
            'ex_attr' => json_decode($eq->attributes, true),
        ])->values()->all();
    }

    // 完整背包回傳格式
    private function formatPackages($items, $equipments, $runes = [])
    {
        $results = [];
        $results['items'] = $items;
        $results['equipments'] = $equipments;
        $results['runes'] = (object) $runes;

        return $results;
    }
}
