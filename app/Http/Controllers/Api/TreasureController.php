<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GddbSurgameTreasure as Model;
use App\Service\ErrorService;
use App\Service\TreasureService;
use App\Service\UserItemService;
use Illuminate\Http\Request;

class TreasureController extends Controller
{
    protected $treasureSvc;

    protected $userItemSvc;

    public function __construct(Request $request, TreasureService $treasureSvc, UserItemService $userItemSvc)
    {
        $this->treasureSvc = $treasureSvc;
        $this->userItemSvc = $userItemSvc;

        $origin = $request->header('Origin');
        $referer = $request->header('Referer');
        $referrerDomain = parse_url($origin, PHP_URL_HOST) ?? parse_url($referer, PHP_URL_HOST);
        if ($referrerDomain != config('services.API_PASS_DOMAIN')) {
            $this->middleware('auth:api', ['except' => []]);
        }
    }

    // 道具合成
    public function fuse(Request $request)
    {
        $user = $this->resolveUid($request);

        if (! $user) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }
        $mainMaterialId = intval($request->input('main_material_id'));
        $materialIds = $this->normalizeEarned($request->input('materials', []));
        $newMaterialAry = array_merge([$mainMaterialId], $materialIds);
        // 檢查道具是否為寶物
        $isFuse = $this->treasureSvc->isTreasure($newMaterialAry);
        if (! $isFuse) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'TREASURE:0004'), 422);
        }

        // 檢查道具是否可以合成
        $invalidItems = $this->treasureSvc->checkInvalidItems($mainMaterialId, $materialIds);
        if (count($invalidItems) > 0) {
            $errMsg = ErrorService::errorCode(__METHOD__, 'TREASURE:0013', 422);
            $errMsg['item_id'] = $invalidItems[0];

            return response()->json($errMsg, 422);
        }

        // 檢查是否需要兩個素材
        $checkTwoMaterial = $this->treasureSvc->validateMaterialCount($mainMaterialId, $materialIds);
        if (! $checkTwoMaterial) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'TREASURE:0015'), 422);
        }

        // 檢查是否有相關道具
        $formatterMaterialAry = $this->groupItemIds($newMaterialAry);
        $result = $this->checkMaterialCount($user, $formatterMaterialAry); // 檢查道具數量
        if (is_array($result)) {
            return response()->json($result, 422);
        }

        // 取得下個道具item_id
        $targetItemId = $this->treasureSvc->getUpgradeItemIds($mainMaterialId);
        if (! $targetItemId) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'TREASURE:0001'), 422);
        }

        try {
            $result = $this->treasureSvc->fuseTreasure($user, $targetItemId, $newMaterialAry);

            // 取得所有合成相關道具
            $allMaterials = array_values(array_unique(array_merge([$targetItemId, $mainMaterialId], $materialIds)));
            // 取得相關道具當前數量
            $allMaterialsData = $this->userItemSvc->getFormattedItems($user->uid, $allMaterials);

            return response()->json([
                'success' => true,
                'data' => $allMaterialsData,
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'error_code' => $e->getMessage(),
            ], 400);
        }
    }

    // 一鍵合成
    public function autoFuse(Request $request)
    {
        return response()->json(['data' => []]);
    }

    /** 退回道具 */
    public function reset(Request $request)
    {
        $user = $this->resolveUid($request);

        if (! $user) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }
        $itemId = request()->input('item_id');
        // 檢查道具
        $check = $this->userItemSvc->checkResource($user->id, $itemId, 1);
        if (! $check['success'] == 1) {
            $errorMsg = ErrorService::errorCode(__METHOD__, $check['error_code']);
            $errorMsg['need_item_id'] = $itemId;

            return response()->json($errorMsg, 422);
        }
        $check = $this->userItemSvc->checkResource($user->id, 100, 1000);
        if (! $check['success'] == 1) {
            $errorMsg = ErrorService::errorCode(__METHOD__, $check['error_code']);
            $errorMsg['need_item_id'] = 100;
            return response()->json($errorMsg, 422);
        }
        $result = $this->treasureSvc->reset($user, $itemId);
        if (isset($result['success']) && $result['success'] === false) {
            return response()->json(ErrorService::errorCode(__METHOD__, $result['error_code']), 422);
        }

        return response()->json(['data' => $result], 200);
    }

    // 獲得裝備
    public function obtain(Request $request)
    {
        $user = auth()->guard('api')->user();
        if (empty($user)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0006'), 422);
        }
        $uid = $user->uid;
        $itemId = $request->input('item_id');
        if (empty($itemId) || $this->treasureSvc->isTreasure($itemId) === false) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'EQUIPMENT:0005'), 422);
        }

        // 發送道具記錄到user_items，並建立裝備紀錄
        $addResult = UserItemService::addItem(90, $user->id, $user->uid, $itemId, 1, 1, '獲得寶物');
        if ($addResult['success'] === 0) {
            return response()->json(ErrorService::errorCode(__METHOD__, $addResult['error_code']), 422);
        }

        $result = Model::where('item_id', $itemId)->first();

        return response()->json(['data' => $result], 200);
    }

    /**
     * 解析請求中的玩家 UID
     */
    protected function resolveUid(Request $request)
    {
        $authUser = auth()->guard('api')->user();
        if ($authUser) {
            return $authUser;
        }

        return null;
    }

    /**
     * 將道具資料調整成整數陣列
     */
    protected function normalizeEarned($input): array
    {
        if (is_string($input)) {
            $trimmed = trim($input);

            if ($trimmed === '') {
                return [];
            }

            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $input = $decoded;
            } else {
                $input = preg_split('/[\s,]+/', $trimmed, -1, PREG_SPLIT_NO_EMPTY);
            }
        }

        if (! is_array($input)) {
            return [];
        }

        $itemIds = [];

        foreach ($input as $value) {
            if (is_bool($value)) {
                $itemIds[] = $value ? 1 : 0;

                continue;
            }

            if (is_numeric($value)) {
                $itemIds[] = (int) $value;
            }
        }

        return $itemIds;
    }

    private function groupItemIds(array $itemIds): array
    {
        $result = [];

        foreach ($itemIds as $id) {
            if (! isset($result[$id])) {
                $result[$id] = [
                    'item_id' => $id,
                    'amount' => 0,
                ];
            }
            $result[$id]['amount']++;
        }

        return array_values($result);
    }

    private function checkMaterialCount($user, $materialIds): bool|array
    {
        foreach ($materialIds as $data) {
            $itemId = $data['item_id'];
            $amount = $data['amount'];

            $check = $this->userItemSvc->checkResource($user->id, $itemId, $amount);

            if (! $check['success'] == 1) {
                $errorMsg = ErrorService::errorCode(__METHOD__, $check['error_code']);
                $errorMsg['need_item_id'] = $itemId;

                return $errorMsg;
            }
        }

        return true;
    }
}
