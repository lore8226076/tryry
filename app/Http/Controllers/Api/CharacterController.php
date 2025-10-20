<?php

namespace App\Http\Controllers\Api;

use App\Models\CharacterStarRequirements;
use App\Models\GddbSurgamePlayerLvUp as PlayerLvUp;
use App\Models\LevelRequirements;
use App\Models\UserCharacter;
use App\Models\Users;
use App\Models\UserSurGameInfo;
use App\Models\UserSurGameInfo as UserInfo;
use App\Service\CharacterService;
use App\Service\CharacterStarService;
use App\Service\ErrorService;
use App\Service\UserItemService;
use Illuminate\Http\Request;

class CharacterController extends Controller
{
    public function __construct(Request $request)
    {
        $origin = $request->header('Origin');
        $referer = $request->header('Referer');
        $referrerDomain = parse_url($origin, PHP_URL_HOST) ?? parse_url($referer, PHP_URL_HOST);
        if ($referrerDomain != config('services.API_PASS_DOMAIN')) {
            $this->middleware('auth:api', ['except' => ['getStarRequirements', 'getLevelRequirements', 'getAllCharacter']]);
        }
    }

    // 角色星級提升
    public function startLevelUp(Request $request)
    {
        $uid = auth()->guard('api')->user()->uid;
        if (empty($uid)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }

        $characterId = $request->input('character_id');
        if (! ctype_digit((string) $characterId)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'CHARACTER:0001'), 422);
        }

        $existUserCharacter = UserCharacter::with(['character', 'user', 'user.userItems'])
            ->where([
                ['uid', '=', $uid],
                ['character_id', '=', $characterId],
            ])
            ->first();

        if (! $existUserCharacter) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'CHARACTER:0001'), 422);
        }
        // 檢查是否已經達到最高星級
        $maxStarLevel = CharacterStarService::getMaxStarLevel($existUserCharacter->character_id);
        if ($existUserCharacter->star_level >= $maxStarLevel) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'CharacterRank:0001'), 422);
        }

        // 檢查星級材料
        $starLevel = $existUserCharacter->star_level + 1;
        $starMaterial = CharacterStarService::getStarMaterial($existUserCharacter->character_id, $starLevel);
        // 檢查星級材料是否存在
        if (! $starMaterial) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'CharacterRank:0002'), 422);
        }
        // 檢查星級材料是否足夠
        // $isEnough = CharacterStarService::checkStarMaterial($existUserCharacter->character_id, $starLevel, $existUserCharacter->user);
        // if (! $isEnough) {
        //     return response()->json(ErrorService::errorCode(__METHOD__, 'CharacterRank:0003'), 422);
        // }

        // // 扣除星級材料
        // $userItemService = new UserItemService();
        // $userItemService->removeItem(50, $existUserCharacter?->user?->id, $existUserCharacter?->user->uid, $starMaterial['base_item_id'], $starMaterial['base_item_amount'], 1, '角色星級提升');
        // if (! empty($starMaterial['extra_item_id']) && ! empty($starMaterial['extra_item_amount'])) {
        //     $userItemService->removeItem(50, $existUserCharacter?->user?->id, $existUserCharacter?->user->uid, $starMaterial['extra_item_id'], $starMaterial['extra_item_amount'], 1, '角色星級提升');
        // }

        // 遞增星級
        $existUserCharacter->increment('star_level', 1);

        // 重新取得最新資料並隱藏 uid
        $existUserCharacter->refresh()->makeHidden(['uid']);
        unset($existUserCharacter->user);
        unset($existUserCharacter->character);
        // 如果slot_index = null 改為-1
        if (is_null($existUserCharacter->slot_index)) {
            $existUserCharacter->slot_index = -1;
        }

        return response()->json(['data' => $existUserCharacter]);
    }

    public function obtainCharacter(Request $request)
    {
        $uid = auth()->guard('api')->user()->uid;
        if (empty($uid)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }

        $characterId = $request->input('character_id');
        if (! ctype_digit((string) $characterId)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'CHARACTER:0001'), 422);
        }

        // 嘗試找到或建立角色
        $userCharacter = UserCharacter::firstOrCreate(
            ['uid' => $uid, 'character_id' => $characterId],
            ['star_level' => 0]
        );

        // 判斷是否已擁有
        $alreadyHas = ! $userCharacter->wasRecentlyCreated;

        $reward = null;
        if ($alreadyHas) {
            // 轉換成角色碎片
            $reward = [
                'item_id' => 199,
                'amount' => 1,
            ];
        }

        return response()->json([
            'data' => [
                'character' => $userCharacter->makeHidden(['uid']),
                'already_has' => $alreadyHas,
                'reward' => $reward, // null 代表拿到新角色，不是碎片
            ],
        ]);
    }

    public function getUserCharacterLists(Request $request)
    {
        $uid = auth()->guard('api')->user()->uid;
        if (empty($uid)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }

        $userCharacterAry = CharacterService::getUserCharacterList($uid);

        return response()->json(['data' => $userCharacterAry]);
    }

    // 主角升級
    public function mainCharacterLvUp(Request $request)
    {
        $uid = auth()->guard('api')->user()->uid;
        if (empty($uid)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }

        // 取得使用者資訊
        $currentUser = UserInfo::where('uid', $uid)->first();
        if (! $currentUser) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'USER:0001'), 422);
        }
        $cloneCurrentUser = $currentUser->replicate();
        $nextLevel = $currentUser->main_character_level + 1;

        // 取得升級資訊
        $playerLvUp = PlayerLvUp::where('account_lv', $nextLevel)->first();
        if (! $playerLvUp) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'PlayerLevelUp:0001'), 422);
        }

        // 檢查是否可以升級
        $checkResult = (new CharacterService)->mainCharacterLvUpCheck($currentUser, $playerLvUp);
        if ($checkResult['success'] === false) {
            $errorMsg = ErrorService::errorCode(__METHOD__, 'PlayerLevelUp:0002');
            $errorMsg['message'] = $checkResult['message'];

            return response()->json($errorMsg, 422);
        }

        // 升級主角
        $result = (new CharacterService)->mainCharacterLvUp($currentUser);
        if (! $result) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'PlayerLevelUp:0003'), 422);
        }

        // 發送升級獎勵
        $reward = $playerLvUp->reward ?? [];
        if (is_string($reward)) {
            $reward = json_decode($reward, true);
        }

        // 檢查獎勵是否為陣列
        if (! is_array($reward)) {
            $reward = [];
        }
        $newReward = [];
        $map = [
            0 => 'item_id',
            1 => 'amount',
        ];

        $newReward = [];
        if (! isset($reward['item_id']) && ! isset($reward['amount'])) {
            foreach ($reward as $index => $value) {
                if (isset($map[$index])) {
                    $newReward[$map[$index]] = $value;
                }
            }
        }

        if (! empty($newReward)) {
            $userItemService = new UserItemService;
            $userItemService->addItem(50, $currentUser->id, $currentUser->uid, $newReward['item_id'], $newReward['amount'], 1, '主角升級獎勵');
        }

        // 回傳升級後的使用者資訊
        return response()->json(['data' => $currentUser->makeHidden(['uid'])]);
    }

    // 取得所有角色星級需求
    public function getStarRequirements()
    {
        $data = CharacterStarRequirements::all();

        return $this->makeJson(true, $data, '查詢成功');
    }

    // 取得所有等級需求
    public function getLevelRequirements()
    {
        $data = LevelRequirements::all();

        return $this->makeJson(true, $data, '查詢成功');
    }

    // 重置人物等級
    public function resetCharacterLevel(Request $request)
    {
        $uid = auth()->guard('api')?->user()?->uid;

        // 檢查玩家是否存在
        $user = Users::where('uid', $uid)->first();
        if (! $user || empty($uid)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0006'), 401);
        }

        // 檢查surgame資料是否存在
        $surgameInfo = UserSurGameInfo::where('uid', $uid)->first();
        if (! $surgameInfo) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'SURGAME:0001'), 404);
        }

        // 重置等級
        $surgameInfo->main_character_level = 1;
        $surgameInfo->current_exp = 500;
        $surgameInfo->save();

        return response()->json(['success' => true, 'message' => '主角等級已重置'], 200);
    }
}
