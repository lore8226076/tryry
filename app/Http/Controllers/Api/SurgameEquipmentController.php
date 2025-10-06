<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Service\DeploySlotService;
use App\Service\ErrorService;
use App\Service\SurgameEquipmentService;
use App\Service\UserItemService;
use Illuminate\Http\Request;

class SurgameEquipmentController extends Controller
{
    public $equipmentService;

    public $deploySlotService;

    public function __construct(Request $request, SurgameEquipmentService $equipmentService, DeploySlotService $deploySlotService)
    {
        $origin = $request->header('Origin');
        $referer = $request->header('Referer');
        $this->equipmentService = $equipmentService;
        $this->deploySlotService = $deploySlotService;
        $referrerDomain = parse_url($origin, PHP_URL_HOST) ?? parse_url($referer, PHP_URL_HOST);
        if ($referrerDomain != config('services.API_PASS_DOMAIN')) {
            $this->middleware('auth:api', ['except' => []]);
        }
    }

    // 取得角色當前裝備
    public function getCurrentEquipments(Request $request)
    {
        $user = auth()->guard('api')->user();
        if (empty($user)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0006'), 401);
        }
        $uid = $user->uid;

        $currentEquipment = $this->equipmentService->getUserEquipment($uid);

        return response()->json(['data' => $currentEquipment]);
    }

    // 指定陣位一件穿裝
    public function autoEquip(Request $request)
    {
        $user = auth()->guard('api')->user();
        if (empty($user)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0006'), 422);
        }
        $uid = $user->uid;
        $slotIndex = $request->input('deploy_index');
        if (! in_array($slotIndex, [0, 1, 2, 3, 4])) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'DeploySlot:0002'), 422);
        }

        // 取得陣位id
        $slotId = $this->deploySlotService->getSlotIdByPosition($uid, $slotIndex);
        if (empty($slotId)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'DeploySlot:0003'), 422);
        }
        $oldEquipment = $this->equipmentService->getHasUseEquipments($uid, $slotId);
        if (count($oldEquipment) > 6) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'EQUIPMENT:0003'), 422);
        }
        // 陣列只取得equipment_uid得值重組並保留值陣列
        $oldEquipmentIds = $this->arrayPluckEquipmentIds($oldEquipment);
        $result = $this->equipmentService->autoEquip($uid, $slotId);
        if ($result === false) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'EQUIPMENT:0004'), 422);
        }

        // 取得特定陣位的裝備
        $currentEquipment = $this->equipmentService->getHasUseEquipments($uid, $slotId);
        $currentEquipmentIds = $this->arrayPluckEquipmentIds($currentEquipment);
        $oldEquipment = $this->equipmentService->getEquipmentsByIds($oldEquipmentIds, $currentEquipmentIds, $uid); // 取得舊裝備資料並排除新裝備
        $currentEquipment = array_merge($oldEquipment, $currentEquipment);

        return response()->json(['data' => $currentEquipment], 200);
    }

    // 獲得裝備
    public function obtainEquipment(Request $request)
    {
        $user = auth()->guard('api')->user();
        if (empty($user)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0006'), 422);
        }
        $uid = $user->uid;
        $itemId = $request->input('item_id');
        if (empty($itemId) || $this->equipmentService->isEquipment($itemId) === false) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'EQUIPMENT:0005'), 422);
        }

        // 發送道具記錄到user_items，並建立裝備紀錄
        $addResult = UserItemService::addItem(70, $user->id, $user->uid, $itemId, 1, 1, '獲得道具');
        if ($addResult['success'] === 0) {
            return response()->json(ErrorService::errorCode(__METHOD__, $addResult['error_code']), 422);
        }

        $equipmentId = $this->equipmentService->giveEquipment($uid, $itemId);
        if (empty($equipmentId)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'EQUIPMENT:0003'), 422);
        }
        $equipment = $this->equipmentService->getEquipmentById($equipmentId);

        return response()->json(['data' => $equipment], 200);
    }

    // 使用裝備
    public function useEquipment(Request $request)
    {
        $user = auth()->guard('api')->user();
        if (empty($user)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0006'), 422);
        }
        $uid = $user->uid;
        $equipmentId = $request->input('equipment_uid');
        $slotIndex = $request->input('deploy_index');
        if (empty($equipmentId) || ! in_array($slotIndex, [0, 1, 2, 3, 4])) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'EQUIPMENT:0002'), 422);
        }

        // 取得陣位id
        $slotId = $this->deploySlotService->getSlotIdByPosition($uid, $slotIndex);
        if (empty($slotId)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'DeploySlot:0003'), 422);
        }
        // // 取得當前裝備
        // $oldEquipment = $this->equipmentService->getHasUseEquipments($uid, $slotId);
        // if (count($oldEquipment) > 6) {
        //     return response()->json(ErrorService::errorCode(__METHOD__, 'EQUIPMENT:0003'), 422);
        // }
        $result = $this->equipmentService->equipEquipment($uid, $equipmentId, $slotId);
        if ($result === false) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'EQUIPMENT:0004'), 422);
        }

        return response()->json(['data' => $result], 200);
    }

    // 分解裝備
    public function salvageEquipment(Request $request)
    {
        $user = auth()->guard('api')->user();
        if (empty($user)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0006'), 422);
        }
        $uid = $user->uid;
        $equipmentIds = $request->input('equipment_uids');
        if (is_string($equipmentIds)) {
            $equipmentIds = json_decode($equipmentIds, true);
        } else {
            $equipmentIds = $equipmentIds;
        }
        if (empty($equipmentIds) || ! is_array($equipmentIds)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'EQUIPMENT:0002'), 422);
        }

        // 統計數量並扣除
        $itemCounts = array_count_values($this->equipmentService->convertEquipmentIdsToItemIds($equipmentIds));
        foreach ($itemCounts as $itemId => $qty) {
            $removeResult = UserItemService::removeItem(71, $user->id, $user->uid, $itemId, $qty, 1, '分解裝備扣除');
            if ($removeResult['success'] === 0) {
                return response()->json(ErrorService::errorCode(__METHOD__, $removeResult['error_code']), 422);
            }
        }

        $hasEquipments = $this->equipmentService->checkUserHasEquipment($uid, $equipmentIds);
        // 檢查是否uid擁有這些道具
        if (! $hasEquipments['status']) {
            return response()->json($hasEquipments['error'], 422);
        }

        $salvageResult = $this->equipmentService->salvageEquipment($uid, $equipmentIds);
        if ($salvageResult['status'] === false) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'EQUIPMENT:0006'), 422);
        }

        return response()->json(['data' => $salvageResult['data']], 200);
    }

    // 陣列只取得equipment_uid得值重組並保留值陣列
    private function arrayPluckEquipmentIds($array)
    {
        return array_map(function ($item) {
            return $item['equipment_uid'];
        }, $array);
    }
}
