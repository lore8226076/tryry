<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserSurGameInfo;
use App\Service\ErrorService;
use App\Service\TalentService;
use Illuminate\Http\Request;

class TalentController extends Controller
{
    public function __construct(Request $request)
    {
        $origin = $request->header('Origin');
        $referer = $request->header('Referer');
        $referrerDomain = parse_url($origin, PHP_URL_HOST) ?? parse_url($referer, PHP_URL_HOST);
        if ($referrerDomain != config('services.API_PASS_DOMAIN')) {
            $this->middleware('auth:api', ['except' => ['']]);
        }
    }

    // 玩家天賦
    public function getUserTalents(Request $request, TalentService $talentService)
    {
        $uid = auth()->guard('api')->user()->uid;
        if (empty($uid)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }

        // 取得玩家遊戲資訊
        $surgameinfo = UserSurGameInfo::where('uid', $uid)->first();
        if ($surgameinfo === null) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0006'), 422);
        }

        $checkPool = $this->checkDrawTalent($request, $talentService, $surgameinfo);
        if ($checkPool['success'] === 0) {
            return response()->json(ErrorService::errorCode(__METHOD__, $checkPool['error_code']), 422);
        }
        $checkPool = $checkPool['checkPool'];
        $results['can_draw_talent'] = ($checkPool['success'] === 1 && $checkPool['status'] === 'active') ? 1 : 0;
        $results['is_max_level'] = $talentService->isUserMaxLevel($surgameinfo) ? 1 : 0;
        $results['current_cost'] = $talentService->getCurrentPoolCost($checkPool['level']);
        $results['items'] = $talentService->getUserTalents($uid);

        return response()->json(['data' => $results], 200);
    }

    // 玩家抽取天賦
    public function drawTalent(Request $request, TalentService $talentService)
    {
        $uid = auth()->guard('api')->user()->uid;
        if (empty($uid)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }

        // 取得玩家遊戲資訊
        $surgameinfo = UserSurGameInfo::where('uid', $uid)->first();
        if ($surgameinfo === null) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0006'), 422);
        }

        // 拿最新可用的池子
        $talentPool = $talentService->getAvailableTalent($uid);
        if (empty($talentPool)) {
            $result['is_level_enough'] = 0;
            $result['is_max_level'] = $talentService->isUserMaxLevel($surgameinfo) ? 1 : 0;
            return response()->json(['data' => $result], 422);
        }

        // 抽獎
        $drawResult = $talentService->executeDraw($uid, $talentPool['session_id'], $talentPool['items'] ?? []);
        if ($drawResult['success'] === 0) {
            return response()->json(ErrorService::errorCode(__METHOD__, $drawResult['error_code']), 422);
        }
        $itemCode = $drawResult['data'];

        $checkPool = $this->checkDrawTalent($request, $talentService, $surgameinfo);
        if ($checkPool['success'] === 0) {
            return response()->json(ErrorService::errorCode(__METHOD__, $checkPool['error_code']), 422);
        }
        $checkPool = $checkPool['checkPool'];

        // 抽獎結果美化
        $formattedResults = $talentService->formatDrawResult($uid, $itemCode);
        $results['can_draw_talent'] = ($checkPool['success'] === 1 && $checkPool['status'] === 'active') ? 1 : 0;
        $results['is_max_level'] = $talentService->isUserMaxLevel($surgameinfo) ? 1 : 0;
        $results['current_cost'] = $talentService->getCurrentPoolCost($checkPool['level']);
        $results['items'] = [$formattedResults];

        return response()->json(['data' => $results], 200);
    }

    // 檢查是否能夠抽取天賦
    public function checkDrawTalent(Request $request, TalentService $talentService, $surgameinfo)
    {
        // 檢查玩家是否還能抽獎
        $checkPool = $talentService->checkMaxLevelTalentPool($surgameinfo);
        // 沒有池子，建立一個
        if ($checkPool['status'] === 'pending') {
            $createResult = $talentService->createTalentPool($surgameinfo->uid, $checkPool['level']);
            if (! $createResult['success']) {
                return [
                    'success' => 0,
                    'error_code' => $createResult['error_code'],
                ];
            }
            $checkPool = $talentService->checkMaxLevelTalentPool($surgameinfo);
        }

        return [
            'success' => 1,
            'checkPool' => $checkPool,
        ];
    }
}
