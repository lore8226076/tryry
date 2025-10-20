<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CharacterDeploySlot as DeploySlot;
use App\Models\UserCharacter;
use App\Models\UserEquipmentSession;
use App\Models\Users;
use App\Models\UserSlotEquipment;
use App\Service\DeploySlotService;
use App\Service\CharacterService;
use App\Service\ErrorService;
use App\Service\UserItemService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeploySlotController extends Controller
{
    public function __construct(Request $request)
    {
        $origin = $request->header('Origin');
        $referer = $request->header('Referer');
        $referrerDomain = parse_url($origin, PHP_URL_HOST) ?? parse_url($referer, PHP_URL_HOST);
        if ($referrerDomain != config('services.API_PASS_DOMAIN')) {
            $this->middleware('auth:api', ['except' => ['showItems']]);
        }
    }

    // 取得特定人的 slot，若無則自動建立
    public function showItems(Request $request, $uid, DeploySlotService $svc)
    {
        // 確認玩家存在
        $user = Users::where('uid', $uid)->first();

        if (! $user || $uid === null) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }

        // 讀取玩家陣位，若不存在則初始化
        $slots = DeploySlot::where('uid', $uid)->orderBy('position', 'asc')->get();

        if ($slots->isEmpty()) {
            $this->initDeploySlot($uid);
            $slots = DeploySlot::where('uid', $uid)->orderBy('position', 'asc')->get();
        }

        $slotIds = $slots->pluck('id');

        // 撈取裝備與強化資訊，僅整理現有資料
        $equipmentBySlot = collect();
        $upgradeBySlot = collect();

        if ($slotIds->isNotEmpty()) {
            $equipmentBySlot = UserEquipmentSession::with('item')
                ->whereIn('slot_id', $slotIds)
                ->where('is_used', 1)
                ->orderBy('position', 'asc')
                ->get()
                ->groupBy('slot_id');

            $upgradeBySlot = UserSlotEquipment::where('uid', $uid)
                ->whereIn('slot_id', $slotIds)
                ->get()
                ->groupBy('slot_id');
        }

        // 組裝符合前端需求的陣位資料
        $response = $slots->map(function ($slot) use ($equipmentBySlot, $upgradeBySlot, $svc) {
            $slotEquipments = $equipmentBySlot->get($slot->id, collect())
                ->filter(fn ($equipment) => $equipment->position !== null);

            $upgradeMap = $upgradeBySlot->get($slot->id, collect())->keyBy('position');

            // 假設一個 slot 最多 0~5 的位置
            $allPositions = collect(range(0, 5));

            $equipments = $allPositions->map(function ($position) use ($slotEquipments, $upgradeMap, $svc) {
                $equipment = $slotEquipments->firstWhere('position', $position);
                $upgradeRow = $upgradeMap->get($position);

                // 取得對應陣位的裝備的refine成功機率
                if ($upgradeRow) {
                    $refineSuccessRate = $svc->getCurrentSuccessRate($upgradeRow);
                } else {
                    $refineSuccessRate = 40; // 預設 40%
                }

                return [
                    'equip_index' => $position,
                    'equipment_uid' => (int) ($equipment->id ?? -1),  // 沒裝備 → 0
                    'equipment_manager_id' => (int) ($equipment?->item?->manager_id ?? -1),
                    'enhance_level' => (int) ($upgradeRow->enhance_level ?? 1), // 預設 1
                    'refine_level' => (int) ($upgradeRow->refine_level ?? 0), // 預設 0
                    'refine_success_rate' => (int) ($refineSuccessRate ?? 40), // 預設 0
                ];
            })->values()->all();

            return [
                'slot_index' => (int) ($slot->position ?? 0),
                'slot_level' => (int) ($slot->level ?? 1),
                'equipments' => $equipments,
                'runes' => [],
            ];
        })->values()->all();

        // 強制設定主角在0號位置
        $svc = app(DeploySlotService::class);
        $svc->forceSetMainCharacter($uid);

        return response()->json(['data' => $response]);
    }

    // 更新或建立 deploy slot，僅更新有傳的欄位
    public function slotLvUpdate(Request $request)
    {
        $uid = auth()->guard('api')->user()->uid;
        if (empty($uid)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }

        $user = Users::where('uid', $uid)->first();

        // 傳入 index
        $index = $request->input('index');

        // 檢查 index 是否為 0~4 的正整數
        if (! ctype_digit((string) $index) || (int) $index < 0 || (int) $index > 4) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'DeploySlot:0003'), 422);
        }

        // 撈出指定位置的 slot
        $slot = DeploySlot::where('uid', $uid)
            ->where('position', (int) $index)
            ->first();

        // 如果沒有，就初始化
        if (! $slot) {
            $this->initDeploySlot($uid);
            $slot = DeploySlot::where('uid', $uid)
                ->where('position', (int) $index)
                ->first();
        }

        // 等級上限檢查
        $targetLv = $slot->level + 1;
        $checkLvMaximum = DeploySlotService::getLvMaximum($targetLv);
        if ($checkLvMaximum === false) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'DeploySlot:0008'), 422);
        }

        // 材料檢查
        $checkResult = DeploySlotService::checkLvMaterial($targetLv, $user);
        if ($checkResult === false) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'DeploySlot:0006'), 422);
        }

        // 隊伍等級檢查
        $allSlots = DeploySlot::where('uid', $uid)->get();
        $levels = $allSlots->pluck('level', 'position')->toArray();

        $minimumLv = min($levels);
        $maximumLv = max($levels);

        if (($maximumLv - $minimumLv) > 5 && ($levels[$index] ?? 1) >= $maximumLv) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'DeploySlot:0007'), 422);
        }

        // 遞增等級
        $slot->increment('level', 1);

        // 重新取出所有 slot（最新等級）
        $allSlots = DeploySlot::where('uid', $uid)->get();

        // 組裝舊版格式
        $response = [];
        $slotRow = $allSlots->firstWhere('position', $index);
        $response['slot_index'] = $slotRow->position;
        $response['slot_level'] = $slotRow->level ?? 1;

        // 強制設定主角在0號位置
        $svc = app(DeploySlotService::class);
        $svc->forceSetMainCharacter($uid);

        return response()->json(['data' => $response]);
    }

    // 角色上陣
    public function slotUpdate(Request $request, DeploySlotService $svc)
    {
        $uid = auth()->guard('api')?->user()?->uid;
        if (empty($uid)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }

        $slotsInput = $request->input('slots', []);

        // form-data 傳字串時轉陣列
        if (is_string($slotsInput)) {
            $decoded = json_decode($slotsInput, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $slotsInput = $decoded;
            }
        }
        if (! is_array($slotsInput)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'DeploySlot:0004'), 422);
        }

        // 先找或建資料（確保有 5 個位置）
        $slots = DeploySlot::where('uid', $uid)->get();
        if ($slots->isEmpty()) {
            $this->initDeploySlot($uid);
            $slots = DeploySlot::where('uid', $uid)->get();
        }

        // 只取前 5 個，並重新索引
        $slotsInput = array_values($slotsInput);
        $slotsInput = array_slice($slotsInput, 0, 5);

        // 轉換為 int 或 null
        $slotsInput = array_map(fn ($v) => $v === null || $v === -1 ? null : (int) $v, $slotsInput);

        // 檢查重複角色（排除 null）
        $nonNullSlots = array_filter($slotsInput, fn ($v) => $v !== null);
        if (count($nonNullSlots) !== count(array_unique($nonNullSlots))) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'DeploySlot:0005'), 422);
        }

        // 檢查角色必須屬於玩家
        foreach ($nonNullSlots as $characterId) {
            if (! UserCharacter::where('uid', $uid)->where('character_id', $characterId)->exists()) {
                $error = ErrorService::errorCode(__METHOD__, 'CHARACTER:0001');
                $error['error_character_id'] = $characterId;

                return response()->json($error, 422);
            }
        }

        // 更新 slot
        DB::transaction(function () use ($uid, $slotsInput) {
            foreach ($slotsInput as $i => $characterId) {
                // 更新 DeploySlot 的 character_id
                DeploySlot::where('uid', $uid)
                    ->where('position', $i)
                    ->update(['character_id' => $characterId]);

                // 先清除舊角色佔用
                UserCharacter::where('uid', $uid)
                    ->where('slot_index', $i)
                    ->update(['has_use' => 0, 'slot_index' => null]);

                // 再設定新角色佔用
                if ($characterId !== null) {
                    $affected = UserCharacter::where('uid', $uid)
                        ->where('character_id', $characterId)
                        ->limit(1)
                        ->update(['has_use' => 1, 'slot_index' => $i]);

                    if ($affected === 0) {
                        throw new \RuntimeException('CHARACTER:0001');
                    }
                }
            }
        });

        // 重新取得 slots
        $slots = DeploySlot::where('uid', $uid)->get();

        // 組裝舊版格式
        $response = [];
        for ($i = 0; $i < 5; $i++) {
            $slotRow = $slots->firstWhere('position', $i);
            $response["slot_{$i}_level"] = $slotRow->level ?? 1;
            $response["slot_{$i}_character_id"] = $slotRow->character_id ?? null;
        }

        $response= CharacterService::getUserCharacterList($uid);

        return response()->json(['data' => $response]);
    }

    // 裝備精煉等級提升
    public function updateRefineLv(Request $request, DeploySlotService $svc, UserItemService $userItemSvc)
    {
        $uid = auth()->guard('api')->user()->uid;
        if (empty($uid)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }
        $deployIndex = $request->input('deploy_index');   // 陣位位置
        $equipIndex = $request->input('equip_index');    // 裝備位置
        $times = (int) $request->input('times', 1); // 強化次數
        if (! in_array($deployIndex, [0, 1, 2, 3, 4]) || ! in_array($equipIndex, [0, 1, 2, 3, 4, 5]) || ! in_array($times, [1, 10])) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'EQUIPMENT:0002'), 422);
        }

        // 陣位id
        $slotId = $svc->getSlotIdByPosition($uid, $deployIndex);
        if (empty($slotId)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'DeploySlot:0003'), 422);
        }

        // 取得該陣位該位置的裝備
        $userEquipment = UserSlotEquipment::with('deploySlot')->where('uid', $uid)
            ->where('slot_id', $slotId)
            ->where('position', $equipIndex)
            ->first();
        if (! $userEquipment) {
            $svc->initUserSlotEquipment($uid, $slotId, $equipIndex);
            $userEquipment = UserSlotEquipment::with('deploySlot')->where('uid', $uid)
                ->where('slot_id', $slotId)
                ->where('position', $equipIndex)
                ->first();
            if (! $userEquipment) {
                return response()->json(ErrorService::errorCode(__METHOD__, 'EQUIPMENT:0006'), 422);
            }
        }

        // 檢查是否可下一級
        if (! $svc::getEquipmentLvMaximum($userEquipment->refine_level + 1, 'refine')) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'EQUIPMENT:0010'), 422);
        }

        $tries = ($times > 1) ? 10 : 1;
        if (! $svc->canRefineTimes($userEquipment, $tries)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'EQUIPMENT:0008'), 422);
        }
        $refineResult = $svc->refineEquipment($userEquipment, $tries);
        if ($refineResult['success'] === 0) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'EQUIPMENT:0009'), 422);
        }

        // 確保有載到 deploySlot，避免 N+1 / null
        $userEquipment->loadMissing('deploySlot');
        // 結果

        $upgradeInfo = $svc->formatShowEnhanceData($userEquipment->refresh(), 'single', $refineResult);
        $result['upgrade_info'] = $upgradeInfo;
        $result['inventory_info'] = $userItemSvc->getFormattedItems($uid, [192]);
        if ($result) {
            return response()->json(['data' => $result], 200);
        }

        return response()->json([], 422);
    }

    // 裝備強化等級更新
    public function updateEnhanceLv(Request $request, DeploySlotService $svc, UserItemService $userItemSvc)
    {
        $uid = auth()->guard('api')->user()->uid;
        if (empty($uid)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }
        $deployIndex = $request->input('deploy_index'); // 陣位位置
        if (! in_array($deployIndex, [0, 1, 2, 3, 4])) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'EQUIPMENT:0002'), 422);
        }
        // 陣位id
        $slotId = $svc->getSlotIdByPosition($uid, $deployIndex);
        if (empty($slotId)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'DeploySlot:0003'), 422);
        }

        $equipIndex = $request->input('equip_index'); // 裝備位置
        if ($equipIndex !== null) {
            if (! in_array($equipIndex, [0, 1, 2, 3, 4, 5])) {
                return response()->json(ErrorService::errorCode(__METHOD__, 'EQUIPMENT:0002'), 422);
            }
            // 單件升級
            $ok = $svc->enhanceEquipment($uid, $slotId, $equipIndex);
            if ($ok['success'] === 0 && isset($ok['error_code'])) {
                return response()->json(ErrorService::errorCode(__METHOD__, $ok['error_code']), 422);
            }
            $userEquipment = UserSlotEquipment::with('deploySlot')->where('uid', $uid)
                ->where('slot_id', $slotId)
                ->where('position', $equipIndex)
                ->first();
            if (! $userEquipment) {
                return response()->json(ErrorService::errorCode(__METHOD__, 'EQUIPMENT:0006'), 422);
            }
            // 確保有載到 deploySlot，避免 N+1 / null
            $userEquipment->loadMissing('deploySlot');
            // 結果
            $upgradeInfo = $svc->formatShowEnhanceData($userEquipment->refresh());
        } else {
            // 一鍵升級
            $ok = $svc->enhanceEquipment($uid, $slotId);
            if ($ok['success'] === 0 && isset($ok['error_code'])) {
                return response()->json(ErrorService::errorCode(__METHOD__, $ok['error_code']), 422);
            }
            $slots = UserSlotEquipment::with('deploySlot')->where('uid', $uid)
                ->where('slot_id', $slotId)
                ->get();
            $upgradeInfo = $svc->formatShowEnhanceData($slots, 'multiple');
        }
        $result['upgrade_info'] = $upgradeInfo;
        $result['inventory_info'] = $userItemSvc->getFormattedItems($uid, [101, 191]);
        if ($result) {
            return response()->json(['data' => $result], 200);
        }

        return response()->json([], 422);
    }

    // 取得裝備等級
    public function showEquipments(Request $request, DeploySlotService $svc)
    {
        $uid = auth()->guard('api')->user()->uid;
        if (empty($uid)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }
        $deployIndex = $request->input('deploy_index'); // 陣位位置
        $deployQuery = UserSlotEquipment::with('deploySlot')->where('uid', $uid);
        if (! is_null($deployIndex)) {
            $slotId = $svc->getSlotIdByPosition($uid, $deployIndex);
            if (empty($slotId)) {
                return response()->json(ErrorService::errorCode(__METHOD__, 'DeploySlot:0003'), 422);
            }
            $deployQuery->where('slot_id', $slotId);
        }
        $slots = $deployQuery->get();
        if ($slots->isEmpty()) {
            return $svc->initUserSlotEquipment($uid);
        }
        $response = $svc->formatShowEnhanceData($slots, 'multiple');
        $response = $this->formatterResults($response);

        return response()->json(['data' => $response]);
    }

    private function initDeploySlot($uid)
    {
        $manyData = [];
        for ($i = 0; $i < 5; $i++) {
            $manyData[] = [
                'uid' => $uid,
                'character_id' => null,
                'level' => 1,
                'position' => $i,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (empty($manyData)) {
            return null;
        }
        try {
            DeploySlot::insert($manyData);
        } catch (\Exception $e) {
            \Log::error('[initDeploySlot] 創建玩家初始陣位資料失敗', [
                'uid' => $uid,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return true;
    }

    private static function checkCharacter($uid, $characterId)
    {
        return UserCharacter::where('uid', $uid)
            ->where('character_id', $characterId)
            ->exists();
    }

    private function formatterResults($data)
    {
        return collect($data)
            ->sortBy([
                ['deploy_index', 'asc'],
                ['equip_index', 'asc'],
            ])
            ->groupBy('deploy_index')
            ->map(function ($group, $deployIndex) {
                return $group->map(function ($item) {
                    return [
                        'equip_index' => $item['equip_index'],
                        'enhance_level' => $item['enhance_level'],
                        'refine_level' => $item['refine_level'],
                    ];
                })->values()->all();
            })
            ->mapWithKeys(function ($group, $deployIndex) {
                return ["equipment_{$deployIndex}_level" => $group];
            })
            ->toArray();
    }
}
